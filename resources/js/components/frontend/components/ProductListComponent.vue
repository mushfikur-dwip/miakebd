<template>
    <div v-if="products.length > 0" v-for="product in products"
        class="p-2 miron contain-layout rounded-2xl bg-white shadow-card transition-[transform,box-shadow] duration-300 sm:hover:shadow-hover sm:hover:-translate-y-1 group">
        <div class="relative overflow-hidden rounded-xl isolate card-shine">
            <label
                class="capitalize text-xs font-semibold rounded-xl py-1 px-2 shadow-badge absolute top-3 left-3 z-10 bg-secondary text-white"
                v-if="product.is_offer && product.flash_sale">{{ $t('label.flash_sale') }}</label>

            <label v-if="discountPercent(product) > 0"
                class="text-xs font-bold rounded-full py-0.5 px-2 shadow-badge absolute bottom-3 left-3 z-10 bg-shopperz-red text-white">
                -{{ discountPercent(product) }}%
            </label>

            <button type="button" @click.prevent="wishlist(product, product.wishlist = !product.wishlist)"
                :class="product.wishlist ? 'lab-fill-heart text-primary' : 'lab-line-heart'"
                class="w-7 h-7 leading-7 rounded-full text-center text-base shadow-badge absolute top-3 right-3 z-10 bg-white transition-all duration-300 hover:scale-110 active:scale-95">
            </button>

            <router-link class="overflow-hidden rounded-xl w-full"
                :to="{ name: 'frontend.product.details', params: { slug: product.slug } }">
                <ProductImage
                    :src="product.cover"
                    :alt="product.name"
                    :width="372"
                    :height="405"
                    img-class="w-full h-full object-cover rounded-xl transition-transform duration-500 group-hover:scale-105"
                />
            </router-link>
        </div>

        <router-link class="block" :to="{ name: 'frontend.product.details', params: { slug: product.slug } }">
            <div class="px-1 sm:px-0 pt-4 pb-2">
                <h3 :title="product.name" class="capitalize text-base font-semibold leading-snug text-heading line-clamp-2 min-h-[2.75rem] transition-colors duration-300 hover:text-primary">
                    {{ product.name }}
                </h3>

                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <div class="flex items-center gap-1">
                        <starRating border-color="#FFBC1F" :rounded-corners="true" :padding="2.5" :border-width="2.5"
                            :star-size="9" class="mt-[2px]" inactive-color="#FFFFFF" active-color="#FFBC1F"
                            :round-start-rating="false" :show-rating="false" :read-only="true" :max-rating="5"
                            :rating="(product.rating_star / product.rating_star_count)" />
                    </div>
                    <div v-if="product.rating_star_count > 0" class="flex items-center gap-1 mt-[5px]">
                        <span class="text-xs font-medium whitespace-nowrap text-text">{{ (product.rating_star /
                            product.rating_star_count).toFixed(1) }}</span>
                        <span class="text-xs font-medium whitespace-nowrap text-text hover:text-primary">({{
                            product.rating_star_count }} {{ product.rating_star_count > 1 ? $t('label.reviews') :
                                $t('label.review') }})</span>
                    </div>
                </div>

                <div class="flex flex-wrap-reverse items-baseline gap-x-3 gap-y-1" v-if="product.is_offer">
                    <h3 class="text-xl sm:text-[22px] font-bold text-primary">
                        <span>{{ product.discounted_price }}</span>
                    </h3>
                    <h4 class="text-sm font-medium text-text">
                        <del>{{ product.currency_price }}</del>
                    </h4>
                </div>
                <h4 class="text-xl sm:text-[22px] font-bold text-primary" v-else>
                    <span>{{ product.currency_price }}</span>
                </h4>
            </div>
        </router-link>

        <div class="px-1 sm:px-0 pb-2">
            <button @click.prevent="addToCart(product)" type="button"
                class="w-full h-11 rounded-full bg-primary text-white font-bold text-sm transition-all duration-300 hover:bg-primary/90 hover:shadow-btn-primary active:scale-[0.98] flex items-center justify-center gap-2">
                <i class="lab-line-shopping-bag text-base"></i>
                <span>{{ $t('button.add_to_cart') }}</span>
            </button>
        </div>
    </div>
</template>

<script>

import starRating from "vue-star-rating";
import router from "../../../router";
import alertService from "../../../services/alertService";
import ProductImage from "./ProductImage";
export default {
    name: "ProductListComponent",
    components: {
        starRating,
        ProductImage
    },
    props: {
        "products": "object",
    },
    data() {
        return {
            rating: []
        }
    },
    methods: {
        discountPercent: function (product) {
            const price = parseFloat(product.flat_price);
            const discounted = parseFloat(product.flat_discounted_price);
            if (!product.is_offer || !price || !discounted || discounted >= price) {
                return 0;
            }
            return Math.round(((price - discounted) / price) * 100);
        },
        wishlist: function (product, toggle) {
            this.$store.dispatch("frontendWishlist/toggle", {
                product_id: product.id,
                toggle: toggle
            }).then((res) => {
            }).catch((err) => {
                if (err.response.status === 401) {
                    product.wishlist = false;
                    router.push({ name: "auth.login" });
                }
            });
        },
        addToCart: function (product) {
            // Determine the correct price based on whether it's an offer
            const finalPrice = product.is_offer ? (product.flat_discounted_price || 0) : (product.flat_price || 0);
            const oldPrice = product.flat_price || 0;
            
            const productArray = {
                name: product.name,
                product_id: product.id,
                image: product.cover,
                variation_names: '',
                variation_id: 0,
                sku: product.sku || '',
                stock: product.stock || 100,
                taxes: product.taxes || [],
                shipping: product.shipping || {},
                quantity: 1,
                discount: product.discount || 0,
                price: finalPrice,
                old_price: oldPrice,
                total_price: finalPrice,
                maximum_purchase_quantity: product.maximum_purchase_quantity || 999,
                // Only campaign resources emit price_source; every other
                // listing that renders this card falls through to "catalogue".
                // It is what keeps a campaign-priced line and a normally
                // priced line for the same product apart in the cart.
                price_source: product.price_source || 'catalogue',
                campaign_id: product.campaign_id || null
            };

            this.$store.dispatch("frontendCart/lists", productArray).then((res) => {
                alertService.success(this.$t('message.add_to_cart'));
            }).catch((err) => {
                if (err.message === 'maximum_quantity') {
                    alertService.error(this.$t('message.maximum_quantity'));
                } else if (err.message === 'stockOut') {
                    alertService.error(this.$t('message.stock_out'));
                } else {
                    alertService.error(this.$t('message.something_went_wrong'));
                }
            });
        }
    }
}
</script>
