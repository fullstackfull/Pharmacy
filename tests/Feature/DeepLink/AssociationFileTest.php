<?php

namespace Tests\Feature\DeepLink;

use App\Services\DeepLink\AssociationFileWriter;
use Tests\TestCase;

/**
 * The published file is what a phone reads, and it is the thing that goes stale.
 *
 * The path list lives in config and the file lives on disk, so a deploy that adds a path changes
 * nothing until the file is rewritten. That gap is why `deeplinks:publish` exists, and this asserts
 * that what it writes actually carries the configured list — including the campaign short link,
 * which is the path the whole integration depends on.
 */
class AssociationFileTest extends TestCase
{
    private const SETTINGS = [
        'android_package_name' => 'com.shop.app',
        'android_sha256_fingerprint' => 'AA:BB:CC',
        'ios_bundle_id' => 'com.shop.app',
        'ios_team_id' => 'TEAM123',
    ];

    public function test_the_apple_file_publishes_the_configured_paths_including_campaign_links(): void
    {
        $document = app(AssociationFileWriter::class)->appleDocument(self::SETTINGS);

        $this->assertSame('TEAM123.com.shop.app', $document['applinks']['details'][0]['appID']);
        $this->assertContains('/go/*', $document['applinks']['details'][0]['paths']);
        $this->assertContains('/product/*', $document['applinks']['details'][0]['paths']);
    }

    public function test_the_android_file_delegates_the_whole_host_to_the_package(): void
    {
        $document = app(AssociationFileWriter::class)->androidDocument(self::SETTINGS);

        $this->assertSame('com.shop.app', $document[0]['target']['package_name']);
        $this->assertSame(['AA:BB:CC'], $document[0]['target']['sha256_cert_fingerprints']);
        $this->assertSame(['delegate_permission/common.handle_all_urls'], $document[0]['relation']);
    }

    public function test_an_unconfigured_platform_produces_no_file_rather_than_an_empty_one(): void
    {
        $writer = app(AssociationFileWriter::class);

        $this->assertNull($writer->androidDocument(['ios_bundle_id' => 'com.shop.app']));
        $this->assertNull($writer->appleDocument(['android_package_name' => 'com.shop.app']));
    }

    public function test_publishing_reports_what_it_could_not_write_instead_of_failing_silently(): void
    {
        $results = app(AssociationFileWriter::class)->publish([]);

        $this->assertNotEmpty($results);

        foreach ($results as $result) {
            $this->assertFalse($result['written']);
            $this->assertSame('not_configured', $result['reason']);
        }
    }

    public function test_the_publish_command_survives_a_database_it_cannot_read(): void
    {
        // A deploy step runs before migrations as often as after them. It must report the problem,
        // not fatal the deployment.
        $this->artisan('deeplinks:publish', ['--check' => true])->assertExitCode(1);
    }
}
