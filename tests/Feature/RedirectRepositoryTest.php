<?php

namespace Tests\Feature;

use App\Contracts\Repositories\RedirectRepositoryInterface;
use App\Models\Redirect;
use App\Repositories\RedirectRepository;
use App\Services\Seo\RedirectResolverService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the redirect data layer end-to-end (model <-> table <-> repository <-> resolver) by
 * provisioning only the additive `redirects` table on the in-memory sqlite connection. The core
 * 6Valley schema (absent here) is not needed.
 */
class RedirectRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('redirects');
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 191);
            $table->string('to_path', 2048);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->string('match_type', 20)->default('exact');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('created_by_type', 40)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
        });
    }

    private function repo(): RedirectRepositoryInterface
    {
        return app(RedirectRepositoryInterface::class);
    }

    public function test_repository_is_auto_bound_by_convention(): void
    {
        $this->assertInstanceOf(RedirectRepository::class, $this->repo());
    }

    public function test_active_rules_feed_the_resolver_and_exclude_inactive(): void
    {
        $repo = $this->repo();
        $repo->add(['from_path' => '/old', 'to_path' => '/new', 'status_code' => 301, 'match_type' => 'exact', 'is_active' => true]);
        $repo->add(['from_path' => '/inactive', 'to_path' => '/x', 'status_code' => 302, 'match_type' => 'exact', 'is_active' => false]);

        $rules = $repo->getActiveRulesArray();
        $this->assertCount(1, $rules);

        $resolver = new RedirectResolverService();
        $this->assertSame(['to' => '/new', 'status' => 301], $resolver->resolve('/old', $rules));
        $this->assertNull($resolver->resolve('/inactive', $rules));
    }

    public function test_exists_for_from_path_with_exclusion(): void
    {
        $repo = $this->repo();
        $created = $repo->add(['from_path' => '/dup', 'to_path' => '/a', 'status_code' => 301, 'match_type' => 'exact', 'is_active' => true]);

        $this->assertTrue($repo->existsForFromPath('/dup'));
        $this->assertFalse($repo->existsForFromPath('/dup', $created->id));
        $this->assertFalse($repo->existsForFromPath('/does-not-exist'));
    }

    public function test_update_and_delete(): void
    {
        $repo = $this->repo();
        $created = $repo->add(['from_path' => '/a', 'to_path' => '/b', 'status_code' => 301, 'match_type' => 'exact', 'is_active' => true]);

        $this->assertTrue($repo->update((string) $created->id, ['to_path' => '/c']));
        $this->assertSame('/c', Redirect::find($created->id)->to_path);

        $this->assertTrue($repo->delete(['id' => $created->id]));
        $this->assertNull(Redirect::find($created->id));
    }
}
