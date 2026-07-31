<template>
    <!-- The one order-summary card, used by all three checkout steps.
         Line items, the promo panel and the step's own action all live inside
         it so the customer reads price, discount and button as one block. -->
    <div class="co-card">
        <div class="co-card-head">
            <span class="co-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 2v20l2.5-1.5L9 22l3-1.5L15 22l2.5-1.5L20 22V2l-2.5 1.5L15 2l-3 1.5L9 2 6.5 3.5 4 2z" />
                    <path d="M8 8h8M8 12h8M8 16h5" />
                </svg>
            </span>
            <h3>{{ $t('label.order_summery') }}</h3>
        </div>

        <div class="co-card-body" :class="items ? 'pt-1.5' : ''">
            <div v-if="items && carts.length > 0" class="co-items">
                <div v-for="(cart, index) in carts" :key="index" class="co-item">
                    <img class="co-thumb" :src="cart.image" :alt="cart.name" loading="lazy" />
                    <div class="co-item-text">
                        <b>{{ cart.name }}</b>
                        <span>{{ $t('label.quantity') }} {{ cart.quantity }} &times; {{ money(cart.price) }}</span>
                    </div>
                    <span class="co-price">{{ money(lineTotal(cart)) }}</span>
                </div>
            </div>

            <!-- Coupon / wallet sits between the items and the totals, so an
                 applied discount appears right above the number it changed. -->
            <slot name="promo"></slot>

            <div class="co-rows">
                <div class="co-row">
                    <span>{{ $t('label.subtotal') }}</span>
                    <span>{{ money(subtotal) }}</span>
                </div>
                <div v-if="totalTax > 0" class="co-row">
                    <span>{{ $t('label.tax') }}</span>
                    <span>{{ money(totalTax) }}</span>
                </div>
                <div class="co-row">
                    <span>{{ $t('label.shipping_charge') }}</span>
                    <span>{{ money(shippingCharge) }}</span>
                </div>
                <div v-if="discount > 0" class="co-row">
                    <span>{{ $t('label.discount') }}</span>
                    <span class="text-success">-{{ money(discount) }}</span>
                </div>
                <div v-if="walletDiscount > 0" class="co-row">
                    <span>{{ $t('label.wallet_discount') }}</span>
                    <span class="text-success">-{{ money(walletDiscount) }}</span>
                </div>
                <div class="co-row total">
                    <span>{{ $t('label.total') }}</span>
                    <span>{{ money(total) }}</span>
                </div>
            </div>

            <slot name="action"></slot>
        </div>
    </div>
</template>

<script>
import appService from "../../../services/appService";

export default {
    name: "SummeryComponent",
    props: {
        // Off on the cart step, where the same products are already listed
        // beside the summary.
        items: { type: Boolean, default: true }
    },
    computed: {
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        carts: function () {
            return this.$store.getters['frontendCart/lists'];
        },
        subtotal: function () {
            return this.$store.getters['frontendCart/subtotal'];
        },
        discount: function () {
            return this.$store.getters['frontendCart/discount'];
        },
        walletDiscount: function () {
            return this.$store.getters['frontendCart/walletDiscount'];
        },
        totalTax: function () {
            return this.$store.getters['frontendCart/totalTax'];
        },
        shippingCharge: function () {
            return this.$store.getters['frontendCart/shippingCharge'];
        },
        total: function () {
            return this.$store.getters['frontendCart/total'];
        }
    },
    methods: {
        money(amount) {
            const setting = this.setting || {};

            return appService.currencyFormat(
                amount,
                setting.site_digit_after_decimal_point,
                setting.site_default_currency_symbol,
                setting.site_currency_position
            );
        },
        lineTotal(cart) {
            // `total` is the taxed line total the cart already computed; fall
            // back to the arithmetic so a cart saved by an older build still
            // shows a number instead of a blank.
            if (typeof cart.total !== 'undefined' && cart.total !== null) {
                return cart.total;
            }

            return cart.price * cart.quantity;
        }
    }
}
</script>
