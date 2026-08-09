<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Static UI/UX audit over the Blade templates (Phase 1.5).
 *
 * A browser sweep can only cover the pages someone remembers to open; this checks EVERY template
 * for the defect classes that actually recur in this codebase — RTL-breaking directional utilities,
 * hardcoded UI strings that bypass translate(), tables that can overflow the page, and missing
 * accessibility attributes.
 *
 * It reports file:line with the offending snippet so each finding is directly actionable, and exits
 * non-zero when errors are found so it can gate CI.
 *
 *   php artisan audit:ui                 # audit everything
 *   php artisan audit:ui --path=admin-views --severity=error
 */
class AuditUiConsistency extends Command
{
    protected $signature = 'audit:ui
        {--path= : Limit to a sub-path of resources/views}
        {--severity=all : all|error|warning}
        {--limit=40 : Max findings to print per rule}
        {--include-styleguide : Also scan the component showcase page (off by default)}';

    protected $description = 'Audit Blade templates for RTL, i18n, accessibility and layout issues';

    /**
     * Paths excluded by default.
     *
     * `layouts/admin/components` is the component SHOWCASE page (route admin/component) — a
     * styleguide whose English demo labels ("Text", "Button", "Dropdown") are deliberate, not
     * untranslated UI. Scanning it produced ~210 false positives that would drown the real
     * findings, so it is opt-in via --include-styleguide.
     */
    private const EXCLUDED_PATHS = ['layouts/admin/components', 'layouts/admin/component-snippets'];

    /**
     * Directional utilities that break in RTL, mapped to their logical equivalents.
     *
     * IMPORTANT: this project loads TWO Bootstrap majors from parallel asset trees —
     * `assets/backend/libs/bootstrap` is Bootstrap 5 (defines .ms-*, .me-*, .gap-*, used by the v2
     * admin) while `assets/back-end/css/bootstrap.min.css` is Bootstrap 4.5 (does NOT define them).
     * So these findings are advisory, not auto-fixable: applying the logical class to a page served
     * by the BS4 tree silently removes the spacing instead of mirroring it. Verify per page.
     */
    private const RTL_UNSAFE = [
        'ml-' => 'ms-', 'mr-' => 'me-', 'pl-' => 'ps-', 'pr-' => 'pe-',
        'text-left' => 'text-start', 'text-right' => 'text-end',
        'float-left' => 'float-start', 'float-right' => 'float-end',
        'border-left' => 'border-start', 'border-right' => 'border-end',
    ];

    public function handle(): int
    {
        $base = resource_path('views');
        $path = $this->option('path') ? $base . '/' . trim((string) $this->option('path'), '/') : $base;

        if (!is_dir($path)) {
            $this->error("Path not found: {$path}");
            return self::FAILURE;
        }

        $findings = [];
        $files = Finder::create()->files()->in($path)->name('*.blade.php');
        $scanned = 0;

        foreach ($files as $file) {
            $relative = str_replace(base_path() . '/', '', $file->getRealPath());

            if (!$this->option('include-styleguide') && $this->isExcluded($relative)) {
                continue;
            }

            $scanned++;
            $lines = preg_split('/\R/', (string) file_get_contents($file->getRealPath())) ?: [];

            foreach ($lines as $i => $line) {
                $lineNo = $i + 1;
                foreach ($this->inspect($line) as $issue) {
                    $findings[$issue['rule']][] = $issue + ['file' => $relative, 'line' => $lineNo];
                }
            }

            // whole-file checks
            $content = (string) file_get_contents($file->getRealPath());
            // Email templates legitimately use tables for layout, and .table-responsive does nothing
            // in a mail client — flagging them would be a false positive, not a finding.
            $isEmailTemplate = str_contains($relative, 'email-template') || str_contains($relative, 'mail-template');

            if (!$isEmailTemplate
                && preg_match('/<table[\s>]/i', $content)
                && !str_contains($content, 'table-responsive')
                && !str_contains($content, 'x-ui.data-table')) {
                $findings['table_overflow'][] = [
                    'rule' => 'table_overflow', 'severity' => 'warning', 'file' => $relative, 'line' => 0,
                    'message' => 'Table without a responsive wrapper — can scroll the whole page sideways.',
                    'fix' => 'Wrap it in <x-ui.data-table> or a .table-responsive container.',
                    'snippet' => '<table>',
                ];
            }
        }

        return $this->report($findings, $scanned);
    }

