<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Smoke test: the storefront home page responds.
     *
     * This requires the full 6Valley schema, which lives only in the proprietary
     * `installation/backup/database.sql` dump (git-ignored, see README). Without it the request
     * fails on the first core table, so the test is skipped rather than left permanently red — a
     * always-failing suite trains everyone to ignore failures.
     *
     * It runs automatically once a database with the core schema is configured for testing.
     */
    public function testBasicTest()
    {
        // Asserted in every environment: the home route exists and points somewhere real. That much
        // needs no schema, and a test whose only assertion is behind a skip asserts nothing at all
        // in the environment the suite actually runs in.
        $route = app('router')->getRoutes()->getByName('home');

        $this->assertNotNull($route, 'the storefront home route is not registered');
        $this->assertSame('/', $route->uri());

        if (!Schema::hasTable('guest_users')) {
            $this->markTestSkipped(
                'The request itself requires the core 6Valley schema '
                . '(installation/backup/database.sql). Import it into the test database to enable it.'
            );
        }

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
