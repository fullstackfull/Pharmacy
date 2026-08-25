<?php

namespace App\Services\Commerce;

use App\Services\Platform\Policy;

/**
 * Which order states can still be changed, and by whom.
 *
 * Both rules were inline status arrays repeated across at least three files — the edit gate in
 * OrderEditService, the customer's cancel button on the web, and the same cancel over the API — so a
 * marketplace that wants cancellation to stop at "processing" instead of "pending" needed a code
 * change and a deployment, and the three copies could disagree without anything failing.
 *
 * The shipped defaults are exactly what the inline arrays said, so switching to this changes no
 * behaviour on any existing installation. What changes is that the rule is now one declaration an
 * operator can read and move.
 *
 * The payment-method conditions on cancellation are deliberately NOT part of this. "The customer
 * may cancel while the order is still pending" is a policy; "money that has already been taken
 * cannot be undone by a button" is an accounting fact, and folding the second into a settings screen
 * would offer an operator a switch that must never be thrown.
 */
class OrderStatePolicy
{
    /** Every state an order can be in, in the order it passes through them. */
    public const STATUSES = [
        'pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'returned', 'failed', 'canceled',
    ];

    public function __construct(private readonly Policy $policy)
    {
    }

    /** @return array<int, string> */
    public function editableStatuses(): array
    {
        return $this->statuses('order_editable_statuses');
    }

    /** @return array<int, string> */
    public function customerCancellableStatuses(): array
    {
        return $this->statuses('order_cancellable_statuses');
    }

    public function isEditable(?string $status): bool
    {
        return $status !== null && in_array($status, $this->editableStatuses(), true);
    }

    public function customerMayCancel(?string $status): bool
    {
        return $status !== null && in_array($status, $this->customerCancellableStatuses(), true);
    }

    /**
     * Read back as a list of real statuses.
     *
     * A stored value that has drifted — a status renamed, a row edited by hand — is filtered rather
     * than trusted, because an unknown status in this list silently widens what may be cancelled.
     *
     * @return array<int, string>
     */
    private function statuses(string $key): array
    {
        $stored = $this->policy->get($key);
        $stored = is_array($stored) ? $stored : [];

        return array_values(array_intersect(self::STATUSES, $stored));
    }
}