    /** @return array<int, array{rule:string, severity:string, message:string, fix:string, snippet:string}> */
    private function inspect(string $line): array
    {
        $issues = [];
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '{{--')) {
            return $issues;
        }

        // 1. RTL-unsafe directional utilities inside class attributes
        if (preg_match('/class\s*=\s*"([^"]*)"/i', $line, $m)) {
            foreach (self::RTL_UNSAFE as $bad => $good) {
                $pattern = str_ends_with($bad, '-')
                    ? '/(^|\s)' . preg_quote($bad, '/') . '\d/'
                    : '/(^|\s)' . preg_quote($bad, '/') . '(\s|$)/';
                if (preg_match($pattern, $m[1])) {
                    $issues[] = [
                        'rule' => 'rtl_directional', 'severity' => 'warning',
                        'message' => "Directional class \"{$bad}\" does not mirror in RTL (Arabic).",
                        'fix' => "Use the logical equivalent \"{$good}\" — but CHECK THE PAGE'S BOOTSTRAP FIRST: "
                            . "the v2 admin loads Bootstrap 5 (assets/backend/libs/bootstrap) where {$good} exists, "
                            . "while assets/back-end ships Bootstrap 4.5 where it does NOT. A blanket replace breaks BS4 pages.",
                        'snippet' => $this->snippet($trimmed),
                    ];
                    break;
                }
            }
        }

        // 2. Hardcoded user-facing button/heading text (bypasses translate())
        if (preg_match('/<(button|h[1-6]|th|label)[^>]*>\s*([A-Za-z][A-Za-z ]{2,40})\s*<\/\1>/', $line, $m)) {
            $text = trim($m[2]);
            if (!str_contains($line, 'translate(') && !str_contains($line, '__(')) {
                $issues[] = [
                    'rule' => 'hardcoded_string', 'severity' => 'error',
                    'message' => "Hardcoded UI text \"{$text}\" is never translated.",
                    'fix' => 'Wrap it in translate(\'key\').',
                    'snippet' => $this->snippet($trimmed),
                ];
            }
        }

        // 3. Images without alt text
        if (preg_match('/<img\b(?![^>]*\balt=)[^>]*>/i', $line)) {
            $issues[] = [
                'rule' => 'missing_alt', 'severity' => 'warning',
                'message' => 'Image without an alt attribute (accessibility + image SEO).',
                'fix' => 'Add alt="" for decorative images, or a description.',
                'snippet' => $this->snippet($trimmed),
            ];
        }

        // 4. Icon-only buttons/links with no accessible name
        if (preg_match('/<(button|a)\b[^>]*>\s*<i\b[^>]*><\/i>\s*<\/\1>/i', $line)
            && !preg_match('/aria-label|title=/i', $line)) {
            $issues[] = [
                'rule' => 'icon_button_no_label', 'severity' => 'warning',
                'message' => 'Icon-only control with no accessible name — unusable with a screen reader.',
                'fix' => 'Add aria-label="{{ translate(\'…\') }}" or a title.',
                'snippet' => $this->snippet($trimmed),
            ];
        }

        // 5. Inline onclick handlers (CSP-hostile, untestable)
        if (preg_match('/\bonclick\s*=/i', $line) && !str_contains($line, 'confirm(')) {
            $issues[] = [
                'rule' => 'inline_handler', 'severity' => 'warning',
                'message' => 'Inline onclick handler — blocks a strict CSP and is hard to test.',
                'fix' => 'Bind the handler in a @push(\'script\') block instead.',
                'snippet' => $this->snippet($trimmed),
            ];
        }

        return $issues;
    }

    private function isExcluded(string $relativePath): bool
    {
        foreach (self::EXCLUDED_PATHS as $excluded) {
            if (str_contains($relativePath, $excluded)) {
                return true;
            }
        }
        return false;
    }

    private function snippet(string $line): string
    {
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        return mb_strlen($line) > 100 ? mb_substr($line, 0, 100) . '…' : $line;
    }

    private function report(array $findings, int $scanned): int
    {
        $severity = (string) $this->option('severity');
        $limit = max(1, (int) $this->option('limit'));

        $this->info("Scanned {$scanned} Blade templates.");
        $this->newLine();

        $totals = ['error' => 0, 'warning' => 0];

        foreach ($findings as $rule => $items) {
            $filtered = $severity === 'all' ? $items : array_values(array_filter($items, fn ($i) => $i['severity'] === $severity));
            if ($filtered === []) {
                continue;
            }

            $first = $filtered[0];
            $this->line(sprintf('<comment>%s</comment> [%s] — %d finding(s)', $rule, $first['severity'], count($filtered)));
            $this->line('  ' . $first['message']);
            $this->line('  <info>Fix:</info> ' . $first['fix']);

            foreach (array_slice($filtered, 0, $limit) as $item) {
                $this->line(sprintf('   %s:%d  %s', $item['file'], $item['line'], $item['snippet']));
            }
            if (count($filtered) > $limit) {
                $this->line(sprintf('   … and %d more (raise --limit to see them)', count($filtered) - $limit));
            }
            $this->newLine();

            foreach ($filtered as $item) {
                $totals[$item['severity']] = ($totals[$item['severity']] ?? 0) + 1;
            }
        }

        if (array_sum($totals) === 0) {
            $this->info('No UI issues found.');
            return self::SUCCESS;
        }

        $this->line(sprintf('<comment>Totals:</comment> %d error(s), %d warning(s)', $totals['error'] ?? 0, $totals['warning'] ?? 0));

        // Non-zero only on errors, so warnings can be burned down without blocking CI.
        return ($totals['error'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
