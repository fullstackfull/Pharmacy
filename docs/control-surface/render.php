<?php

/**
 * Renders the three control-surface documents from records.json.
 *
 * The matrix, the orphan register and the per-domain report are three views of one set of records,
 * so a capability cannot be connected in one document and orphaned in another. Correct the records
 * and re-run this — do not hand-edit the generated markdown.
 *
 *     php docs/control-surface/render.php
 */

$records = json_decode(file_get_contents(__DIR__ . '/records.json'), true);

if (!is_array($records)) {
    fwrite(STDERR, "records.json did not parse\n");
    exit(1);
}

$DOCS = __DIR__ . '/..';

/** The surfaces a capability is measured against, in the order the brief asks for them. */
const SURFACES = [
    'backend' => 'Backend',
    'admin' => 'Admin',
    'seller_web' => 'Seller Web',
    'flutter' => 'Flutter App',
    'analytics' => 'Analytics',
    'monitor' => 'Monitor',
    'dev_portal' => 'Dev Portal',
    'audit' => 'Audit',
];

const VERDICTS = [
    'CONNECTED TO ADMIN',
    'CONNECTED TO SELLER',
    'CONNECTED TO DEVELOPER PORTAL',
    'CONNECTED TO MONITOR',
    'INTERNAL BY DESIGN',
    'DEPRECATED',
    'ORPHAN',
];

$cell = static fn (?string $text): string => str_replace(['|', "\n"], ['\\|', ' '], trim((string) ($text ?: '—')));

/** A surface that answers "None" is a gap; one that answers "N/A" is not. */
$isGap = static fn (?string $value): bool => strcasecmp(trim((string) $value), 'none') === 0;

$areas = [];
$byVerdict = [];
foreach ($records as $record) {
    $areas[$record['area'] ?? 'platform'][] = $record;
    $byVerdict[$record['verdict'] ?? 'ORPHAN'][] = $record;
}
ksort($areas);

$total = count($records);
$orphans = count($byVerdict['ORPHAN'] ?? []);
$internal = count($byVerdict['INTERNAL BY DESIGN'] ?? []);
$connected = $total - $orphans - $internal - count($byVerdict['DEPRECATED'] ?? []);

$write = static function (string $path, array $lines) use ($DOCS): void {
    file_put_contents($DOCS . '/' . $path, implode("\n", $lines) . "\n");
    echo str_pad($path, 42) . number_format(filesize($DOCS . '/' . $path)) . " bytes\n";
};

// ── 1 · the matrix ───────────────────────────────────────────────────────────
$out = [];
$out[] = '# System Control Surface Matrix';
$out[] = '';
$out[] = '> Every meaningful capability the platform has, and which surface owns it.';
$out[] = '';
$out[] = 'The question this document answers, for each capability: **who manages it, who can see its';
$out[] = 'status, where is it configured, where are its failures visible, and where is its history?**';
$out[] = 'A capability with no answer is not a design decision; it is a capability nobody owns.';
$out[] = '';
$out[] = '| | |';
$out[] = '|---|---|';
$out[] = '| Capabilities audited | ' . $total . ' |';
$out[] = '| Fully connected to a surface | ' . $connected . ' |';
$out[] = '| Internal by design | ' . $internal . ' |';
$out[] = '| Deprecated | ' . count($byVerdict['DEPRECATED'] ?? []) . ' |';
$out[] = '| **Orphaned — no owner, no surface** | **' . $orphans . '** |';
$out[] = '';
$out[] = 'Orphans are enumerated with their evidence in [ORPHAN_BACKEND_CAPABILITIES.md](ORPHAN_BACKEND_CAPABILITIES.md).';
$out[] = 'The per-domain reading is in [FINAL_PLATFORM_COVERAGE_AUDIT.md](FINAL_PLATFORM_COVERAGE_AUDIT.md).';
$out[] = '';
$out[] = '## How to read a cell';
$out[] = '';
$out[] = '`None` is a gap: that surface could reasonably own this and does not. `N/A` is not a gap — the';
$out[] = 'dimension does not apply, as a nightly reconciliation job has no phone screen. The distinction is';
$out[] = 'the whole point of the document, so it is never blurred.';
$out[] = '';

foreach ($areas as $area => $items) {
    $out[] = '## ' . ucfirst(str_replace('_', ' ', (string) $area)) . ' (' . count($items) . ')';
    $out[] = '';
    $out[] = '| Capability | ' . implode(' | ', array_slice(SURFACES, 1)) . ' | Owner | Verdict |';
    $out[] = '|---' . str_repeat('|---', count(SURFACES) - 1) . '|---|---|';

    foreach ($items as $record) {
        $cells = [];
        foreach (array_keys(SURFACES) as $key) {
            if ($key === 'backend') {
                continue;
            }
            $value = $cell($record[$key] ?? null);
            $cells[] = $isGap($record[$key] ?? null) ? '**None**' : $value;
        }

        $out[] = '| ' . $cell($record['capability'] ?? null) . ' | ' . implode(' | ', $cells)
            . ' | ' . $cell($record['owner'] ?? null) . ' | ' . $cell($record['verdict'] ?? null) . ' |';
    }
    $out[] = '';
}

