/**
 * Shared setup for browser checks against the local staging app.
 *
 * Staging only: BASE points at 127.0.0.1 and the credentials are the throwaway
 * staging admin created during environment setup. Nothing here may be pointed at
 * a production host.
 */
export const BASE = process.env.APP_BASE || 'http://127.0.0.1:8000';
export const ADMIN = {
    email: process.env.STAGING_ADMIN_EMAIL || 'staging.qa@localhost.test',
    password: process.env.STAGING_ADMIN_PASSWORD || 'StagingQA!2026',
    // The controller gates the admin login page on the literal primary key id==1, so any other
    // account - including one holding the Master Admin role - must sign in via the employee page.
    loginPath: '/login/employee',
    role: 'employee',
};
export const SHOTS = process.env.SHOT_DIR || '/tmp/shots';

export const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 834, height: 1112 },
    { name: 'mobile', width: 390, height: 844 },
];

/** Collects console errors and failed requests so a "looks fine" screenshot cannot hide a broken page. */
export function watch(page) {
    const problems = [];
    page.on('console', m => {
        if (m.type() !== 'error') return;
        // Duplicates the requestfailed/response listeners below, which carry the
        // URL and so can tell sandbox noise from an app failure. Skip the echo.
        if (m.text().startsWith('Failed to load resource')) return;
        problems.push('CONSOLE ' + m.text().slice(0, 200));
    });
    page.on('pageerror', e => problems.push('JS ' + String(e).slice(0, 200)));
    page.on('requestfailed', r => {
        // The sandbox blocks external origins (Google Fonts et al.) — expected
        // here, fine in production. Same-origin failures are real problems,
        // except ERR_ABORTED: that is the browser cancelling in-flight loads
        // because the check itself navigated away.
        if (!r.url().startsWith(BASE)) return;
        const reason = r.failure()?.errorText || '';
        if (reason === 'net::ERR_ABORTED') return;
        problems.push('REQFAIL ' + reason + ' ' + r.url().replace(BASE, '').slice(0, 120));
    });
    page.on('response', r => {
        if (r.status() >= 400) problems.push(`HTTP ${r.status()} ${r.url().replace(BASE, '').slice(0, 120)}`);
    });
    return problems;
}

/** The store's promotional popup (#popup-modal) opens over the page and eats
 *  clicks; real shoppers close it, so click-driven checks must too. It shows
 *  itself on document.ready, so a single early hide() races it — keep asking
 *  until it is genuinely gone. */
export async function dismissPromoPopup(page) {
    await page.waitForTimeout(700);
    for (let attempt = 0; attempt < 5; attempt++) {
        const gone = await page.evaluate(() => {
            const modal = document.getElementById('popup-modal');
            if (!modal || !modal.classList.contains('show')) return true;
            if (window.jQuery) window.jQuery(modal).modal('hide');
            return false;
        }).catch(() => true);
        if (gone) break;
        await page.waitForTimeout(400);
    }
    await page.waitForFunction(() => !document.querySelector('.modal-backdrop.show'), null, { timeout: 3000 })
        .catch(() => {});
}

export async function loginAdmin(page) {
    await page.goto(BASE + ADMIN.loginPath, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', ADMIN.email);
    await page.fill('input[name="password"]', ADMIN.password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1200);
    return page.url();
}

/**
 * Switch the session to Arabic (rtl) or English (ltr).
 *
 * The app stores direction in the session via POST /change-language, so RTL cannot
 * be faked by setting an attribute — the server has to render it. This drives the
 * real endpoint, which is what makes the RTL screenshots trustworthy.
 */
export async function setDirection(page, dir) {
    const code = dir === 'rtl' ? 'sy' : 'en';
    // The single-process dev server drops connections under load, which leaves the
    // csrf meta unread and turns the POST into a 419 — so try up to three times.
    for (let attempt = 1; attempt <= 3; attempt++) {
        await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' }).catch(() => {});
        // The storefront emits <meta name="_token">; the admin emits "csrf-token".
        // Reading only the admin's name is what silently 419'd every RTL run.
        const token = await page.evaluate(() => {
            const meta = document.querySelector('meta[name="_token"], meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : null;
        }).catch(() => null);
        if (!token) continue;
        const status = await page.evaluate(async ({ base, code, token }) => {
            const body = new URLSearchParams({ language_code: code });
            const res = await fetch(base + '/change-language', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token || '' },
                body,
            }).catch(() => null);
            return res ? res.status : 0;
        }, { base: BASE, code, token });
        if (status === 200) return status;
    }
    return 0;
}

/**
 * Does the page show a Laravel error screen?
 *
 * Searching the body text for "Exception" matches the debug bar's own Exceptions
 * tab on a perfectly healthy page, which reads as a failure that is not there.
 * Look for the error page's actual structure instead.
 */
export async function hasServerError(page) {
    return page.evaluate(() => {
        if (document.querySelector('.exception, #ignition, .Whoops, [class*="whoops"]')) return true;
        const title = (document.title || '').toLowerCase();
        return /server error|whoops|not found|forbidden/.test(title);
    });
}
