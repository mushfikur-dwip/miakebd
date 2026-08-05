<template>
    <div ref="root">
        <slot v-if="visible"></slot>
    </div>
</template>

<script>
// Defers rendering (and therefore the mounted() API calls) of below-the-fold
// home sections until they are about to scroll into view. The home page used
// to fire ~9 API requests at once on first visit; now only the above-the-fold
// sections load immediately.
export default {
    name: "LazyRenderComponent",
    data() {
        return {
            visible: false,
            observer: null,
        };
    },
    mounted() {
        if (!("IntersectionObserver" in window)) {
            this.visible = true;
            return;
        }
        this.observer = new IntersectionObserver(
            (entries) => {
                if (entries[0].isIntersecting) {
                    this.visible = true;
                    this.observer.disconnect();
                }
            },
            // Start loading ~1.5 viewports before the section is reached so it
            // is ready by the time the user scrolls to it.
            { rootMargin: "150% 0px" }
        );
        this.observer.observe(this.$refs.root);
    },
    beforeUnmount() {
        if (this.observer) {
            this.observer.disconnect();
        }
    },
};
</script>
