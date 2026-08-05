<template>
    <div v-if="tags.length > 0" class="mb-8 sm:mb-10">
        <h2 class="mb-1 text-xl sm:text-2xl font-bold capitalize text-heading">
            {{ $t("label.browse_by_concern") }}
        </h2>
        <span class="mb-4 block h-1 w-10 rounded-full bg-primary"></span>

        <div class="flex flex-wrap gap-2">
            <router-link v-for="tag in tags" :key="tag.id"
                :to="{ name: 'frontend.blog.tag', params: { slug: tag.slug } }"
                class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm
                       font-medium text-heading transition-colors hover:border-primary hover:bg-primary hover:text-white"
                :class="activeSlug === tag.slug ? 'border-primary bg-primary text-white' : ''">
                {{ tag.name }}
                <span class="rounded-full bg-primary-slate px-2 py-0.5 text-xs font-semibold text-primary">
                    {{ tag.published_posts_count }}
                </span>
            </router-link>
        </div>
    </div>
</template>

<script>
/**
 * Reader-concern chips — acne, sunburn, tan. Separate from the category nav:
 * a reader searches "tan removal" long before they think in product
 * categories, and each concern has its own indexable landing page.
 */
export default {
    name: "BlogConcernsComponent",
    props: {
        activeSlug: { type: String, default: "" },
    },
    computed: {
        tags: function () {
            return this.$store.getters["frontendBlog/tags"];
        },
    },
};
</script>
