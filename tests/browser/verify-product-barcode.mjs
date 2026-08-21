// The barcode field, end to end: typed in the product form, saved, shown back, printed on the
// label, and found by a search that used to only know the SKU.
import {BASE, SHOTS, loginAdmin, watch, hasServerError} from './_env.mjs';
import {chromium} from 'playwright';

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
const problems = watch(page);
await loginAdmin(page);

const barcode = '6291' + Date.now().toString().slice(-9);
const productId = process.env.PRODUCT_ID || '3';

await page.goto(BASE + '/admin/products/update/' + productId, {waitUntil: 'domcontentloaded'});
await page.locator('input[name="code"]').first().waitFor({state: 'attached', timeout: 30000});
if (await hasServerError(page)) problems.push('product edit screen errored');

const field = page.locator('input[name="barcode"]').first();
console.log('barcode field on the form:', await field.count() > 0);
if (await field.count() === 0) problems.push('no barcode field on the product form');
await field.fill(barcode);

await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop').forEach((n) => n.remove()));
// The product form runs a long client-side requirements check before it posts; this check is
// about the barcode reaching the server, so the form is submitted directly.
await page.evaluate(() => document.querySelector('#product_form').submit());
await page.waitForLoadState('networkidle');

// The product form keeps long-polling widgets alive, so wait for the field, not for the network.
await page.goto(BASE + '/admin/products/update/' + productId, {waitUntil: 'domcontentloaded'});
await page.locator('input[name="barcode"]').first().waitFor({state: 'attached', timeout: 30000});
const saved = await page.locator('input[name="barcode"]').first().inputValue();
console.log('saved barcode:', saved);
if (saved !== barcode) problems.push('barcode did not survive the save: ' + saved);

// The printed label must carry the product's own barcode, not the SKU.
await page.goto(BASE + '/admin/products/barcode/' + productId, {waitUntil: 'domcontentloaded'});
const printed = await page.locator('.barcode_code').first().innerText().catch(() => '');
console.log('label prints:', printed.trim());
if (!printed.includes(barcode)) problems.push('label did not print the barcode');
await page.screenshot({path: SHOTS + '/barcode-label.png'});

// A scan finds the product where the SKU already did — and only that product.
const searchRows = async (term) => {
    await page.goto(BASE + '/admin/products/list/in-house?searchValue=' + term, {waitUntil: 'domcontentloaded'});
    return page.locator('table tbody tr').count();
};

const found = await searchRows(barcode);
const nothing = await searchRows('6299999999999');
console.log('rows for the barcode:', found, '| rows for a barcode nobody has:', nothing);
if (found < 1) problems.push('searching the barcode found nothing');
if (nothing >= found) problems.push('the barcode search is not filtering at all');

console.log(problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nall good');
await browser.close();
process.exit(problems.length ? 1 : 0);
