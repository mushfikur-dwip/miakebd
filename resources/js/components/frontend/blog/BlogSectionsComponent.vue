<template>
    <div v-if="sections.length > 0" class="flex flex-col gap-10 sm:gap-12">
        <!-- One row per blog category, newest first inside each — the way the
             reference magazine groups its feed. Mirrors the shop's product
             categories, so a sunscreen article sits under Sunscreen. -->
        <section v-for="section in sections" :key="section.slug">
            <div class="mb-4 flex items-end justify-between gap-4">
                <div>
                    <h2 class="mb-1 text-xl sm:text-2xl font-bold capitalize text-heading">{{ section.name }}</h2>
                    <span class="block h-1 w-10 rounded-full bg-primary"></span>
                </div>
                <router-link :to="{ name: 'frontend.blog.category', params: { slug: section.slug } }"
                    class="shrink-0 text-sm font-semibold text-primary transition-opacity hover:opacity-80">
                    {{ $t("label.view_all") }}
                    <i class="lab lab-line-arrow-right text-xs rtl:rotate-180"></i>
                </router-link>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 sm:gap-6">
                <BlogCardComponent v-for="post in section.posts" :key="post.id" :post="post" />
            </div>
        </section>
    </div>
</template>

<script>
import BlogCardComponent from "./BlogCardComponent";

export default {
    name: "BlogSectionsComponent",
    components: { BlogCardComponent },
    computed: {
        sections: function () {
            return this.$store.getters["frontendBlog/sections"];
        },
    },
};
</script>
