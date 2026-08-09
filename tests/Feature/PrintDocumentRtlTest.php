<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * RTL for the printed financial documents.
 *
 * These are standalone <html> pages rendered through mPDF, not panel pages: nothing in the admin
 * layout reaches them, so the direction has to come from the document itself. Every one of them
 * shipped with a bare `<html>` — no lang, no dir — which meant an Arabic store's transaction
 * statements printed left-to-right regardless of the admin's language.
 *
 * mPDF honours both the dir attribute and [dir="rtl"] CSS selectors, so the two halves of the fix
 * are asserted here rather than through a PDF render (mPDF's first run builds font caches and takes
 * minutes, which makes it useless as a test).
 */
class PrintDocumentRtlTest extends TestCase
{
    /** Every print document that mPDF renders. */
    private const PRINT_DOCUMENTS = [
        'resources/views/admin-views/report/admin-earning-duration-wise-pdf.blade.php',
        'resources/views/admin-views/refund-transaction/refund_transaction_summary_report_pdf.blade.php',
        'resources/views/admin-views/transaction/order_wise_pdf.blade.php',
        'resources/views/admin-views/transaction/order_wise_expense_pdf.blade.php',
        'resources/views/admin-views/transaction/total_orders_report_pdf.blade.php',
        'resources/views/admin-views/transaction/order_transaction_summary_report_pdf.blade.php',
        'resources/views/admin-views/transaction/expense_transaction_summary_report_pdf.blade.php',
        'resources/views/vendor-views/transaction/order_wise_pdf.blade.php',
        'resources/views/vendor-views/transaction/order_wise_expense_pdf.blade.php',
        'resources/views/vendor-views/transaction/order_transaction_summary_report_pdf.blade.php',
        'resources/views/vendor-views/transaction/expense_transaction_summary_report_pdf.blade.php',
    ];

    private const PRINT_STYLESHEETS = [
        'public/assets/back-end/css/admin/order-transaction.css',
        'public/assets/back-end/css/vendor/order-transaction.css',
    ];

    public function test_every_print_document_declares_a_direction(): void
    {
        foreach (self::PRINT_DOCUMENTS as $document) {
            $html = $this->openingHtmlTag(file_get_contents(base_path($document)));

            $this->assertMatchesRegularExpression(
                '/\bdir\s*=/i',
                $html,
                "{$document} renders without a dir attribute, so it cannot mirror for Arabic"
            );
            $this->assertMatchesRegularExpression(
                '/\blang\s*=/i',
                $html,
                "{$document} declares no language"
            );
        }
    }

    /**
     * The opening <html …> tag with Blade expressions removed.
     *
     * Stripping matters: `{{ app()->getLocale() }}` contains a literal ">" from the object arrow,
     * which ends the tag as far as a regex is concerned — the same trap that made the UI auditor's
     * missing_alt rule report 70 false positives.
     */
    private function openingHtmlTag(string $content): string
    {
        $stripped = preg_replace(['/\{!!.*?!!\}/s', '/\{\{.*?\}\}/s'], "\x01", $content) ?? $content;

        preg_match('/<html\b[^>]*>/is', $stripped, $m);

        return $m[0] ?? '';
    }

    /** The direction must follow the session, not be hardcoded to either value. */
    public function test_direction_follows_the_session(): void
    {
        $html = '<html lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}" dir="{{ session(\'direction\') ?? \'ltr\' }}">';

        session(['direction' => 'rtl']);
        $this->assertStringContainsString('dir="rtl"', Blade::render($html));

        session(['direction' => 'ltr']);
        $this->assertStringContainsString('dir="ltr"', Blade::render($html));

        session()->forget('direction');
        $this->assertStringContainsString('dir="ltr"', Blade::render($html)); // safe default
    }

    /**
     * The statements use physical classes throughout. Rather than rewrite ~200 class attributes in
     * generated documents, the stylesheet mirrors them under [dir="rtl"] — so LTR output is
     * byte-identical to before and only Arabic changes.
     */
    public function test_print_stylesheets_mirror_physical_classes_for_rtl(): void
    {
        foreach (self::PRINT_STYLESHEETS as $stylesheet) {
            $css = file_get_contents(base_path($stylesheet));

            foreach (['text-left', 'text-right', 'float-left', 'float-right'] as $class) {
                $this->assertMatchesRegularExpression(
                    '/\[dir\s*=\s*["\']?rtl["\']?\]\s*\.' . preg_quote($class, '/') . '\b/',
                    $css,
                    "{$stylesheet} does not mirror .{$class} for RTL"
                );
            }
        }
    }

    /** The LTR rules must survive untouched — an English store's statements must not change. */
    public function test_ltr_rules_are_unchanged(): void
    {
        foreach (self::PRINT_STYLESHEETS as $stylesheet) {
            $css = file_get_contents(base_path($stylesheet));

            $this->assertMatchesRegularExpression('/^\.text-left\s*\{\s*text-align:\s*left/m', $css);
            $this->assertMatchesRegularExpression('/^\.text-right\s*\{\s*text-align:\s*right/m', $css);
        }
    }

    public function test_print_documents_still_compile(): void
    {
        foreach (self::PRINT_DOCUMENTS as $document) {
            $compiled = Blade::compileString(file_get_contents(base_path($document)));

            $this->assertNotSame('', $compiled, "{$document} compiled to nothing");
        }
    }
}
