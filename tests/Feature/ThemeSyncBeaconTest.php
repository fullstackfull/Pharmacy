<?php

namespace Tests\Feature;

use App\Services\Theme\ThemeSyncBeacon;
use Tests\TestCase;

/**
 * The beacon's contract is a property of its payload: data-only, silent, and carrying nothing a
 * client could render without asking the server first.
 */
class ThemeSyncBeaconTest extends TestCase
{
    public function test_payload_is_a_silent_data_only_background_push(): void
    {
        $message = (new ThemeSyncBeacon())->payload(42)['message'];

        $this->assertSame(ThemeSyncBeacon::TOPIC, $message['topic']);
        $this->assertSame(ThemeSyncBeacon::TYPE, $message['data']['type']);
        $this->assertSame('42', $message['data']['revision'], 'FCM data values must be strings');

        $this->assertArrayNotHasKey('notification', $message,
            'a merchant reordering their page must never buzz every phone');

        $this->assertSame(1, $message['apns']['payload']['aps']['content-available']);
        $this->assertSame('background', $message['apns']['headers']['apns-push-type']);
        $this->assertArrayNotHasKey('alert', $message['apns']['payload']['aps']);
    }

    public function test_payload_never_carries_the_theme_itself(): void
    {
        $data = (new ThemeSyncBeacon())->payload(7)['message']['data'];

        $this->assertSame(['type', 'revision'], array_keys($data),
            'the beacon is an accelerator for the version check, never the transport for the page');
    }

    public function test_announce_survives_a_system_with_no_fcm_configured(): void
    {
        // The test environment has no push credentials and possibly no settings table at all —
        // exactly the state a fresh install publishes its first theme in.
        (new ThemeSyncBeacon())->announce(1);

        $this->assertTrue(true, 'announce() must never throw');
    }
}
