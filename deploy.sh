#!/bin/bash
# Production update: pulls main and re-runs the standard deploy steps.
# Usage: bash deploy.sh
set -e
cd "$(dirname "$0")"

trap 'echo; echo "❌ فشل التحديث — الموقع ما زال بوضع الصيانة (php artisan up لإعادته يدوياً بعد حل المشكلة)."' ERR

echo "==> وضع الصيانة"
php artisan down || true

echo "==> سحب آخر نسخة من GitHub"
git pull origin main

echo "==> تحديث مكتبات PHP"
php composer.phar install --no-dev --optimize-autoloader

echo "==> ترحيلات قاعدة البيانات"
php artisan migrate --force

echo "==> تنظيف الكاش"
php artisan optimize:clear

echo "==> رابط التخزين والصلاحيات"
php artisan storage:link 2>/dev/null || true
php artisan file:permission

echo "==> إعادة التشغيل"
php artisan up

echo
echo "✅ تم التحديث إلى: $(git rev-parse --short HEAD)"
