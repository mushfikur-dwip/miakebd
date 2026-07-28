<template>
    <div class="relative overflow-hidden bg-gray-100" :style="{ aspectRatio: `${width} / ${height}` }">
        <img
            :src="currentSrc"
            :alt="alt"
            :width="width"
            :height="height"
            :loading="eager ? 'eager' : 'lazy'"
            :fetchpriority="eager ? 'high' : 'auto'"
            decoding="async"
            :class="imgClass"
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
        eager: { type: Boolean, default: false },
        imgClass: { type: String, default: "w-full h-full object-cover" },
    },
    data() {
        return {
            currentSrc: this.src,
            fallbackUsed: false,
        };
    },
    watch: {
        src(value) {
            this.currentSrc = value;
            this.fallbackUsed = false;
        },
    },
    methods: {
        useFallback() {
            if (this.fallbackUsed) {
                return;
            }

            this.fallbackUsed = true;
            this.currentSrc = "/images/default/product/cover.png";
        },
    },
};
</script>
