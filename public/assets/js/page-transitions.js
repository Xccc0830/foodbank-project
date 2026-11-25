/**
 * Coordinated transitions for same-application page navigation.
 */
(function () {
    'use strict';

    const body = document.body;
    if (!body || (!body.classList.contains('app-body') && !body.classList.contains('login-page'))) {
        return;
    }

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let navigating = false;
    let veil;

    function ensureVeil() {
        if (veil) {
            return veil;
        }

        veil = document.createElement('div');
        veil.className = 'page-transition-veil';
        veil.setAttribute('aria-hidden', 'true');
        veil.innerHTML =
            '<span class="page-transition-panel"></span>' +
            '<span class="page-transition-mark">' +
                '<span class="page-transition-mark-ring"></span>' +
            '</span>';
        document.body.appendChild(veil);
        return veil;
    }

    function isPageLink(link) {
        if (!link || !link.href || link.target === '_blank' || link.hasAttribute('download')) {
            return false;
        }
        if (link.closest('form') || link.getAttribute('href').startsWith('#')) {
            return false;
        }

        const destination = new URL(link.href, window.location.href);
        if (destination.origin !== window.location.origin) {
            return false;
        }

        const currentUrl = new URL(window.location.href);
        const currentPage = currentUrl.searchParams.get('page') || (body.classList.contains('app-body') ? 'dashboard' : '');
        const nextPage = destination.searchParams.get('page') || '';
        const currentAction = currentUrl.searchParams.get('action') || 'login';
        const nextAction = destination.searchParams.get('action') || '';
        const isDifferentScreen = nextPage
            ? nextPage !== currentPage
            : Boolean(nextAction && nextAction !== currentAction && nextAction !== 'logout');

        return isDifferentScreen;
    }

    function clearTransition() {
        navigating = false;
        body.classList.remove('page-transitioning', 'page-transition-arrived');
    }

    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[href]');
        if (event.defaultPrevented || navigating || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || reducedMotion.matches || !isPageLink(link)) {
            return;
        }

        event.preventDefault();
        navigating = true;
        ensureVeil();
        body.classList.add('page-transitioning');

        window.setTimeout(function () {
            window.location.assign(link.href);
        }, 260);
    }, true);

    window.addEventListener('pageshow', function () {
        clearTransition();
        body.classList.add('page-transition-arrived');
        ensureVeil();
        window.setTimeout(function () {
            body.classList.remove('page-transition-arrived');
        }, 420);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            ensureVeil();
        });
    } else {
        ensureVeil();
    }
})();
