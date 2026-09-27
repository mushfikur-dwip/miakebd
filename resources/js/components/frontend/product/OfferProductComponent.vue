<template>
    <LoadingComponent :props="loading"/>
    <section class="mb-10 sm:mb-20">
        <div class="container">
            <div class="flex items-center justify-between gap-5 mb-6 max-md:mb-8">
                <div class="flex flex-wrap items-end gap-3 max-md:flex-col max-md:items-start max-md:gap-1.5">
                    <!-- The page's main heading (was an h3, leaving no h1). -->
                    <h1 class="text-3xl font-bold capitalize max-sm:text-lg">
                        {{ $t('label.offer_products') }}
                    </h1>
                    <span v-if="products.length > 0" class="text-xl font-medium capitalize max-sm:text-sm">
                        ({{ products.length }} {{ products.length > 1 ? $t('label.products_found') : $t('label.product_found') }})
                    </span>
                </div>
            </div>

            <div class="w-full max-md:p-0">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 mb-12">
                    <LoadingContentComponent :props="loadingContent"/>
                    <ProductListComponent v-if="products.length > 0" :products="products"/>
                </div>
                <!-- No live offers: a blank page with "(0 Product Found)" looked
                     broken, so say so and point somewhere to shop. -->
                <div v-if="loaded && !loadingContent.isActive && products.length === 0"
                    class="flex flex-col items-center text-center gap-4 py-14 px-6 mb-12 rounded-2xl bg-primary-slate">
                    <i class="lab-line-bag text-4xl text-primary" aria-hidden="true"></i>
                    <p class="text-base sm:text-lg font-semibold max-w-md">{{ $t('message.no_offers_now') }}</p>
                    <router-link :to="{ name: 'frontend.product' }"
                        class="inline-flex items-center h-11 px-6 rounded-full font-bold text-white bg-primary shadow-btn-primary">
                        {{ $t('label.explore_all_products') }}
                    </router-link>
                </div>
                <PaginationComponent @pagination-change-page="offerProducts" :data="pagination" :limit="1"
                                     :keep-length="false"/>
            </div>
        </div>
    </section>
</template>

<script>

import ProductListComponent from "../components/ProductListComponent.vue";
import LoadingContentComponent from "../components/LoadingContentComponent.vue";
import LoadingComponent from "../components/LoadingComponent.vue";
import PaginationComponent from "../components/PaginationComponent.vue";

export default {
    name: "OfferProductComponent",
    components: {
        PaginationComponent,
        LoadingComponent,
        LoadingContentComponent,
        ProductListComponent
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
            loadingContent: {
                isActive: false
            },
            // The empty message waits for the first answer, so it never
            // flashes up while the offers are still loading.
            loaded: false,
        }
    },
    computed: {
        pagination: function () {
            return this.$store.getters["frontendProduct/offerProductPagination"];
        },
        products: function () {
            return this.$store.getters["frontendProduct/offerProducts"];
        }
    },
    mounted() {
        this.offerProducts();
    },
    methods: {
        offerProducts: function (page = 1) {
            this.loadingContent.isActive = true;
            this.$store.dispatch("frontendProduct/offerProducts", {
                paginate: 1,
                page: page,
                per_page: 32,
                order_column: "name",
                order_type: "asc",
            }).then((res) => {
                this.loadingContent.isActive = false;
                this.loaded = true;
            }).catch((err) => {
                this.loadingContent.isActive = false;
                this.loaded = true;
            });
        },
    }
}
</script>