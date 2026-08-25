<?php

namespace Tests\Feature;

use App\Services\Platform\FeatureFlags;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Turning something on for some of the shop rather than all of it.
 *
 * The platform had no flag table, no config and no per-seller or per-percentage switch: the only
 * lever was publishing or unpublishing a whole addon module, so every change went live for every
 * seller and every shopper at the same moment and the only way back was a deployment.
 *
 * Three rules make the answer trustworthy, and each has a test here because getting any of them
 * wrong turns a safety mechanism into a source of incidents: an unknown flag must be off, the same
 * subject must always get the same answer, and the master switch must beat everything including the
 * pilot list — an off switch some people are exempt from is not an off switch.
 */
class FeatureFlagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('feature_flags');
        (require database_path('migrations/2026_09_20_000001_create_feature_flags_table.php'))->up();

        Schema::dropIfExists('audit_logs');
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('actor_type')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name')->nullable();
            $t->string('action');
            $t->string('subject_type')->nullable();
            $t->string('subject_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->json('context')->nullable();
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamps();
        });

        Cache::flush();
    }

    private function flags(): FeatureFlags
    {
        return app(FeatureFlags::class);
    }

    private function save(string $key, array $input): void
    {
        $this->flags()->save($key, $input);
        Cache::flush();
    }

    /** A rollout is opt-in. An unknown key is a typo or a removed flag; either way, the old behaviour. */
    public function test_a_flag_that_does_not_exist_is_off(): void
    {
        $this->assertFalse($this->flags()->enabled('nothing.here', 5));
    }

    public function test_an_off_switch_beats_the_pilot_list(): void
    {
        $this->save('checkout.new_flow', ['enabled' => false, 'rollout_percent' => 100, 'seller_ids' => '7']);

        $this->assertFalse($this->flags()->enabled('checkout.new_flow', 7));
    }

    public function test_the_pilot_list_beats_the_percentage(): void
    {
        $this->save('checkout.new_flow', ['enabled' => true, 'rollout_percent' => 0, 'seller_ids' => '7, 9']);

        $this->assertTrue($this->flags()->enabled('checkout.new_flow', 7));
        $this->assertTrue($this->flags()->enabled('checkout.new_flow', 9));
        $this->assertFalse($this->flags()->enabled('checkout.new_flow', 8));
    }

    public function test_a_full_rollout_is_on_for_everyone_including_callers_with_no_subject(): void
    {
        $this->save('checkout.new_flow', ['enabled' => true, 'rollout_percent' => 100]);

        $this->assertTrue($this->flags()->enabled('checkout.new_flow', 3));
        $this->assertTrue($this->flags()->enabled('checkout.new_flow'));
    }

    /**
     * A percentage with nobody to bucket cannot be honoured, and guessing would answer the same
     * request differently on every reload.
     */
    public function test_a_partial_rollout_is_off_when_there_is_no_subject_to_bucket(): void
    {
        $this->save('checkout.new_flow', ['enabled' => true, 'rollout_percent' => 50]);

        $this->assertFalse($this->flags()->enabled('checkout.new_flow'));
    }

    /**
     * The one property that makes a rollout usable: a shop stays on the same side of the line
     * across every request, page and device until somebody moves the percentage. A random draw per
     * request would show one seller two versions of the product in a single session.
     */
    public function test_the_same_seller_always_gets_the_same_answer(): void
    {
        $this->save('checkout.new_flow', ['enabled' => true, 'rollout_percent' => 50]);

        $first = $this->flags()->enabled('checkout.new_flow', 42);

        foreach (range(1, 20) as $ignored) {
            $this->assertSame($first, $this->flags()->enabled('checkout.new_flow', 42));
        }
    }

    /** Two flags at the same percentage must not pick the same population, or nothing generalises. */
    public function test_two_flags_at_the_same_percentage_do_not_select_the_same_sellers(): void
    {
        $flags = $this->flags();

        $one = array_map(static fn (int $id) => $flags->bucket('flag.one', (string) $id), range(1, 200));
        $two = array_map(static fn (int $id) => $flags->bucket('flag.two', (string) $id), range(1, 200));

        $this->assertNotSame($one, $two);
    }

    /** Roughly the share it says. Not exact — deterministic bucketing never is — but not 0 or 200. */
    public function test_a_percentage_selects_approximately_that_share(): void
    {
        $this->save('checkout.new_flow', ['enabled' => true, 'rollout_percent' => 30]);
        $flags = $this->flags();

        $on = count(array_filter(range(1, 1000), static fn (int $id) => $flags->enabled('checkout.new_flow', $id)));

        $this->assertGreaterThan(200, $on);
        $this->assertLessThan(400, $on);
    }

    /** 140% is a typo for 100, not a reason to lose the rest of the edit. */
    public function test_an_impossible_percentage_is_clamped_rather_than_refused(): void
    {
        $this->save('checkout.new_flow', ['enabled' => true, 'rollout_percent' => 140]);

        $this->assertSame(100, $this->flags()->all()['checkout.new_flow']['rollout_percent']);
    }

    /** A key that does not match what the code asks for is a switch that silently does nothing. */
    public function test_a_key_the_code_could_not_ask_for_is_refused(): void
    {
        $result = $this->flags()->save('Checkout New Flow!', ['enabled' => true]);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $this->flags()->all());
    }

    /**
     * Deleting returns everyone to the old behaviour at once, which from the outside is
     * indistinguishable from the new code having been reverted.
     */
    public function test_creating_and_deleting_a_flag_are_both_recorded(): void
    {
        $this->save('checkout.new_flow', ['enabled' => true, 'rollout_percent' => 10]);
        $this->flags()->delete('checkout.new_flow');

        $actions = \App\Models\AuditLog::pluck('action')->all();

        $this->assertContains('platform.feature_flag_created', $actions);
        $this->assertContains('platform.feature_flag_deleted', $actions);
    }

    /** A rollout that cannot be read must never fail open. */
    public function test_an_unreadable_flag_table_means_the_old_behaviour_everywhere(): void
    {
        $this->save('checkout.new_flow', ['enabled' => true, 'rollout_percent' => 100]);
        Schema::dropIfExists('feature_flags');
        Cache::flush();

        $this->assertFalse($this->flags()->enabled('checkout.new_flow', 1));
    }
}
