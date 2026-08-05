<template>
    <LoadingComponent :props="loading" />

    <BlogNavComponent :is-index="true" />

    <section class="py-8 sm:py-12">
        <div class="container">
            <header class="mb-8 sm:mb-10 text-center">
                <h1 class="text-2xl sm:text-4xl font-bold capitalize text-heading mb-2">
                    {{ $t("label.blog_heading") }}
                </h1>
                <p class="text-paragraph max-w-2xl mx-auto mb-3">{{ $t("message.blog_intro") }}</p>
                <span class="mx-auto block h-1 w-16 rounded-full bg-primary"></span>
            </header>

            <!-- Search results mode. The sidebar search box routes here with
                 ?search=..., and the feed endpoint already understands it. -->
            <template v-if="isSearch">
                <div class="grid grid-cols-12 gap-6 lg:gap-8">
                    <div class="col-span-12 lg:col-span-8">
                        <h2 class="text-xl sm:text-2xl font-bold text-heading mb-5 sm:mb-7">
                            {{ $t("label.search") }}: &ldquo;{{ searchTerm }}&rdquo;
                        </h2>

                        <div v-if="searchResults.length > 0"
                            class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-6">
                            <BlogCardComponent v-for="post in searchResults" :key="'s' + post.id" :post="post" />
                        </div>

                        <div v-if="searchResults.length === 0 && !loading.isActive"
                            class="py-16 text-center text-paragraph">
                            {{ $t("message.no_data_found") }}
                        </div>

                        <div class="mt-10 text-center" v-if="searchHasMore">
                            <button type="button" @click="loadMoreSearch" :disabled="loadingMore"
                                class="py-2 px-4 text-sm sm:py-3 sm:px-6 rounded-3xl font-semibold bg-primary-slate text-primary transition-all duration-300 hover:bg-primary hover:text-white disabled:opacity-50">
                                {{ loadingMore ? $t("label.loading") : $t("label.load_more") }}
                            </button>
                        </div>
                    </div>

                    <div class="col-span-12 lg:col-span-4">
                        <BlogSidebarComponent />
                    </div>
                </div>
            </template>

            <!-- Magazine landing -->
            <template v-else>
                <!-- Hero: lead story on the left, two smaller stories stacked
                     on the right, headlines on the photos. -->
                <div v-if="featured" class="mb-10 sm:mb-14 grid grid-cols-12 gap-4 sm:gap-6">
                    <div class="col-span-12 lg:col-span-8 min-h-[280px] sm:min-h-[420px]">
                        <BlogCardComponent :post="featured" :overlay="true" :big="true" />
                    </div>

                    <div v-if="sidePosts.length > 0" class="col-span-12 lg:col-span-4 grid gap-4 sm:gap-6"
                        :class="sidePosts.length === 1 ? 'grid-rows-1' : 'grid-rows-2'">
                        <div v-for="post in sidePosts" :key="'side' + post.id" class="min-h-[200px] sm:min-h-0">
                            <BlogCardComponent :post="post" :overlay="true" />
                        </div>
                    </div>
                </div>

                <!-- Reader concerns — acne, sunburn, tan. Sits above the feed
                     because it is how most readers arrive. -->
                <BlogConcernsComponent />

                <div class="grid grid-cols-12 gap-6 lg:gap-8">
                    <div class="col-span-12 lg:col-span-8">
                        <div v-if="gridPosts.length > 0" class="flex items-center justify-between gap-4 mb-5 sm:mb-7">
                            <h2 class="text-2xl sm:text-4xl font-bold capitalize text-heading">
                                {{ $t("label.recent_posts") }}
                            </h2>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-6">
                            <BlogCardComponent v-for="post in gridPosts" :key="'r' + post.id" :post="post" />
                            <BlogCardComponent v-for="post in more" :key="'m' + post.id" :post="post" />
                        </div>

                        <div v-if="!featured && gridPosts.length === 0 && !loading.isActive"
                            class="py-16 text-center text-paragraph">
                            {{ $t("message.no_data_found") }}
                        </div>

                        <!-- Appends in place rather than linking to a separate
                             archive route: /blog/{anything} is the post route
                             server-side, so an /blog/all URL would 404. -->
                        <div class="mt-10 text-center" v-if="hasMore">
                            <button type="button" @click="loadMore" :disabled="loadingMore"
                                class="py-2 px-4 text-sm sm:py-3 sm:px-6 rounded-3xl font-semibold bg-primary-slate text-primary transition-all duration-300 hover:bg-primary hover:text-white disabled:opacity-50">
                                {{ loadingMore ? $t("label.loading") : $t("label.load_more") }}
                            </button>
                        </div>
                    </div>

                    <div class="col-span-12 lg:col-span-4">
                        <BlogSidebarComponent />
                    </div>
                </div>

                <!-- Category-wise rows, full width below the feed. Each row is
                     one blog category with its newest posts. -->
                <div class="mt-12 sm:mt-16">
                    <BlogSectionsComponent />
                </div>
            </template>
        </div>
    </section>
</template>

