<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\Coupon;
use App\Models\Setting;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The six areas that changed the platform without leaving a line.
 *
 * The audit log was real and well used — the marketplace, campaigns and the theme engine all wrote
 * to it. It was not reached by promotions, business settings, integration credentials, payment
 * methods, notification providers or role changes: precisely the changes that decide what a
 * customer pays and who may sign in, and precisely the ones somebody eventually has to reconstruct.
 *
 * Two mechanisms cover them, because the platform writes them in two different ways, and the
 * failure mode of picking only one is silent. A coupon is saved as a model, so an observer sees it.
 * A settings row is written with `where(...)->update(...)`, which instantiates no model and raises
 * no event — an observer there would record nothing while looking installed. These hold both.
 *
 * And the values. Half of what these rows carry is a gateway secret or an SMTP password; an audit
 * log that copied them would be the softest place in the system to steal them from.
 */
class PlatformAuditTrailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach (['audit_logs', 'business_settings', 'addon_settings', 'coupons'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->nullable(); $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable(); $table->string('action');
            $table->string('subject_type')->nullable(); $table->string('subject_id')->nullable();
            $table->json('before')->nullable(); $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable(); $table->text('user_agent')->nullable();
            $table->timestamps();
        });
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id(); $table->string('type')->nullable(); $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('addon_settings', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('key_name')->nullable();
            $table->text('live_values')->nullable(); $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable(); $table->string('mode')->nullable();
            $table->integer('is_active')->default(0); $table->text('additional_data')->nullable();
            $table->timestamps();
        });
        Schema::create('coupons', function (Blueprint $table) {
            $table->id(); $table->string('title')->nullable(); $table->string('code')->nullable();
            $table->string('coupon_type')->nullable(); $table->decimal('discount', 10, 2)->default(0);
            $table->string('discount_type')->nullable(); $table->integer('status')->default(1);
            $table->timestamps();
        });
    }

    // ---- the writes that raise a model event -------------------------------------------------

    public function test_a_coupon_records_what_it_was_and_what_it_became(): void
    {
        $coupon = Coupon::create(['title' => 'Eid', 'code' => 'EID10', 'discount' => 10, 'status' => 1]);

        $this->assertSame('promotion.coupon_created', $this->lastAction());

        $coupon->update(['discount' => 40]);
        $line = AuditLog::query()->latest('id')->first();

        $this->assertSame('promotion.coupon_updated', $line->action);
        $this->assertSame(10.0, (float) $line->before['discount']);
        $this->assertSame(40, $line->after['discount']);
        $this->assertSame(Coupon::class, $line->subject_type);
        $this->assertSame((string) $coupon->id, (string) $line->subject_id);
    }

    public function test_a_save_that_changed_nothing_worth_reading_writes_no_line(): void
    {
        $coupon = Coupon::create(['title' => 'Eid', 'code' => 'EID10']);
        $before = AuditLog::query()->count();

        $coupon->touch();

        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_deleting_a_promotion_keeps_what_it_had_been(): void
    {
        Coupon::create(['title' => 'Eid', 'code' => 'EID10', 'discount' => 10])->delete();

        $line = AuditLog::query()->latest('id')->first();
        $this->assertSame('promotion.coupon_deleted', $line->action);
        $this->assertSame('EID10', $line->before['code']);
    }

    // ---- the writes that raise nothing at all ------------------------------------------------

    public function test_a_settings_mass_update_is_recorded_even_though_no_model_was_touched(): void
    {
        // The whole reason AuditedBuilder exists. This is how roughly a hundred settings writes in
        // the codebase are issued; an observer sees none of them.
        BusinessSetting::create(['type' => 'company_name', 'value' => 'Old Name']);
        AuditLog::query()->delete();

        BusinessSetting::where('type', 'company_name')->update(['value' => 'Syria Cosmetics']);

        $line = AuditLog::query()->latest('id')->first();
        $this->assertNotNull($line, 'a mass update must not pass unrecorded');
        $this->assertSame('settings.business_updated', $line->action);
        $this->assertSame('Old Name', $line->before['value']);
        $this->assertSame('Syria Cosmetics', $line->after['value']);
        $this->assertSame('company_name', $line->context['type'], 'named by what a reader recognises');
    }

    public function test_update_or_insert_is_recorded_whichever_of_the_two_it_did(): void
    {
        BusinessSetting::updateOrInsert(['type' => 'currency'], ['value' => 'SYP']);
        $this->assertSame('settings.business_created', $this->lastAction());

        BusinessSetting::updateOrInsert(['type' => 'currency'], ['value' => 'USD']);
        $this->assertSame('settings.business_updated', $this->lastAction());
    }

    public function test_deleting_a_setting_is_recorded(): void
    {
        BusinessSetting::create(['type' => 'download_app_apple_stroe', 'value' => 'https://example.test']);
        AuditLog::query()->delete();

        BusinessSetting::where('type', 'download_app_apple_stroe')->delete();

        $this->assertSame('settings.business_deleted', $this->lastAction());
    }

    public function test_a_sweep_over_many_rows_is_one_line_rather_than_a_hundred(): void
    {
        for ($i = 0; $i < 30; $i++) {
            BusinessSetting::create(['type' => 'gateway_' . $i, 'value' => 'off']);
        }
        AuditLog::query()->delete();

        BusinessSetting::where('value', 'off')->update(['value' => 'on']);

        $this->assertSame(1, AuditLog::query()->count());
        $this->assertSame('more than 25', AuditLog::query()->first()->context['rows']);
    }

    // ---- what the trail may never keep -------------------------------------------------------

    public function test_a_gateway_secret_is_recorded_as_changed_and_never_as_itself(): void
    {
        Setting::create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'key_name' => 'stripe',
            'settings_type' => 'payment_config', 'mode' => 'live', 'is_active' => 1,
            'live_values' => ['secret_key' => 'sk_live_ORIGINAL', 'published_key' => 'pk_live_1'],
        ]);
        AuditLog::query()->delete();

        Setting::where('key_name', 'stripe')
            ->update(['live_values' => json_encode(['secret_key' => 'sk_live_ROTATED', 'published_key' => 'pk_live_2'])]);

        $line = AuditLog::query()->latest('id')->first();
        $recorded = json_encode([$line->before, $line->after, $line->context]);

        $this->assertSame('settings.integration_updated', $line->action);
        $this->assertStringNotContainsString('sk_live_ORIGINAL', $recorded);
        $this->assertStringNotContainsString('sk_live_ROTATED', $recorded);
        $this->assertStringContainsString(Redactor::MASK, $recorded, 'it still says the key changed');
        $this->assertStringContainsString('pk_live_2', $recorded, 'and keeps what is not a secret');
    }

    public function test_a_secret_nested_in_a_settings_json_blob_is_masked_too(): void
    {
        // The column is called `value`, so key-name matching alone would never see inside it. This
        // is the shape every mail and SMS credential in the platform is stored in.
        BusinessSetting::create(['type' => 'mail_config', 'value' => json_encode(['host' => 'smtp.test', 'password' => 'OPENSESAME'])]);
        AuditLog::query()->delete();

        BusinessSetting::where('type', 'mail_config')
            ->update(['value' => json_encode(['host' => 'smtp.test', 'password' => 'NEWSECRET'])]);

        $recorded = json_encode(AuditLog::query()->latest('id')->first()->only(['before', 'after']));

        $this->assertStringNotContainsString('OPENSESAME', $recorded);
        $this->assertStringNotContainsString('NEWSECRET', $recorded);
        $this->assertStringContainsString('smtp.test', $recorded);
    }

    public function test_a_very_long_value_is_cut_rather_than_copied_whole(): void
    {
        BusinessSetting::create(['type' => 'colors', 'value' => str_repeat('a', 40)]);
        AuditLog::query()->delete();

        BusinessSetting::where('type', 'colors')->update(['value' => str_repeat('b', 5000)]);

        $this->assertLessThan(600, mb_strlen((string) AuditLog::query()->latest('id')->first()->after['value']));
    }

    // ---- the log stays out of the way --------------------------------------------------------

    public function test_a_settings_write_still_succeeds_when_the_log_table_is_gone(): void
    {
        // The audit contract: a missing line is never worth failing the action that was audited.
        BusinessSetting::create(['type' => 'company_name', 'value' => 'Old']);
        Schema::drop('audit_logs');

        BusinessSetting::where('type', 'company_name')->update(['value' => 'New']);

        $this->assertSame('New', BusinessSetting::where('type', 'company_name')->value('value'));
    }

    private function lastAction(): ?string
    {
        return AuditLog::query()->latest('id')->value('action');
    }
}
