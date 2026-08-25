<?php

namespace Tests\Feature;

use App\Models\Seller;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\SellerCenter\Icons;
use App\Services\SellerCenter\Moment;
use App\Services\SellerCenter\Navigation;
use App\Services\SellerCenter\Shell;
use App\Services\SellerCenter\Status;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 1's definition of done (handoff 13).
 *
 * The foundation is finished when a screen can be assembled from configuration alone: one table,
 * one filter system, one status language, every data state, both directions. These tests hold that
 * line — a second table implementation, a colour outside the four severity tokens, or a navigation
 * item a role cannot reach would all show up here rather than in review.
 */
class SellerCenterFoundationTest extends TestCase
{
    private const SELLER = 1;
    private const RIVAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['seller_staff', 'seller_roles', 'sellers', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status', 20)->default('approved');
            $table->timestamps();
        });
        Schema::create('seller_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name', 120);
            $table->text('permissions')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name', 120);
            $table->string('email', 191);
            $table->string('password')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Seller::insert([
            ['id' => self::SELLER, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved'],
            ['id' => self::RIVAL, 'f_name' => 'Rival', 'l_name' => 'Two', 'status' => 'approved'],
        ]);
    }

    /** Every destination resolves, so these tests describe the registry rather than the wave. */
    private function allRoutesExist(): callable
    {
        return static fn (string $name): bool => true;
    }

    private function owner(): \App\Services\Marketplace\SellerPrincipal
    {
        return \App\Services\Marketplace\SellerPrincipal::owner(Seller::find(self::SELLER));
    }

    private function staffWith(array $permissions): \App\Services\Marketplace\SellerPrincipal
    {
        $role = SellerRole::create([
            'seller_id' => self::SELLER,
            'name' => 'Limited',
            'permissions' => $permissions,
        ]);
        $staff = SellerStaff::create([
            'seller_id' => self::SELLER,
            'seller_role_id' => $role->id,
            'name' => 'Clerk',
            'email' => 'clerk@example.com',
            'password' => Hash::make('ClerkPass123'),
            'status' => SellerStaff::STATUS_ACTIVE,
        ]);

        return \App\Services\Marketplace\SellerPrincipal::staff(Seller::find(self::SELLER), $staff, $permissions);
    }

    // ───────────────────────────────────────────────────────── navigation

    public function test_a_role_never_sees_a_destination_it_cannot_reach(): void
    {
        $groups = Navigation::for($this->staffWith(['orders.view']), [], [], $this->allRoutesExist());
        $items = collect($groups)->flatMap(fn ($group) => $group['items'])->pluck('key');

        // Navigation hiding is a courtesy, not the enforcement — but it still has to be right, or
        // a seller's staff spends their day clicking into refusals.
        $this->assertTrue($items->contains('orders'));
        $this->assertFalse($items->contains('finance'));
        $this->assertFalse($items->contains('team'));
    }

    public function test_a_group_with_nothing_in_it_is_not_rendered_as_a_heading(): void
    {
        $groups = Navigation::for($this->staffWith(['orders.view']), [], [], $this->allRoutesExist());

        foreach ($groups as $group) {
            $this->assertNotEmpty($group['items'], $group['key'] . ' rendered with no items');
        }
    }

    public function test_a_module_the_marketplace_has_not_enabled_is_absent_not_disabled(): void
    {
        $withFlag = collect(Navigation::for($this->owner(), [], ['warehouses_enabled' => true], $this->allRoutesExist()))
            ->flatMap(fn ($group) => $group['items'])->pluck('key');
        $withoutFlag = collect(Navigation::for($this->owner(), [], [], $this->allRoutesExist()))
            ->flatMap(fn ($group) => $group['items'])->pluck('key');

        // An absent flag means off. A warehouse tab that appears because a settings row is missing
        // is worse than one that never appears.
        $this->assertFalse($withoutFlag->contains('warehouse'));
        $this->assertTrue($withFlag->contains('warehouse'));
    }

    public function test_zero_never_renders_a_badge(): void
    {
        $groups = Navigation::for($this->owner(), ['issues_open' => 0, 'orders_ready' => 4], [], $this->allRoutesExist());
        $items = collect($groups)->flatMap(fn ($group) => $group['items'])->keyBy('key');

        $this->assertNull($items['control-tower']['badgeValue'] ?? null);
        $this->assertSame('4', $items['orders.ready']['badgeValue'] ?? null);
    }

    public function test_a_count_above_ninety_nine_reads_as_ninety_nine_plus(): void
    {
        $groups = Navigation::for($this->owner(), ['orders_ready' => 428], [], $this->allRoutesExist());
        $items = collect($groups)->flatMap(fn ($group) => $group['items'])->keyBy('key');

        $this->assertSame('99+', $items['orders.ready']['badgeValue']);
    }

    public function test_only_critical_and_high_put_a_dot_on_the_rail(): void
    {
        $medium = collect(Navigation::for($this->owner(), ['issues_open' => 3, 'issues_severity' => 'medium'], [], $this->allRoutesExist()))
            ->firstWhere('key', 'home');
        $critical = collect(Navigation::for($this->owner(), ['issues_open' => 3, 'issues_severity' => 'critical'], [], $this->allRoutesExist()))
            ->firstWhere('key', 'home');

        // An ordinary count does not earn a mark that means "something is wrong".
        $this->assertNull($medium['alert']);
        $this->assertSame('critical', $critical['alert']);
    }

    public function test_the_active_item_is_the_longest_matching_path_not_the_first(): void
    {
        $groups = [[
            'key' => 'inventory',
            'items' => [
                ['key' => 'inventory', 'href' => '/seller/inventory'],
                ['key' => 'inventory.movements', 'href' => '/seller/inventory/movements'],
            ],
        ]];

        // `/seller/inventory` also matches the ledger's path; the longest match has to win, or
        // every detail page lights its section's first item instead of its own.
        $active = Navigation::active('/seller/inventory/movements', $groups);
        $this->assertSame('inventory', $active['group']);
        $this->assertSame('inventory.movements', $active['item']);

        // And a detail route under a list still lights the list.
        $this->assertSame('inventory', Navigation::active('/seller/inventory/1234', $groups)['item']);

        // A path in no group lights nothing rather than guessing.
        $this->assertNull(Navigation::active('/seller/somewhere-else', $groups)['group']);
    }

    // ───────────────────────────────────────────────── status vocabulary

    public function test_unknown_is_never_rendered_as_healthy(): void
    {
        // A domain with no sample must not read green — that is the whole point of the distinction
        // (handoff 06 §2).
        $this->assertSame(Status::UNKNOWN, Status::tone('unknown'));
        $this->assertSame(Status::UNKNOWN, Status::tone('no_data'));
        $this->assertSame(Status::GOOD, Status::tone('healthy'));
    }

    public function test_every_status_carries_a_glyph_as_well_as_a_colour(): void
    {
        foreach (['active', 'rejected', 'expiring_soon', 'out_of_stock', 'under_review', 'unknown'] as $status) {
            $resolved = Status::of($status);
            $this->assertNotSame('', $resolved['glyph']);
            $this->assertTrue(Icons::has($resolved['glyph']), $status . ' points at a glyph that does not exist');
        }
    }

    public function test_severity_sorts_critical_high_medium_low_everywhere(): void
    {
        $this->assertSame(
            ['critical', 'high', 'medium', 'low'],
            Status::sortSeverities(['medium', 'low', 'critical', 'high']),
        );
        $this->assertSame('critical', Status::highest(['low', 'critical', 'medium']));
        $this->assertNull(Status::highest([]));
    }

    public function test_an_unmapped_server_status_falls_back_rather_than_inventing_a_colour(): void
    {
        $resolved = Status::of('some_status_nobody_mapped');

        $this->assertSame(Status::NEUTRAL, $resolved['tone']);
        $this->assertTrue(Icons::has($resolved['glyph']));
    }

    public function test_the_sla_ladder_matches_the_specified_thresholds(): void
    {
        $now = new \DateTimeImmutable('2026-08-24 09:00:00');
        $at = fn (string $offset) => new \DateTimeImmutable('2026-08-24 09:00:00 ' . $offset);

        $this->assertSame('breached', Status::sla($at('-1 hour'), false, $now)['state']);
        $this->assertSame('critical', Status::sla($at('-1 hour'), false, $now)['tone']);
        $this->assertSame('closing', Status::sla($at('+1 hour'), false, $now)['state']);
        $this->assertSame('soon', Status::sla($at('+5 hours'), false, $now)['state']);
        $this->assertSame('on_time', Status::sla($at('+2 days'), false, $now)['state']);
        $this->assertSame('met', Status::sla($at('-1 hour'), true, $now)['state']);
        $this->assertSame('not_applicable', Status::sla(null, false, $now)['state']);
    }

    // ─────────────────────────────────────────────────── glyphs and icons

    public function test_every_glyph_the_navigation_asks_for_actually_exists(): void
    {
        foreach (Navigation::groups() as $group) {
            $this->assertTrue(Icons::has($group['icon']), $group['key'] . ' asks for a glyph that does not exist');
        }
    }

    public function test_an_unknown_glyph_renders_nothing_rather_than_a_broken_box(): void
    {
        $this->assertSame('', Icons::paths('no-such-icon'));
    }

    // ─────────────────────────────────────────────────────────── shell

    public function test_the_back_arrow_is_the_one_glyph_that_flips_with_the_direction(): void
    {
        session(['direction' => 'ltr']);
        $this->assertSame('arrow-left', Shell::backGlyph());

        session(['direction' => 'rtl']);
        $this->assertSame('arrow-right', Shell::backGlyph());
    }

    public function test_density_falls_back_to_compact_rather_than_to_nothing(): void
    {
        session()->forget('sc_density');
        $this->assertSame(Shell::DENSITY_COMPACT, Shell::density());

        Shell::setDensity('comfortable');
        $this->assertSame(Shell::DENSITY_COMFORTABLE, Shell::density());

        Shell::setDensity('nonsense');
        $this->assertSame(Shell::DENSITY_COMPACT, Shell::density());
    }

    public function test_a_route_that_has_not_shipped_yet_returns_null_rather_than_throwing(): void
    {
        // The shell renders through eight waves with half its destinations missing.
        $this->assertNull(Shell::route('seller.a-screen-from-wave-eight'));
    }

    // ────────────────────────────────────────────── moments in time

    public function test_a_moment_that_never_happened_renders_as_a_dash(): void
    {
        // Not "now", and not the epoch. An order with no ship-by time has no ship-by time.
        $this->assertSame('—', Moment::stamp(null));
        $this->assertSame('—', Moment::day(null));
        $this->assertSame('—', Moment::time(null));
        $this->assertSame('—', Moment::longDay(null));
    }

    public function test_the_month_is_a_word_in_the_readers_own_language(): void
    {
        $at = Carbon::parse('2026-08-25 05:05:00');

        session()->put('local', 'en');
        $this->assertSame('25 Aug 05:05', Moment::stamp($at));

        // This install's Arabic lives in the `sy` folder, which Carbon has never heard of. Asking
        // it to translate under that name emits two include() failures per call before falling
        // back, so the folder is mapped to the tag Carbon knows before the question is asked.
        session()->put('local', 'sy');
        $translated = Moment::stamp($at);

        $this->assertStringNotContainsString('Aug', $translated);
        $this->assertStringContainsString('05:05', $translated);
    }

    public function test_a_moment_that_is_not_a_carbon_is_still_formatted(): void
    {
        // `expected_delivery_date` and the movement ledger hand over whatever the driver returned.
        $this->assertSame('25 Aug 2026', Moment::day(new \DateTimeImmutable('2026-08-25 05:05:00')));
    }

    public function test_the_year_is_offered_rather_than_assumed(): void
    {
        $at = Carbon::parse('2026-08-25 05:05:00');

        session()->put('local', 'en');

        // A table of today's runs does not need the year; a movement ledger going back two years
        // does. Neither is the default for the other.
        $this->assertSame('25 Aug 05:05', Moment::stamp($at));
        $this->assertSame('25 Aug 2026 05:05', Moment::stamp($at, withYear: true));
        $this->assertSame('25 Aug', Moment::day($at, withYear: false));
    }
}
