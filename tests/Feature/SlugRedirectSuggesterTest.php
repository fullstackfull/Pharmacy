<?php

namespace Tests\Feature;

use App\Models\Redirect;
use App\Services\Seo\SlugRedirectSuggester;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SlugRedirectSuggesterTest extends TestCase
{
    private SlugRedirectSuggester $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SlugRedirectSuggester();

        Schema::dropIfExists('redirects');
        Schema::create('redirects', function (Blueprint $t) {
            $t->id();
            $t->string('from_path', 191);
            $t->string('to_path', 2048);
            $t->unsignedSmallInteger('status_code')->default(301);
            $t->string('match_type', 20)->default('exact');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('hits')->default(0);
            $t->timestamp('last_hit_at')->nullable();
            $t->string('note', 500)->nullable();
            $t->string('created_by_type', 40)->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
    }

    public function test_creates_a_301_when_a_product_slug_changes(): void
    {
        $redirect = $this->svc->suggest('product', 'old-slug', 'new-slug');

        $this->assertNotNull($redirect);
        $this->assertSame('/product/old-slug', $redirect->from_path);
        $this->assertSame('/product/new-slug', $redirect->to_path);
        $this->assertSame(301, $redirect->status_code);
        $this->assertTrue($redirect->is_active);
        $this->assertSame('system', $redirect->created_by_type);
    }

    public function test_no_op_when_slug_did_not_change(): void
    {
        $this->assertNull($this->svc->suggest('product', 'same', 'same'));
        $this->assertSame(0, Redirect::count());
    }

    public function test_no_op_for_empty_slugs(): void
    {
        $this->assertNull($this->svc->suggest('product', '', 'new'));
        $this->assertNull($this->svc->suggest('product', 'old', ''));
        $this->assertSame(0, Redirect::count());
    }

    public function test_no_op_for_unknown_entity_type(): void
    {
        $this->assertNull($this->svc->suggest('unknown_thing', 'a', 'b'));
        $this->assertSame(0, Redirect::count());
    }

    public function test_never_overwrites_an_existing_rule(): void
    {
        Redirect::create([
            'from_path' => '/product/old-slug', 'to_path' => '/custom-target',
            'status_code' => 302, 'match_type' => 'exact', 'is_active' => true,
        ]);

        $this->assertNull($this->svc->suggest('product', 'old-slug', 'new-slug'));

        // the admin's custom rule is intact
        $this->assertSame('/custom-target', Redirect::where('from_path', '/product/old-slug')->first()->to_path);
        $this->assertSame(1, Redirect::count());
    }

    public function test_supports_other_entity_types(): void
    {
        $this->assertSame('/brand/old', $this->svc->suggest('brand', 'old', 'new')->from_path);
        $this->assertSame('/blog/old', $this->svc->suggest('blog', 'old', 'new')->from_path);
    }
}
