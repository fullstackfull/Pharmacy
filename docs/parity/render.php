<?php

/**
 * Renders docs/SELLER_WEB_APP_PARITY.md and docs/parity/<domain>.md from records.json.
 *
 * The matrix, the registers and the per-domain files all come from the same records, so a
 * classification cannot be right in one place and wrong in another. Correct records.json and
 * re-run this — do not hand-edit the generated markdown.
 *
 *     php docs/parity/render.php
 */

$domains = json_decode(file_get_contents(__DIR__ . '/records.json'), true);

$slugs = [];
foreach ($domains as $d) {
    $slugs[$d['domain']] = explode(' ', $d['domain'])[0];
}

$WAVE = [
    'orders' => 2, 'products' => 2, 'inventory' => 2, 'control_tower' => 2, 'notifications_chat' => 2,
    'automation' => 3,
    'shipping_delivery' => 4, 'returns_refunds' => 4,
    'finance' => 5,
    'brands_compliance' => 6, 'growth_reviews' => 6,
    'security_integrations' => 7, 'settings_profile' => 7,
    'reports_bulk' => 8,
];

/** Two domains split across waves; the keyword decides which half a capability belongs to. */
function waveFor(string $slug, array $cap, array $WAVE): int
{
    $text = strtolower($cap['capability']);
    if ($slug === 'security_integrations') {
        return preg_match('/\b(api key|api keys|webhook|endpoint|scope|signing secret|delivery|deliveries)\b/', $text) ? 8 : 7;
    }
    if ($slug === 'returns_refunds' && str_contains($text, 'balance')) {
        return 5;
    }
    if ($slug === 'reports_bulk') {
        return 8;
    }

    return $WAVE[$slug];
}

$WAVE_NAME = [
    1 => 'Foundation', 2 => 'Core seller operations', 3 => 'Automation', 4 => 'Fulfilment',
    5 => 'Finance', 6 => 'Trust', 7 => 'Enterprise', 8 => 'Platform',
];

$CATEGORIES = ['BOTH', 'WEB MISSING', 'APP MISSING', 'WEB ENHANCEMENT', 'APP ADAPTATION', 'DEVICE SPECIFIC', 'DEPRECATED', 'BACKEND MISSING'];

$rows = [];
foreach ($domains as $d) {
    $slug = $slugs[$d['domain']];
    foreach ($d['capabilities'] as $c) {
        $c['domain'] = $slug;
        $c['domain_label'] = $d['domain'];
        $c['wave'] = waveFor($slug, $c, $WAVE);
        $rows[] = $c;
    }
}

$countBy = static function (array $rows, string $key): array {
    $out = [];
    foreach ($rows as $r) {
        $out[$r[$key]] = ($out[$r[$key]] ?? 0) + 1;
    }

    return $out;
};

$cell = static fn (?string $text): string => str_replace(['|', "\n"], ['\\|', ' '], trim((string) ($text ?: '—')));

/** Evidence that only repeats paths already named in the three location fields adds nothing. */
$evidenceAdds = static function (array $c): bool {
    preg_match_all('#[A-Za-z0-9_./-]+\.(php|dart|blade\.php)#', $c['evidence'], $found);
    if ($found[0] === []) {
        return true;
    }
    $known = $c['flutter'] . ' ' . $c['web'] . ' ' . $c['backend'];
    foreach (array_unique($found[0]) as $path) {
        if (!str_contains($known, $path)) {
            return true;
        }
    }

    return false;
};

$out = [];
$line = static function (string $text = '') use (&$out): void {
    $out[] = $text;
};

$byCategory = $countBy($rows, 'category');
$total = count($rows);

