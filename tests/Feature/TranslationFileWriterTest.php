<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The translation file the app writes must always be a file the app can read back.
 *
 * `translate()` appends unknown keys to resources/lang/{locale}/new-messages.php and includes that
 * file on every call. The old writer concatenated values into a double-quoted PHP string, so one
 * translation carrying a quote made the file unparsable — and because the include runs on every
 * request, the whole site fatalled at once. That is what these tests stand guard over.
 */
class TranslationFileWriterTest extends TestCase
{
    private string $locale = 'zz-writer-test';
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = base_path('resources/lang/' . $this->locale);
        @mkdir($this->directory, 0775, true);
        file_put_contents($this->directory . '/messages.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->directory . '/new-messages.php', "<?php\n\nreturn [\n\t'greeting' => 'He said \"hello\" for 100\$ — and left',\n];\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*'));
        @rmdir($this->directory);
        parent::tearDown();
    }

    private function stillParses(): bool
    {
        exec('php -l ' . escapeshellarg($this->directory . '/new-messages.php') . ' 2>&1', $output, $status);

        return $status === 0;
    }

    public function test_appending_a_key_keeps_the_file_parsable_and_the_awkward_value_intact(): void
    {
        getOrPutTranslateMessageValueByKey(local: $this->locale, key: 'a_brand_new_key');

        $this->assertTrue($this->stillParses(), 'writing a new key made the language file unparsable');

        $messages = include $this->directory . '/new-messages.php';
        $this->assertSame('He said "hello" for 100$ — and left', $messages['greeting']);
        $this->assertArrayHasKey('a_brand_new_key', $messages);
    }

    public function test_a_corrupt_file_falls_back_instead_of_fatalling_the_request(): void
    {
        file_put_contents($this->directory . '/new-messages.php', "<?php\n\nreturn [\n\t\"broken\" => \"a \"quoted\" value\",\n];\n");

        $this->assertSame(
            'Some key',
            getOrPutTranslateMessageValueByKey(local: $this->locale, key: 'some_key'),
        );
    }

    public function test_the_repair_command_rescues_a_file_written_by_the_old_writer(): void
    {
        file_put_contents(
            $this->directory . '/new-messages.php',
            "<?php\n\nreturn [\n\t\"fine\" => \"plain value\",\n\t\"broken\" => \"a \"quoted\" value\",\n];\n",
        );

        $this->artisan('lang:repair', ['--locale' => $this->locale])->assertSuccessful();

        $this->assertTrue($this->stillParses());
        $messages = include $this->directory . '/new-messages.php';
        $this->assertSame('plain value', $messages['fine']);
        $this->assertSame('a "quoted" value', $messages['broken']);
    }
}
