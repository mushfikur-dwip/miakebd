<template>
    <LoadingComponent :props="loading" />

    <BlogNavComponent />

    <section class="py-8 sm:py-12">
        <div class="container">
            <div class="grid grid-cols-12 gap-6 lg:gap-8">
                <div class="col-span-12 lg:col-span-8">
                    <nav class="mb-4 flex items-center gap-2 text-sm text-paragraph">
                        <router-link :to="{ name: 'frontend.home' }" class="transition-colors hover:text-primary">
                            {{ $t("label.home") }}
                        </router-link>
                        <span>/</span>
                        <router-link :to="{ name: 'frontend.blog' }" class="transition-colors hover:text-primary">
                            {{ $t("label.blog") }}
                        </router-link>
                    </nav>

                    <header class="mb-6 sm:mb-8">
                        <span class="mb-2 inline-block rounded-full bg-primary-slate px-3 py-1 text-xs font-semibold text-primary">
                            {{ $t("label.concern") }}
                        </span>
                        <h1 class="mb-1 text-2xl sm:text-4xl font-bold capitalize text-heading">{{ tag.name }}</h1>
                        <span class="mb-3 block h-1 w-10 rounded-full bg-primary"></span>
                        <p v-if="tag.description" class="text-paragraph">{{ tag.description }}</p>
                        <span class="mt-3 inline-block text-sm text-paragraph">
                            {{ tag.published_posts_count }} {{ $t("label.posts") }}
                        </span>
                    </header>

                    <BlogConcernsComponent :active-slug="slug" />

                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-6">
                        <BlogCardComponent v-for="post in posts" :key="post.id" :post="post" />
                    </div>

                    <div v-if="posts.length === 0 && !loading.isActive" class="py-16 text-center text-paragraph">
                        {{ $t("message.no_data_found") }}
                    </div>

                    <div class="mt-8 flex justify-center" v-if="posts.length > 0">
                        <PaginationBox :pagination="pagination" :method="list" />
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
import PaginationBox from "../../admin/components/pagination/PaginationBox";
import BlogCardComponent from "./BlogCardComponent";
import BlogConcernsComponent from "./BlogConcernsComponent";
import BlogNavComponent from "./BlogNavComponent";
import BlogSidebarComponent from "./BlogSidebarComponent";

export default {
    name: "BlogTagPageComponent",
    components: {
        LoadingComponent,
        PaginationBox,
        BlogCardComponent,
        BlogConcernsComponent,
        BlogNavComponent,
        BlogSidebarComponent,
    },
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
        slug: function () {
            return this.$route.params.slug || "";
        },
        posts: function () {
            return this.$store.getters["frontendBlog/lists"];
        },
        pagination: function () {
            return this.$store.getters["frontendBlog/pagination"];
        },
        // The chips payload already carries name, description and count, so the
        // header needs no extra request.
        tag: function () {
            const tags = this.$store.getters["frontendBlog/tags"] || [];
            return tags.find((item) => item.slug === this.slug)
                || { name: this.slug, published_posts_count: 0 };
        },
    },
    mounted() {
        this.$store.dispatch("frontendBlog/categories").catch(() => {});
        this.$store.dispatch("frontendBlog/tags").then(() => this.applyHead()).catch(() => {});
        this.list();
    },
    methods: {
        list: function (page = 1) {
            this.loading.isActive = true;

            this.$store.dispatch("frontendBlog/lists", {
                paginate: 1,
                page: page,
                per_page: 9,
                tag: this.slug,
            }).then(() => {
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
        applyHead: function () {
            const url = `${window.location.origin}/blog/tag/${this.slug}`;
            const title = `${this.tag.name} — Suglow Blog`;
            const description = this.tag.description
                || `${this.tag.name} articles, causes and treatment guides from the Suglow blog.`;

            this.seoHead = {
                title: title,
                meta: [
                    { name: "description", content: description },
                    { property: "og:title", content: title },
                    { property: "og:description", content: description },
                    { property: "og:type", content: "website" },
                    { property: "og:url", content: url },
                ],
                link: [{ rel: "canonical", href: url }],
            };
        },
    },
    watch: {
        "$route.params.slug": function (value) {
            if (value) {
                this.list();
                this.applyHead();
            }
        },
    },
};
</script>
