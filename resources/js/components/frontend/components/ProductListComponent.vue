<template>
    <!-- Cards after the first row fade up as they scroll into view. The first
         row is left alone: it is what the shopper sees on arrival, and hiding
         it for an animation would only delay the page's largest paint. -->
    <div v-if="products.length > 0" v-for="(product, index) in products" :key="product.id"
        v-reveal="index >= 4 ? (index % 4) * 70 : false"
        class="p-2 miron contain-layout rounded-2xl bg-white shadow-card transition-[transform,box-shadow] duration-300 sm:hover:shadow-hover sm:hover:-translate-y-1 group">
        <div class="relative overflow-hidden rounded-xl isolate card-shine">
            <label
                class="capitalize text-xs font-semibold rounded-xl py-1 px-2 shadow-badge absolute top-3 left-3 z-10 bg-secondary text-white"
                v-if="product.is_offer && product.flash_sale">{{ $t('label.flash_sale') }}</label>

            <label v-if="discountPercent(product) > 0"
                class="text-xs font-bold rounded-full py-0.5 px-2 shadow-badge absolute bottom-3 left-3 z-10 bg-shopperz-red text-white">
                -{{ discountPercent(product) }}%
            </label>

            <span v-if="isStockOut(product)"
                class="text-[11px] font-bold uppercase tracking-wide rounded-full py-0.5 px-2 absolute bottom-3 right-3 z-10 bg-white/90 text-heading">
                {{ $t('label.stock_out') }}
            </span>

            <button type="button" @click.prevent="wishlist(product, product.wishlist = !product.wishlist)"
                :class="[product.wishlist ? 'lab-fill-heart text-primary' : 'lab-line-heart', popped === product.id ? 'heart-pop' : '']"
                :aria-label="$t('button.favorite')" :aria-pressed="!!product.wishlist"
                class="w-7 h-7 leading-7 rounded-full text-center text-base shadow-badge absolute top-3 right-3 z-10 bg-white transition-all duration-300 hover:scale-110 active:scale-95">
            </button>

            <router-link class="overflow-hidden rounded-xl w-full"
                :to="{ name: 'frontend.product.details', params: { slug: product.slug } }">
                <ProductImage
                    :src="product.cover"
                    :alt="product.name"
                    :width="372"
                    :height="405"
                    :eager="index < 2"
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
            <!-- Out of stock: said on the card, not discovered at the last step
                 of checkout when the order is refused. -->
            <button v-if="isStockOut(product)" type="button" disabled
                class="w-full h-11 rounded-full bg-[#F1F1F6] text-[#8a8ca3] font-bold text-sm cursor-not-allowed flex items-center justify-center gap-2">
                <span>{{ $t('label.stock_out') }}</span>
            </button>

            <!-- A size or shade has to be picked first, on the product page. -->
            <router-link v-else-if="product.has_variations"
                :to="{ name: 'frontend.product.details', params: { slug: product.slug } }"
                class="w-full h-11 rounded-full border-2 border-primary text-primary font-bold text-sm transition-all duration-300 hover:bg-primary hover:text-white active:scale-[0.98] flex items-center justify-center gap-2">
                <span>{{ $t('button.choose_options') }}</span>
                <i class="lab-line-arrow-right text-sm"></i>
            </router-link>

            <button v-else @click.prevent="addToCart(product)" type="button"
                :class="added === product.id ? 'bg-success hover:bg-success' : 'bg-primary hover:bg-primary/90'"
                class="cart-btn w-full h-11 rounded-full text-white font-bold text-sm transition-all duration-300 hover:shadow-btn-primary active:scale-[0.98] flex items-center justify-center gap-2">
                <template v-if="added === product.id">
                    <svg class="cart-btn-tick w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 6 9 17l-5-5" />
                    </svg>
                    <span>{{ $t('label.added') }}</span>
                </template>
                <template v-else>
                    <i class="lab-line-shopping-bag text-base"></i>
                    <span>{{ $t('button.add_to_cart') }}</span>
                </template>
            </button>
        </div>
    </div>
</template>

<script>

import starRating from "vue-star-rating";
import router from "../../../router";
import alertService from "../../../services/alertService";
import pixelService from "../../../services/pixelService";
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
            rating: [],
            // The card currently showing "Added", and the heart mid-pop.
            added: null,
            popped: null,
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
        // null means the listing did not say; only a real figure of 0 or
        // less is out of stock.
        isStockOut: function (product) {
            return product.stock !== null && product.stock !== undefined && Number(product.stock) <= 0;
        },
        wishlist: function (product, toggle) {
            if (toggle) {
                this.popped = product.id;
                setTimeout(() => {
                    if (this.popped === product.id) {
                        this.popped = null;
                    }
                }, 450);
            }

            this.$store.dispatch("frontendWishlist/toggle", {
                product_id: product.id,
                toggle: toggle
            }).then((res) => {
                if (toggle) {
                    pixelService.addToWishlist(product);
                }
            }).catch((err) => {
                // No response at all on a dropped connection.
                if (err && err.response && err.response.status === 401) {
                    product.wishlist = false;
                    router.push({ name: "auth.login" });
                } else {
                    product.wishlist = !toggle;
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
                // A listing that did not load stock sends null; the server
                // still checks stock when the order is placed.
                stock: product.stock === null || product.stock === undefined ? 100 : Number(product.stock),
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
                pixelService.addToCart(product, 1);
                alertService.success(this.$t('message.add_to_cart'));

                this.added = product.id;
                setTimeout(() => {
                    if (this.added === product.id) {
                        this.added = null;
                    }
                }, 1600);
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

<style scoped>
/* The heart swells and settles when a product is saved. */
.heart-pop {
    animation: heart-pop 0.45s cubic-bezier(0.2, 1.6, 0.4, 1);
}

@keyframes heart-pop {
    0% { transform: scale(1); }
    40% { transform: scale(1.35); }
    100% { transform: scale(1); }
}

/* The tick draws itself on the "Added" state. */
.cart-btn-tick path {
    stroke-dasharray: 24;
    stroke-dashoffset: 24;
    animation: cart-tick 0.35s ease-out forwards;
}

@keyframes cart-tick {
    to { stroke-dashoffset: 0; }
}
</style>
