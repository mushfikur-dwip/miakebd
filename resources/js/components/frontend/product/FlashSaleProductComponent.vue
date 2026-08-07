<template>
    <!-- A running Flash Sale campaign owns this page: it prices its own
         products and carries the countdown. With no campaign running the page
         falls back to the long-standing product-flag listing, so /flash-sale
         is never empty just because nobody has created a campaign. -->
    <CampaignProductComponent v-if="flashCampaign" :slug="flashCampaign.slug" />

    <template v-else>
        <LoadingComponent :props="loading"/>
        <section class="mb-10 sm:mb-20">
            <div class="container">
                <div class="flex items-center justify-between gap-5 mb-6 max-md:mb-8">
                    <div class="flex flex-wrap items-end gap-3 max-md:flex-col max-md:items-start max-md:gap-1.5">
                        <h3 class="text-3xl font-bold capitalize max-sm:text-lg">
                            {{ $t('label.flash_sale') }}
                        </h3>
                        <span class="text-xl font-medium capitalize max-sm:text-sm">
                            ({{ products.length }} {{ products.length > 1 ? $t('label.products_found') : $t('label.product_found') }})
                        </span>
                    </div>
                </div>

                <div class="w-full max-md:p-0">
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 mb-12">
                        <LoadingContentComponent :props="loadingContent"/>
                        <ProductListComponent v-if="products.length > 0" :products="products"/>
                    </div>
                    <PaginationComponent @pagination-change-page="flashSaleProducts" :data="pagination" :limit="1" :keep-length="false"/>
                </div>
            </div>
        </section>
    </template>
</template>

<script>
import ProductListComponent from "../components/ProductListComponent";
import LoadingComponent from "../components/LoadingComponent";
import LoadingContentComponent from "../components/LoadingContentComponent";
import PaginationComponent from "../components/PaginationComponent";
import CampaignProductComponent from "./CampaignProductComponent";
import campaignTypeEnum from "../../../enums/modules/campaignTypeEnum";

export default {
    name: "FlashSaleProductComponent",
    components: {
        LoadingComponent,
        LoadingContentComponent,
        ProductListComponent,
        PaginationComponent,
        CampaignProductComponent,
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
            loadingContent: {
                isActive: false
            },
        }
    },
    computed: {
        pagination: function () {
            return this.$store.getters["frontendProduct/flashSaleProductPagination"];
        },
        products: function () {
            return this.$store.getters["frontendProduct/flashSaleProducts"];
        },
        flashCampaign: function () {
            const campaigns = this.$store.getters["frontendCampaign/lists"] || [];
            // The API only returns campaigns inside their window, so the first
            // flash one here is by definition running. The list is ordered by
            // ends_at, so the soonest to finish wins if two ever overlap.
            return campaigns.find(campaign => campaign.type === campaignTypeEnum.FLASH) || null;
        }
    },
    mounted() {
        // The nav fills this store on every full page load, but a customer
        // landing straight on /flash-sale can arrive before it resolves — and
        // the fallback list must not be fetched and shown in that gap only to
        // be replaced a moment later.
        if ((this.$store.getters["frontendCampaign/lists"] || []).length === 0) {
            this.loading.isActive = true;
            this.$store.dispatch("frontendCampaign/lists").then(() => {
                this.loading.isActive = false;
                this.loadFallback();
            }).catch(() => {
                this.loading.isActive = false;
                this.loadFallback();
            });
        } else {
            this.loadFallback();
        }
    },
    methods: {
        loadFallback: function () {
            if (this.flashCampaign) {
                return;
            }
            this.flashSaleProducts();
        },
        flashSaleProducts: function (page = 1) {
            this.loadingContent.isActive = true;
            this.$store.dispatch("frontendProduct/flashSaleProducts", {
                paginate: 1,
                page: page,
                per_page: 32,
                order_column: "name",
                order_type: "asc",
            }).then(() => {
                this.loadingContent.isActive = false;
            }).catch(() => {
                this.loadingContent.isActive = false;
            });
        },
    }
}
</script>
