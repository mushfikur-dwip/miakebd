<template>
    <!-- Overlay variant: the headline sits on the photo, magazine style.
         Used for the hero block on the blog landing page. -->
    <router-link v-if="overlay" :to="{ name: 'frontend.blog.details', params: { slug: post.slug } }"
        class="group relative block h-full overflow-hidden rounded-2xl shadow-card transition-shadow duration-300 hover:shadow-hover">
        <img v-if="post.cover" :src="post.cover" :alt="post.title" loading="lazy"
            class="absolute inset-0 h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" />
        <div v-else class="absolute inset-0 bg-primary-light"></div>

        <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/35 to-black/5"></div>

        <div class="relative flex h-full flex-col justify-end p-4 sm:p-6">
            <span v-if="post.category"
                class="mb-2 sm:mb-3 inline-block w-fit px-3 py-1 text-xs font-semibold text-white bg-primary rounded-full">
                {{ post.category.name }}
            </span>

            <h3 :class="big ? 'text-xl sm:text-3xl' : 'text-base sm:text-lg'"
                class="font-bold leading-snug text-white line-clamp-3">
                {{ post.title }}
            </h3>

            <div class="mt-2 flex items-center gap-3 text-xs text-white/80">
                <span v-if="post.published_human" class="flex items-center gap-1">
                    <i class="lab lab-line-calendar"></i>
                    {{ post.published_human }}
                </span>
                <span v-if="post.reading_minutes">· {{ post.reading_minutes }} {{ $t("label.min_read") }}</span>
            </div>
        </div>
    </router-link>

    <!-- Standard card, styled like the shop's product cards: soft shadow,
         rounded corners, primary-coloured category eyebrow. -->
    <article v-else
        class="group flex h-full flex-col overflow-hidden rounded-2xl bg-white shadow-card transition-all duration-300 hover:shadow-hover">
        <router-link :to="{ name: 'frontend.blog.details', params: { slug: post.slug } }"
            class="relative block aspect-[4/3] overflow-hidden">
            <img v-if="post.cover" :src="post.cover" :alt="post.title" loading="lazy"
                class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" />
            <div v-else class="h-full w-full bg-primary-light"></div>
        </router-link>

        <div class="flex flex-1 flex-col p-4 sm:p-5">
            <span v-if="post.category" class="mb-1 text-xs font-semibold uppercase tracking-wide text-primary">
                {{ post.category.name }}
            </span>

            <h3 class="mb-2 text-base sm:text-lg font-bold leading-snug text-heading line-clamp-2">
                <router-link :to="{ name: 'frontend.blog.details', params: { slug: post.slug } }"
                    class="transition-colors hover:text-primary">
                    {{ post.title }}
                </router-link>
            </h3>

            <p class="mb-4 text-sm leading-relaxed text-paragraph line-clamp-3">{{ post.excerpt }}</p>

            <div class="mt-auto flex items-center justify-between gap-3 text-xs text-paragraph">
                <span v-if="post.published_human" class="flex items-center gap-1">
                    <i class="lab lab-line-calendar"></i>
                    {{ post.published_human }}
                </span>
                <span class="flex items-center gap-1 font-semibold text-primary">
                    {{ $t("label.read_more") }}
                    <i class="lab lab-line-arrow-right rtl:rotate-180"></i>
                </span>
            </div>
        </div>
    </article>
</template>

<script>
export default {
    name: "BlogCardComponent",
    props: {
        post: { type: Object, required: true },
        // Text-on-photo magazine card for the hero block.
        overlay: { type: Boolean, default: false },
        // Larger headline for the lead story of the hero block.
        big: { type: Boolean, default: false },
    },
};
</script>
