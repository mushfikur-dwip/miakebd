<template>
    <!-- No photo uploaded yet: the server's stock "No Image Available" picture
         is swapped for the shop's own logo on its soft brand colour, so a
         missing photo reads as on-brand rather than broken. -->
    <div v-if="isPlaceholder" class="pi relative overflow-hidden flex items-center justify-center bg-primary-slate"
         :style="{ aspectRatio: `${width} / ${height}` }" role="img" :aria-label="alt">
        <img v-if="logo" :src="logo" alt="" aria-hidden="true" loading="lazy" decoding="async"
             class="w-1/2 max-w-[160px] opacity-40 grayscale-[30%] object-contain">
        <i v-else class="lab-line-bag text-4xl text-primary/40" aria-hidden="true"></i>
    </div>
    <div v-else class="pi relative overflow-hidden bg-gray-100" :class="{ 'pi-waiting': !loaded && !eager }"
         :style="{ aspectRatio: `${width} / ${height}` }">
        <img
            ref="img"
            :src="currentSrc"
            :alt="alt"
            :width="width"
            :height="height"
            :loading="eager ? 'eager' : 'lazy'"
            :fetchpriority="eager ? 'high' : 'auto'"
            decoding="async"
            :class="[imgClass, eager ? '' : 'pi-img', loaded ? 'pi-loaded' : '']"
            @load="loaded = true"
            @error="useFallback"
        >
    </div>
</template>

<script>
export default {
    name: "ProductImage",
    props: {
        src: { type: String, required: true },
        alt: { type: String, required: true },
        width: { type: Number, default: 800 },
        height: { type: Number, default: 800 },
        // Above the fold: loaded at once, at high priority, and never faded -
        // a fade would push back the largest paint it is meant to be.
        eager: { type: Boolean, default: false },
        imgClass: { type: String, default: "w-full h-full object-cover" },
    },
    data() {
        return {
            currentSrc: this.src,
            fallbackUsed: false,
            loaded: false,
        };
    },
    computed: {
        // The server hands out /images/default/... for a product without a
        // photo, and the error fallback below points there too.
        isPlaceholder: function () {
            return !this.currentSrc || this.currentSrc.includes("/images/default/");
        },
        logo: function () {
            const setting = this.$store && this.$store.getters["frontendSetting/lists"];
            return setting && setting.theme_logo ? setting.theme_logo : "";
        },
    },
    mounted() {
        // Already in the browser cache: load may have fired before Vue listened.
        const img = this.$refs.img;
        if (img && img.complete && img.naturalWidth > 0) {
            this.loaded = true;
        }
    },
    watch: {
        src(value) {
            this.currentSrc = value;
            this.fallbackUsed = false;
            this.loaded = false;
        },
    },
    methods: {
        useFallback() {
            if (this.fallbackUsed) {
                this.loaded = true;
                return;
            }

            this.fallbackUsed = true;
            this.currentSrc = "/images/default/product/cover.png";
        },
    },
};
</script>

<style scoped>
/* A soft sweep while the photo is on its way, then the photo fades in over it.
   Opacity and a background-position animation only: no layout, and the box
   already has its final size from aspect-ratio, so nothing shifts. */
.pi-waiting {
    background: linear-gradient(100deg, #f1f2f6 30%, #f8f8fb 50%, #f1f2f6 70%);
    background-size: 220% 100%;
    animation: pi-shimmer 1.3s ease-in-out infinite;
}

.pi-img {
    opacity: 0;
    transition: opacity 0.45s ease, transform 0.5s ease;
}

.pi-img.pi-loaded {
    opacity: 1;
}

@keyframes pi-shimmer {
    from { background-position: 120% 0; }
    to { background-position: -120% 0; }
}

@media (prefers-reduced-motion: reduce) {
    .pi-waiting {
        animation: none;
    }

    .pi-img {
        opacity: 1;
    }
}
</style>
