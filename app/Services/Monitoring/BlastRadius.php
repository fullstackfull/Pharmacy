<?php

namespace App\Services\Monitoring;

use App\Services\Monitoring\Support\Clock;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * How many sellers a failure is actually reaching.
 *
 * On a marketplace this is the first question asked about any incident and the console could not
 * answer it at all: no monitoring table carried a seller, vendor or shop id — "vendor" existed only
 * as a request-channel label — so the page could say the queue was backed up or that a bug had
 * fired two hundred times, and never whether that was one seller or all of them. Every triage
 * therefore began with a manual SQL session, during the incident, by the one person who could have
 * been fixing it.
 *
 * The dimension is taken from the seller who was signed in when the exception fired rather than
 * added to every counter. That is deliberate and it is the honest half of the problem: request and
 * dependency buckets are keyed by route pattern precisely so they stay bounded as the catalogue and
 * the seller list grow, and putting a seller id on them would multiply the table by the number of
 * sellers to answer a question only errors and incidents actually ask.
 *
 * So this reports what can be attributed, and says plainly what cannot. A blast radius that quietly
 * omits the signals it cannot see is worse than no blast radius: it reads as "one seller affected"
 * when the truth is "one seller affected, and four systems we cannot attribute at all".
 */
class BlastRadius
{
    /** Sellers listed by name on a group before the list stops being a list. */
    private const NAMED_SELLERS = 8;

    /**
     * Signals that carry no seller dimension, and why.
     *
     * Named rather than hidden. An operator who knows the queue figure is unattributed asks the
     * next question; one who is shown a total that silently excludes it stops.
     *
     * @var array<string, string>
     */
    public const UNATTRIBUTED = [
        'requests' => 'request_buckets_are_keyed_by_route_pattern_so_they_stay_bounded_as_the_marketplace_grows',
        'queues' => 'a_queued_job_records_its_queue_and_class_not_whose_work_it_was',
        'dependencies' => 'an_outbound_call_is_attributed_to_the_service_it_reached_not_to_a_seller',
    ];

    public function connection(): Connection
    {
        return DB::connection(config('monitoring.connection'));
    }

    /**
     * The radius across every error recorded in the window.
     *
     * @return array{state: string, sellers: int|null, occurrences: int|null, unattributed: array<string, string>, message?: string}
     */
    public function inWindow(Carbon $since): array
    {
        try {
            if (!Schema::connection(config('monitoring.connection'))->hasTable('monitoring_errors')) {
                return $this->unavailable('the error store has not been created on this installation');
            }

            $errors = $this->sellerErrors()->where('created_at', '>=', Clock::stamp($since));

            return [
                'state' => 'ok',
                'sellers' => (int) $errors->clone()->distinct()->count('user_id'),
                'occurrences' => (int) $errors->clone()->count(),
                'unattributed' => self::UNATTRIBUTED,
            ];
        } catch (Throwable $exception) {
            return $this->unavailable($exception->getMessage());
        }
    }

    /**
     * The radius of one bug.
     *
     * "Two hundred occurrences" and "two hundred occurrences across one seller" are the same number
     * and opposite decisions: the second is one shop with a loop, the first is the marketplace.
     *
     * @return array{state: string, sellers: int|null, named: array<int, array{id: int, name: string}>, more: int, unattributed: array<string, string>, message?: string}
     */
    public function forGroup(int $groupId, Carbon $since): array
    {
        try {
            $ids = $this->sellerErrors()
                ->where('group_id', $groupId)
                ->where('created_at', '>=', Clock::stamp($since))
                ->distinct()
                ->orderBy('user_id')
                ->limit(self::NAMED_SELLERS + 1)
                ->pluck('user_id')
                ->map(static fn ($id) => (int) $id)
                ->all();

            $total = (int) $this->sellerErrors()
                ->where('group_id', $groupId)
                ->where('created_at', '>=', Clock::stamp($since))
                ->distinct()
                ->count('user_id');

            return [
                'state' => 'ok',
                'sellers' => $total,
                'named' => $this->names(array_slice($ids, 0, self::NAMED_SELLERS)),
                'more' => max(0, $total - self::NAMED_SELLERS),
                'unattributed' => self::UNATTRIBUTED,
            ];
        } catch (Throwable $exception) {
            return ['state' => 'unavailable', 'sellers' => null, 'named' => [], 'more' => 0, 'unattributed' => self::UNATTRIBUTED, 'message' => $exception->getMessage()];
        }
    }

    /**
     * Names for the ids, read from the application database.
     *
     * A separate query on a separate connection on purpose: monitoring may live on its own database
     * or its own host, so a join across the two is not available and pretending otherwise would
     * work on a single-database install and fail on exactly the deployments that need monitoring.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, name: string}>
     */
    private function names(array $ids): array
    {
        if ($ids === [] || !Schema::hasTable('sellers')) {
            return array_map(static fn (int $id) => ['id' => $id, 'name' => '#' . $id], $ids);
        }

        $sellers = DB::table('sellers')->whereIn('id', $ids)->pluck(DB::raw("CONCAT(f_name, ' ', l_name)"), 'id');

        return array_map(static fn (int $id) => [
            'id' => $id,
            'name' => trim((string) ($sellers[$id] ?? '')) ?: ('#' . $id),
        ], $ids);
    }

    /**
     * Errors that happened to a signed-in seller.
     *
     * The recorder already writes user_type and user_id, and for a seller the user id IS the seller
     * id — which is why this needed no new column and no second write path. Anonymous and customer
     * traffic is excluded rather than counted as an unknown seller: an inflated radius is worse
     * than a stated gap when it is what decides whether somebody is woken up.
     */
    private function sellerErrors(): \Illuminate\Database\Query\Builder
    {
        return $this->connection()->table('monitoring_errors')
            ->where('user_type', 'seller')
            ->whereNotNull('user_id');
    }

    /** @return array{state: string, sellers: null, occurrences: null, unattributed: array<string, string>, message: string} */
    private function unavailable(string $message): array
    {
        return [
            'state' => 'unavailable',
            'sellers' => null,
            'occurrences' => null,
            'unattributed' => self::UNATTRIBUTED,
            'message' => $message,
        ];
    }
}
