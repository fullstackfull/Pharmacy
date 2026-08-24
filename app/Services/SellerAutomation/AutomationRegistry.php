<?php

namespace App\Services\SellerAutomation;

use App\Models\SellerAutomationRule;

/**
 * What a seller is allowed to build a rule out of.
 *
 * Registering triggers and actions in one place is what keeps the catalogue endpoint, the validation
 * and the engine from drifting: the screen offers exactly what the validator accepts and exactly
 * what the engine can run, because all three read this.
 *
 * Combinations are checked rather than assumed. A trigger that selects orders and an action that
 * changes listings is not a rule with a bug in it; it is not a rule at all, and refusing it at
 * creation is far cheaper than discovering it at three in the morning.
 */
class AutomationRegistry
{
    /** @var array<string, AutomationTrigger> */
    private array $triggers = [];

    /** @var array<string, AutomationAction> */
    private array $actions = [];

    /**
     * @param  iterable<AutomationTrigger>  $triggers
     * @param  iterable<AutomationAction>  $actions
     */
    public function __construct(iterable $triggers = [], iterable $actions = [])
    {
        foreach ($triggers as $trigger) {
            $this->triggers[$trigger->key()] = $trigger;
        }

        foreach ($actions as $action) {
            $this->actions[$action->key()] = $action;
        }
    }

    public function trigger(string $key): ?AutomationTrigger
    {
        return $this->triggers[$key] ?? null;
    }

    public function action(string $key): ?AutomationAction
    {
        return $this->actions[$key] ?? null;
    }

    public function triggerFor(SellerAutomationRule $rule): ?AutomationTrigger
    {
        return $this->trigger($rule->trigger);
    }

    public function actionFor(SellerAutomationRule $rule): ?AutomationAction
    {
        return $this->action($rule->action);
    }

    public function accepts(string $triggerKey, string $actionKey): bool
    {
        $trigger = $this->trigger($triggerKey);
        $action = $this->action($actionKey);

        if (!$trigger || !$action) {
            return false;
        }

        return in_array($trigger->subjectType(), $action->subjectTypes(), true);
    }

    /**
     * The catalogue as a screen needs it: every trigger, every action, and which pairs are legal.
     *
     * @return array{triggers: array<int, array>, actions: array<int, array>}
     */
    public function catalogue(): array
    {
        return [
            'triggers' => array_values(array_map(fn (AutomationTrigger $trigger) => [
                'key' => $trigger->key(),
                'subject_type' => $trigger->subjectType(),
                'settings' => array_keys($trigger->rules()),
                'required_settings' => $this->requiredKeys($trigger->rules()),
                'actions' => array_values(array_keys(array_filter(
                    $this->actions,
                    fn (AutomationAction $action) => in_array($trigger->subjectType(), $action->subjectTypes(), true),
                ))),
            ], $this->triggers)),
            'actions' => array_values(array_map(fn (AutomationAction $action) => [
                'key' => $action->key(),
                'permission' => $action->permission(),
                'subject_types' => $action->subjectTypes(),
                'settings' => array_keys($action->rules()),
                'required_settings' => $this->requiredKeys($action->rules()),
            ], $this->actions)),
        ];
    }

    /** @return array<int, string> */
    private function requiredKeys(array $rules): array
    {
        return array_values(array_keys(array_filter(
            $rules,
            fn ($rule) => str_contains(is_array($rule) ? implode('|', $rule) : (string) $rule, 'required'),
        )));
    }
}
