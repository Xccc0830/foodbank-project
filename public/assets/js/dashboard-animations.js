/**
 * Dashboard motion enhancements.
 * Content remains visible when JavaScript or intersection observers are unavailable.
 */
(function () {
    'use strict';

    function initializeDashboardMotion() {
        const dashboard = document.querySelector('.dashboard-page');
        if (!dashboard) {
            return;
        }

        document.body.classList.add('dashboard-motion-page');

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const revealTargets = Array.from(dashboard.querySelectorAll(
            '.dashboard-section-heading, ' +
            '.dashboard-insight-card, ' +
            '.dashboard-impact-section, ' +
            '.dashboard-recent-card, ' +
            '.dashboard-quick-card'
        ));

        function revealAll() {
            dashboard.classList.remove('dashboard-motion-ready');
            revealTargets.forEach(function (target) {
                target.classList.add('is-visible');
            });
        }

        if (reducedMotion.matches || !('IntersectionObserver' in window)) {
            revealAll();
            return;
        }

        revealTargets.forEach(function (target, index) {
            target.classList.add('dashboard-reveal');
            target.style.setProperty('--dashboard-reveal-delay', ((index % 3) * 70) + 'ms');
        });
        dashboard.classList.add('dashboard-motion-ready');

        function animateBadge(badge) {
            if (!badge || badge.dataset.motionAnimated === 'true') {
                return;
            }

            const originalText = badge.textContent.trim();
            const match = originalText.match(/^(\d+)(.*)$/);
            if (!match || Number(match[1]) < 1) {
                badge.dataset.motionAnimated = 'true';
                return;
            }

            const target = Number(match[1]);
            const suffix = match[2];
            const duration = 560;
            const startedAt = performance.now();
            badge.dataset.motionAnimated = 'true';
            badge.setAttribute('aria-label', originalText);

            function update(timestamp) {
                const progress = Math.min((timestamp - startedAt) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                badge.textContent = Math.round(target * eased) + suffix;
                if (progress < 1) {
                    window.requestAnimationFrame(update);
                }
            }

            window.requestAnimationFrame(update);
        }

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('is-visible');
                animateBadge(entry.target.querySelector('.dashboard-count-badge'));
                observer.unobserve(entry.target);
            });
        }, {
            threshold: 0.12,
            rootMargin: '0px 0px -48px 0px'
        });

        revealTargets.forEach(function (target) {
            observer.observe(target);
        });

        function handleMotionPreference(event) {
            if (!event.matches) {
                return;
            }

            observer.disconnect();
            revealAll();
        }

        if (typeof reducedMotion.addEventListener === 'function') {
            reducedMotion.addEventListener('change', handleMotionPreference);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeDashboardMotion);
    } else {
        initializeDashboardMotion();
    }
})();
