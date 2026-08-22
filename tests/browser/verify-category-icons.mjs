// The sub-category icon field, end to end: it exists on every level's form, an upload survives
// the save, and it shows in the list. Plus the bare /admin/category/update URL must not 500.
import {BASE, SHOTS, loginAdmin, watch, hasServerError} from './_env.mjs';
import {chromium} from 'playwright';

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
await page.emulateMedia({reducedMotion: 'reduce'});
const problems = watch(page);
await loginAdmin(page);

const clean = () => page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal-backdrop,.alert--container').forEach((n) => n.remove()));

// 1. The bare update URL — reported as still 500ing.
const bare = await page.goto(BASE + '/admin/category/update', {waitUntil: 'domcontentloaded'});
console.log('GET /admin/category/update  ->', bare.status());
if (bare.status() >= 500) problems.push('bare /admin/category/update returned ' + bare.status());
if (await hasServerError(page)) problems.push('bare /admin/category/update rendered a server error page');

// 2. Editing a real sub-category.
await page.goto(BASE + '/admin/sub-category/view', {waitUntil: 'domcontentloaded'});
await clean();
const firstEdit = page.locator('a.edit[href^="#categoryEditOffcanvas"]').first();
const hasSub = await firstEdit.count() > 0;
console.log('sub-categories present:', hasSub);

if (hasSub) {
    const iconCells = await page.locator('table.k-table tbody tr td img, table.k-table tbody tr td .category-icon-placeholder').count();
    console.log('icon cells rendered in the list:', iconCells);
    if (iconCells === 0) problems.push('the sub-category list shows no icon column');

    await firstEdit.click();
    await page.waitForTimeout(600);
    const field = page.locator('.offcanvas.show input[type=file][name="image"]');
    const visible = await field.count() > 0;
    console.log('icon upload on the sub-category edit form:', visible);
    if (!visible) problems.push('no icon upload field on the sub-category edit form');
    await page.screenshot({path: SHOTS + '/subcategory-edit-icon.png'});
}

// 3. The add form for a sub-category.
await page.goto(BASE + '/admin/sub-category/view', {waitUntil: 'domcontentloaded'});
await clean();
await page.locator('[href="#categoryAddOffcanvas"]').first().click();
await page.waitForTimeout(600);
const addField = await page.locator('.offcanvas.show input[type=file][name="image"]').count();
console.log('icon upload on the sub-category add form:', addField > 0);
if (addField === 0) problems.push('no icon upload field on the sub-category add form');
await page.screenshot({path: SHOTS + '/subcategory-add-icon.png'});

// 4. Sub-sub-category level too.
await page.goto(BASE + '/admin/sub-sub-category/view', {waitUntil: 'domcontentloaded'});
await clean();
if (await hasServerError(page)) problems.push('sub-sub-category page errored');
await page.locator('[href="#categoryAddOffcanvas"]').first().click();
await page.waitForTimeout(600);
const subSubField = await page.locator('.offcanvas.show input[type=file][name="image"]').count();
console.log('icon upload on the sub-sub-category add form:', subSubField > 0);
if (subSubField === 0) problems.push('no icon upload field on the sub-sub-category add form');

// 5. The part that matters: an uploaded icon must survive the save and come back in the list.
await page.goto(BASE + '/admin/sub-category/view', {waitUntil: 'domcontentloaded'});
await clean();
const editLink = page.locator('a.edit[href^="#categoryEditOffcanvas"]').first();
const targetId = (await editLink.getAttribute('href')).split('-').pop();
await editLink.click();
await page.waitForTimeout(600);
await page.locator('.offcanvas.show input[type=file][name="image"]').setInputFiles('/tmp/sub-icon.png');
await page.waitForTimeout(400);
// The <form> WRAPS the offcanvas rather than sitting inside it.
await page.locator('form:has(.offcanvas.show)').first().evaluate((form) => form.submit());
await page.waitForLoadState('domcontentloaded');
await page.waitForTimeout(1500);

await page.goto(BASE + '/admin/sub-category/view', {waitUntil: 'domcontentloaded'});
const savedIcon = await page.locator(`table.k-table tbody tr:has-text("#${targetId}") td img`).count();
console.log('sub-category #' + targetId + ' shows a real icon after upload:', savedIcon > 0);
if (savedIcon === 0) problems.push('the uploaded sub-category icon did not survive the save');
await page.screenshot({path: SHOTS + '/subcategory-list-icons.png'});

console.log(problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nall good');
await browser.close();
process.exit(problems.length ? 1 : 0);
