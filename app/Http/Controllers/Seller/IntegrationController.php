<?php

namespace App\Http\Controllers\Seller;

use App\Models\SellerApiKey;
use App\Models\SellerWebhook;
use App\Models\SellerWebhookDelivery;
use App\Services\Marketplace\SellerApiKeyService;
use App\Services\Marketplace\SellerIntegrationService;
use App\Services\Marketplace\SellerPermissionService;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\Marketplace\SellerWebhookDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Keys and webhooks — how a seller's own systems talk to the marketplace, and it to them.
 *
 * These existed only on the phone. A seller wiring up an ERP was told to install the app, issue a
 * key on a handset and copy it across, and there was no screen anywhere that would show them why
 * their endpoint had stopped receiving orders. Integration work happens at a desk.
 *
 * Every decision here belongs to SellerIntegrationService, which the v3 API also calls, so the two
 * clients cannot disagree about which destinations may be dialled or what a repoint resets.
 *
 * One rule is enforced in this controller rather than in the service, because it is about who is
 * asking rather than about what is being asked: **a key cannot manage keys**. An integration that
 * leaks should cost the seller what that integration could do; if a leaked key could mint more keys
 * or delete the webhook that would have reported it, the limit on its scopes would be decorative.
 */
class IntegrationController extends SellerCenterController
{
    private const DELIVERY_PAGE_SIZE = 25;

    public function __construct(
        private readonly SellerIntegrationService $integrations,
        private readonly SellerApiKeyService $apiKeys,
        private readonly SellerPermissionService $permissions,
    ) {
    }

    /** The two halves side by side, with the one number that matters about each. */
    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $webhooks = $this->webhooks($sellerId);

