<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Services\ApprovalEngine;
use App\Services\AuditLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The reusable maker-checker engine (Phase 3 — spec item 82).
 *
 * The two rules that make dual-control real, and are asserted here:
 *
 *   1. The maker cannot be a checker — the requester cannot approve their own request.
 *   2. One approver counts once — a single person cannot supply both approvals a dual-control
 *      action needs, enforced by a unique key so a race cannot beat it.
 *
 * And the point of *reusable*: the same engine handles a payout, a refund and a price change with no
 * per-workflow code, and every decision lands on the unified audit log.
 */
class ApprovalEngineTest extends TestCase
{
    private ApprovalEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['approval_requests', 'approval_decisions', 'audit_logs'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('approval_requests', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 60)->unique();
            $t->string('workflow', 60);
            $t->string('subject_type', 100)->nullable();
            $t->string('subject_id', 191)->nullable();
            $t->string('status', 20)->default('pending');
            $t->decimal('amount', 24, 4)->nullable();
            $t->string('currency', 10)->nullable();
            $t->unsignedTinyInteger('required_approvals')->default(1);
            $t->unsignedTinyInteger('approvals_count')->default(0);
            $t->string('requested_by_type', 20)->nullable();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->text('request_note')->nullable();
            $t->json('payload')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
        Schema::create('approval_decisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('approval_request_id');
            $t->string('decision', 20);
            $t->string('actor_type', 20)->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 191)->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['approval_request_id', 'actor_type', 'actor_id'], 'apd_one_per_actor');
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('actor_type', 40)->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 191)->nullable();
            $t->string('action', 100);
            $t->string('subject_type', 100)->nullable();
            $t->string('subject_id', 191)->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->json('context')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamps();
        });

        $this->engine = new ApprovalEngine(new AuditLogger());
    }

    // ---- ordinary single approval ----

    public function test_one_approval_approves_a_single_control_request(): void
    {
        $request = $this->engine->open('refund', amount: 100, requestedBy: 1, requestedByType: 'admin');

        $result = $this->engine->approve($request, actorId: 2, actorType: 'admin');

        $this->assertTrue($result['ok']);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $result['request']->status);
    }

    // ---- rule 1: maker cannot be checker ----

    public function test_the_requester_cannot_approve_their_own_request(): void
    {
        $request = $this->engine->open('payout', amount: 500, requestedBy: 7, requestedByType: 'admin');

        $result = $this->engine->approve($request, actorId: 7, actorType: 'admin');

        $this->assertFalse($result['ok']);
        $this->assertSame('the_person_who_requested_cannot_approve', $result['reason']);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->fresh()->status);
    }

    // ---- rule 2: dual control, one approver counts once ----

    public function test_a_dual_control_request_needs_two_different_approvers(): void
    {
        $request = $this->engine->open('large_refund', amount: 5000, requiredApprovals: 2, requestedBy: 1, requestedByType: 'admin');

        $first = $this->engine->approve($request, actorId: 2, actorType: 'admin');
        $this->assertTrue($first['ok']);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $first['request']->status, 'one of two is not enough');

        $second = $this->engine->approve($request->fresh(), actorId: 3, actorType: 'admin');
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $second['request']->status, 'two distinct approvers');
    }

    public function test_one_person_cannot_supply_both_approvals(): void
    {
        $request = $this->engine->open('large_refund', amount: 5000, requiredApprovals: 2, requestedBy: 1, requestedByType: 'admin');

        $this->engine->approve($request, actorId: 2, actorType: 'admin');
        $second = $this->engine->approve($request->fresh(), actorId: 2, actorType: 'admin');

        $this->assertFalse($second['ok']);
        $this->assertSame('already_decided_by_this_actor', $second['reason']);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->fresh()->status, 'still one of two');
    }

    // ---- rejection ----

    public function test_a_single_rejection_ends_the_request(): void
    {
        $request = $this->engine->open('large_refund', amount: 5000, requiredApprovals: 2, requestedBy: 1, requestedByType: 'admin');
        $this->engine->approve($request, actorId: 2, actorType: 'admin');

        $result = $this->engine->reject($request->fresh(), actorId: 3, actorType: 'admin', note: 'looks fraudulent');

        $this->assertTrue($result['ok']);
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $result['request']->status, 'one checker stops it');
    }

    public function test_an_already_decided_request_cannot_be_approved_again(): void
    {
        $request = $this->engine->open('refund', amount: 100, requestedBy: 1, requestedByType: 'admin');
        $this->engine->approve($request, actorId: 2, actorType: 'admin');

        $result = $this->engine->approve($request->fresh(), actorId: 4, actorType: 'admin');

        $this->assertFalse($result['ok']);
        $this->assertSame('not_pending', $result['reason']);
    }

    // ---- reusable across workflows, on the unified trail ----

    public function test_the_same_engine_serves_different_workflows(): void
    {
        $payout = $this->engine->open('payout', amount: 500, requestedBy: 1, requestedByType: 'admin');
        $price = $this->engine->open('price_change', subject: ['type' => 'product', 'id' => 9], requestedBy: 1, requestedByType: 'admin');

        $this->assertSame('payout', $payout->workflow);
        $this->assertSame('price_change', $price->workflow);
        $this->assertSame('product', $price->subject_type);
    }

    public function test_every_decision_lands_on_the_audit_log(): void
    {
        $request = $this->engine->open('payout', amount: 500, requestedBy: 1, requestedByType: 'admin');
        $this->engine->approve($request, actorId: 2, actorType: 'admin');

        $this->assertTrue(AuditLog::where('action', 'payout.approval_requested')->exists());
        $this->assertTrue(AuditLog::where('action', 'payout.approved')->exists());
    }

    public function test_the_payload_is_preserved_for_the_action_to_replay_on_approval(): void
    {
        $request = $this->engine->open('price_change',
            subject: ['type' => 'product', 'id' => 9],
            payload: ['old_price' => 100, 'new_price' => 80],
            requestedBy: 1, requestedByType: 'admin');

        $this->engine->approve($request, actorId: 2, actorType: 'admin');

        // The caller reads this to know what to actually do once approved.
        $this->assertSame(80, $request->fresh()->payload['new_price']);
    }

    public function test_a_missing_table_returns_null_rather_than_fataling(): void
    {
        Schema::dropIfExists('approval_requests');

        $this->assertNull($this->engine->open('refund', amount: 100));
    }
}