<script>
import { useHead } from "@vueuse/head";
import { ref } from "vue";
import LoadingComponent from "../../admin/components/LoadingComponent";
import BlogCardComponent from "./BlogCardComponent";
import BlogConcernsComponent from "./BlogConcernsComponent";
import BlogNavComponent from "./BlogNavComponent";
import BlogSectionsComponent from "./BlogSectionsComponent";
import BlogSidebarComponent from "./BlogSidebarComponent";

export default {
    name: "BlogComponent",
    components: {
        LoadingComponent,
        BlogCardComponent,
        BlogConcernsComponent,
        BlogNavComponent,
        BlogSectionsComponent,
        BlogSidebarComponent,
    },
    setup() {
        // The server already wrote the real tags into the raw HTML for
        // crawlers; this keeps the client-side head in sync during SPA
        // navigation, when no new document is fetched.
        const seoHead = ref({});
        useHead(seoHead);
        return { seoHead };
    },
    data() {
        return {
            loading: { isActive: false },
            loadingMore: false,
            more: [],
            // The overview endpoint already returned the hero plus 6 recent, so
            // paging starts after those 7.
            nextPage: 2,
            hasMore: false,
            loadedOverview: false,
            searchResults: [],
            searchNextPage: 1,
            searchHasMore: false,
        };
    },
    computed: {
        featured: function () {
            return this.$store.getters["frontendBlog/featured"];
        },
        recent: function () {
            return this.$store.getters["frontendBlog/recent"];
        },
        searchTerm: function () {
            const value = this.$route.query.search;
            return (typeof value === "string" ? value : "").trim();
        },
        isSearch: function () {
            return this.searchTerm !== "";
        },
        // The two stories stacked beside the lead story in the hero block.
        sidePosts: function () {
            return this.featured ? this.recent.slice(0, 2) : [];
        },
        gridPosts: function () {
            return this.featured ? this.recent.slice(2) : this.recent;
        },
    },
    mounted() {
        this.applyHead();
        this.$store.dispatch("frontendBlog/categories");
        // Fire-and-forget: the concern chips and category rows render when they
        // arrive rather than blocking the hero and feed above them.
        this.$store.dispatch("frontendBlog/tags").catch(() => {});
        this.$store.dispatch("frontendBlog/sections").catch(() => {});

        if (this.isSearch) {
            this.runSearch(true);
        } else {
            this.loadOverview();
        }
    },
    methods: {
        loadOverview: function () {
            this.loading.isActive = true;

            this.$store.dispatch("frontendBlog/overview").then(() => {
                this.loading.isActive = false;
                this.loadedOverview = true;
                // Only offer "load more" when a second page actually exists.
                this.hasMore = this.recent.length >= 6;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
        runSearch: function (reset) {
            if (reset) {
                this.searchResults = [];
                this.searchNextPage = 1;
                this.searchHasMore = false;
                this.loading.isActive = true;
            } else {
                this.loadingMore = true;
            }

            this.$store.dispatch("frontendBlog/lists", {
                paginate: 1,
                page: this.searchNextPage,
                per_page: 9,
                // requestHandler() concatenates values raw, so encode here —
                // an unencoded "&" or Bengali term would corrupt the query.
                search: encodeURIComponent(this.searchTerm),
            }).then((res) => {
                this.searchResults = this.searchResults.concat(res.data.data || []);
                this.searchHasMore = !!res.data.links?.next;
                this.searchNextPage += 1;
                this.loading.isActive = false;
                this.loadingMore = false;
            }).catch(() => {
                this.loading.isActive = false;
                this.loadingMore = false;
            });
        },
        loadMoreSearch: function () {
            this.runSearch(false);
        },
        loadMore: function () {
            this.loadingMore = true;

            this.$store.dispatch("frontendBlog/lists", {
                paginate: 1,
                page: this.nextPage,
                per_page: 9,
            }).then((res) => {
                const shownIds = [
                    ...(this.featured ? [this.featured.id] : []),
                    ...this.recent.map((post) => post.id),
                    ...this.more.map((post) => post.id),
                ];

                // The feed and the overview overlap — the first page of /blog
                // is the same newest posts the hero and grid already show — so
                // filter rather than render duplicates.
                this.more = this.more.concat(
                    res.data.data.filter((post) => !shownIds.includes(post.id))
                );

                this.hasMore = !!res.data.links?.next;
                this.nextPage += 1;
                this.loadingMore = false;
            }).catch(() => {
                this.loadingMore = false;
            });
        },
        applyHead: function () {
            this.seoHead = {
                title: this.$t("label.blog_meta_title"),
                meta: [
                    { name: "description", content: this.$t("message.blog_meta_description") },
                    { property: "og:title", content: this.$t("label.blog_meta_title") },
                    { property: "og:description", content: this.$t("message.blog_meta_description") },
                    { property: "og:type", content: "website" },
                    { property: "og:url", content: `${window.location.origin}/blog` },
                ],
                link: [{ rel: "canonical", href: `${window.location.origin}/blog` }],
            };
        },
    },
    watch: {
        // The sidebar search routes here with a query string; clearing it
        // (the "All" pill) drops back to the magazine landing.
        "$route.query.search": function () {
            if (this.isSearch) {
                this.runSearch(true);
            } else if (!this.loadedOverview) {
                this.loadOverview();
            }
        },
    },
};
</script>
