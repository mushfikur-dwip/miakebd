<template>
    <nav class="sticky top-0 z-20 border-b border-gray-100 bg-white/95 backdrop-blur">
        <div class="container">
            <div class="flex items-center gap-1 overflow-x-auto no-scrollbar py-3">
                <router-link :to="{ name: 'frontend.blog' }"
                    class="shrink-0 px-3 py-1.5 text-sm font-semibold rounded-full transition-colors"
                    :class="isIndex ? 'bg-primary text-white' : 'text-paragraph hover:bg-primary-slate hover:text-primary'">
                    {{ $t("label.all_posts") }}
                </router-link>

                <router-link v-for="category in categories" :key="category.id"
                    :to="{ name: 'frontend.blog.category', params: { slug: category.slug } }"
                    class="shrink-0 px-3 py-1.5 text-sm font-semibold rounded-full transition-colors"
                    :class="activeSlug === category.slug ? 'bg-primary text-white' : 'text-paragraph hover:bg-primary-slate hover:text-primary'">
                    {{ category.name }}
                </router-link>

                <!-- ms-auto, not ml-auto: in RTL the shop pill still hugs the
                     far edge instead of collapsing into the category pills. -->
                <router-link :to="{ name: 'frontend.product' }"
                    class="shrink-0 ms-auto px-4 py-1.5 text-sm font-semibold rounded-full bg-primary text-white transition-opacity hover:opacity-90">
                    {{ $t("label.shop") }}
                </router-link>
            </div>
        </div>
    </nav>
</template>

<script>
export default {
    name: "BlogNavComponent",
    props: {
        activeSlug: { type: String, default: "" },
        isIndex: { type: Boolean, default: false },
    },
    computed: {
        categories: function () {
            return this.$store.getters["frontendBlog/categories"];
        },
    },
};
</script>

<style scoped>
/* The nav scrolls horizontally on mobile; the scrollbar itself is noise. */
.no-scrollbar::-webkit-scrollbar {
    display: none;
}

.no-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
