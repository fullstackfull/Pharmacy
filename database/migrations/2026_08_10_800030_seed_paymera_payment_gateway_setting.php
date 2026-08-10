<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Register the Paymera eGate payment gateway row in addon_settings.
 *
 * The admin "Payment Methods" screen lists only gateway rows that already exist, so a brand-new gateway
 * is invisible until its addon_settings row is present — this seeds it (disabled, empty credentials).
 * The keys inside live_values/test_values (terminal_id, username, token) are exactly the fields the
 * admin config form renders, so the admin pastes the Terminal ID + Basic-Auth username + token here and
 * enables it, just like Stripe.
 *
 * Additive and idempotent: firstOrCreate never overwrites an already-configured row (so re-running can't
 * wipe credentials), and down() removes only the paymera row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('addon_settings')) {
            return;
        }

        Setting::firstOrCreate(
            ['key_name' => 'paymera', 'settings_type' => 'payment_config'],
            [
                'key_name' => 'paymera',
                'live_values' => [
                    'gateway' => 'paymera',
                    'mode' => 'live',
                    'status' => '0',
                    'terminal_id' => '',
                    'username' => '',
                    'token' => '',
                ],
                'test_values' => [
                    'gateway' => 'paymera',
                    'mode' => 'test',
                    'status' => '0',
                    'terminal_id' => '',
                    'username' => '',
                    'token' => '',
                ],
                'settings_type' => 'payment_config',
                'mode' => 'test',
                'is_active' => 0,
                'additional_data' => null,
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('addon_settings')) {
            Setting::where('key_name', 'paymera')->where('settings_type', 'payment_config')->delete();
        }
    }
};
