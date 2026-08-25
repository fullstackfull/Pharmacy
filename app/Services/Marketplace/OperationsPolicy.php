<?php

namespace App\Services\Marketplace;

/**
 * The windows the marketplace judges its sellers by.
 *
 * Every number here decides when a seller is told they are late, and every one of them was a private
 * constant in whichever detector happened to use it. Two consequences followed.
 *
 * They could not be changed. A marketplace that wants sellers to answer a return within a day rather
 * than two had to edit a class and deploy, which is not a policy — it is a build.
 *
 * And they disagreed. `sla_processing_hours` is the declared ship-by promise and is editable; beside
 * it sat a fixed 72 hours after which an order was "stuck", a fixed quarter-of-the-window at which
 * the same order became "urgent", and fixed 2- and 8-hour bands that decided when the countdown in
 * the panel turned amber. Four answers to "is this order late", three of them invisible.
 *
 * So they live together, read through one service, with the old constants as the defaults — an
 * install that never opens the settings page behaves exactly as it does today.
 */
class OperationsPolicy
{
    /**
     * The shipped defaults, which are the values these thresholds already had in code.
     *
     * @var array<string, int|float>
     */
    public const DEFAULTS = [
        // An order that has not moved for this long is raised as stuck, whatever its ship-by time.
        'ops_stuck_order_hours' => 72,
        // And stops being raised after this, because an order nobody has touched in six weeks is a
        // different conversation from one that stalled on Tuesday.
        'ops_stuck_stop_after_days' => 45,
        // How much of the ship-by window may remain before the order is called urgent.
        'ops_sla_urgent_fraction' => 0.25,
        // The two bands the countdown turns on, in minutes: amber inside the first, warned inside
        // the second. Read by the panel so the colour and the detector agree about "at risk".
        'ops_sla_closing_minutes' => 120,
        'ops_sla_soon_minutes' => 480,
        // How long a customer may wait for an answer to a refund request, and how long an authorised
        // return may sit unprocessed. Both are promises the marketplace makes, not seller habits.
        'ops_returns_response_hours' => 48,
        'ops_returns_processing_hours' => 72,
        // How long after delivery money may take to reach the ledger before it is called late.
        'ops_finance_grace_hours' => 6,
        // How far ahead expiring stock is surfaced. On a regulated catalogue this is an operational
        // decision, not a display preference.
        'ops_batch_expiry_days' => 30,
    ];

    /** Bounds a value must sit inside, so a policy cannot be set to something meaningless. */
    public const LIMITS = [
        'ops_stuck_order_hours' => ['min' => 1, 'max' => 720],
        'ops_stuck_stop_after_days' => ['min' => 1, 'max' => 365],
        'ops_sla_urgent_fraction' => ['min' => 0.05, 'max' => 0.9],
        'ops_sla_closing_minutes' => ['min' => 5, 'max' => 1440],
        'ops_sla_soon_minutes' => ['min' => 10, 'max' => 10080],
        'ops_returns_response_hours' => ['min' => 1, 'max' => 720],
        'ops_returns_processing_hours' => ['min' => 1, 'max' => 720],
        'ops_finance_grace_hours' => ['min' => 1, 'max' => 168],
        'ops_batch_expiry_days' => ['min' => 1, 'max' => 365],
    ];

    public function stuckOrderHours(): int
    {
        return (int) $this->value('ops_stuck_order_hours');
    }

    public function stuckStopAfterDays(): int
    {
        return (int) $this->value('ops_stuck_stop_after_days');
    }

    public function slaUrgentFraction(): float
    {
        return (float) $this->value('ops_sla_urgent_fraction');
    }

    /**
     * The countdown's two warning bands, in minutes, closest first.
     *
     * @return array{closing: int, soon: int}
     */
    public function slaBands(): array
    {
        $closing = (int) $this->value('ops_sla_closing_minutes');
        $soon = (int) $this->value('ops_sla_soon_minutes');

        // "Warned" cannot begin before "closing" ends, whatever the two settings say — a soon band
        // narrower than the closing one would make the amber state unreachable rather than wrong.
        return ['closing' => $closing, 'soon' => max($soon, $closing)];
    }

    public function returnsResponseHours(): int
    {
        return (int) $this->value('ops_returns_response_hours');
    }

    public function returnsProcessingHours(): int
    {
        return (int) $this->value('ops_returns_processing_hours');
    }

    public function financeGraceHours(): int
    {
        return (int) $this->value('ops_finance_grace_hours');
    }

    public function batchExpiryDays(): int
    {
        return (int) $this->value('ops_batch_expiry_days');
    }

    /** Every policy with its current value, for the screen that edits them. */
    public function all(): array
    {
        $values = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = $this->value($key);
        }

        return $values;
    }

    /**
     * One setting, clamped to its limits and falling back to the shipped default.
     *
     * A stored value outside its bounds is brought inside rather than honoured: the bounds exist
     * because the value drives a deadline, and a zero-hour deadline marks every order late the
     * instant it arrives.
     */
    private function value(string $key): int|float
    {
        $default = self::DEFAULTS[$key];

        try {
            $stored = getWebConfig(name: $key);
        } catch (\Throwable) {
            $stored = null;
        }

        if ($stored === null || $stored === '' || !is_numeric($stored)) {
            return $default;
        }

        $value = is_float($default) ? (float) $stored : (int) $stored;
        $limits = self::LIMITS[$key];

        return max($limits['min'], min($limits['max'], $value));
    }
}
