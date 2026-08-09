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
        if (!Schema::hasTable('guest_users')) {
            $this->markTestSkipped(
                'Requires the core 6Valley schema (installation/backup/database.sql). '
                . 'Import it into the test database to enable this smoke test.'
            );
        }

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
