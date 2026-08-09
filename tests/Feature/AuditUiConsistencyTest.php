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

    /** Panel-scoped fixtures: the Bootstrap-availability rule only fires where the panel is known. */
    private string $adminDir;
    private string $vendorDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = resource_path('views/__audit_fixture');
        $this->adminDir = resource_path('views/admin-views/__audit_fixture');
        $this->vendorDir = resource_path('views/vendor-views/__audit_fixture');
        foreach ([$this->dir, $this->adminDir, $this->vendorDir] as $d) {
            File::ensureDirectoryExists($d);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, $this->adminDir, $this->vendorDir] as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function fixture(string $content): void
    {
        File::put($this->dir . '/sample.blade.php', $content);
    }

    private function panelFixture(string $panel, string $content): void
    {
        File::put(($panel === 'admin' ? $this->adminDir : $this->vendorDir) . '/sample.blade.php', $content);
    }

    private function audit(array $options = []): string
    {
        \Illuminate\Support\Facades\Artisan::call('audit:ui', array_merge(['--path' => '__audit_fixture'], $options));
        return \Illuminate\Support\Facades\Artisan::output();
    }

    private function auditPanel(string $panel): string
    {
        \Illuminate\Support\Facades\Artisan::call('audit:ui', ['--path' => $panel . '-views/__audit_fixture']);
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

    /**
     * Regression: `{{ $asset->url }}` contains a literal ">" from the object arrow, which the tag
     * regex treated as the end of the <img>, hiding the alt attribute that followed. 70 of 78
     * missing_alt findings across the codebase were this false positive.
     */
    public function test_alt_after_a_blade_expression_containing_an_arrow_is_not_flagged(): void
    {
        $this->fixture('<img src="{{ $asset->url }}" alt="{{ $asset->label }}">');

        $this->assertStringNotContainsString('missing_alt', $this->audit());
    }

    /** …and the rule must still fire when there is genuinely no alt, arrow or not. */
    public function test_missing_alt_is_still_detected_with_a_blade_src(): void
    {
        $this->fixture('<img src="{{ $asset->url }}" class="w-100">');

        $this->assertStringContainsString('missing_alt', $this->audit());
    }

    /** A Blade expression must not be mistaken for untranslated user-facing text. */
    public function test_blade_expression_inside_a_heading_is_not_hardcoded_text(): void
    {
        $this->fixture('<th>{{ $product->name }}</th>');

        $this->assertStringNotContainsString('hardcoded_string', $this->audit());
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

    /**
     * The fixture is five columns wide on purpose. It used to be a single cell, which never
     * represented the defect — a one-column table has nothing to scroll — and stopped matching once
     * the rule learned to tell a wide data table from a narrow key/value list.
     */
    public function test_detects_table_without_responsive_wrapper(): void
    {
        $this->fixture('<table><tbody><tr><td>1</td><td>2</td><td>3</td><td>4</td><td>5</td></tr></tbody></table>');
        $this->assertStringContainsString('table_overflow', $this->audit());
    }

    public function test_table_in_responsive_wrapper_is_clean(): void
    {
        $this->fixture('<div class="table-responsive"><table><tbody></tbody></table></div>');
        $this->assertStringNotContainsString('table_overflow', $this->audit());
    }

    /**
     * A two-column "label : value" list is rendered as a table all over this codebase
     * (contacts/view, product-gallery, brand-view). It has nothing to scroll, and wrapping it
     * would be cargo-cult.
     */
    public function test_narrow_key_value_table_is_not_an_overflow_risk(): void
    {
        $this->fixture('<table><tbody><tr><td>Name</td><td>Ali</td></tr></tbody></table>');

        $this->assertStringNotContainsString('table_overflow', $this->audit());
    }

    /** role="presentation" is the author explicitly declaring "this is layout, not data". */
    public function test_presentation_table_is_not_flagged(): void
    {
        $this->fixture('<table role="presentation"><tbody><tr><td>1</td><td>2</td><td>3</td><td>4</td></tr></tbody></table>');

        $this->assertStringNotContainsString('table_overflow', $this->audit());
    }

    /** A <table> holding only hidden inputs (an auto-submitting redirect form) has no cells. */
    public function test_table_with_no_cells_is_not_flagged(): void
    {
        $this->fixture('<table><tbody><input type="hidden" name="a" value="1"></tbody></table>');

        $this->assertStringNotContainsString('table_overflow', $this->audit());
    }

    /** Invoices and PDFs are printed on paper — there is no viewport to scroll. */
    public function test_print_documents_are_not_flagged(): void
    {
        File::put($this->dir . '/order-invoice.blade.php',
            '<table><tbody><tr><td>1</td><td>2</td><td>3</td><td>4</td></tr></tbody></table>');
        File::delete($this->dir . '/sample.blade.php');

        $this->assertStringNotContainsString('table_overflow', $this->audit());
    }

    /**
     * Regression: a partial has no container of its own. Flagging one whose include site already
     * wraps it led to a nested .table-responsive — two scrollbars, worse than the finding.
     */
    public function test_partial_wrapped_at_its_include_site_is_not_flagged(): void
    {
        File::put($this->dir . '/_wide.blade.php',
            '<table><tbody><tr><td>1</td><td>2</td><td>3</td><td>4</td></tr></tbody></table>');
        File::put($this->dir . '/sample.blade.php',
            '<div class="table-responsive">' . "\n" . "@include('__audit_fixture._wide')" . "\n" . '</div>');

        $this->assertStringNotContainsString('table_overflow', $this->audit());
    }

    /** …and an include site that does NOT wrap it is a real finding. */
    public function test_partial_left_unwrapped_by_its_include_site_is_flagged(): void
    {
        File::put($this->dir . '/_wide.blade.php',
            '<table><tbody><tr><td>1</td><td>2</td><td>3</td><td>4</td></tr></tbody></table>');
        File::put($this->dir . '/sample.blade.php', "<div>\n@include('__audit_fixture._wide')\n</div>");

        $this->assertStringContainsString('table_overflow', $this->audit());
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

    // ---- class availability: the two panels load different Bootstrap majors ----

    /**
     * The admin panel serves Bootstrap 5.3, where form-row was removed. A BS4 form-row there does
     * not fall back to anything — it silently stacks the columns, which is how the theme asset
     * upload form shipped broken.
     */
    public function test_flags_a_bootstrap_4_only_class_on_an_admin_page(): void
    {
        $this->panelFixture('admin', '<form class="form-row"><div class="col-md-6">x</div></form>');

        $output = $this->auditPanel('admin');
        $this->assertStringContainsString('class_not_defined', $output);
        $this->assertStringContainsString('form-row', $output);
    }

    /**
     * The vendor panel serves Bootstrap 4.5 and still lacks some of Bootstrap 5's utilities.
     *
     * This used to assert on ms-3, which stopped being a valid example once the vendor panel gained
     * a logical-utilities stylesheet — the rule was correctly reporting that the gap had closed.
     * visually-hidden is still genuinely absent there (the panel has BS4's sr-only instead).
     */
    public function test_flags_a_bootstrap_5_only_class_on_a_vendor_page(): void
    {
        $this->panelFixture('vendor', '<div class="visually-hidden">x</div>');

        $this->assertStringContainsString('class_not_defined', $this->auditPanel('vendor'));
    }

    /** …and the same class is fine on the admin panel, so the rule must be panel-aware. */
    public function test_does_not_flag_a_bootstrap_5_class_on_an_admin_page(): void
    {
        $this->panelFixture('admin', '<div class="visually-hidden">x</div>');

        $this->assertStringNotContainsString('class_not_defined', $this->auditPanel('admin'));
    }

    /**
     * A standalone document (invoice/PDF) brings its own CSS and never loads the panel bundle, so
     * it must be judged against what it defines itself.
     */
    public function test_standalone_document_defining_its_own_class_is_clean(): void
    {
        $this->panelFixture('admin', '<html><head><style>.text-left { text-align: left; }</style></head>'
            . '<body><td class="text-left">x</td></body></html>');

        $this->assertStringNotContainsString('class_not_defined', $this->auditPanel('admin'));
    }

    /** A standalone document that uses a class nothing defines is still a real finding. */
    public function test_standalone_document_using_an_undefined_class_is_flagged(): void
    {
        $this->panelFixture('admin', '<html><head><style>.foo { color: red; }</style></head>'
            . '<body><td class="text-left">x</td></body></html>');

        $this->assertStringContainsString('class_not_defined', $this->auditPanel('admin'));
    }

    /** Shared/storefront templates have no known panel, so the rule must stay silent there. */
    public function test_shared_templates_are_not_judged_against_a_panel(): void
    {
        $this->fixture('<form class="form-row">x</form>');

        $this->assertStringNotContainsString('class_not_defined', $this->audit());
    }
}
