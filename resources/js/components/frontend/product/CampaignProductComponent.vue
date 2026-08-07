<template>
    <LoadingComponent :props="loading" />
    <section class="mb-10 sm:mb-20">
        <div class="container">
            <div v-if="available">
                <div class="flex flex-wrap items-center justify-between gap-4 mb-6 max-md:mb-8">
                    <div class="flex flex-wrap items-end gap-3 max-md:flex-col max-md:items-start max-md:gap-1.5">
                        <h3 class="text-3xl font-bold capitalize max-sm:text-lg">
                            {{ campaign.name }}
                        </h3>
                        <span class="text-xl font-medium capitalize max-sm:text-sm">
                            ({{ total }} {{ total > 1 ? $t('label.products_found') : $t('label.product_found') }})
                        </span>
                    </div>

                    <CampaignCountdownComponent :ends-in-seconds="campaign.ends_in_seconds || 0" @expired="expire" />
                </div>

                <div class="w-full max-md:p-0">
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 mb-12">
                        <LoadingContentComponent :props="loadingContent" />
                        <ProductListComponent v-if="products.length > 0" :products="products" />
                    </div>
                    <PaginationComponent @pagination-change-page="campaignProducts" :data="pagination" :limit="1"
                        :keep-length="false" />
                </div>
            </div>

            <!-- Reached by an old link, or by sitting on the page past the end
                 time. Nothing here is priced any more, so show the ending
                 rather than an empty grid that looks like a bug. -->
            <div v-else-if="!loading.isActive" class="py-16 text-center">
                <h3 class="text-2xl font-bold capitalize max-sm:text-lg">{{ $t('label.campaign_ended') }}</h3>
                <p class="mt-2 text-text">{{ $t('message.campaign_ended') }}</p>
                <router-link :to="{ name: 'frontend.product' }"
                    class="inline-block mt-6 py-3 px-6 rounded-3xl font-semibold bg-primary text-white transition-all duration-300 hover:bg-primary/90">
                    {{ $t('label.shop_now') }}
                </router-link>
            </div>
        </div>
    </section>
</template>

<script>
import ProductListComponent from "../components/ProductListComponent.vue";
import LoadingContentComponent from "../components/LoadingContentComponent.vue";
import LoadingComponent from "../components/LoadingComponent.vue";
import PaginationComponent from "../components/PaginationComponent.vue";
import CampaignCountdownComponent from "../components/CampaignCountdownComponent.vue";

export default {
    name: "CampaignProductComponent",
    components: {
        PaginationComponent,
        LoadingComponent,
        LoadingContentComponent,
        ProductListComponent,
        CampaignCountdownComponent
    },
    props: {
        /**
         * Overrides the route param. /flash-sale renders this component for
         * the running flash campaign without moving the customer off that URL,
         * so the header link and any existing bookmark keep working.
         */
        slug: {
            type: String,
            default: null
        }
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
            loadingContent: {
                isActive: false
            },
            // Flipped false by a rejected fetch (the API refuses a campaign
            // outside its window) or by the countdown reaching zero.
            available: true,
        }
    },
    computed: {
        campaignSlug: function () {
            return this.slug || this.$route.params.slug || null;
        },
        campaign: function () {
            return this.$store.getters["frontendCampaign/show"];
        },
        pagination: function () {
            return this.$store.getters["frontendCampaign/productPagination"];
        },
        products: function () {
            return this.$store.getters["frontendCampaign/products"];
        },
        total: function () {
            return this.$store.getters["frontendCampaign/productPage"]?.total ?? this.products.length;
        }
    },
    watch: {
        // The nav can move between two campaigns without unmounting this
        // component; without a watcher the second one would keep the first
        // one's products and countdown.
        campaignSlug: function (slug) {
            if (slug) {
                this.load();
            }
        }
    },
    mounted() {
        this.load();
    },
    methods: {
        load: function () {
            if (!this.campaignSlug) {
                return;
            }

            this.available = true;
            this.loading.isActive = true;

            this.$store.dispatch("frontendCampaign/show", this.campaignSlug).then(() => {
                this.$store.dispatch("frontendCampaign/products", {
                    slug: this.campaignSlug,
                    per_page: 32,
                }).then(() => {
                    this.loading.isActive = false;
                }).catch(() => {
                    this.available = false;
                    this.loading.isActive = false;
                });
            }).catch(() => {
                this.available = false;
                this.loading.isActive = false;
            });
        },
        campaignProducts: function (page = 1) {
            if (!this.campaignSlug) {
                return;
            }

            this.loadingContent.isActive = true;
            this.$store.dispatch("frontendCampaign/products", {
                slug: this.campaignSlug,
                per_page: 32,
                page: page
            }).then(() => {
                this.loadingContent.isActive = false;
            }).catch(() => {
                this.available = false;
                this.loadingContent.isActive = false;
            });
        },
        expire: function () {
            this.available = false;
        }
    }
}
</script>
