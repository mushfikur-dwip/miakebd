/**
 * suglow.com — Vite config: SSR entry + code splitting
 *
 * UPLOAD TO:  dev/vite.config.js
 *
 * ############ READ THIS BEFORE YOU OVERWRITE ############
 * BUG 3 FIX: your existing vite.config.js almost certainly defines the "@" path
 * alias. My previous version dropped it, which would have broken every
 * `import X from '@/Components/...'` in your app and killed `npm run build`.
 *
 * OPEN YOUR CURRENT dev/vite.config.js FIRST and check three things:
 *   1. the `input:` paths below match yours (app.js vs app.ts vs app.jsx)
 *   2. any extra plugins you use are copied across
 *   3. any extra aliases you defined are copied into `resolve.alias`
 * Keep a backup: rename the old one to vite.config.js.bak before uploading.
 * ########################################################
 */
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
    plugins: [
        laravel({
            // <-- CHECK THESE MATCH YOUR PROJECT
            input: ['resources/css/app.css', 'resources/js/app.js'],
            ssr:   'resources/js/ssr.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],

    // BUG 3 FIX — without this every '@/...' import fails to resolve.
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '~': path.resolve(__dirname, 'resources'),
        },
    },

    build: {
        // Warn earlier so oversized chunks are visible in the build output.
        chunkSizeWarningLimit: 250,
        rollupOptions: {
            output: {
                /**
                 * Splits the bundle so a product page no longer downloads the whole app.
                 * Your report showed 468 KiB of 705 KiB JavaScript going unused.
                 */
                manualChunks(id) {
                    if (!id.includes('node_modules')) return

                    if (id.includes('/vue/') || id.includes('/@vue/') || id.includes('/@inertiajs/')) {
                        return 'vendor'
                    }
                    if (id.includes('/axios/')) {
                        return 'http'
                    }
                    if (id.includes('/@headlessui/') || id.includes('/@heroicons/') || id.includes('/lucide')) {
                        return 'ui'
                    }
                    if (id.includes('/swiper/') || id.includes('/slick') || id.includes('/glide')) {
                        return 'carousel'
                    }
                    if (id.includes('/chart.js/') || id.includes('/apexcharts/')) {
                        return 'charts'
                    }
                },
            },
        },
    },

    ssr: {
        noExternal: ['@inertiajs/vue3'],
    },
})
