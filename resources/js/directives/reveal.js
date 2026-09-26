// v-reveal — one-shot scroll-reveal for storefront sections.
//
// All motion is CSS (transform + opacity only, so it stays on the compositor
// thread and never triggers layout or paint storms). This directive's whole
// job is toggling a class through a single shared IntersectionObserver:
// no scroll listeners, no timers per element, no layout reads, and every
// element is unobserved the moment it has been revealed.
//
// Usage:
//   v-reveal                              fade + rise (default)
//   v-reveal="'zoom'"                     variant: fade | left | right | zoom
//   v-reveal="150"                        start 150ms after entering view
//   v-reveal="{ variant: 'left', delay: 150 }"
//   v-reveal="false"                      off - e.g. the first row of a grid,
//                                         which is on screen at load and must
//                                         not be hidden for an animation

const prefersReducedMotion =
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let observer = null;

function getObserver() {
    if (observer) return observer;
    if (typeof IntersectionObserver === 'undefined') return null;

    observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            const el = entry.target;
            observer.unobserve(el);
            // The delay is a CSS custom property read by transition-delay, so
            // there is no setTimeout per element. Timers here also outlived
            // unmount — a element scrolled past and then navigated away from
            // still woke the main thread to touch a detached node.
            el.classList.add('reveal-visible');
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });

    return observer;
}

function parseBinding(value) {
    if (typeof value === 'number') return { variant: '', delay: value, stagger: 0 };
    if (typeof value === 'string') return { variant: value, delay: 0, stagger: 0 };
    if (value && typeof value === 'object') {
        return {
            variant: value.variant || '',
            delay: value.delay || 0,
            stagger: value.stagger || 0,
        };
    }
    return { variant: '', delay: 0, stagger: 0 };
}

export default {
    mounted(el, binding) {
        if (binding.value === false) return;

        const { variant, delay, stagger } = parseBinding(binding.value);

        const obs = getObserver();
        // Old browser or the user asked for reduced motion: show the element
        // as-is and never observe it.
        if (!obs || prefersReducedMotion) return;

        // v-reveal="{ stagger: 60 }" cascades the element's direct children
        // instead of revealing the block as one. The container is then only an
        // observation hook and must not animate itself — nesting a transform
        // inside an animating parent makes the children drift.
        if (stagger > 0) {
            el.classList.add('reveal-group');

            Array.from(el.children).forEach((child, index) => {
                child.classList.add('reveal', 'reveal-child');
                if (variant) child.classList.add(`reveal-${variant}`);
                child.style.setProperty('--reveal-delay', `${delay + index * stagger}ms`);
            });

            obs.observe(el);
            return;
        }

        el.classList.add('reveal');
        if (variant) el.classList.add(`reveal-${variant}`);

        if (delay > 0) {
            el.style.setProperty('--reveal-delay', `${delay}ms`);
        }

        obs.observe(el);
    },
    unmounted(el) {
        if (observer) observer.unobserve(el);
    },
};
