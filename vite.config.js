import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue'

export default defineConfig(({ command }) => ({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
    ],
    resolve: {
        extensions: ['.js', '.vue', '.json']
    },
    optimizeDeps: {
        include: ["quill"],
        exclude: ["swiper/vue", "swiper/types"]
    },
    build: {
        target: 'es2020',
        cssCodeSplit: true,
        sourcemap: false,
        reportCompressedSize: false,
        chunkSizeWarningLimit: 250,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    // Rollup/Vite virtual helper modules — CommonJS interop and
                    // the Vue SFC normalizer. Almost every chunk needs them, but
                    // they live outside node_modules, so without an explicit
                    // home Rollup parks them in whichever manual chunk it
                    // creates first and every component then statically imports
                    // that chunk. That is what put the 704 kB admin chart bundle
                    // on the storefront's critical path.
                    if (
                        id.includes('commonjsHelpers') ||
                        id.includes('plugin-vue:export-helper') ||
                        id.includes('vite/preload-helper')
                    ) {
                        return 'vendor';
                    }

                    if (!id.includes('node_modules')) {
                        return;
                    }

                    // Resolve the real package name. The previous version used
                    // id.includes(), which is fragile — 'vue-inner-image-zoom'
                    // contains 'vue' and 'vue3-quill' contains 'quill'.
                    const afterModules = id.split('node_modules/').pop();
                    const segments = afterModules.split('/');
                    const pkg = afterModules.startsWith('@')
                        ? segments.slice(0, 2).join('/')
                        : segments[0];

                    // Framework core must be matched FIRST. With no chunk of its
                    // own, Rollup folded Vue into whichever manual chunk reached
                    // it first — 'charts' — which made a 704 kB admin-only chunk
                    // a static import of every component and put it on the
                    // storefront's critical path.
                    if (
                        pkg === 'vue' ||
                        pkg === 'vue-router' ||
                        pkg === 'vuex' ||
                        pkg.startsWith('@vue')
                    ) {
                        return 'vendor';
                    }

                    // Admin-only and page-specific heavyweights. These stay out
                    // of the initial load only while the components importing
                    // them are lazy-loaded.
                    if (pkg === 'apexcharts' || pkg === 'vue3-apexcharts') {
                        return 'charts';
                    }

                    if (pkg === 'firebase' || pkg.startsWith('@firebase')) {
                        return 'firebase';
                    }

                    if (pkg === 'quill' || pkg === 'vue3-quill') {
                        return 'editor';
                    }

                    // Homepage slider and product gallery — part of the LCP
                    // element, so deliberately left eager.
                    if (pkg === 'swiper' || pkg === 'vue-inner-image-zoom') {
                        return 'product-media';
                    }

                    if (pkg === 'axios') {
                        return 'http';
                    }

                    // Everything else stays in Rollup's automatic chunking.
                }
            }
        }
    },
    esbuild: {
        // Build only — `npm run dev` keeps its console output.
        drop: command === 'build' ? ['console', 'debugger'] : [],
    },
    // server: {
    //     host: '0.0.0.0',
    //     hmr: {
    //         host: 'localhost'
    //     }
    // }
}));
