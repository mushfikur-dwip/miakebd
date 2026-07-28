/**
 * suglow.com — Inertia SSR entry point
 *
 * UPLOAD TO:  dev/resources/js/ssr.js
 *
 * INSTALL FIRST (needs Terminal/SSH):
 *   npm install @inertiajs/server @vue/server-renderer
 *
 * ADD TO dev/.env :
 *   INERTIA_SSR_ENABLED=true
 *   INERTIA_SSR_URL=http://127.0.0.1:13714
 *
 * BUILD AND START:
 *   npm run build
 *   php artisan inertia:start-ssr
 *
 * NOTE: the glob below assumes your pages live in resources/js/Pages/**.vue.
 * If yours differ, match the path used in resources/js/app.js.
 */
import { createInertiaApp } from '@inertiajs/vue3'
import createServer from '@inertiajs/server'
import { renderToString } from '@vue/server-renderer'
import { createSSRApp, h } from 'vue'

createServer(page =>
    createInertiaApp({
        page,
        render: renderToString,
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
            const match = pages[`./Pages/${name}.vue`]
            if (!match) {
                throw new Error(`SSR could not resolve page: ${name}`)
            }
            return match
        },
        setup({ App, props, plugin }) {
            return createSSRApp({ render: () => h(App, props) }).use(plugin)
        },
    })
)