$line('# Seller Center — Web / App Capability Parity');
$line();
$line('> Mandated by PART 2 of the Seller Center implementation brief. Produced before any redesign screen was');
$line('> altered, by reading both clients and the server rather than by comparing screenshots.');
$line();
$line('**Method.** Fourteen domains were audited against three sources at once: the Flutter seller app');
$line('(`sillercenter-syria-cosmatics`), the existing web seller panel (`resources/views/vendor-views/**`,');
$line('`routes/vendor/routes.php`) and the server that answers both (`routes/rest_api/v3/seller.php`,');
$line('`routes/rest_api/v{1,2}/api.php`). Every row below carries the file and line that proves it, so a reader');
$line('can disagree with a classification by opening the code, not by trusting the audit.');
$line();
$line('**Parity is behavioural, not visual.** A capability is at parity when the same business outcome is reachable');
$line('with the same rules, statuses, permissions and calculations. The two clients are allowed to look nothing');
$line('alike and to sequence the work differently; they are not allowed to disagree about what a status means, who');
$line('may perform an action, or what a number is.');
$line();
$line('## Coverage');
$line();
$line('| | |');
$line('|---|---|');
$line('| Domains audited | ' . count($domains) . ' |');
$line('| Capabilities recorded | ' . $total . ' |');
$line('| WEB MISSING — all documented below, none outstanding | ' . $byCategory['WEB MISSING'] . ' |');
$line('| Undocumented WEB MISSING | **0** |');
$line();
$line('Every WEB MISSING capability appears in the register in [§3](#3-web-missing-register-the-mandatory-list),');
$line('is assigned to an implementation wave, and is repeated with its full evidence in the domain file listed in');
$line('[§5](#5-domains). The register and the domain files are rendered from the same records, so they cannot');
$line('drift apart.');
$line();
$line('---');
$line();
$line('## 1. How to read a row');
$line();
$line('| Category | Meaning | What it obliges |');
$line('|---|---|---|');
$line('| `BOTH` | Exists in the app and on the web, with the same business behaviour. | Keep them in step. A change to one is a change to both. |');
$line('| `WEB MISSING` | The app can do it; the web cannot. | **Must be built into the Web Seller Center.** Marketplace capability may not live only on a phone. |');
$line('| `APP MISSING` | The web can do it; the app cannot. | Note it. The app closes the gap where it makes sense on a phone; nothing is deleted from the web to force symmetry. |');
$line('| `WEB ENHANCEMENT` | Web-only and correct that way — bulk, breadth, long-form work a phone should not attempt. | Keep web-only. Do not shrink it into the app. |');
$line('| `APP ADAPTATION` | Same capability, deliberately different shape on a phone. | Legitimate. Verify the business rules match, not the layout. |');
$line('| `DEVICE SPECIFIC` | Belongs to the device — camera, scanner, push token, biometrics. | Not a web gap. The web offers the equivalent input where one exists (upload, typed code). |');
$line('| `DEPRECATED` | Present in code but no longer part of the product. | Do not carry forward. Removal is a separate, explicit decision. |');
$line('| `BACKEND MISSING` | Neither client can do it because the server does not offer it. | Server work first. A client-side workaround here would duplicate business logic, which PART 7 forbids. |');
$line();
$line('Each row also names the **permission** that governs it. Those keys are the ones the server already enforces');
$line('(`app/Http/Middleware/EnsureSellerPermission.php`), which is what makes one authorization system serve both');
$line('clients per PART 5.');
$line();
$line('---');
$line();
$line('## 2. Matrix');
$line();

$header = '| Domain | ' . implode(' | ', array_map(fn ($c) => str_replace(' ', '<br>', $c), $CATEGORIES)) . ' | Total |';
$line($header);
$line('|---' . str_repeat('|---:', count($CATEGORIES) + 1) . '|');

foreach ($domains as $d) {
    $slug = $slugs[$d['domain']];
    $mine = array_values(array_filter($rows, fn ($r) => $r['domain'] === $slug));
    $counts = $countBy($mine, 'category');
    $cells = array_map(fn ($c) => (string) ($counts[$c] ?? 0), $CATEGORIES);
    $line('| [' . $slug . '](parity/' . $slug . '.md) | ' . implode(' | ', $cells) . ' | ' . count($mine) . ' |');
}
$totals = array_map(fn ($c) => (string) ($byCategory[$c] ?? 0), $CATEGORIES);
$line('| **All** | **' . implode('** | **', $totals) . '** | **' . $total . '** |');
$line();
$line('---');
$line();
$line('## 3. WEB MISSING register (the mandatory list)');
$line();
$line('These ' . $byCategory['WEB MISSING'] . ' capabilities are the reason PART 2 exists: each is a real marketplace');
$line('capability that today is reachable only from a phone. They are grouped by the wave that closes them, from');
$line('`13-implementation-priority.md`. Wave assignment follows the domain, with two documented splits: the API-key');
$line('and webhook half of `security_integrations` belongs to wave 8 rather than 7, and the refund-to-balance row in');
$line('`returns_refunds` belongs to wave 5, because that is where the ledger it writes into is built.');
$line();

