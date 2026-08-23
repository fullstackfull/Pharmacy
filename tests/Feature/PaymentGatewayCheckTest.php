<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The fault an admin screen cannot show you.
 *
 * A gateway keeps two sets of credentials — live_values and test_values — and the controllers read
 * the one matching the row's `mode`. So a shop can have a switched-on gateway, correct credentials
 * typed into the form, and a checkout that refuses every payment, because the credentials went into
 * the column the gateway is not reading. The form looks right, because it IS right; it is just
 * describing the other mode.
 *
 * These pin that the check names that case specifically, and that it never reads out a credential.
 */
class PaymentGatewayCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('addon_settings');
        Schema::create('addon_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key_name')->nullable();
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->nullable();
            $table->integer('is_active')->default(0);
            $table->timestamps();
        });
    }

    private function gateway(string $key, ?string $mode, int $active, array $live, array $test): void
    {
        DB::table('addon_settings')->insert([
            'id' => (string) Str::uuid(),
            'key_name' => $key,
            'settings_type' => 'payment_config',
            'mode' => $mode,
            'is_active' => $active,
            'live_values' => json_encode($live),
            'test_values' => json_encode($test),
        ]);
    }

    /**
     * Run the check and return [exit code, everything it printed].
     *
     * Artisan::call, not $this->artisan(): the PendingCommand harness buffers into its own sink, so
     * Artisan::output() comes back EMPTY under it — which quietly turns any
     * assertStringNotContainsString into a test that passes because there is nothing to search.
     *
     * @return array{0: int, 1: string}
     */
    private function check(string $gateway): array
    {
        $code = Artisan::call('payment:check', ['gateway' => $gateway]);

        return [$code, Artisan::output()];
    }

    /** The shape every gateway's blob has: three structural fields plus its credentials. */
    private function credentials(string $mode, string $terminal = '', string $user = '', string $token = ''): array
    {
        return ['gateway' => 'paymera', 'mode' => $mode, 'status' => '1',
            'terminal_id' => $terminal, 'username' => $user, 'token' => $token];
    }

    public function test_it_names_the_mode_the_credentials_were_saved_under(): void
    {
        $this->gateway('paymera', 'live', 1,
            live: $this->credentials('live'),
            test: $this->credentials('test', '99990001', 'egate_user', 'egate_token'));

        [$code, $output] = $this->check('paymera');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('live_values has no terminal_id, username, token', $output);
        $this->assertStringContainsString('but test_values does', $output, 'the whole point: it must say WHERE they are');
    }

    public function test_a_mode_matching_neither_column_says_nothing_is_read(): void
    {
        $this->gateway('paymera', 'sandbox', 1,
            live: $this->credentials('live', '1', 'u', 't'),
            test: $this->credentials('test', '1', 'u', 't'));

        [$code, $output] = $this->check('paymera');

        $this->assertSame(1, $code);
        $this->assertStringContainsString("mode is 'sandbox'", $output);
    }

    public function test_a_complete_gateway_is_ready(): void
    {
        $this->gateway('paymera', 'test', 1,
            live: $this->credentials('live'),
            test: $this->credentials('test', '99990001', 'egate_user', 'egate_token'));

        [$code, $output] = $this->check('paymera');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('ready', $output);
    }

    public function test_a_switched_off_gateway_is_reported_but_does_not_fail_the_check(): void
    {
        // An incomplete gateway nobody switched on is not a fault — it is a gateway nobody uses.
        $this->gateway('paymera', 'test', 0, live: $this->credentials('live'), test: $this->credentials('test'));

        [$code, $output] = $this->check('paymera');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('test_values has no terminal_id', $output);
    }

    public function test_it_never_reads_out_a_credential(): void
    {
        // The reason this is safe to paste into a support chat, and the reason it must stay so.
        $this->gateway('paymera', 'live', 1,
            live: $this->credentials('live', 'TERMINAL9999', 'SECRET_USER', 'SECRET_TOKEN_VALUE'),
            test: $this->credentials('test'));

        [, $output] = $this->check('paymera');

        $this->assertNotSame('', trim($output), 'an empty output would pass the checks below for the wrong reason');

        foreach (['TERMINAL9999', 'SECRET_USER', 'SECRET_TOKEN_VALUE'] as $secret) {
            $this->assertStringNotContainsString($secret, $output, 'a credential reached the output');
        }
    }

    public function test_a_gateway_that_was_never_saved_says_where_to_save_it(): void
    {
        [$code, $output] = $this->check('nosuchgateway');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No configuration row exists', $output);
    }
}
