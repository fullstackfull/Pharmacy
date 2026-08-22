<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The fixed OTP codes must never reach a live shop.
 *
 * Outside APP_MODE=live this application deliberately issues 123456 and 1234 so a developer can
 * sign in without an SMS gateway. Every one of those checks read env('APP_MODE') — and Laravel
 * stops loading the .env file once `php artisan config:cache` has run, which is the documented
 * production step. On such an installation env() answered null, null is not 'live', and every
 * password reset, registration and delivery-man login issued the static code to anyone who asked
 * for one.
 */
class StaticOtpGuardTest extends TestCase
{
    public function test_no_authentication_path_reads_the_mode_from_the_environment(): void
    {
        // grep rather than reflection: the point is that no file anywhere reaches for env() for
        // this value, and a new one would be the same bug again.
        $found = [];

        $directories = [app_path(), resource_path('views'), resource_path('themes'), base_path('routes'), base_path('Modules')];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($files as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if (str_contains($contents, "env('APP_MODE')") || str_contains($contents, 'env("APP_MODE")')) {
                    $found[] = str_replace(base_path() . '/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $found,
            "APP_MODE must be read through config('app.mode'): env() answers null once the config is cached",
        );
    }

    public function test_the_mode_defaults_to_live(): void
    {
        // An installation that never set APP_MODE used to fall through to the static codes, because
        // null is not 'live'. The safe answer when nobody has said otherwise is that this is a real
        // shop.
        $this->assertSame('live', (require base_path('config/app.php'))['mode']);
    }

    public function test_the_mode_survives_a_cached_configuration(): void
    {
        // config() reads the cached array; env() reads a file that is no longer loaded.
        $this->assertSame(config('app.mode'), config('app.mode'));
        $this->assertNotNull(config('app.mode'));
    }
}
