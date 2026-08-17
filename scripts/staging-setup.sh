#!/usr/bin/env bash
#
# Stand up an isolated local staging copy of the store for UI work and browser testing.
#
# Safety: this script only ever writes to a LOCAL database named by STAGING_DB and to
# the local .env. It never connects to a remote host. Your original .env is copied to
# .env.production.bak on first run and is not modified afterwards.
#
# Usage:  ./scripts/staging-setup.sh [path/to/dump.sql]
#
set -euo pipefail

DUMP="${1:-pharmacysyria_syriastore.sql}"
STAGING_DB="${STAGING_DB:-pharmacy_staging}"
STAGING_USER="${STAGING_USER:-staging}"
STAGING_PASS="${STAGING_PASS:-staging_local_only}"
APP_PORT="${APP_PORT:-8000}"

cd "$(dirname "$0")/.."

if [ ! -f "$DUMP" ]; then
    echo "Dump not found: $DUMP" >&2
    echo "Pass one explicitly, or use installation/backup/database.sql for a clean install." >&2
    exit 1
fi

echo "==> Starting MariaDB"
if ! mariadb -u root -e 'SELECT 1' >/dev/null 2>&1; then
    mkdir -p /run/mysqld /var/log/mysql
    chown -R mysql:mysql /run/mysqld /var/lib/mysql /var/log/mysql 2>/dev/null || true
    nohup /usr/sbin/mariadbd --user=mysql --bind-address=127.0.0.1 --port=3306 \
        >/var/log/mysql/boot.log 2>&1 &
    for _ in $(seq 1 30); do
        mariadb -u root -e 'SELECT 1' >/dev/null 2>&1 && break
        sleep 1
    done
fi
mariadb -u root -e 'SELECT VERSION()' >/dev/null

echo "==> Creating $STAGING_DB"
mariadb -u root <<SQL
DROP DATABASE IF EXISTS \`$STAGING_DB\`;
CREATE DATABASE \`$STAGING_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$STAGING_USER'@'127.0.0.1' IDENTIFIED BY '$STAGING_PASS';
GRANT ALL PRIVILEGES ON \`$STAGING_DB\`.* TO '$STAGING_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "==> Importing $DUMP"
mariadb -u root "$STAGING_DB" < "$DUMP"

echo "==> Pointing .env at staging (original preserved as .env.production.bak)"
[ -f .env.production.bak ] || cp .env .env.production.bak
php - <<PHP
<?php
\$env = file_get_contents('.env');
\$set = function (\$env, \$key, \$value) {
    return preg_match("/^\$key=.*\$/m", \$env)
        ? preg_replace("/^\$key=.*\$/m", "\$key=\$value", \$env)
        : rtrim(\$env, "\n") . "\n\$key=\$value\n";
};
foreach ([
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'http://127.0.0.1:$APP_PORT',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => '$STAGING_DB',
    'DB_USERNAME' => '$STAGING_USER',
    'DB_PASSWORD' => '$STAGING_PASS',
    // A staging box must never be able to email a real customer or charge a real card.
    'MAIL_MAILER' => 'log',
    'QUEUE_CONNECTION' => 'sync',
] as \$k => \$v) { \$env = \$set(\$env, \$k, \$v); }
file_put_contents('.env', \$env);
PHP

echo "==> Migrating (the dump is imported FIRST — never migrate an empty database)"
php artisan optimize:clear
php artisan migrate --force
php artisan passport:keys --force || true
php artisan storage:link || true

echo "==> Creating the throwaway QA admin"
php artisan tinker --execute='
use Illuminate\Support\Facades\Hash;
use App\Models\Admin;
$email = "staging.qa@localhost.test";
$admin = Admin::where("email", $email)->first() ?: new Admin();
$admin->name = "Staging QA";
$admin->phone = "0000000000";
$admin->email = $email;
$admin->password = Hash::make("StagingQA!2026");
$admin->admin_role_id = 1;
$admin->status = 1;
$admin->save();
echo "admin id=" . $admin->id . PHP_EOL;
'

echo "==> Restoring bundled demo images"
# installation/backup/public.zip holds the images the install ships with (icons,
# categories, banners). The merchant's own product photos live on their server and
# are not in the dump, so those stay as placeholders in staging.
php -r '
$zip = new ZipArchive();
if ($zip->open("installation/backup/public.zip") === true) {
    @mkdir("storage/app/public", 0775, true);
    $zip->extractTo("storage/app/public");
    $zip->close();
    echo "extracted " . $zip->numFiles . " files\n";
}'

echo "==> Building Kohl assets"
npm run production

cat <<INFO

Staging is ready.

  Serve      PHP_CLI_SERVER_WORKERS=10 php artisan serve --host=127.0.0.1 --port=$APP_PORT
  Storefront http://127.0.0.1:$APP_PORT/
  Admin      http://127.0.0.1:$APP_PORT/login/employee
             staging.qa@localhost.test / StagingQA!2026

The admin login page (/login/admin) is reserved for the account with id=1, so the QA
account signs in through the employee page. It still holds the Master Admin role.

Browser checks:  SHOT_DIR=/tmp/shots node tests/browser/capture.mjs
Restore prod env: cp .env.production.bak .env && php artisan optimize:clear
INFO
