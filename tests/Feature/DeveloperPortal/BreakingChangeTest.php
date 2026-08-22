<?php

namespace Tests\Feature\DeveloperPortal;

use App\Services\DeveloperPortal\ApiSnapshotService;
use Tests\TestCase;

/**
 * What counts as breaking, and what does not.
 *
 * The whole point of the changelog is that a release can be checked before it ships, and that only
 * works if the grading means something. Over-flagging buries the changes that genuinely reject a
 * call that used to succeed; under-flagging ships them.
 */
class BreakingChangeTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $now
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function compare(array $before, array $now): array
    {
        $method = new \ReflectionMethod(ApiSnapshotService::class, 'compareOne');
        $method->setAccessible(true);

        $defaults = [
            'methods' => ['GET'],
            'path' => '/api/v1/thing',
            'auth_required' => false,
            'auth_mechanism' => 'public',
            'deprecated' => false,
            'permissions' => [],
            'rate_limit' => null,
            'fields' => [],
        ];

        return $method->invoke(app(ApiSnapshotService::class), array_merge($defaults, $before), array_merge($defaults, $now));
    }

    private function field(array $overrides = []): array
    {
        return array_merge(['required' => false, 'type' => 'string', 'enum' => []], $overrides);
    }

    public function test_dropping_a_verb_is_a_narrowing_not_a_disappearance(): void
    {
        $found = $this->compare(['methods' => ['GET', 'POST']], ['methods' => ['GET']]);

        $this->assertSame('method_removed', $found[0][0]);
        $this->assertSame('breaking', $found[0][2]);
    }

    public function test_removing_a_request_parameter_is_a_warning_not_a_break(): void
    {
        // The request still succeeds; the parameter is ignored. Grading this breaking put it next
        // to the changes that actually reject a previously valid call.
        $found = $this->compare(
            ['fields' => ['note' => $this->field()]],
            ['fields' => []],
        );

        $this->assertSame('param_removed', $found[0][0]);
        $this->assertSame('warning', $found[0][2]);
    }

    public function test_a_new_required_parameter_is_breaking(): void
    {
        $found = $this->compare([], ['fields' => ['reason' => $this->field(['required' => true])]]);

        $this->assertSame('required_param_added', $found[0][0]);
        $this->assertSame('breaking', $found[0][2]);
    }

    public function test_narrowing_a_free_field_to_a_fixed_list_is_breaking(): void
    {
        // Nothing used to say anything about this at all: a field that accepted any string and now
        // accepts three values rejects every caller sending a fourth.
        $found = $this->compare(
            ['fields' => ['status' => $this->field()]],
            ['fields' => ['status' => $this->field(['enum' => ['new', 'paid', 'shipped']])]],
        );

        $this->assertSame('enum_added', $found[0][0]);
        $this->assertSame('breaking', $found[0][2]);
    }

    public function test_removing_an_accepted_value_is_breaking(): void
    {
        $found = $this->compare(
            ['fields' => ['status' => $this->field(['enum' => ['new', 'paid', 'refunded']])]],
            ['fields' => ['status' => $this->field(['enum' => ['new', 'paid']])]],
        );

        $this->assertSame('enum_value_removed', $found[0][0]);
        $this->assertSame('breaking', $found[0][2]);
    }

    public function test_a_new_optional_parameter_is_not_a_change_anyone_needs_to_act_on(): void
    {
        $found = $this->compare([], ['fields' => ['note' => $this->field()]]);

        $this->assertSame('param_added', $found[0][0]);
        $this->assertSame('none', $found[0][2]);
    }

    public function test_requiring_authentication_where_there_was_none_is_breaking(): void
    {
        $found = $this->compare(['auth_required' => false], ['auth_required' => true, 'auth_mechanism' => 'passport']);

        $this->assertSame('auth_added', $found[0][0]);
        $this->assertSame('breaking', $found[0][2]);
    }
}
