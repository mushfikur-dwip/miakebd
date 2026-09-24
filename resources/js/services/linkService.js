/**
 * Works out where a hand-typed banner link should send the visitor.
 *
 * Links are typed into the admin by a person, so they arrive in every shape:
 * a bare path ("/brands/cerave"), a full URL on our own domain, a URL on
 * somebody else's, or a domain with no scheme at all ("facebook.com/x").
 *
 * The scheme-less case is the one that bites: in an href it is read as a
 * relative path, so "facebook.com/x" quietly resolves against our own site and
 * 404s. And an internal link sent through a plain <a> reloads the whole SPA,
 * throwing away the cart drawer and re-downloading the bundle - so anything
 * pointing at our own origin is handed to the router instead.
 */
const linkService = {
    /**
     * @returns {{type: 'none'}
     *          |{type: 'internal', path: string}
     *          |{type: 'external', href: string}}
     */
    resolve(raw) {
        const link = (raw || '').trim();

        if (!link) {
            return { type: 'none' };
        }

        // Unambiguously ours.
        if (link.startsWith('/')) {
            return { type: 'internal', path: link };
        }

        const scheme = link.match(/^([a-z][a-z0-9+.-]*):/i);

        // mailto:, tel:, whatsapp: and friends are passed through untouched -
        // they are not pages and must not be parsed as one.
        if (scheme && !['http', 'https'].includes(scheme[1].toLowerCase())) {
            return { type: 'external', href: link };
        }

        const href = scheme ? link : 'https://' + link;

        try {
            const url = new URL(href);

            if (url.origin === window.location.origin) {
                return { type: 'internal', path: url.pathname + url.search + url.hash };
            }

            return { type: 'external', href };
        } catch (e) {
            // Not parseable as a URL. Send it out rather than to the router,
            // where a bad path would blank the page.
            return { type: 'external', href };
        }
    }
};

export default linkService;
