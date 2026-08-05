<template>
    <LoadingComponent :props="loading" />

    <BlogNavComponent :active-slug="post.category ? post.category.slug : ''" />

    <section class="py-8 sm:py-12">
        <div class="container">
            <div class="grid grid-cols-12 gap-6 lg:gap-8">
                <div class="col-span-12 lg:col-span-8">
                    <!-- Breadcrumb. Matches the BreadcrumbList JSON-LD the
                         server emits — Google wants the markup and the visible
                         trail to agree. -->
                    <nav class="mb-4 flex flex-wrap items-center gap-2 text-sm text-paragraph">
                        <router-link :to="{ name: 'frontend.home' }" class="transition-colors hover:text-primary">
                            {{ $t("label.home") }}
                        </router-link>
                        <span>/</span>
                        <router-link :to="{ name: 'frontend.blog' }" class="transition-colors hover:text-primary">
                            {{ $t("label.blog") }}
                        </router-link>
                        <template v-if="post.category">
                            <span>/</span>
                            <router-link
                                :to="{ name: 'frontend.blog.category', params: { slug: post.category.slug } }"
                                class="transition-colors hover:text-primary">
                                {{ post.category.name }}
                            </router-link>
                        </template>
                    </nav>

                    <article>
                        <header class="mb-6">
                            <span v-if="post.category"
                                class="mb-3 inline-block rounded-full bg-primary-slate px-3 py-1 text-xs font-semibold text-primary">
                                {{ post.category.name }}
                            </span>

                            <h1 class="mb-3 text-2xl sm:text-4xl font-bold leading-tight text-heading">
                                {{ post.title }}
                            </h1>

                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-paragraph">
                                <span v-if="post.author_name" class="flex items-center gap-1.5">
                                    <i class="lab lab-line-user"></i>
                                    {{ post.author_name }}
                                </span>
                                <span v-if="post.published_human" class="flex items-center gap-1.5">
                                    <i class="lab lab-line-calendar"></i>
                                    {{ post.published_human }}
                                </span>
                                <span v-if="post.views" class="flex items-center gap-1.5">
                                    <i class="lab lab-line-eye"></i>
                                    {{ post.views }}
                                </span>
                                <span v-if="post.reading_minutes">
                                    {{ post.reading_minutes }} {{ $t("label.min_read") }}
                                </span>
                            </div>
                        </header>

                        <img v-if="post.cover" :src="post.cover" :alt="post.title"
                            class="mb-6 w-full rounded-2xl object-cover shadow-card" />

                        <!-- ql-editor keeps the Quill output styled the same way
                             the CMS pages already render it. -->
                        <div class="ql-editor blog-content" v-html="post.content"></div>

                        <!-- Concerns this article covers. Internal links out to
                             each concern hub, which is what ties the cluster
                             together for both readers and Google. -->
                        <div v-if="post.tags && post.tags.length > 0"
                            class="mt-8 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-6">
                            <span class="text-sm font-semibold text-heading">{{ $t("label.concerns") }}:</span>
                            <router-link v-for="tag in post.tags" :key="tag.slug"
                                :to="{ name: 'frontend.blog.tag', params: { slug: tag.slug } }"
                                class="rounded-full bg-primary-slate px-3 py-1 text-xs font-semibold text-primary
                                       transition-colors hover:bg-primary hover:text-white">
                                {{ tag.name }}
                            </router-link>
                        </div>
                    </article>

                    <div v-if="related.length > 0" class="mt-12">
                        <h2 class="mb-1 text-2xl sm:text-3xl font-bold capitalize text-heading">
                            {{ $t("label.related_posts") }}
                        </h2>
                        <span class="mb-5 block h-1 w-10 rounded-full bg-primary"></span>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                            <BlogCardComponent v-for="item in related" :key="item.id" :post="item" />
                        </div>
                    </div>
                </div>

                <div class="col-span-12 lg:col-span-4">
                    <BlogSidebarComponent />
                </div>
            </div>
        </div>
    </section>
</template>

<script>
import { useHead } from "@vueuse/head";
import { ref } from "vue";
import LoadingComponent from "../../admin/components/LoadingComponent";
import BlogCardComponent from "./BlogCardComponent";
import BlogNavComponent from "./BlogNavComponent";
import BlogSidebarComponent from "./BlogSidebarComponent";
import "vue3-quill/lib/vue3-quill.css";

export default {
    name: "BlogDetailsComponent",
    components: { LoadingComponent, BlogCardComponent, BlogNavComponent, BlogSidebarComponent },
    setup() {
        const seoHead = ref({});
        useHead(seoHead);
        return { seoHead };
    },
    data() {
        return {
            loading: { isActive: false },
        };
    },
    computed: {
        post: function () {
            return this.$store.getters["frontendBlog/show"];
        },
        related: function () {
            return this.$store.getters["frontendBlog/related"];
        },
    },
    mounted() {
        this.postSetup();
        this.$store.dispatch("frontendBlog/categories");
    },
    methods: {
        postSetup: function () {
            const slug = this.$route.params.slug;

            if (typeof slug !== "string" || slug === "") {
                return;
            }

            this.loading.isActive = true;

            this.$store.dispatch("frontendBlog/show", slug).then(() => {
                this.loading.isActive = false;
                this.applyHead();
            }).catch(() => {
                this.loading.isActive = false;
                this.$router.push({ name: "route.notFound" });
            });

            this.$store.dispatch("frontendBlog/related", slug).then().catch();
        },
        applyHead: function () {
            const post = this.post;

            if (!post || !post.title) {
                return;
            }

            this.seoHead = {
                title: post.meta_title || post.title,
                meta: [
                    { name: "description", content: post.meta_description || post.excerpt },
                    { name: "keywords", content: post.meta_keywords },
                    { property: "og:type", content: "article" },
                    { property: "og:title", content: post.meta_title || post.title },
                    { property: "og:description", content: post.meta_description || post.excerpt },
                    { property: "og:image", content: post.cover },
                    { property: "og:url", content: post.url },
                    { property: "article:published_time", content: post.published_at },
                    { property: "article:modified_time", content: post.updated_at },
                    { name: "twitter:card", content: "summary_large_image" },
                    { name: "twitter:image", content: post.cover },
                ].filter((tag) => !!tag.content),
                link: [{ rel: "canonical", href: post.canonical_url || post.url }],
            };
        },
    },
    watch: {
        // Clicking a related post keeps the same component mounted, so without
        // this the page would keep showing the previous article.
        "$route.params.slug": function (value) {
            if (value) {
                this.postSetup();
            }
        },
    },
};
</script>

<style scoped>
.blog-content :deep(h2) {
    @apply text-xl sm:text-2xl font-bold text-heading mt-8 mb-3;
}

.blog-content :deep(h3) {
    @apply text-lg font-bold text-heading mt-6 mb-2;
}

.blog-content :deep(p) {
    @apply mb-4 leading-relaxed text-paragraph;
}

.blog-content :deep(img) {
    @apply rounded-2xl my-6 max-w-full h-auto;
}

.blog-content :deep(a) {
    @apply text-primary underline;
}

.blog-content :deep(ul) {
    @apply list-disc ps-6 mb-4 text-paragraph;
}

.blog-content :deep(ol) {
    @apply list-decimal ps-6 mb-4 text-paragraph;
}

.blog-content :deep(blockquote) {
    @apply border-s-4 border-primary ps-4 italic text-paragraph my-6;
}
</style>