$webMissing = array_values(array_filter($rows, fn ($r) => $r['category'] === 'WEB MISSING'));
$byWave = [];
foreach ($webMissing as $r) {
    $byWave[$r['wave']][] = $r;
}
ksort($byWave);

$line('| Wave | Capabilities | Closes |');
$line('|---|---:|---|');
foreach ($byWave as $wave => $items) {
    $doms = array_unique(array_map(fn ($r) => $r['domain'], $items));
    sort($doms);
    $line('| ' . $wave . ' — ' . $WAVE_NAME[$wave] . ' | ' . count($items) . ' | ' . implode(', ', $doms) . ' |');
}
$line();

foreach ($byWave as $wave => $items) {
    $line('### Wave ' . $wave . ' — ' . $WAVE_NAME[$wave] . ' (' . count($items) . ')');
    $line();
    $line('| # | Capability | Domain | Where it exists today | Server it calls | Permission |');
    $line('|---:|---|---|---|---|---|');
    $index = 1;
    foreach ($items as $r) {
        $line('| ' . $index++ . ' | ' . $cell($r['capability']) . ' | ' . $r['domain'] . ' | ' . $cell($r['flutter'])
            . ' | ' . $cell($r['backend']) . ' | ' . $cell($r['permission'] ?? null) . ' |');
    }
    $line();
}

$line('---');
$line();
$line('## 4. The other registers');
$line();

$registers = [
    'BACKEND MISSING' => 'Server work, not client work. Building any of these into a client would mean inventing the rule in the client, which PART 7 forbids.',
    'DEPRECATED' => 'Code that still exists and product that no longer does. Not carried into the Seller Center; removal from the legacy panel stays a separate decision (PART 15).',
    'WEB ENHANCEMENT' => 'Correctly web-only. Listed so nobody "fixes" the asymmetry by cramming them into the app.',
    'APP ADAPTATION' => 'Same capability, phone-shaped. Check the rules, not the layout.',
    'DEVICE SPECIFIC' => 'The device is the capability. The web equivalent is the typed or uploaded form of the same input.',
];

foreach ($registers as $category => $blurb) {
    $items = array_values(array_filter($rows, fn ($r) => $r['category'] === $category));
    $line('### ' . $category . ' (' . count($items) . ')');
    $line();
    $line($blurb);
    $line();
    $line('| Capability | Domain | Note |');
    $line('|---|---|---|');
    foreach ($items as $r) {
        $note = $category === 'BACKEND MISSING' ? $r['backend'] : ($category === 'APP MISSING' ? $r['flutter'] : $r['web']);
        if ($category === 'WEB ENHANCEMENT' || $category === 'DEPRECATED') {
            $note = $r['web'];
        }
        if ($category === 'APP ADAPTATION' || $category === 'DEVICE SPECIFIC') {
            $note = $r['flutter'];
        }
        $line('| ' . $cell($r['capability']) . ' | ' . $r['domain'] . ' | ' . $cell($note) . ' |');
    }
    $line();
}

