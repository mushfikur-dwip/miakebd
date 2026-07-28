import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue'

export default defineConfig({
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
        chunkSizeWarningLimit: 250,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return;
                    }

                    if (id.includes('apexcharts') || id.includes('vue3-apexcharts')) {
                        return 'charts';
                    }

                    if (id.includes('firebase')) {
                        return 'firebase';
                    }

                    if (id.includes('swiper') || id.includes('vue-inner-image-zoom')) {
                        return 'product-media';
                    }

                    if (id.includes('quill') || id.includes('vue3-quill')) {
                        return 'editor';
                    }
                }
            }
        }
    },
    // server: {
    //     host: '0.0.0.0',
    //     hmr: {
    //         host: 'localhost'
    //     }
    // }
});
