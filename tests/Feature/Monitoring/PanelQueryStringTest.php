<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Panels\PanelRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A filter nobody can spell must not take the section down.
 *
 * `?status[]=x` is a URL anyone can type and a crawler will eventually try. It hands the request an
 * ARRAY where the panel expected a string, and casting an array to string is a PHP warning that
 * this application's error handler turns into a throw — so the whole section rendered "could not be
 * built: Array to string conversion" instead of a monitoring page. Found on three panels by sending
 * it at all thirty-three sections; the fix is the same in each: a value that is not a single string
 * is not a filter, so it is not applied.
 *
 * This runs the panels directly rather than through HTTP, because the point is the panel's own
 * handling of what the request hands it.
 */
class PanelQueryStringTest extends TestCase
{
    /** Every parameter any section reads, all arrays, all at once. */
    private const HOSTILE = 'status[]=x&severity[]=y&type[]=z&level[]=a&q[]=b&file[]=c&date[]=d'
        . '&group[]=e&page[]=f&channel[]=g&release[]=h&route[]=i&metric[]=j&trace[]=k'
        . '&queue[]=l&kind[]=m&version[]=n&min_ms[]=o&sort[]=p&search[]=q&state[]=r';

    protected function setUp(): void
    {
        parent::setUp();

        // The panels read monitoring's own connection; an installation without those tables is a
        // separate honest state, and this test is about the query string, not the schema.
        config()->set('monitoring.connection', config('database.default'));
    }

    public function test_no_section_can_be_broken_by_an_array_where_a_string_belongs(): void
    {
        $registry = app(PanelRegistry::class);
        $broken = [];

        foreach (array_keys($this->sections()) as $section) {
            $request = Request::create('/admin/monitoring/' . $section . '?' . self::HOSTILE, 'GET');
            $payload = $registry->data($section, '24h', $request);

            // 'failed' is the registry's own catch-all: the panel threw. This test is about ONE
            // reason for throwing — an array cast to a string — so a section that fails for a
            // missing table on a bare test database is not this test's business, and saying so is
            // better than making the test pass by pretending the schema is there.
            $message = (string) ($payload['message'] ?? '');

            if (($payload['state'] ?? null) === 'failed' && str_contains($message, 'Array to string')) {
                $broken[$section] = $message;
            }
        }

        $this->assertSame([], $broken, 'these sections threw on a query string a crawler could send');
    }

    /** @return array<string, mixed> */
    private function sections(): array
    {
        return \App\Services\Monitoring\MonitoringNavigation::sections();
    }
}
