<template>
    <section class="mb-10 sm:mb-20">
        <div class="container">
            <div class="mb-6">
                <h2 class="text-[26px] leading-10 font-semibold capitalize mb-2">
                    {{ page.title }}
                </h2>
                <div v-if="page.image" class="w-full mb-6">
                    <img :src="page.image" :alt="page.title">
                </div>
                <div class="ql-editor" v-html="page.description"></div>
            </div>
            <TemplateManagerComponent :menuTemplateId="page.menu_template_id" />
        </div>
    </section>
</template>

<script>
import TemplateManagerComponent from "../components/TemplateManagerComponent.vue";
import 'vue3-quill/lib/vue3-quill.css';
import { useHead } from "@vueuse/head";
import { ref } from "vue";

export default {
    name: "PageComponent",
    components: {TemplateManagerComponent},
    // The tab title and description follow the page, as the server-rendered
    // HTML already does (RootController::page). Before, arriving here from
    // another screen kept that screen's title.
    setup() {
        const seoHead = ref({});
        useHead(seoHead);
        return { seoHead };
    },
    computed: {
        page: function () {
            return this.$store.getters['frontendPage/show'];
        }
    },
    mounted() {
        this.pageSetup();
        this.setHead(this.page);
    },
    methods: {
        pageSetup: function () {
            if (Object.keys(this.$route.params).length > 0 && typeof this.$route.params.slug === 'string') {
                this.$store.dispatch('frontendPage/show', this.$route.params.slug).then().catch()
            }
        },
        setHead: function (page) {
            if (!page || !page.title) {
                return;
            }
            // DOMParser, not innerHTML: a parsed document never loads the
            // page's images or runs anything in it.
            const text = new DOMParser().parseFromString(page.description || '', 'text/html')
                .body.textContent.replace(/\s+/g, ' ').trim();
            const description = text.length > 155 ? text.slice(0, 154).replace(/\s+\S*$/, '') + '…' : text;

            this.seoHead = {
                title: `${page.title} | Suglow`,
                meta: [{
                    name: 'description',
                    content: description || `${page.title} — Suglow, authentic cosmetics and skincare in Bangladesh.`,
                }],
            };
        }
    },
    watch: {
        $route() {
            this.pageSetup();
        },
        page(page) {
            this.setHead(page);
        }
    }
}
</script>