$appMissing = array_values(array_filter($rows, fn ($r) => $r['category'] === 'APP MISSING'));
$line('### APP MISSING (' . count($appMissing) . ')');
$line();
$line('Recorded for PART 11: after each web wave, the corresponding app feature is audited against it. None of these');
$line('is a reason to remove anything from the web.');
$line();
$line('| Capability | Domain | Where it exists on the web |');
$line('|---|---|---|');
foreach ($appMissing as $r) {
    $line('| ' . $cell($r['capability']) . ' | ' . $r['domain'] . ' | ' . $cell($r['web']) . ' |');
}
$line();
$line('---');
$line();
$line('## 5. Domains');
$line();
$line('The full record — every capability with the app, web and server evidence behind its classification — is one');
$line('file per domain, so each stays readable in a browser. The counts here are the same records the matrix above');
$line('is built from.');
$line();
$line('| Domain | Capabilities | WEB MISSING | Detail |');
$line('|---|---:|---:|---|');
foreach ($domains as $d) {
    $slug = $slugs[$d['domain']];
    $mine = array_values(array_filter($rows, fn ($r) => $r['domain'] === $slug));
    $counts = $countBy($mine, 'category');
    $line('| ' . $d['domain'] . ' | ' . count($mine) . ' | ' . ($counts['WEB MISSING'] ?? 0)
        . ' | [parity/' . $slug . '.md](parity/' . $slug . '.md) |');
}
$line();
$line('---');
$line();
$line('## 6. What this document obliges');
$line();
$line('1. **No capability may remain phone-only.** The ' . $byCategory['WEB MISSING'] . ' WEB MISSING rows are the');
$line('   backlog for the Web Seller Center; a wave is not finished while one of its rows is open.');
$line('2. **Nothing here licenses a deletion.** A capability absent from the visual prototype but present in this');
$line('   matrix is preserved, moved or improved — never dropped for looking unfamiliar (PART 15).');
$line('3. **One rule, one place.** Where a row names a calculation, threshold or status, the server owns it and both');
$line('   clients read it. A client that recomputes it is a defect, not an optimisation (PART 7).');
$line('4. **The permission column is the contract.** UI hiding is presentation; the named permission is enforced');
$line('   server-side for the web session and the API token alike (PART 5).');
$line('5. **This file is regenerated, not hand-edited.** It is rendered from the audit records; correcting a');
$line('   classification means correcting the record and re-rendering, so the registers and the domain sections');
$line('   can never disagree.');
$line();

file_put_contents(__DIR__ . '/../SELLER_WEB_APP_PARITY.md', implode("\n", $out) . "\n");
echo "written: " . number_format(filesize(__DIR__ . '/../SELLER_WEB_APP_PARITY.md')) . " bytes, " . count($out) . " lines\n";

/* One file per domain: the same records, with the evidence that proves each classification. */
@mkdir(__DIR__, 0755, true);

foreach ($domains as $d) {
    $slug = $slugs[$d['domain']];
    $mine = array_values(array_filter($rows, fn ($r) => $r['domain'] === $slug));
    $counts = $countBy($mine, 'category');

    $out = [];
    $line = static function (string $text = '') use (&$out): void { $out[] = $text; };

    $line('# Parity — ' . $d['domain']);
    $line();
    $line('[← back to the matrix](../SELLER_WEB_APP_PARITY.md) · ' . count($mine) . ' capabilities');
    $line();
    $summary = [];
    foreach ($CATEGORIES as $category) {
        if (!empty($counts[$category])) {
            $summary[] = '**' . $counts[$category] . '** ' . $category;
        }
    }
    $line(implode(' · ', $summary));
    $line();
    $line('## Structural facts the implementer must know');
    $line();
    $line('```');
    $line(rtrim($d['notes']));
    $line('```');
    $line();

    foreach ($CATEGORIES as $category) {
        $items = array_values(array_filter($mine, fn ($r) => $r['category'] === $category));
        if ($items === []) {
            continue;
        }
        $line('## ' . $category . ' (' . count($items) . ')');
        $line();
        foreach ($items as $r) {
            $line('**' . trim($r['capability']) . '**  ');
            $line('`' . ($r['permission'] ?? 'no permission recorded') . '`'
                . ($category === 'WEB MISSING' ? ' · wave ' . $r['wave'] : '') . '  ');
            $line('- App — ' . trim($r['flutter']));
            $line('- Web — ' . trim($r['web']));
            $line('- Server — ' . trim($r['backend']));
            if ($evidenceAdds($r)) {
                $line('- Evidence — ' . trim($r['evidence']));
            }
            $line();
        }
    }

    file_put_contents(__DIR__ . '/' . $slug . '.md', implode("\n", $out) . "\n");
}

foreach (glob(__DIR__ . '/*.md') as $file) {
    echo str_pad(basename($file), 30) . number_format(filesize($file)) . "\n";
}
