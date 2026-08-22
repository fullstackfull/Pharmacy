import {BASE, SHOTS, loginAdmin, watch, hasServerError} from './_env.mjs';
import {chromium} from 'playwright';

/*
 | Every Developer Portal section, rendered as a real administrator.
 |
 | The portal reflects on 439 controller methods and reads their source; a Blade error in one
 | section would only show up when somebody opened that section, which is exactly the kind of
 | breakage a screenshot check exists to catch before a merchant finds it.
 */
const SECTIONS = [
    'overview', 'quick_start', 'explorer', 'customer_app', 'vendor_app', 'delivery_app',
    'partner', 'authentication', 'errors', 'rate_limits', 'pagination', 'uploads',
    'versions', 'changelog', 'deprecations', 'quality', 'health', 'openapi', 'postman',
    'models', 'console', 'debugger', 'webhooks', 'integrations', 'settings',
];

// The pinned Playwright expects a build number this image does not carry, so the browser that IS
// here is named explicitly rather than downloading a second copy of Chromium into the container.
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'});
const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
const page = await context.newPage();
const problems = [];
watch(page, problems);

await page.emulateMedia({reducedMotion: 'reduce'});
await loginAdmin(page);

for (const section of SECTIONS) {
    const response = await page.goto(`${BASE}/admin/developer/${section}`, {waitUntil: 'domcontentloaded'});
    const status = response?.status() ?? 0;
    if (status !== 200) {
        problems.push(`${section}: HTTP ${status}`);
        continue;
    }
    if (await hasServerError(page)) {
        problems.push(`${section}: server error in the rendered page`);
        continue;
    }

    // A section that rendered but produced nothing is as broken as one that threw.
    const rendered = await page.locator('.dev-body').innerText().catch(() => '');
    if (rendered.trim().length < 20) {
        problems.push(`${section}: rendered empty`);
    }

    console.log(`${section.padEnd(16)} ${status}  ${rendered.trim().slice(0, 60).replace(/\s+/g, ' ')}`);
}

// One endpoint page, since that is a separate template with its own data.
await page.goto(`${BASE}/admin/developer/explorer`, {waitUntil: 'domcontentloaded'});
const firstEndpoint = page.locator('.dev-row').first();
if (await firstEndpoint.count() > 0) {
    await firstEndpoint.click();
    await page.waitForLoadState('domcontentloaded');
    if (await hasServerError(page)) {
        problems.push('endpoint page: server error');
    } else {
        const heading = await page.locator('.dev-endpoint-head__path').innerText().catch(() => '');
        console.log(`endpoint page     200  ${heading}`);
        await page.screenshot({path: `${SHOTS}/developer-endpoint.png`, fullPage: true});
    }
} else {
    problems.push('explorer listed no endpoints');
}

await page.goto(`${BASE}/admin/developer/overview`, {waitUntil: 'domcontentloaded'});
await page.screenshot({path: `${SHOTS}/developer-overview.png`, fullPage: true});

await browser.close();

if (problems.length) {
    console.error('\nPROBLEMS:\n' + problems.map(p => ' - ' + p).join('\n'));
    process.exit(1);
}
console.log('\nEvery section rendered.');
