// Live check for the twelve offers/content sections added to the Theme Builder:
// each one paints, the interactive pieces answer, and nothing throws in the console.
import {BASE, SHOTS, watch} from './_env.mjs';
import {chromium} from 'playwright';

const shots = SHOTS;
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
// Reduced motion makes the page hold still: reveals land instantly and rails stop auto-scrolling,
// so a click lands where the locator saw the element. The count-up still ends on its real value.
await page.emulateMedia({reducedMotion: 'reduce'});
const problems = watch(page);

await page.goto(BASE + '/', {waitUntil: 'networkidle'});
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container,.offcanvas,.offcanvas-backdrop')
    .forEach((node) => node.remove()));
await page.evaluate(() => { document.body.style.overflow = 'auto'; });

const sections = ['deal_of_the_day', 'featured_deal', 'clearance_sale', 'coupon_strip', 'stats_bar', 'bundle',
    'interest_tiles', 'stories', 'blog_posts', 'branches', 'shipping_cutoff', 'before_after'];

for (const type of sections) {
    const node = page.locator('.tbs-' + type).first();
    const painted = await node.count() > 0 && await node.isVisible();
    console.log((painted ? 'ok   ' : 'MISS ') + type);
    if (!painted) problems.push('section not painted: ' + type);
}

// Stats count up to their real value rather than sitting at zero.
await page.locator('.tbs-stats_bar').first().scrollIntoViewIfNeeded();
await page.waitForTimeout(1600);
const counted = await page.locator('.tbs-stats_bar [data-ml-count]').first().innerText();
console.log('stat reads:', counted.trim());
if (/^0\D*$/.test(counted.trim())) problems.push('stat counter never counted up');

// A coupon copies and says so. Cards fade in on scroll, so let the reveal settle before clicking.
await page.locator('[data-ml-copy]').first().scrollIntoViewIfNeeded();
await page.waitForTimeout(700);
await page.locator('[data-ml-copy]').first().click();
await page.waitForTimeout(200);
const copied = await page.locator('[data-ml-copy]').first().evaluate((node) => node.classList.contains('is-copied'));
console.log('coupon copied:', copied);
if (!copied) problems.push('coupon copy gave no feedback');

// The before/after handle moves the reveal.
const reveal = page.locator('.ml-ba').first();
await reveal.scrollIntoViewIfNeeded();
await reveal.locator('.ml-ba__range').fill('20');
await reveal.locator('.ml-ba__range').dispatchEvent('input');
const width = await reveal.locator('.ml-ba__after').evaluate((node) => node.style.width);
console.log('before/after reveal:', width);
if (width !== '20%') problems.push('before/after slider did not move the reveal');

// A story opens full screen and closes again. The sticky header would otherwise sit over the
// dot at the scroll position Playwright picks, so it is taken out of the way first.
await page.evaluate(() => document.querySelectorAll('header, .phpdebugbar').forEach((node) => node.remove()));
await page.waitForTimeout(700);
await page.locator('[data-ml-story]').first().click();
await page.waitForTimeout(400);
const viewerOpen = await page.locator('.ml-story-viewer').first().isVisible();
console.log('story viewer opens:', viewerOpen);
if (!viewerOpen) problems.push('story viewer did not open');
await page.screenshot({path: shots + '/new-sections-story.png'});
await page.locator('.ml-story-viewer__close').first().click();
await page.waitForTimeout(300);

// The bundle button adds every product of the set to the cart: the check is the cart itself,
// read back through the storefront's own nav-cart endpoint.
const cartLines = () => page.evaluate(async () => {
    const url = document.querySelector('#route-cart-nav-cart').dataset.url;
    const body = new FormData();
    body.append('_token', document.querySelector('meta[name="_token"]').content);
    const response = await fetch(url, {method: 'POST', body});
    const payload = await response.json();
    return (payload.data.match(/cart-item|cart_item/g) || []).length;
});

const before = await cartLines();
await page.locator('[data-ml-bundle]').first().scrollIntoViewIfNeeded();
await page.locator('[data-ml-bundle]').first().click();
await page.waitForTimeout(3000);
const after = await cartLines();
console.log('cart lines:', before, '->', after);
if (after <= before) problems.push('bundle button did not add the set to the cart');

await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal.show,.modal-backdrop,.offcanvas.show,.offcanvas-backdrop')
    .forEach((node) => node.remove()));
await page.screenshot({path: shots + '/new-sections-full.png', fullPage: true});

// The framework serves its own placeholder from /public/..., which this staging docroot does not
// map; that 404 predates these sections and is not what this check is about.
const real = problems.filter((problem) => !problem.includes('img/placeholder/placeholder-'));
console.log(real.length ? '\nPROBLEMS:\n' + real.join('\n') : '\nall good');
await browser.close();
process.exit(real.length ? 1 : 0);
