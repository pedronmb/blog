/* Shared by PHP pages and autonomous HTML. Runs before CSS to avoid a light flash. */
(() => {
    'use strict';
    const key = 'solidchain-theme';
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    let preference = null;
    let button;

    function stored(value) {
        return value === 'light' || value === 'dark' ? value : null;
    }

    try { preference = stored(localStorage.getItem(key)); } catch (_) { /* Storage may be disabled. */ }

    function apply() {
        const theme = preference || (system.matches ? 'dark' : 'light');
        root.dataset.theme = theme;
        if (button) {
            const label = theme === 'dark' ? 'Activar modo claro' : 'Activar modo oscuro';
            button.setAttribute('aria-label', label);
            button.title = label;
        }
    }

    apply();

    function initialize() {
        const navigation = document.querySelector('.site-header .main-nav');
        if (!navigation || navigation.querySelector('.theme-toggle')) { return; }
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'theme-toggle';
        button.innerHTML = '<svg class="theme-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.4 13.2A8.5 8.5 0 0 1 10.8 3.6 8.5 8.5 0 1 0 20.4 13.2Z"/></svg><svg class="theme-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.4 1.4m11.2 11.2L19 19M5 19l1.4-1.4M17.6 6.4 19 5"/></svg>';
        button.addEventListener('click', () => {
            preference = root.dataset.theme === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem(key, preference); } catch (_) { /* The control still works without storage. */ }
            apply();
        });
        navigation.append(button);
        apply();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else { initialize(); }
    system.addEventListener('change', () => { if (!preference) { apply(); } });
    window.addEventListener('storage', event => {
        if (event.key === key || event.key === null) {
            preference = stored(event.newValue);
            apply();
        }
    });
})();
