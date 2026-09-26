<template>
    <!-- The shape of a product card, shown while a listing loads - so the grid
         appears at once and the real cards drop into place without moving
         anything, instead of a blank white page. -->
    <div v-for="n in count" :key="n" class="p-2 rounded-2xl bg-white shadow-card" aria-hidden="true">
        <div class="pcs rounded-xl" style="aspect-ratio: 372 / 405"></div>
        <div class="px-1 sm:px-0 pt-4 pb-2">
            <div class="pcs h-4 w-11/12 rounded mb-2"></div>
            <div class="pcs h-4 w-2/3 rounded mb-4"></div>
            <div class="pcs h-3 w-20 rounded mb-3"></div>
            <div class="pcs h-6 w-24 rounded"></div>
        </div>
        <div class="px-1 sm:px-0 pb-2">
            <div class="pcs h-11 w-full rounded-full"></div>
        </div>
    </div>
</template>

<script>
export default {
    name: "ProductCardSkeleton",
    props: {
        count: { type: Number, default: 6 },
    },
};
</script>

<style scoped>
.pcs {
    background: linear-gradient(100deg, #f1f2f6 30%, #f8f8fb 50%, #f1f2f6 70%);
    background-size: 220% 100%;
    animation: pcs-shimmer 1.3s ease-in-out infinite;
}

@keyframes pcs-shimmer {
    from { background-position: 120% 0; }
    to { background-position: -120% 0; }
}

@media (prefers-reduced-motion: reduce) {
    .pcs {
        animation: none;
    }
}
</style>
