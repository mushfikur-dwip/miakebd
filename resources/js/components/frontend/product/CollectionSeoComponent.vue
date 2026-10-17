<template>
    <!-- Under a brand or category listing: what the page is about, where else
         to look, and the questions shoppers ask - with real prices. The same
         words the server puts in the page's FAQPage markup and its crawler
         fallback (App\Support\CollectionSeo); Google reads the rendered page
         and wants marked-up questions to be visible on it. -->
    <section v-if="hasContent" class="cseo" aria-labelledby="cseo-heading">
        <div v-if="seo.intro.length" class="cseo-about">
            <h2 id="cseo-heading" class="cseo-h2">{{ seo.heading }}</h2>
            <p v-for="(paragraph, index) in seo.intro" :key="index" class="cseo-p">{{ paragraph }}</p>
        </div>

        <nav v-if="seo.related.length" class="cseo-related" :aria-label="seo.related_heading">
            <h2 class="cseo-h2">{{ seo.related_heading }}</h2>
            <div class="cseo-chips">
                <router-link v-for="link in seo.related" :key="link.path" :to="link.path" class="cseo-chip">
                    {{ link.name }}<span>{{ link.count }}</span>
                </router-link>
            </div>
        </nav>

        <div v-if="seo.faqs.length" class="cseo-faq">
            <h2 :id="seo.intro.length ? null : 'cseo-heading'" class="cseo-h2">Frequently asked questions</h2>
            <details v-for="(faq, index) in seo.faqs" :key="faq.question" class="cseo-q" :open="index === 0">
                <summary>{{ faq.question }}</summary>
                <p>{{ faq.answer }}</p>
            </details>
        </div>
    </section>
</template>

<script>
import axios from "axios";

export default {
    name: "CollectionSeoComponent",
    props: {
        // "brand" or "category"; null on the plain /product listing.
        type: { type: String, default: null },
        slug: { type: String, default: null },
    },
    data() {
        return {
            seo: null,
            // The request in flight, so a fast click through two brands cannot
            // leave the first one's answers under the second one's products.
            ticket: 0,
        };
    },
    computed: {
        hasContent: function () {
            return !!this.seo && (this.seo.intro.length > 0 || this.seo.faqs.length > 0 || this.seo.related.length > 0);
        },
        // One key for both props: going from a brand to a category changes
        // both at once, which as two watchers fetched the same page twice.
        target: function () {
            return this.type && this.slug ? this.type + "/" + this.slug : null;
        },
    },
    watch: {
        target: { handler: "load", immediate: true },
    },
    methods: {
        load: function () {
            const ticket = ++this.ticket;
            this.seo = null;

            if (!this.type || !this.slug) {
                return;
            }

            axios.get("frontend/collection-seo/" + this.type + "/" + encodeURIComponent(this.slug)).then((res) => {
                if (ticket !== this.ticket) {
                    return;
                }
                this.seo = res.data.data;
                this.applyHead();
            }).catch(() => {
                // No section is better than a broken one; the products above
                // are what the page is for.
            });
        },
        /**
         * The browser tab and the head tags, for moving between listings
         * inside the shop. A fresh load already has them from the server;
         * without this a click from the home page to a brand kept the home
         * page's title. Updated in place so there is never a second canonical
         * or description tag beside the server's.
         */
        applyHead: function () {
            const path = this.type === "brand" ? "/brand/" : "/product-category/";
            const url = window.location.origin + path + encodeURIComponent(this.slug);

            document.title = this.seo.title;
            this.setAttribute('meta[name="description"]', "content", this.seo.description);
            this.setAttribute('meta[property="og:title"]', "content", this.seo.title);
            this.setAttribute('meta[property="og:description"]', "content", this.seo.description);
            this.setAttribute('meta[property="og:url"]', "content", url);
            this.setAttribute('link[rel="canonical"]', "href", url);
        },
        setAttribute: function (selector, attribute, value) {
            const element = document.head.querySelector(selector);

            if (element && value) {
                element.setAttribute(attribute, value);
            }
        },
    },
};
</script>

<style scoped>
.cseo {
    display: grid;
    gap: 28px;
    margin-top: 8px;
    padding-top: 28px;
    border-top: 1px solid #f0f1f4;
}

.cseo-h2 {
    margin: 0 0 10px;
    font-size: 19px;
    font-weight: 700;
    line-height: 1.3;
    color: #1f1f39;
}

.cseo-p {
    max-width: 820px;
    margin: 0 0 10px;
    font-size: 14.5px;
    line-height: 1.7;
    color: #6e7191;
}

.cseo-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.cseo-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 13px;
    font-size: 13.5px;
    font-weight: 600;
    color: #1f1f39;
    background: #ffffff;
    border: 1px solid #e4e5ea;
    border-radius: 99px;
    transition: border-color 0.2s ease, color 0.2s ease, background-color 0.2s ease;
}

.cseo-chip span {
    min-width: 20px;
    padding: 1px 6px;
    font-size: 11.5px;
    text-align: center;
    color: rgb(var(--primary));
    background: rgb(var(--primary-light));
    border-radius: 99px;
}

.cseo-chip:hover {
    color: rgb(var(--primary));
    border-color: rgb(var(--primary));
    background: rgb(var(--primary-slate));
}

.cseo-faq {
    max-width: 860px;
}

.cseo-q {
    border-bottom: 1px solid #f0f1f4;
}

.cseo-q summary {
    position: relative;
    padding: 14px 32px 14px 0;
    font-size: 15px;
    font-weight: 600;
    line-height: 1.45;
    color: #1f1f39;
    list-style: none;
    cursor: pointer;
}

.cseo-q summary::-webkit-details-marker {
    display: none;
}

/* A plus that turns into a minus. */
.cseo-q summary::before,
.cseo-q summary::after {
    content: "";
    position: absolute;
    top: 50%;
    right: 6px;
    width: 12px;
    height: 2px;
    border-radius: 2px;
    background: rgb(var(--primary));
    transition: transform 0.25s ease;
}

.cseo-q summary::after {
    transform: rotate(90deg);
}

.cseo-q[open] summary::after {
    transform: rotate(0deg);
}

.cseo-q summary:hover {
    color: rgb(var(--primary));
}

.cseo-q p {
    margin: 0;
    padding: 0 32px 16px 0;
    font-size: 14.5px;
    line-height: 1.7;
    color: #6e7191;
}

@media (prefers-reduced-motion: reduce) {
    .cseo-chip,
    .cseo-q summary::before,
    .cseo-q summary::after {
        transition: none;
    }
}
</style>
