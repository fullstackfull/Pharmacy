# Deploying this platform

What has to be running for the platform to behave the way its screens claim it does.

Most of this is not optional in the way "nice to have" is optional. A seller's Action Center reads
issues a scheduled command produces; a bulk price change is a queued job; a webhook retry is a row
that a sweep turns back into work. Without the scheduler and a worker, those screens are not wrong —
they are empty, for ever, with no error anywhere to say why.

## 1. The scheduler

One cron entry, and everything below runs from it:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Confirm what it will run with `php artisan schedule:list`. What depends on it:

| Command | Cadence | What stops without it |
|---|---|---|
| `seller:refresh-insights` | hourly | The Action Center, the Control Tower and the daily briefing go stale, then empty as issues expire |
| `seller:escalate-issues` | every 4h | Issues nobody answers never climb; an ignored critical stays a low for ever |
| `seller:run-automation` | every 15m | Every seller rule stops running. The screen still shows the rules, which is worse than showing none |
| `seller:retry-webhooks` | every 5m | A delivery that fails once is never retried, so an endpoint that blips loses that event permanently |
| `seller:run-stuck-bulk-jobs` | every minute | Bulk jobs sit at `queued` for ever if no worker ever picked them up |
| `marketplace:settle --release` | daily 02:00 | Seller balances never move from reserved to payable |
| `marketplace:evaluate-sla` | daily 03:00 | SLA breaches are never recorded |
| `monitoring:*`, `analytics:*`, `telemetry:*` | various | Platform monitoring and analytics stop collecting and rolling up |

## 2. A queue worker

`QUEUE_CONNECTION=database`, so the jobs table is the queue and something has to drain it.

```bash
php artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600
```

Run it under a supervisor that restarts it — `--max-time` makes the process exit on purpose so a
long-lived worker never holds stale code after a deploy.

What is queued: bulk price and stock operations, and every webhook delivery. `seller:run-stuck-bulk-jobs`
is a safety net for a missing worker, not a replacement for one; nothing sweeps up webhook deliveries
that were never attempted.

The `failed_jobs` table must exist (it is created by migration). Four consumers read it, and without
it an exhausted job leaves no trace at all.

## 3. Migrations

```bash
php artisan migrate --force
```

**Never run this against an empty database.** Import `installation/backup/database.sql` first, then
migrate. The migrations are written to be incremental and safe to run twice: each checks whether its
table or column already exists, and the one migration that drops tables refuses to drop a table that
holds rows.

## 4. Caches

After a deploy:

```bash
php artisan optimize:clear
php artisan file:permission
```

`AppServiceProvider` reads themes, settings, payment configuration and add-on state from the database
at boot, so a stale config cache is how a setting change appears not to take effect.

## 5. What is safe to leave off

- **Webhooks and API keys** need no configuration. They do nothing until a seller creates one.
- **Brand enforcement** ships behind a flag and off. Turning it on over an empty registry would
  refuse every listing in every shop; populate the registry first.
- **Seller automation** does nothing until a seller writes a rule.

## 6. Checking a deploy

```bash
php artisan schedule:list                     # every command above should appear
php artisan queue:work --once                 # should exit cleanly
php artisan seller:refresh-insights           # prints how many issues it wrote
```

If the seller app shows empty screens where a seller expects issues or automation results, check the
scheduler before checking the code.