$write('SYSTEM_CONTROL_SURFACE_MATRIX.md', $out);

// ── 2 · the orphan register ──────────────────────────────────────────────────
$out = [];
$out[] = '# Orphan Backend Capabilities';
$out[] = '';
$out[] = '> Every capability found in the backend, and the surface it ended up connected to.';
$out[] = '';
$out[] = 'The acceptance criterion is **zero unexplained orphans**: nothing meaningful may run in the';
$out[] = 'background without a documented owner and a place a person can see it. A capability that is';
$out[] = 'deliberately invisible is recorded as `INTERNAL BY DESIGN` with the reason no screen is';
$out[] = 'appropriate — silence is not the same as a decision.';
$out[] = '';
$out[] = '| Verdict | Capabilities | Meaning |';
$out[] = '|---|---:|---|';

$meaning = [
    'CONNECTED TO ADMIN' => 'The marketplace operator manages or oversees it.',
    'CONNECTED TO SELLER' => 'The seller manages it, in the panel or the app.',
    'CONNECTED TO DEVELOPER PORTAL' => 'Documented as an API capability an integrator can use.',
    'CONNECTED TO MONITOR' => 'Its health and its failures are visible to an operator.',
    'INTERNAL BY DESIGN' => 'Infrastructure. No screen is appropriate, and the reason is stated.',
    'DEPRECATED' => 'Present in code, no longer part of the product.',
    'ORPHAN' => 'No owner and no surface. This is the list that must reach zero.',
];

foreach (VERDICTS as $verdict) {
    $items = $byVerdict[$verdict] ?? [];
    if ($items === []) {
        continue;
    }
    $out[] = '| ' . $verdict . ' | ' . count($items) . ' | ' . $meaning[$verdict] . ' |';
}
$out[] = '';

foreach (VERDICTS as $verdict) {
    $items = $byVerdict[$verdict] ?? [];
    if ($items === []) {
        continue;
    }

    $out[] = '## ' . $verdict . ' (' . count($items) . ')';
    $out[] = '';
    $out[] = $meaning[$verdict];
    $out[] = '';

    foreach ($items as $record) {
        $out[] = '**' . trim((string) ($record['capability'] ?? '')) . '**  ';
        $out[] = '`' . ($record['area'] ?? 'platform') . '` · owner: ' . ($record['owner'] ?? '—') . '  ';
        $out[] = '- Backend — ' . trim((string) ($record['backend'] ?? '—'));

        $gaps = [];
        foreach (SURFACES as $key => $label) {
            if ($key !== 'backend' && $isGap($record[$key] ?? null)) {
                $gaps[] = $label;
            }
        }
        if ($gaps !== []) {
            $out[] = '- No surface on — ' . implode(', ', $gaps);
        }
        if (!empty($record['note'])) {
            $out[] = '- ' . trim((string) $record['note']);
        }
        $out[] = '';
    }
}

$write('ORPHAN_BACKEND_CAPABILITIES.md', $out);

// ── 3 · the per-domain report ────────────────────────────────────────────────
$out = [];
$out[] = '# Final Platform Coverage Audit';
$out[] = '';
$out[] = '> Domain by domain: what is complete, and what is explicitly not.';
$out[] = '';
$out[] = 'A domain is complete when every capability in it has a management surface, a status surface,';
$out[] = 'somewhere its failures show, and an audit trail — not when its screens exist.';
$out[] = '';

foreach ($areas as $area => $items) {
    $out[] = '## ' . strtoupper(str_replace('_', ' ', (string) $area));
    $out[] = '';

    foreach (SURFACES as $key => $label) {
        $applicable = array_filter($items, fn ($r) => strcasecmp(trim((string) ($r[$key] ?? '')), 'n/a') !== 0);
        $gaps = array_filter($applicable, fn ($r) => $isGap($r[$key] ?? null));

        if ($applicable === []) {
            $out[] = $label . ': N/A';
            continue;
        }

        $out[] = $gaps === []
            ? $label . ': Complete'
            : $label . ': **' . count($gaps) . ' of ' . count($applicable) . ' without a surface**';
    }

    $out[] = '';

    $incomplete = [];
    foreach ($items as $record) {
        $gaps = [];
        foreach (SURFACES as $key => $label) {
            if ($key !== 'backend' && $isGap($record[$key] ?? null)) {
                $gaps[] = $label;
            }
        }
        if ($gaps !== [] && ($record['verdict'] ?? '') !== 'INTERNAL BY DESIGN') {
            $incomplete[] = '- **' . trim((string) $record['capability']) . '** — no ' . implode(', ', $gaps)
                . (!empty($record['note']) ? '. ' . trim((string) $record['note']) : '');
        }
    }

    if ($incomplete !== []) {
        $out[] = 'Incomplete in this domain:';
        $out[] = '';
        foreach ($incomplete as $line) {
            $out[] = $line;
        }
        $out[] = '';
    }
}

$write('FINAL_PLATFORM_COVERAGE_AUDIT.md', $out);