        return view('seller-views.integrations.index', [
            'keys' => $this->keys($sellerId),
            'webhooks' => $webhooks,
            'failing' => $webhooks->filter(fn (SellerWebhook $hook) => $hook->consecutive_failures > 0),
            'events' => SellerWebhookDispatcher::EVENTS,
        ]);
    }

    public function api(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $keys = $this->keys($sellerId);

        return view('seller-views.integrations.api', [
            'keys' => $keys,
            'grantable' => $this->grantableScopes($this->principal($request)),
            'catalog' => $this->permissions->catalog(),
            // Shown once, on the redirect that issued it, and never retrievable again.
            'issued' => session('issued_key'),
            'state' => $this->listState($keys->count(), false),
        ]);
    }

    public function storeKey(Request $request): RedirectResponse
    {
        $principal = $this->principal($request);

        if ($refusal = $this->refuseIntegration($principal)) {
            return $refusal;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $issued = $this->apiKeys->issue(
            principal: $principal,
            name: $validated['name'],
            scopes: $validated['scopes'] ?? [],
            expiresAt: $validated['expires_at'] ?? null,
        );

        return redirect()->route('seller.integrations.api')
            ->with('issued_key', ['prefix' => $issued['key']->prefix, 'plaintext' => $issued['plaintext']])
            ->with('success', translate('api_key_issued'));
    }

    public function revokeKey(Request $request, int $key): RedirectResponse
    {
        $principal = $this->principal($request);

        if ($refusal = $this->refuseIntegration($principal)) {
            return $refusal;
        }

        $record = SellerApiKey::where('seller_id', $principal->sellerId())->find($key);

        if (!$record) {
            return back()->with('error', translate('api_key_not_found'));
        }

        $this->apiKeys->revoke($record, $principal);

        return back()->with('success', translate('api_key_revoked'));
    }

    public function webhookList(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $webhooks = $this->webhooks($sellerId);

        return view('seller-views.integrations.webhooks', [
            'webhooks' => $webhooks->map(fn (SellerWebhook $hook) => $this->integrations->present($hook)),
            'events' => SellerWebhookDispatcher::EVENTS,
            // The signing secret exists in exactly one place: the response to the request that
            // created the endpoint. There is no screen that can show it again.
            'secret' => session('webhook_secret'),
            'state' => $this->listState($webhooks->count(), false),
        ]);
    }

    public function storeWebhook(Request $request): RedirectResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $result = $this->integrations->createWebhook($this->sellerId($request), $request->all());

        if (!$result['ok']) {
            return back()->withInput()->withErrors($result['errors']);
        }

        return redirect()->route('seller.integrations.webhooks')
            ->with('webhook_secret', $result['secret'])
            ->with('success', translate('webhook_created'));
    }

    public function updateWebhook(Request $request, int $webhook): RedirectResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $record = $this->ownedWebhook($request, $webhook);

        if (!$record) {
            return back()->with('error', translate('webhook_not_found'));
        }

        $result = $this->integrations->updateWebhook($record, $request->all());

        if (!$result['ok']) {
            return back()->withInput()->withErrors($result['errors']);
        }

        return back()->with('success', translate('webhook_updated'));
    }

    public function setWebhookStatus(Request $request, int $webhook): RedirectResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $record = $this->ownedWebhook($request, $webhook);

        if (!$record) {
            return back()->with('error', translate('webhook_not_found'));
        }

        $result = $this->integrations->setWebhookStatus($record, (string) $request->get('status'));

        return $result['ok']
            ? back()->with('success', translate('webhook_updated'))
            : back()->withErrors($result['errors']);
    }

    public function destroyWebhook(Request $request, int $webhook): RedirectResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $record = $this->ownedWebhook($request, $webhook);

        if (!$record) {
            return back()->with('error', translate('webhook_not_found'));
        }

        $this->integrations->deleteWebhook($record);

        return redirect()->route('seller.integrations.webhooks')->with('success', translate('webhook_deleted'));
    }

    public function testWebhook(Request $request, int $webhook): RedirectResponse
    {
        if ($refusal = $this->refuseIntegration($this->principal($request))) {
            return $refusal;
        }

        $record = $this->ownedWebhook($request, $webhook);

        if (!$record) {
            return back()->with('error', translate('webhook_not_found'));
        }

        $result = $this->integrations->queueTest($record, (string) $request->get('event', SellerWebhookDispatcher::EVENTS[0]));

        return $result['ok']
            ? back()->with('success', translate('webhook_test_queued'))
            : back()->withErrors($result['errors']);
    }

    /**
     * Every attempt, kept whether it worked or not.
     *
     * A seller whose integration is quietly missing every third order needs to see the third one,
     * and a failure counter on the endpoint cannot show them which.
     */
    public function health(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $webhooks = $this->webhooks($sellerId);
        $filtered = $request->filled('webhook_id') || $request->filled('status');

        $deliveries = Schema::hasTable('seller_webhook_deliveries')
            ? SellerWebhookDelivery::where('seller_id', $sellerId)
                ->when($request->filled('webhook_id'), fn ($query) => $query->where('webhook_id', (int) $request->query('webhook_id')))
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
                ->orderByDesc('id')
                ->paginate(self::DELIVERY_PAGE_SIZE)
                ->withQueryString()
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, self::DELIVERY_PAGE_SIZE);

        return view('seller-views.integrations.health', [
            'deliveries' => $deliveries,
            'webhooks' => $webhooks->keyBy('id'),
            'currentWebhook' => $request->query('webhook_id'),
            'currentStatus' => $request->query('status'),
            'state' => $this->listState($deliveries->total(), $filtered),
        ]);
    }

    private function keys(int $sellerId)
    {
        return Schema::hasTable('seller_api_keys')
            ? SellerApiKey::where('seller_id', $sellerId)->orderByDesc('id')->get()
            : collect();
    }

    private function webhooks(int $sellerId)
    {
        return Schema::hasTable('seller_webhooks')
            ? SellerWebhook::where('seller_id', $sellerId)->orderByDesc('id')->get()
            : collect();
    }

    private function ownedWebhook(Request $request, int $id): ?SellerWebhook
    {
        return SellerWebhook::where('seller_id', $this->sellerId($request))->find($id);
    }

    /** @return array<int, string> */
    private function grantableScopes(SellerPrincipal $principal): array
    {
        $all = $this->permissions->allKeys();

        if ($principal->isOwner()) {
            return $all;
        }

        return array_values(array_filter($all, fn (string $scope) => $principal->can($scope)));
    }

    /**
     * A key may read this screen. It may not write to it.
     *
     * Said out loud rather than answered with a bare 403, so somebody debugging an integration is
     * not left guessing whether their scopes are wrong.
     */
    private function refuseIntegration(SellerPrincipal $principal): ?RedirectResponse
    {
        return $principal->apiKey === null
            ? null
            : back()->with('error', translate('api_keys_cannot_manage_api_keys'));
    }
}
