import {BASE, SHOTS, loginAdmin, watch, hasServerError} from './_env.mjs';
import {chromium} from 'playwright';

/*
 | Every Analytics section, rendered as a real administrator.
 |
 | The area reads from four tables and splices live data into today, so a section that throws only
 | does so when somebody opens it with the wrong shape of data underneath. That is precisely what a
 | render check is for.
 */
const SECTIONS = [
    'overview', 'live', 'acquisition', 'campaigns', 'audience', 'retention',
    'behaviour', 'catalogue', 'search', 'vendors', 'funnel', 'revenue',
    'timing', 'events', 'journeys', 'quality', 'settings',
];

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'});
const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
const page = await context.newPage();
const problems = [];
watch(page, problems);

await page.emulateMedia({reducedMotion: 'reduce'});
await loginAdmin(page);

for (const section of SECTIONS) {
    const response = await page.goto(`${BASE}/admin/analytics/${section}`, {waitUntil: 'domcontentloaded'});
    const status = response?.status() ?? 0;

    if (status !== 200) { problems.push(`${section}: HTTP ${status}`); continue; }
    if (await hasServerError(page)) { problems.push(`${section}: server error`); continue; }

    const rendered = (await page.locator('.ana-body').innerText().catch(() => '')).trim();
    if (rendered.length < 20) { problems.push(`${section}: rendered empty`); continue; }

    console.log(`${section.padEnd(12)} ${status}  ${rendered.slice(0, 62).replace(/\s+/g, ' ')}`);
}

// Every range, on the busiest section — the window maths is where an off-by-one hides.
for (const range of ['today', '7d', '30d', '90d', '365d']) {
    const response = await page.goto(`${BASE}/admin/analytics/overview?range=${range}`, {waitUntil: 'domcontentloaded'});
    if (response?.status() !== 200 || await hasServerError(page)) {
        problems.push(`range ${range}: failed`);
    } else {
        const window = await page.locator('.ana-window').innerText().catch(() => '');
        console.log(`range ${range.padEnd(6)} 200  ${window.split('\n')[0].slice(0, 56)}`);
    }
}

// A custom range, and a reversed one, which must be corrected rather than returning nothing.
for (const query of ['from=2026-08-01&to=2026-08-22', 'from=2026-08-22&to=2026-08-01']) {
    const response = await page.goto(`${BASE}/admin/analytics/overview?${query}`, {waitUntil: 'domcontentloaded'});
    if (response?.status() !== 200 || await hasServerError(page)) problems.push(`custom range ${query}: failed`);
}
console.log('custom ranges 200');

// The CSV export must actually stream a file.
const download = await page.evaluate(async (base) => {
    const res = await fetch(`${base}/admin/analytics/export/source?range=30d`, {credentials: 'include'});
    return {status: res.status, type: res.headers.get('content-type'), body: (await res.text()).slice(0, 200)};
}, BASE);
if (download.status !== 200 || !String(download.type).includes('csv')) {
    problems.push(`export: HTTP ${download.status} type ${download.type}`);
} else {
    console.log(`export        200  ${download.body.split('\n')[0].slice(0, 60)}`);
}

await page.goto(`${BASE}/admin/analytics/overview`, {waitUntil: 'domcontentloaded'});
await page.screenshot({path: `${SHOTS}/analytics-overview.png`, fullPage: true});

await browser.close();

if (problems.length) {
    console.error('\nPROBLEMS:\n' + problems.map(p => ' - ' + p).join('\n'));
    process.exit(1);
}
console.log('\nEvery Analytics section rendered.');
