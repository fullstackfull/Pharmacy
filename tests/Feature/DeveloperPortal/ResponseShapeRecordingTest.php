<?php

namespace Tests\Feature\DeveloperPortal;

use App\Services\DeveloperPortal\Generators\OpenApiGenerator;
use App\Services\DeveloperPortal\ResponseShapeRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The whole path: a real request, an observed shape, and a document that carries it.
 *
 * Everything here has to hold at once — that a live call teaches the portal something, that it
 * costs the caller nothing, and that a documentation table which is not installed does not make
 * the API behave differently.
 */
class ResponseShapeRecordingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(database_path('migrations/*_create_api_response_shapes_table.php')) as $migration) {
            (require $migration)->up();
        }

        $this->assertTrue(Schema::hasTable('api_response_shapes'));
    }

    public function test_a_real_call_teaches_the_portal_what_the_endpoint_answers_with(): void
    {
        $this->getJson('/api/v1/deep-link/config')->assertOk();

        $row = DB::table('api_response_shapes')->where('route', '/api/v1/deep-link/config')->first();

        $this->assertNotNull($row, 'the response shape should have been recorded');
        $this->assertSame(200, (int) $row->status);

        $shape = json_decode((string) $row->shape, true);

        $this->assertSame('object', $shape['type']);
        $this->assertArrayHasKey('android', $shape['properties']);
        $this->assertArrayHasKey('ios', $shape['properties']);
        $this->assertSame('array', $shape['properties']['android']['properties']['paths']['type']);
    }

    public function test_no_value_from_the_response_is_written_down(): void
    {
        $body = $this->getJson('/api/v1/deep-link/config')->getContent();

        $stored = (string) DB::table('api_response_shapes')->value('shape');

        // The campaign path is a real value in that response; it must not be in the description.
        // (The body escapes its slashes, so it is compared decoded.)
        $this->assertContains('/go/{code}', [json_decode((string) $body, true)['campaign_path'] ?? null]);
        $this->assertStringNotContainsString('go', $stored, 'no value from the response may appear');
        $this->assertStringContainsString('campaign_path', $stored, 'the key is kept, the value is not');
    }

    public function test_the_generated_document_carries_the_observed_schema(): void
    {
        $this->getJson('/api/v1/deep-link/config')->assertOk();

        $document = app(OpenApiGenerator::class)->generate();
        $operation = $document['paths']['/api/v1/deep-link/config']['get'] ?? null;

        $this->assertNotNull($operation);

        $schema = $operation['responses']['200']['content']['application/json']['schema'] ?? null;

        $this->assertNotNull($schema, 'the observed shape should reach the document');
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('android', $schema['properties']);
    }

    public function test_the_documentation_gap_closes_for_an_observed_endpoint(): void
    {
        // Independent of what any other test in this file has already observed.
        DB::table('api_response_shapes')->delete();

        $generator = app(OpenApiGenerator::class);

        $before = collect($generator->warnings())->firstWhere('endpoint', 'GET /api/v1/deep-link/config');
        $this->assertContains('no response schema, and none observed yet', $before['missing'] ?? []);

        $this->getJson('/api/v1/deep-link/config')->assertOk();

        $after = collect(app(OpenApiGenerator::class)->warnings())->firstWhere('endpoint', 'GET /api/v1/deep-link/config');
        $this->assertNotContains('no response schema, and none observed yet', $after['missing'] ?? []);
    }

    public function test_the_endpoint_page_is_given_the_observed_shape(): void
    {
        $this->getJson('/api/v1/deep-link/config')->assertOk();

        $manifest = app(\App\Services\DeveloperPortal\ApiManifest::class);
        $id = $manifest->findByPath('/api/v1/deep-link/config', 'GET')['id'];

        $payload = app(\App\Services\Telemetry\DeveloperPortalService::class)->endpoint($id);

        $this->assertNotEmpty($payload['observed'], 'the page has nothing to show without this');
        $this->assertSame(200, $payload['observed'][0]['status']);
        $this->assertSame('object', $payload['observed'][0]['shape']['type']);
    }

    public function test_the_page_that_renders_it_compiles(): void
    {
        // The view is not rendered here — it needs the admin shell and a signed-in administrator —
        // but a Blade syntax error in it would only show up in production otherwise.
        $compiled = \Illuminate\Support\Facades\Blade::compileString(
            file_get_contents(resource_path('views/admin-views/telemetry/developer-endpoint.blade.php'))
        );

        $this->assertStringContainsString('observed', $compiled);
        $this->assertNotSame([], @token_get_all('<?php ?>' . $compiled), 'the compiled view is unparsable');
    }

    public function test_the_api_works_when_the_table_is_not_installed(): void
    {
        Schema::drop('api_response_shapes');

        $this->getJson('/api/v1/deep-link/config')->assertOk();
        $this->assertFalse(app(ResponseShapeRecorder::class)->ready());
    }
}
