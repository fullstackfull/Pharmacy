<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\Reporting\AnalyticsReporting;
use App\Services\Analytics\Reporting\Window;
use App\Services\Analytics\Support\AttributionEngine;
use App\Services\Analytics\Support\BotDetector;
use App\Services\Analytics\Support\PathNormalizer;
use App\Services\Analytics\VisitorContext;
use App\Services\Telemetry\ClientIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An app visitor is a person, not a network.
 *
 * API callers hold no cookie, so their identity used to be a permanent salted hash of the masked
 * address. Two things were wrong with that and both produced numbers that looked like measurements:
 * every device behind one carrier NAT collapsed into a single "visitor" — a floor reported as a
 * count — and the hash never expired, so the column was a permanent pseudonym for a household while
 * its own docblock claimed it lasted a day.
 */
class ApiVisitorIdentityTest extends TestCase
{
    private const CONNECTION = 'analytics_identity';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('analytics.connection', self::CONNECTION);
        config()->set('analytics.enabled', true);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        foreach (['*_create_analytics_tables.php', '*_add_identity_basis_to_analytics_sessions.php'] as $pattern) {
            foreach (glob(database_path('migrations/' . $pattern)) as $migration) {
                (require $migration)->up();
            }
        }
    }

    public function test_two_phones_behind_one_carrier_nat_are_two_visitors(): void
    {
        // The whole point. Same address, two installations: two people, and the old rule said one.
        $first = $this->identify($this->apiRequest(clientId: 'install-aaaaaaaa'));
        $second = $this->identify($this->apiRequest(clientId: 'install-bbbbbbbb'));

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith('app:', $first);
        $this->assertStringStartsWith('app:', $second);
    }

    public function test_the_same_installation_is_the_same_visitor_on_every_call(): void
    {
        $this->assertSame(
            $this->identify($this->apiRequest(clientId: 'install-aaaaaaaa', ip: '203.0.113.9')),
            $this->identify($this->apiRequest(clientId: 'install-aaaaaaaa', ip: '198.51.100.4')),
        );
    }

    public function test_the_installation_id_is_never_stored_in_the_clear(): void
    {
        $identity = $this->identify($this->apiRequest(clientId: 'install-aaaaaaaa'));

        $this->assertStringNotContainsString('install-aaaaaaaa', $identity);
    }

    public function test_a_caller_without_an_installation_id_falls_back_to_the_network_and_says_so(): void
    {
        $context = $this->context();
        $request = $this->apiRequest();
        $context->resolve($request);

        $this->assertStringStartsWith('net:', (string) $context->visitorId($request));
        $this->assertSame(ClientIdentity::BASIS_NETWORK, $context->identityBasis());
    }

    public function test_the_network_identity_expires_with_the_day_it_was_minted(): void
    {
        // Its docblock always claimed the hash told visitors apart "for a day". Nothing in it
        // carried a day, so the same household hashed to the same value for the life of the app key.
        Carbon::setTestNow('2026-03-01 12:00:00');
        $today = $this->identify($this->apiRequest(ip: '203.0.113.9'));

        Carbon::setTestNow('2026-03-02 12:00:00');
        $tomorrow = $this->identify($this->apiRequest(ip: '203.0.113.9'));

        Carbon::setTestNow();

        $this->assertNotSame($today, $tomorrow);
    }

    public function test_a_placeholder_installation_id_is_refused(): void
    {
        // An app that ships the same literal for every install would otherwise collapse its entire
        // user base into one visitor — the very failure this header exists to fix, with a number
        // that looks healthier than the network fallback it replaced.
        foreach (['null', 'undefined', '00000000-0000-0000-0000-000000000000'] as $placeholder) {
            $context = $this->context();
            $request = $this->apiRequest(clientId: $placeholder);
            $context->resolve($request);

            $this->assertSame(ClientIdentity::BASIS_NETWORK, $context->identityBasis(), $placeholder);
        }
    }

    public function test_a_signed_in_account_still_wins_over_everything_else(): void
    {
        $identity = (new ClientIdentity())->forApi($this->apiRequest(clientId: 'install-aaaaaaaa'), 'customer', 41);

        $this->assertSame(['api:customer:41', ClientIdentity::BASIS_USER], $identity);
    }

    public function test_a_request_with_no_account_no_id_and_no_address_is_not_given_a_shared_identity(): void
    {
        // "unknown" as an identity is one fictional visitor that every such request joins. Recording
        // nothing is the honest answer, and it is what the session opener now gets.
        $request = $this->apiRequest();
        $request->server->remove('REMOTE_ADDR');

        $context = $this->context();
        $context->resolve($request);

        $this->assertNull($context->visitorId($request));
        $this->assertNull($context->sessionId($request));
        $this->assertSame(0, DB::connection(self::CONNECTION)->table('analytics_sessions')->count());
    }

    public function test_the_session_records_how_its_visitor_was_identified(): void
    {
        $context = $this->context();
        $request = $this->apiRequest();
        $context->resolve($request);
        $context->sessionId($request);

        $this->assertSame(
            ClientIdentity::BASIS_NETWORK,
            DB::connection(self::CONNECTION)->table('analytics_sessions')->value('identity_basis'),
        );
    }

    public function test_the_visitor_card_admits_which_part_of_its_figure_is_a_floor(): void
    {
        $connection = DB::connection(self::CONNECTION);
        $now = Carbon::now();

        foreach ([
            ['visitor_id' => 'net:aaa', 'identity_basis' => ClientIdentity::BASIS_NETWORK],
            ['visitor_id' => 'net:bbb', 'identity_basis' => ClientIdentity::BASIS_NETWORK],
            ['visitor_id' => 'cookie-1', 'identity_basis' => ClientIdentity::BASIS_COOKIE],
            ['visitor_id' => 'cookie-2', 'identity_basis' => ClientIdentity::BASIS_COOKIE],
        ] as $session) {
            $connection->table('analytics_sessions')->insert($session + [
                'channel' => 'api', 'is_bot' => false, 'is_internal' => false,
                'started_at' => $now, 'last_activity_at' => $now,
            ]);
        }

        $totals = app(AnalyticsReporting::class)->totals(Window::make('today'));

        $this->assertSame(2, $totals['visitors']['approximate']['sessions']);
        $this->assertSame(50.0, $totals['visitors']['approximate']['share_pct']);
    }

    public function test_a_window_identified_entirely_by_cookie_carries_no_caveat(): void
    {
        // A caveat on every card is a caveat nobody reads.
        $now = Carbon::now();

        DB::connection(self::CONNECTION)->table('analytics_sessions')->insert([
            'visitor_id' => 'cookie-1', 'channel' => 'web', 'identity_basis' => ClientIdentity::BASIS_COOKIE,
            'is_bot' => false, 'is_internal' => false, 'started_at' => $now, 'last_activity_at' => $now,
        ]);

        $totals = app(AnalyticsReporting::class)->totals(Window::make('today'));

        $this->assertArrayNotHasKey('approximate', $totals['visitors']);
    }

    // ---------------------------------------------------------------------------------------

    private function context(): VisitorContext
    {
        return new VisitorContext(new BotDetector(), new AttributionEngine(), new PathNormalizer(), new ClientIdentity());
    }

    private function identify(Request $request): ?string
    {
        $context = $this->context();
        $context->resolve($request);

        return $context->visitorId($request);
    }

    private function apiRequest(?string $clientId = null, string $ip = '203.0.113.9'): Request
    {
        $request = Request::create('/api/v1/products/latest', 'GET', server: ['REMOTE_ADDR' => $ip]);
        $request->headers->set('X-App-Version', '4.2.0');

        if ($clientId !== null) {
            $request->headers->set('X-Client-Id', $clientId);
        }

        return $request;
    }
}
