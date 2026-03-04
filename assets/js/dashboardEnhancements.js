import $ from 'jquery';

export function initDashboardEnhancements() {
    const $dashboard = $('.dashboard-modern');

    if ($dashboard.length === 0) {
        return;
    }

    const cards = document.querySelectorAll('.dashboard-modern .triggerHide');

    if ('IntersectionObserver' in window && cards.length > 0) {
        const observer = new IntersectionObserver((entries, currentObserver) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in-view');
                    currentObserver.unobserve(entry.target);
                }
            });
        }, {
            rootMargin: '0px 0px -10% 0px',
            threshold: 0.1
        });

        cards.forEach((card) => observer.observe(card));
    } else {
        cards.forEach((card) => card.classList.add('in-view'));
    }

    const intro = document.querySelector('.dashboard-intro');

    if (intro) {
        intro.addEventListener('mousemove', (event) => {
            const bounds = intro.getBoundingClientRect();
            const x = (event.clientX - bounds.left) / bounds.width;
            const y = (event.clientY - bounds.top) / bounds.height;
            intro.style.setProperty('--intro-glow-x', `${x * 100}%`);
            intro.style.setProperty('--intro-glow-y', `${y * 100}%`);
        });
    }
}
