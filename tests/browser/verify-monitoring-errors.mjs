/**
 * Errors section check.
 *
 * Argv: [2] a group id that has occurrence rows, [3] a group id that has none.
 * Fixtures are seeded by the caller immediately before this runs, because this staging database
 * is shared and gets reset underneath long-running checks.
 */
import {BASE, SHOTS, loginAdmin, watch, hasServerError} from './_env.mjs';
import {chromium} from 'playwright';

const WITH_OCCURRENCES = process.argv[2];
const WITHOUT_OCCURRENCES = process.argv[3];

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await page.emulateMedia({reducedMotion: 'reduce'});
const problems = watch(page);
await loginAdmin(page);

async function open(label, url) {
    const res = await page.goto(BASE + url, {waitUntil: 'domcontentloaded'});
    if (res.status() >= 400) problems.push(label + ' returned ' + res.status());
    if (await hasServerError(page)) problems.push(label + ' rendered a server error');
    await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));
    const body = await page.evaluate(() => document.body.innerText);
    for (const word of ['Warning:', 'Deprecated:', 'Undefined ', 'Fatal error', 'ErrorException']) {
        if (body.includes(word)) problems.push(label + ' printed a PHP ' + word.trim());
    }
    const rows = await page.locator('.mon-main .k-table tbody tr').count();
    console.log(label, '->', res.status(), '| table rows:', rows);
    return {body, rows};
}

const list = await open('list   status=all', '/admin/monitoring/errors?range=24h&status=all');
console.log('   stat tiles:', await page.locator('.k-stat').count(), '| pager:', await page.locator('.k-pager').count(),
    '| filter controls:', await page.locator('.k-view__toolbar select, .k-view__toolbar input[type=search]').count());
if (list.rows !== 25) problems.push('expected 25 rows on page one, got ' + list.rows);
if (await page.locator('.k-stat').count() < 4) problems.push('missing stat tiles');
await page.screenshot({path: SHOTS + '/monitoring-errors.png', fullPage: true});

const second = await open('page 2', '/admin/monitoring/errors?range=24h&status=all&page=2');
if (second.rows < 1) problems.push('page two is empty');

const detail = await open('group with occurrences', '/admin/monitoring/errors?range=24h&status=all&group=' + WITH_OCCURRENCES);
const trace = await page.locator('#mon-error-group .mon-pre').innerText();
console.log('   detail cards:', await page.locator('#mon-error-group').count(), '| trace chars:', trace.length,
    '| metrics:', await page.locator('#mon-error-group .mon-metric').count());
if (!trace.includes('#0 /var/www')) problems.push('stack trace missing from the detail card');
if (!trace.includes('[redacted]')) problems.push('stack trace was not redacted');
for (const secret of ['sk_live_', 'hunter2', '4111 1111']) {
    if (detail.body.includes(secret)) problems.push('UNREDACTED SECRET rendered: ' + secret);
}
await page.screenshot({path: SHOTS + '/monitoring-errors-group.png', fullPage: true});

const bare = await open('group with no occurrence rows', '/admin/monitoring/errors?range=24h&status=all&group=' + WITHOUT_OCCURRENCES);
if (!bare.body.includes('No occurrence rows for this group fall inside this window')) {
    problems.push('a group with no occurrence rows did not say so');
}

const search = await open('search  q=Lock wait', '/admin/monitoring/errors?range=24h&status=all&q=Lock%20wait');
if (search.rows < 1) problems.push('search found nothing it should have found');

const wildcard = await open('search  q=% (literal, not an operator)', '/admin/monitoring/errors?range=24h&status=all&q=%25');
if (wildcard.rows > 0) problems.push('a bare % matched rows, so LIKE wildcards are not escaped');

const missed = await open('filtered to nothing', '/admin/monitoring/errors?range=24h&channel=nowhere');
console.log('   empty state:', await page.locator('.k-empty__title').innerText());
if (!missed.body.includes('No error groups match these filters')) problems.push('filtered-empty state is wrong');

await open('default view  status=open', '/admin/monitoring/errors?range=24h');
await open('deep page is capped', '/admin/monitoring/errors?range=24h&status=all&page=99999');
await open('unknown group id', '/admin/monitoring/errors?range=24h&group=99999999');
await open('json payload', '/admin/monitoring/errors?range=24h&json=1');
await open('narrow window (live)', '/admin/monitoring/errors?range=live');

await page.setViewportSize({width: 390, height: 844});
await open('mobile', '/admin/monitoring/errors?range=24h');
const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
console.log('   horizontal overflow on mobile:', overflow);
if (overflow) problems.push('the page scrolls sideways on mobile');

console.log(problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nall good');
await browser.close();
process.exit(problems.length ? 1 : 0);
