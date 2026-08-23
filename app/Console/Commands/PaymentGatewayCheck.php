<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Why a payment gateway is not taking payments, without printing a single credential.
 *
 * A gateway's settings live in addon_settings as two JSON blobs — live_values and test_values — and
 * the controllers read the ONE that matches the row's `mode` column. That is the fault nobody can
 * see from the admin screen: the form shows the fields filled in, because they ARE filled in, in the
 * column the gateway is not reading. A shop can sit with a switched-on gateway, correct credentials
 * saved under `test`, `mode` set to `live`, and a checkout that refuses every payment.
 *
 * So this reports the column in force and which of its fields are blank, BY NAME. No value is ever
 * read out — the point is to be safe to run on a live shop and safe to paste into a support chat.
 *
 *   php artisan payment:check            all configured gateways
 *   php artisan payment:check paymera    one of them
 *
 * Exits non-zero when a gateway that is SWITCHED ON has a gap, so it can gate a deploy.
 */
class PaymentGatewayCheck extends Command
{
    protected $signature = 'payment:check {gateway? : one gateway key, e.g. paymera}';

    protected $description = 'Report which payment gateways can actually take a payment, and why not';

    /** Fields that are structure, not credentials — blank ones here are not a fault. */
    private const NON_CREDENTIAL_FIELDS = ['gateway', 'mode', 'status'];

    public function handle(): int
    {
        $wanted = $this->argument('gateway');

        try {
            $rows = DB::table('addon_settings')
                ->where('settings_type', 'payment_config')
                ->when($wanted !== null, fn ($query) => $query->where('key_name', $wanted))
                ->orderBy('key_name')
                ->get();
        } catch (\Throwable $exception) {
            $this->error('addon_settings could not be read: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($rows->isEmpty()) {
            $this->warn($wanted === null
                ? 'No payment gateway is configured at all (no addon_settings row with settings_type=payment_config).'
                : "No configuration row exists for '{$wanted}'. Save it once in Admin → 3rd Party Setup → Payment Methods.");

            return self::FAILURE;
        }

        $table = [];
        $faults = [];
        $broken = 0;

        foreach ($rows as $row) {
            $active = (int) ($row->is_active ?? 0) === 1;
            $verdict = $this->verdict($row);

            if ($verdict !== 'ready') {
                // Below the table, not inside it. This sentence is the answer, and a table cell
                // wraps it into unreadable fragments on any terminal narrower than the machine it
                // was written on.
                $faults[] = ($active ? '  ' : '  (off) ') . $row->key_name . ': ' . $verdict;
            }
            if ($active && $verdict !== 'ready') {
                $broken++;
            }

            $table[] = [
                $row->key_name,
                $active ? 'on' : 'off',
                var_export($row->mode, true),
                $this->columnInForce($row->mode) ?? '—',
                $verdict === 'ready' ? 'ready' : 'CANNOT TAKE PAYMENTS',
            ];
        }

        $this->table(['gateway', 'switched', 'mode', 'reads', 'verdict'], $table);

        if ($faults !== []) {
            $this->newLine();
            $this->line('Why:');
            foreach ($faults as $fault) {
                $this->line($fault);
            }
        }

        $this->newLine();

        if ($broken > 0) {
            $this->error($broken . ' switched-on gateway(s) cannot take a payment.');
            $this->line('Nothing above prints a credential — only field names — so it is safe to share.');

            return self::FAILURE;
        }

        $this->info('Every switched-on gateway has the credentials it reads.');

        return self::SUCCESS;
    }

    /** Which JSON column the controllers will actually read for this row's mode. */
    private function columnInForce(?string $mode): ?string
    {
        return match ($mode) {
            'live' => 'live_values',
            'test' => 'test_values',
            default => null,
        };
    }

    /**
     * The row's fault in one phrase, or "ready".
     *
     * Field NAMES only. A gateway's token is exactly the thing a support paste must not carry.
     */
    private function verdict(object $row): string
    {
        $column = $this->columnInForce($row->mode);

        if ($column === null) {
            return 'mode is ' . var_export($row->mode, true) . ' — expected "live" or "test", so no column is read';
        }

        $values = json_decode((string) ($row->$column ?? ''), true);

        if (!is_array($values) || $values === []) {
            return $column . ' is empty or not valid JSON';
        }

        $blank = [];
        foreach ($values as $field => $value) {
            if (in_array($field, self::NON_CREDENTIAL_FIELDS, true)) {
                continue;
            }
            if ($value === null || $value === '') {
                $blank[] = $field;
            }
        }

        if ($blank !== []) {
            $other = $column === 'live_values' ? 'test_values' : 'live_values';
            $savedElsewhere = $this->hasValues($row->$other ?? null, $blank);

            return $column . ' has no ' . implode(', ', $blank)
                . ($savedElsewhere ? " — but {$other} does. The credentials are saved under the mode that is switched off." : '');
        }

        return 'ready';
    }

    /**
     * Whether the OTHER mode holds what this one is missing.
     *
     * This is the whole reason the command exists: it turns "the fields look filled in on my screen"
     * into "they are, in the column your gateway is not reading".
     *
     * @param  array<int, string>  $fields
     */
    private function hasValues(mixed $json, array $fields): bool
    {
        $values = json_decode((string) $json, true);

        if (!is_array($values)) {
            return false;
        }

        foreach ($fields as $field) {
            if (($values[$field] ?? '') === '') {
                return false;
            }
        }

        return true;
    }
}
