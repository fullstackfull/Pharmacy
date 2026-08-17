/**
 * Kohl runtime.
 *
 * Deliberately dependency-free and tiny: it runs alongside 41 existing admin
 * scripts, so it may not assume jQuery, Bootstrap or any load order, and it may
 * not touch anything it did not render itself.
 */

const THEME_KEY = 'k-theme';

/**
 * Apply the stored colour-scheme choice.
 *
 * Three states, matching the token layer: 'dark' and 'light' stamp the root and
 * win over the OS; no stored value leaves the attribute off so the OS preference
 * decides. Stamping 'light' by default would override a user's dark OS setting.
 */
function applyStoredTheme() {
    let stored = null;
    try {
        stored = window.localStorage.getItem(THEME_KEY);
    } catch (error) {
        return;                       // private mode / storage disabled — follow the OS
    }

    if (stored === 'dark' || stored === 'light') {
        document.documentElement.setAttribute('data-k-theme', stored);
    } else {
        document.documentElement.removeAttribute('data-k-theme');
    }
}

function setTheme(value) {
    try {
        if (value === 'system') {
            window.localStorage.removeItem(THEME_KEY);
        } else {
            window.localStorage.setItem(THEME_KEY, value);
        }
    } catch (error) {
        /* not fatal — the choice just will not persist */
    }
    applyStoredTheme();
}

/** The direction the document is actually rendering in, for components that must know. */
function direction() {
    return document.documentElement.getAttribute('dir') === 'rtl' ? 'rtl' : 'ltr';
}

applyStoredTheme();

window.Kohl = Object.assign(window.Kohl || {}, {
    version: '0.1.0',
    setTheme,
    direction,
});
