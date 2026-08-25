<?php

namespace App\Services\Marketplace;

use App\Jobs\DeliverSellerWebhook;
use App\Models\SellerWebhook;
use App\Models\SellerWebhookDelivery;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Every decision about a shop's outbound integrations, in one place both clients call.
 *
 * The v3 API grew these rules inline — the https-only rule, the destination check, what a repoint
 * resets, what gets audited — and the web panel had no integrations screen at all, so there was
 * nothing to disagree with them yet. Building that screen is exactly the moment the rules stop being
 * one controller's private business: two implementations of "which destinations may this platform be
 * made to dial" is one implementation and one hole.
 *
 * So the controllers here decide nothing. They validate that a person asked, hand the request over,
 * and render whatever comes back — the API as JSON, the panel as a page.
 */
class SellerIntegrationService
{
    public function __construct(private readonly SellerWebhookDispatcher $dispatcher)
    {
    }

    /**
     * What a webhook must look like.
     *
     * https only, deliberately: a signed delivery over plain http is signed plaintext, and the
     * payload carries order and payout details.
     *
     * @return array<string, string>
     */
    public function webhookRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'url' => 'required|url|starts_with:https://|max:500',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:' . implode(',', SellerWebhookDispatcher::EVENTS),
        ];
    }

    /**
     * Add an endpoint. The signing secret is returned once and stored nowhere readable.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, errors?: array<string, string>, webhook?: SellerWebhook, secret?: string}
     */
    public function createWebhook(int|string $sellerId, array $input): array
    {
        if ($errors = $this->validateWebhook($input)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $secret = Str::random(48);

        $webhook = SellerWebhook::create([
            'seller_id' => $sellerId,
            'name' => $input['name'],
            'url' => $input['url'],
            'events' => $this->knownEvents($input['events']),
            'secret' => $secret,
            'status' => SellerWebhook::STATUS_ACTIVE,
        ]);

        // Where a shop's order and payout events are sent. Only the two paths that switch an
        // endpoint OFF were ever audited, so creating one — or repointing a live one at a new
        // destination — wrote nothing at all. That is the shape an exfiltration would take.
        $this->record('created', $webhook, after: ['url' => $webhook->url, 'events' => $webhook->events]);

        return ['ok' => true, 'webhook' => $webhook, 'secret' => $secret];
    }

    /**
     * Change an endpoint.
     *
     * A rewritten endpoint starts clean: its failure run is reset and any switch-off the marketplace
     * applied is cleared, because the endpoint that was failing is not the endpoint that now exists.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, errors?: array<string, string>, webhook?: SellerWebhook}
     */
    public function updateWebhook(SellerWebhook $webhook, array $input): array
    {
        if ($errors = $this->validateWebhook($input)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $before = ['url' => $webhook->url, 'events' => $webhook->events, 'status' => $webhook->status];

        $webhook->forceFill([
            'name' => $input['name'],
            'url' => $input['url'],
            'events' => $this->knownEvents($input['events']),
            'status' => SellerWebhook::STATUS_ACTIVE,
            'consecutive_failures' => 0,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();

        $this->record('repointed', $webhook, $before, ['url' => $webhook->url, 'events' => $webhook->events]);

        return ['ok' => true, 'webhook' => $webhook];
    }

    /**
     * Switch an endpoint on or off.
     *
     * Only active and paused are settable. Disabled is the marketplace's answer to an endpoint that
     * stopped answering — switching back to active is how that is cleared, deliberately, with the
     * reason still on screen.
     *
     * @return array{ok: bool, errors?: array<string, string>, webhook?: SellerWebhook}
     */
    public function setWebhookStatus(SellerWebhook $webhook, string $status): array
    {
        if (!in_array($status, SellerWebhook::SELLER_SETTABLE_STATUSES, true)) {
            return ['ok' => false, 'errors' => ['status' => translate('webhook_status_not_settable')]];
        }

        $before = ['status' => $webhook->status];

        $webhook->forceFill([
            'status' => $status,
            // Cast rather than carried through: the column is NOT NULL and the attribute is null on
            // a model that has not been refreshed since its insert, so pausing an endpoint created
            // moments earlier wrote a null and failed the write.
            'consecutive_failures' => $status === SellerWebhook::STATUS_ACTIVE ? 0 : (int) $webhook->consecutive_failures,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();

        $this->record('status_changed', $webhook, $before, ['status' => $status]);

        return ['ok' => true, 'webhook' => $webhook];
    }

    /** Remove an endpoint. Its deliveries stay: removing the endpoint does not un-send them. */
    public function deleteWebhook(SellerWebhook $webhook): void
    {
        $this->record('deleted', $webhook, ['url' => $webhook->url, 'events' => $webhook->events]);

        $webhook->delete();
    }

    /**
     * Queue a test delivery.
     *
     * A real delivery of a real event shape, queued and signed the same way, so what the receiver
     * sees in the test is what it will see in production.
     *
     * @return array{ok: bool, errors?: array<string, string>, delivery?: SellerWebhookDelivery}
     */
    public function queueTest(SellerWebhook $webhook, string $event): array
    {
        if (!in_array($event, SellerWebhookDispatcher::EVENTS, true)) {
            return ['ok' => false, 'errors' => ['event' => translate('webhook_unknown_event')]];
        }

        $delivery = SellerWebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'seller_id' => $webhook->seller_id,
            'event' => $event,
            'payload' => ['test' => true],
            'status' => SellerWebhookDelivery::STATUS_PENDING,
        ]);

        DeliverSellerWebhook::dispatch($delivery->id);

        return ['ok' => true, 'delivery' => $delivery];
    }

    /**
     * An endpoint as anything outside this service should see it — never its secret.
     *
     * @return array<string, mixed>
     */
    public function present(SellerWebhook $webhook): array
    {
        return [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'url' => $webhook->url,
            'events' => $webhook->events ?? [],
            'status' => $webhook->status,
            'consecutive_failures' => $webhook->consecutive_failures,
            'last_success_at' => $webhook->last_success_at,
            'last_failure_at' => $webhook->last_failure_at,
            'disabled_at' => $webhook->disabled_at,
            'disabled_reason' => $webhook->disabled_reason,
            // An endpoint nothing has been sent to has not earned a green tick.
            'never_called' => $webhook->last_success_at === null && $webhook->last_failure_at === null,
            'created_at' => $webhook->created_at,
        ];
    }

    /**
     * The shape a rule string cannot express.
     *
     * Validation can only say the string looks like an https URL. Whether it points at the cloud
     * metadata service or an internal admin panel is a question about the resolved address.
     *
     * @return array<string, string>
     */
    private function validateWebhook(array $input): array
    {
        $validator = Validator::make($input, $this->webhookRules());

        if ($validator->fails()) {
            return array_map(
                static fn (array $messages) => (string) ($messages[0] ?? ''),
                $validator->errors()->toArray(),
            );
        }

        $verdict = $this->dispatcher->mayDial((string) $input['url']);

        return $verdict['allowed'] ? [] : ['url' => translate('webhook_url_' . $verdict['reason'])];
    }

    /** @return array<int, string> */
    private function knownEvents(array $events): array
    {
        return array_values(array_intersect($events, SellerWebhookDispatcher::EVENTS));
    }

    /**
     * One line per change to where a shop's events are sent.
     *
     * The URL is recorded in full on both sides. A repoint is the one webhook change that matters to
     * a fraud review, and "the endpoint changed" without the two addresses is not a finding.
     */
    private function record(string $event, SellerWebhook $webhook, ?array $before = null, ?array $after = null): void
    {
        app(AuditLogger::class)->record(
            action: 'integration.webhook_' . $event,
            subject: $webhook,
            before: $before,
            after: $after,
            context: ['seller_id' => $webhook->seller_id, 'name' => $webhook->name],
        );
    }
}
