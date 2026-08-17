<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\ExchangeRateLog;
use App\Services\Marketplace\ExchangeRateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Exchange-rate governance (Phase 3, Stage E).
 *
 * It writes the rate onto the existing currencies table and logs the before/after; a no-op change
 * records nothing; bulk update reports how many actually moved; and the guards refuse a non-positive
 * rate and a missing currency. Conversion itself is the platform's — untouched here.
 */
class ExchangeRateTest extends TestCase
{
    private ExchangeRateService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['exchange_rate_logs', 'currencies'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('currencies', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('symbol')->nullable(); $t->string('code')->nullable();
            $t->decimal('exchange_rate', 18, 6)->default(1); $t->boolean('status')->default(true); $t->timestamps();
        });
        Schema::create('exchange_rate_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('currency_id'); $t->string('code')->nullable();
            $t->decimal('old_rate', 18, 6)->nullable(); $t->decimal('new_rate', 18, 6); $t->string('source')->default('manual');
            $t->unsignedBigInteger('changed_by')->nullable(); $t->timestamps();
        });

        $this->svc = new ExchangeRateService();
    }

    private function currency(string $code, float $rate): Currency
    {
        return Currency::create(['name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => $rate, 'status' => true]);
    }

    public function test_updating_a_rate_writes_it_and_logs_the_change(): void
    {
        $c = $this->currency('SYP', 13000);

        $res = $this->svc->updateRate($c->id, 14500, 7);

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['changed']);
        $this->assertEquals(14500, (float) $c->fresh()->exchange_rate);

        $log = ExchangeRateLog::where('currency_id', $c->id)->first();
        $this->assertEquals(13000, (float) $log->old_rate);
        $this->assertEquals(14500, (float) $log->new_rate);
        $this->assertSame(7, $log->changed_by);
    }

    public function test_an_unchanged_rate_records_nothing(): void
    {
        $c = $this->currency('EUR', 0.92);

        $res = $this->svc->updateRate($c->id, 0.92);

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['changed']);
        $this->assertSame(0, ExchangeRateLog::count(), 'a no-op change leaves no log line');
    }

    public function test_a_non_positive_rate_is_refused(): void
    {
        $c = $this->currency('GBP', 0.8);

        $this->assertFalse($this->svc->updateRate($c->id, 0)['ok']);
        $this->assertFalse($this->svc->updateRate($c->id, -1)['ok']);
        $this->assertEquals(0.8, (float) $c->fresh()->exchange_rate, 'unchanged');
    }

    public function test_a_missing_currency_is_reported(): void
    {
        $res = $this->svc->updateRate(999999, 1.5);
        $this->assertFalse($res['ok']);
        $this->assertSame('currency_not_found', $res['reason']);
    }

    public function test_bulk_update_applies_many_and_counts_the_changes(): void
    {
        $a = $this->currency('SYP', 13000);
        $b = $this->currency('EUR', 0.92);
        $c = $this->currency('AED', 3.67);

        // SYP moves, EUR unchanged, AED moves.
        $changed = $this->svc->bulkUpdate([$a->id => 14000, $b->id => 0.92, $c->id => 3.70], 7);

        $this->assertSame(2, $changed, 'only SYP and AED actually changed');
        $this->assertSame(2, ExchangeRateLog::count());
    }

    public function test_history_returns_changes_newest_first(): void
    {
        $c = $this->currency('SYP', 13000);
        $this->svc->updateRate($c->id, 13500);
        $this->svc->updateRate($c->id, 14000);

        $history = $this->svc->history($c->id);

        $this->assertCount(2, $history);
        $this->assertSame('14000.000000', (string) $history->first()->new_rate, 'newest first');
    }

    // ---- multi-currency conversion (payouts) ----

    public function test_convert_scales_by_the_ratio_of_the_two_rates(): void
    {
        // Base USD (rate 1), target SYP (rate 13000): 100 USD -> 1,300,000 SYP.
        $this->currency('USD', 1);
        $this->currency('SYP', 13000);

        $result = $this->svc->convert(100, 'USD', 'SYP');

        $this->assertEqualsWithDelta(1300000, $result['amount'], 0.001);
        $this->assertEqualsWithDelta(13000, $result['rate'], 0.000001);
        $this->assertSame('SYP', $result['to']);
    }

    public function test_convert_between_two_non_usd_currencies_uses_their_ratio(): void
    {
        $this->currency('AED', 3.67);
        $this->currency('EUR', 0.92);

        // 100 AED -> EUR at ratio 0.92/3.67.
        $result = $this->svc->convert(100, 'AED', 'EUR');

        $this->assertEqualsWithDelta(100 * (0.92 / 3.67), $result['amount'], 0.001);
    }

    public function test_convert_is_a_noop_for_the_same_currency_or_a_bad_rate(): void
    {
        $this->currency('USD', 1);
        $this->currency('ZZZ', 0); // non-positive rate must not scale the amount to zero

        $this->assertSame(100.0, $this->svc->convert(100, 'USD', 'USD')['amount']);
        $this->assertSame(1.0, $this->svc->convert(100, 'USD', 'USD')['rate']);
        $this->assertSame(100.0, $this->svc->convert(100, 'USD', 'ZZZ')['amount'], 'a zero rate falls back to 1:1');
    }
}
