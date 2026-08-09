<?php

namespace Tests\Feature;

use App\Models\PaymentRoutingRule;
use App\Services\Marketplace\PaymentRoutingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Payment orchestration (Phase 3, Stage E).
 *
 * The non-breaking contract first: with no rule the gateway list comes back unchanged. Then the two
 * actions — hide removes a gateway, prefer floats it to the front — under amount and country
 * conditions, with hide winning over prefer and a rule never inventing a gateway that was not offered.
 */
class PaymentRoutingTest extends TestCase
{
    private PaymentRoutingService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('payment_routing_rules');
        Schema::create('payment_routing_rules', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('gateway_code'); $t->string('action')->default('hide');
            $t->decimal('min_amount', 24, 2)->nullable(); $t->decimal('max_amount', 24, 2)->nullable(); $t->string('country')->nullable();
            $t->unsignedInteger('priority')->default(10); $t->string('status')->default('active'); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });

        $this->svc = new PaymentRoutingService();
    }

    private function rule(array $attrs): void
    {
        PaymentRoutingRule::create(array_merge(['name' => 'r', 'priority' => 10, 'status' => 'active'], $attrs));
    }

    private array $gateways = ['stripe', 'paypal', 'razor_pay', 'ssl_commerz'];

    public function test_with_no_rules_the_list_is_unchanged(): void
    {
        $this->assertSame($this->gateways, $this->svc->resolve($this->gateways, 100, 'Syria'));
    }

    public function test_a_hide_rule_removes_a_gateway(): void
    {
        $this->rule(['gateway_code' => 'paypal', 'action' => 'hide']);

        $resolved = $this->svc->resolve($this->gateways, 100, 'Syria');

        $this->assertNotContains('paypal', $resolved);
        $this->assertContains('stripe', $resolved);
        $this->assertCount(3, $resolved);
    }

    public function test_a_hide_rule_only_applies_within_its_amount_range(): void
    {
        $this->rule(['gateway_code' => 'paypal', 'action' => 'hide', 'min_amount' => 5000]);

        $this->assertContains('paypal', $this->svc->resolve($this->gateways, 100), 'under the threshold: still offered');
        $this->assertNotContains('paypal', $this->svc->resolve($this->gateways, 6000), 'over the threshold: hidden');
    }

    public function test_a_country_condition_scopes_the_rule(): void
    {
        $this->rule(['gateway_code' => 'stripe', 'action' => 'hide', 'country' => 'Syria']);

        $this->assertNotContains('stripe', $this->svc->resolve($this->gateways, 100, 'Syria'));
        $this->assertContains('stripe', $this->svc->resolve($this->gateways, 100, 'Jordan'));
    }

    public function test_a_prefer_rule_floats_a_gateway_to_the_front(): void
    {
        $this->rule(['gateway_code' => 'razor_pay', 'action' => 'prefer', 'priority' => 1]);

        $resolved = $this->svc->resolve($this->gateways, 100);

        $this->assertSame('razor_pay', $resolved[0]);
        $this->assertCount(4, $resolved, 'nothing removed');
    }

    public function test_prefer_rules_order_by_priority(): void
    {
        $this->rule(['gateway_code' => 'ssl_commerz', 'action' => 'prefer', 'priority' => 5]);
        $this->rule(['gateway_code' => 'razor_pay', 'action' => 'prefer', 'priority' => 1]);

        $resolved = $this->svc->resolve($this->gateways, 100);

        $this->assertSame(['razor_pay', 'ssl_commerz'], array_slice($resolved, 0, 2));
    }

    public function test_hide_wins_over_prefer_for_the_same_gateway(): void
    {
        $this->rule(['gateway_code' => 'paypal', 'action' => 'prefer', 'priority' => 1]);
        $this->rule(['gateway_code' => 'paypal', 'action' => 'hide', 'priority' => 2]);

        $this->assertNotContains('paypal', $this->svc->resolve($this->gateways, 100));
    }

    public function test_a_rule_for_an_unoffered_gateway_does_nothing(): void
    {
        $this->rule(['gateway_code' => 'a_gateway_not_offered', 'action' => 'hide']);

        $this->assertSame($this->gateways, $this->svc->resolve($this->gateways, 100));
    }
}
