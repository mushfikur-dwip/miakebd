<template>
    <aside class="flex flex-col gap-6 sm:gap-8">
        <!-- Search. Routes to the blog index with ?search=..., which the feed
             endpoint already filters on. -->
        <form @submit.prevent="submitSearch" class="relative">
            <input v-model="term" type="text" :placeholder="$t('label.search') + '…'"
                class="w-full rounded-full border border-gray-200 bg-white py-3 ps-5 pe-12 text-sm text-heading placeholder:text-paragraph/70 outline-none transition-colors focus:border-primary" />
            <button type="submit" :aria-label="$t('label.search')"
                class="absolute end-1.5 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-primary text-white transition-opacity hover:opacity-90">
                <i class="lab lab-line-search"></i>
            </button>
        </form>

        <!-- Categories with live post counts. These are internal links to the
             topic landing pages, which is how a blog spreads authority to its
             category clusters. -->
        <div class="rounded-2xl bg-white p-5 sm:p-6 shadow-card">
            <h3 class="mb-1 text-lg font-bold text-heading">{{ $t("label.categories") }}</h3>
            <span class="mb-4 block h-1 w-10 rounded-full bg-primary"></span>
            <ul>
                <li v-for="category in categories" :key="category.id" class="border-b border-gray-100 last:border-0">
                    <router-link :to="{ name: 'frontend.blog.category', params: { slug: category.slug } }"
                        class="group flex items-center justify-between py-3 text-sm text-paragraph transition-colors hover:text-primary">
                        <span class="flex items-center gap-2">
                            <i class="lab lab-line-arrow-right text-xs text-primary rtl:rotate-180"></i>
                            {{ category.name }}
                        </span>
                        <span class="rounded-full bg-primary-slate px-2 py-0.5 text-xs font-semibold text-primary">
                            {{ category.posts_count }}
                        </span>
                    </router-link>
                </li>
                <li v-if="categories.length === 0" class="py-3 text-sm text-paragraph">
                    {{ $t("message.no_data_found") }}
                </li>
            </ul>
        </div>

        <!-- Most-read. Keeps strong posts linked from every article rather than
             letting them fall off the feed. -->
        <div v-if="popular.length > 0" class="rounded-2xl bg-white p-5 sm:p-6 shadow-card">
            <h3 class="mb-1 text-lg font-bold text-heading">{{ $t("label.most_read") }}</h3>
            <span class="mb-4 block h-1 w-10 rounded-full bg-primary"></span>
            <ul class="flex flex-col gap-4">
                <li v-for="post in popular" :key="post.id">
                    <router-link :to="{ name: 'frontend.blog.details', params: { slug: post.slug } }"
                        class="group flex items-center gap-3">
                        <img v-if="post.cover" :src="post.cover" :alt="post.title" loading="lazy"
                            class="h-16 w-16 shrink-0 rounded-xl object-cover" />
                        <span>
                            <span class="block text-sm font-semibold leading-snug text-heading line-clamp-2 transition-colors group-hover:text-primary">
                                {{ post.title }}
                            </span>
                            <span v-if="post.views" class="mt-1 flex items-center gap-1 text-xs text-paragraph">
                                <i class="lab lab-line-eye"></i>
                                {{ post.views }}
                            </span>
                        </span>
                    </router-link>
                </li>
            </ul>
        </div>

        <!-- Blog to storefront. The whole point of running the blog is moving
             readers into the catalogue. -->
        <div class="rounded-2xl bg-primary-slate p-6 sm:p-8 text-center">
            <p class="mb-2 text-xs font-semibold uppercase tracking-widest text-primary">suglow.com</p>
            <p class="mb-1 text-xl font-bold text-heading">{{ $t("label.authentic_beauty_products") }}</p>
            <p class="mb-5 text-sm text-paragraph">{{ $t("message.blog_shop_cta") }}</p>
            <router-link :to="{ name: 'frontend.product' }"
                class="inline-block rounded-3xl bg-primary px-6 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90">
                {{ $t("label.shop") }}
            </router-link>
        </div>
    </aside>
</template>

<script>
export default {
    name: "BlogSidebarComponent",
    data() {
        return {
            term: (this.$route.query.search || "").toString(),
        };
    },
    computed: {
        categories: function () {
            return this.$store.getters["frontendBlog/categories"];
        },
        popular: function () {
            return this.$store.getters["frontendBlog/popular"];
        },
    },
    methods: {
        submitSearch: function () {
            const query = {};
            if (this.term.trim() !== "") {
                query.search = this.term.trim();
            }
            this.$router.push({ name: "frontend.blog", query });
        },
    },
    watch: {
        // Keep the box in sync when the route changes underneath it, e.g. the
        // "All" pill clearing the query.
        "$route.query.search": function (value) {
            this.term = (value || "").toString();
        },
    },
};
</script>
