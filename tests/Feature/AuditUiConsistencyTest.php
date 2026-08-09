<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Verifies the UI auditor detects each defect class and stays quiet on clean markup — so the audit
 * itself is trustworthy rather than a source of noise.
 */
class AuditUiConsistencyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = resource_path('views/__audit_fixture');
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function fixture(string $content): void
    {
        File::put($this->dir . '/sample.blade.php', $content);
    }

    private function audit(array $options = []): string
    {
        \Illuminate\Support\Facades\Artisan::call('audit:ui', array_merge(['--path' => '__audit_fixture'], $options));
        return \Illuminate\Support\Facades\Artisan::output();
    }

    public function test_detects_rtl_unsafe_directional_classes(): void
    {
        $this->fixture('<div class="ml-3">x</div>');
        $this->assertStringContainsString('rtl_directional', $this->audit());
    }

    public function test_accepts_logical_rtl_safe_classes(): void
    {
        $this->fixture('<div class="ms-3">x</div>');
        $this->assertStringNotContainsString('rtl_directional', $this->audit());
    }

    public function test_detects_hardcoded_ui_text(): void
    {
        $this->fixture('<button type="button">Save Changes</button>');
        $this->assertStringContainsString('hardcoded_string', $this->audit());
    }

    public function test_translated_text_is_not_flagged(): void
    {
        $this->fixture("<button type=\"button\">{{ translate('save') }}</button>");
        $this->assertStringNotContainsString('hardcoded_string', $this->audit());
    }

    public function test_detects_image_without_alt(): void
    {
        $this->fixture('<img src="x.png">');
        $this->assertStringContainsString('missing_alt', $this->audit());
    }

    public function test_image_with_alt_is_clean(): void
    {
        $this->fixture('<img src="x.png" alt="a product">');
        $this->assertStringNotContainsString('missing_alt', $this->audit());
    }

    public function test_detects_icon_only_button_without_accessible_name(): void
    {
        $this->fixture('<button class="btn"><i class="fi fi-rr-trash"></i></button>');
        $this->assertStringContainsString('icon_button_no_label', $this->audit());
    }

    public function test_icon_button_with_aria_label_is_clean(): void
    {
        $this->fixture('<button class="btn" aria-label="delete"><i class="fi fi-rr-trash"></i></button>');
        $this->assertStringNotContainsString('icon_button_no_label', $this->audit());
    }

    public function test_detects_table_without_responsive_wrapper(): void
    {
        $this->fixture('<table><tbody><tr><td>x</td></tr></tbody></table>');
        $this->assertStringContainsString('table_overflow', $this->audit());
    }

    public function test_table_in_responsive_wrapper_is_clean(): void
    {
        $this->fixture('<div class="table-responsive"><table><tbody></tbody></table></div>');
        $this->assertStringNotContainsString('table_overflow', $this->audit());
    }

    public function test_clean_template_reports_no_issues(): void
    {
        $this->fixture("<div class=\"ms-3\">{{ translate('hello') }}</div>");
        $this->assertStringContainsString('No UI issues found', $this->audit());
    }

    public function test_comments_are_ignored(): void
    {
        $this->fixture('{{-- <div class="ml-3">Save Changes</div> --}}');
        $output = $this->audit();
        $this->assertStringContainsString('No UI issues found', $output);
    }

    public function test_severity_filter_limits_output(): void
    {
        $this->fixture('<div class="ml-3">x</div>'); // warning only
        $this->assertStringNotContainsString('rtl_directional', $this->audit(['--severity' => 'error']));
    }
}
