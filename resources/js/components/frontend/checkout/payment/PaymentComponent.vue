<template>
    <LoadingComponent :props="loading" />
    <div class="row">
        <div class="col-12 lg:col-8 flex flex-col gap-4">
            <!-- The guest form goes straight from typing the address to here,
                 so this is where it is read back before the order is placed. -->
            <div v-if="deliverTo" class="co-card">
                <div class="co-card-head">
                    <span class="co-card-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                            <circle cx="12" cy="10" r="3" />
                        </svg>
                    </span>
                    <h3>{{ deliverTo.heading }}</h3>
                    <router-link :to="{ name: 'frontend.checkout.checkout' }" class="co-card-link">
                        {{ $t('button.change') }}
                    </router-link>
                </div>
                <div class="co-card-body pay-deliver">
                    <b>{{ deliverTo.name }}</b>
                    <span v-if="deliverTo.phone" dir="ltr">{{ deliverTo.phone }}</span>
                    <span v-if="deliverTo.address">{{ deliverTo.address }}</span>
                </div>
            </div>

            <div v-if="remainingAmount > 0" class="co-card">
                <div class="co-card-head">
                    <span class="co-card-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="5" width="20" height="14" rx="2" />
                            <path d="M2 10h20" />
                        </svg>
                    </span>
                    <h3>{{ $t('label.select_payment_method') }}</h3>
                </div>

                <div class="co-card-body">
                    <!-- A row per way to pay: picture, name, what happens
                         next, and a tick. They rise in one after another as
                         the list arrives, and the chosen one's picture pops. -->
                    <div class="co-opts co-opts--pay" role="radiogroup"
                         :aria-label="$t('label.select_payment_method')">
                        <button v-for="(method, index) in paymentOptions" :key="method.id"
                                type="button" class="co-opt co-pay" role="radio"
                                :aria-checked="isSelected(method)"
                                :class="isSelected(method) ? 'selected' : ''"
                                :style="{ '--i': index }"
                                @click.prevent="selectPaymentMethod(method)">
                            <span class="co-pay-icon" :class="paymentArt(method) ? 'co-pay-icon--art' : ''">
                                <img :src="paymentArt(method) || method.image" alt="" width="56" height="48"
                                     decoding="async" />
                            </span>
                            <span class="co-pay-text">
                                <b>{{ method.name }}</b>
                                <span v-if="paymentHint(method)">{{ paymentHint(method) }}</span>
                            </span>
                            <span class="co-pay-check" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                                     stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 12.5l4.2 4.2L19 7" />
                                </svg>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 lg:col-4">
            <div class="co-sticky">
                <SummeryComponent>
                    <template #promo>
                        <ExtraComponent />
                    </template>

                    <template #action>
                        <!-- Not a <label>: the policy links live inside the
                             text, and a label would swallow their clicks. -->
                        <div class="co-terms">
                            <button type="button" class="co-cbx" :class="agreed ? 'checked' : ''"
                                    role="checkbox" :aria-checked="agreed"
                                    :aria-label="$t('message.agree_to_store_policies')"
                                    @click.prevent="agreed = !agreed">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"
                                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M20 6 9 17l-5-5" />
                                </svg>
                            </button>
                            <div v-if="legalPages.length > 0">
                                {{ $t('message.agree_to_policies') }}
                                <template v-for="(page, index) in legalPages" :key="page.id"><router-link
                                        :to="{ name: 'frontend.page', params: { slug: page.slug } }">{{ page.title }}</router-link><template
                                        v-if="index < legalPages.length - 1">, </template></template>.
                            </div>
                            <div v-else>{{ $t('message.agree_to_store_policies') }}</div>
                        </div>

                        <!-- Desktop keeps the action in the card; on a phone it
                             lives in the bar pinned to the bottom instead. -->
                        <div class="max-lg:hidden">
                            <button type="button" class="co-place co-confirm" :class="submitting ? 'is-busy' : ''"
                                    :disabled="submitting" @click.prevent="confirmOrder">
                                <span v-if="submitting" class="co-spin" aria-hidden="true"></span>
                                <span>{{ submitting ? $t('button.placing_order') : $t('button.confirm_order') }}</span>
                                <svg v-if="!submitting" class="co-confirm-arrow" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true">
                                    <path d="M5 12h14M13 6l6 6-6 6" />
                                </svg>
                            </button>
                            <router-link :to="{ name: 'frontend.checkout.checkout' }" class="co-back">
                                {{ $t('button.back_to_checkout') }}
                            </router-link>
                        </div>

                        <p v-if="isGuest" class="co-fine">{{ $t('message.guest_no_account_needed') }}</p>
                    </template>
                </SummeryComponent>
            </div>
        </div>
    </div>

    <div class="co-bar">
        <div class="co-bar-total">
            <span>{{ $t('label.total') }}</span>
            <b>{{ money(total) }}</b>
        </div>
        <button type="button" class="co-confirm" :class="submitting ? 'is-busy' : ''" :disabled="submitting"
                @click.prevent="confirmOrder">
            <span v-if="submitting" class="co-spin" aria-hidden="true"></span>
            <span>{{ submitting ? $t('button.placing_order') : $t('button.confirm_order') }}</span>
            <svg v-if="!submitting" class="co-confirm-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h14M13 6l6 6-6 6" />
            </svg>
        </button>
    </div>

    <OrderPlacingComponent :open="placing" />
</template>

<script>
import statusEnum from "../../../../enums/modules/statusEnum";
import SummeryComponent from "../SummeryComponent.vue";
import ExtraComponent from "../ExtraComponent.vue";
import LoadingComponent from "../../components/LoadingComponent.vue";
import forEach from "lodash/forEach";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";
import sourceEnum from "../../../../enums/modules/sourceEnum";
import askEnum from "../../../../enums/modules/askEnum";
import menuSectionEnum from "../../../../enums/modules/menuSectionEnum";
import ENV from "../../../../config/env";
import pixelService from "../../../../services/pixelService";
import ActivityEnum from "../../../../enums/modules/activityEnum";
import orderTypeEnum from "../../../../enums/modules/orderTypeEnum";
import OrderPlacingComponent from "../OrderPlacingComponent.vue";

export default {
    name: "PaymentComponent",
    components: { ExtraComponent, SummeryComponent, LoadingComponent, OrderPlacingComponent },
    data() {
        return {
            loading: {
                isActive: false
            },
            paymentGateways: [],
            credit: {},
            cashOnDelivery: {},
            statusEnum: statusEnum,
            sourceEnum: sourceEnum,
            ActivityEnum: ActivityEnum,
            // Reactive, so both the desktop and the mobile confirm button lock
            // together and Vue always owns the disabled state.
            submitting: false,
            // The "Placing your order..." screen, from the moment the order is
            // sent until the browser leaves for the payment step.
            placing: false,
            agreed: true,
            form: {}
        }
    },
    computed: {
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        walletStatus: function () {
            const settings = this.$store.getters['frontendSetting/lists'];
            return settings && settings.wallet_status === true;
        },
        profile: function () {
            return this.$store.getters.authInfo;
        },
        isGuest: function () {
            const user = this.$store.getters.authInfo;
            return !!user && parseInt(user.is_guest, 10) === askEnum.YES;
        },
        // Already fetched by the footer on every page, so this reads the store
        // rather than firing a second request for the same list.
        legalPages: function () {
            const pages = this.$store.getters['frontendPage/lists'] || [];

            return pages.filter(page => page.menu_section_id === menuSectionEnum.LEGAL);
        },
        paymentMethod: function () {
            return this.$store.getters['frontendCart/paymentMethod'];
        },
        // Every way to pay this order, in the order they are offered: cash on
        // delivery, the wallet (only while it is not already applied), then
        // the online gateways.
        paymentOptions: function () {
            const setting = this.setting || {};
            const options = [];

            if (Object.keys(this.cashOnDelivery).length > 0 && setting.site_cash_on_delivery === ActivityEnum.ENABLE) {
                options.push(this.cashOnDelivery);
            }
            if (this.appliedWalletAmount === 0 && this.walletStatus && Object.keys(this.credit).length > 0) {
                options.push(this.credit);
            }
            if (setting.site_online_payment_gateway === ActivityEnum.ENABLE) {
                options.push(...this.paymentGateways);
            }

            return options;
        },
        subtotal: function () {
            return this.$store.getters['frontendCart/subtotal'];
        },
        discount: function () {
            return this.$store.getters['frontendCart/discount'];
        },
        total: function () {
            return this.$store.getters['frontendCart/total'];
        },
        appliedWalletAmount: function () {
            return this.$store.getters['frontendCart/appliedWalletAmount'];
        },
        totalBeforeWallet: function () {
            // Total before wallet discount (for backend)
            return this.total + this.appliedWalletAmount;
        },
        remainingAmount: function () {
            // Amount remaining after wallet discount
            return Math.max(0, this.total);
        },
        orderType: function () {
            return this.$store.getters['frontendCart/orderType'];
        },
        getShippingAddress: function () {
            return this.$store.getters['frontendCart/shippingAddress'];
        },
        getBillingAddress: function () {
            return this.$store.getters['frontendCart/billingAddress'];
        },
        getOutletAddress: function () {
            return this.$store.getters['frontendCart/outletAddress'];
        },
        cartCoupon: function () {
            return this.$store.getters['frontendCart/coupon'];
        },
        products: function () {
            return this.$store.getters['frontendCart/lists'];
        },
        shippingCharge: function () {
            return this.$store.getters['frontendCart/shippingCharge']
        },
        totalTax: function () {
            return this.$store.getters['frontendCart/totalTax'];
        },
        deliverTo: function () {
            if (this.orderType === orderTypeEnum.PICK_UP) {
                const outlet = this.getOutletAddress || {};

                return outlet.id ? {
                    heading: this.$t('label.pick_up_address'),
                    name: outlet.name,
                    phone: outlet.phone ? ((outlet.country_code || '') + ' ' + outlet.phone).trim() : '',
                    address: [outlet.address, outlet.state].filter(Boolean).join(', '),
                } : null;
            }

            const address = this.getShippingAddress || {};

            return address.id ? {
                heading: this.$t('label.deliver_to'),
                name: address.full_name,
                phone: address.phone ? ((address.country_code || '') + ' ' + address.phone).trim() : '',
                address: [address.address, address.state].filter(Boolean).join(', '),
            } : null;
        },
    },
    mounted() {
        // "Reached checkout but did not buy" - the audience worth retargeting
        // hardest, and the step Meta optimises towards before a purchase exists.
        // Normally already sent from the checkout step; this covers a shopper
        // who lands here directly. Once per basket either way.
        pixelService.initiateCheckoutOnce(this.products, this.total);

        window.addEventListener('pageshow', this.onPageShow);

        this.loading.isActive = true;
        this.$store.dispatch('frontendPaymentGateway/lists', { status: this.statusEnum.ACTIVE }).then(res => {
            if (res.data.data.length > 0) {
                forEach(res.data.data, (gateway) => {
                    if (gateway.slug === "credit") {
                        this.credit = gateway;
                    } else if (gateway.slug === "cashondelivery") {
                        this.cashOnDelivery = gateway;
                        if(this.setting.site_cash_on_delivery === this.ActivityEnum.ENABLE){
                            this.selectPaymentMethod(this.cashOnDelivery);
                        }

                    } else {
                        this.paymentGateways.push(gateway);
                    }
                });
            }
            this.loading.isActive = false;
        }).catch((err) => {
            this.loading.isActive = false;
        });
    },
    beforeUnmount() {
        window.removeEventListener('pageshow', this.onPageShow);
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
        isSelected: function (gateway) {
            return Object.keys(this.paymentMethod || {}).length > 0 && this.paymentMethod.id === gateway.id;
        },
        /**
         * The shop's own picture for cash on delivery and the wallet, in place
         * of the stock ones the install came with (a flat blue "COD" tile and
         * a clip-art card). Only while the stock picture is still the one in
         * use: anything the admin uploads instead is shown as uploaded.
         */
        paymentArt: function (method) {
            const image = String(method.image || '');
            const stock = (file) => !image || new RegExp('/' + file + '\\.png(\\?.*)?$').test(image);

            if (method.slug === 'cashondelivery' && stock('cashondelivery')) {
                return ENV.API_URL + '/images/payment/cash-on-delivery.webp';
            }
            if (method.slug === 'credit' && stock('credit')) {
                return ENV.API_URL + '/images/payment/wallet.webp';
            }

            return '';
        },
        /** The line under the name: what happens next, or the wallet's balance. */
        paymentHint: function (method) {
            if (method.slug === 'cashondelivery') {
                return this.$t('label.pay_when_it_arrives');
            }
            if (method.slug === 'credit') {
                const balance = (this.profile || {}).balance;

                return balance === undefined || balance === null ? '' : this.$t('label.balance') + ': ' + balance;
            }

            return this.$t('label.pay_online_now');
        },
        /**
         * Back from a payment gateway, the browser can restore this page
         * exactly as it was left - "Placing your order..." still up and the
         * buttons locked, with nothing left that would ever take them down.
         * Hand it back in the state a reload would.
         */
        onPageShow: function (event) {
            if (event.persisted) {
                this.placing = false;
                this.submitting = false;
            }
        },
        selectPaymentMethod: function (paymentMethod) {
            this.$store.dispatch("frontendCart/paymentMethod", paymentMethod);
        },
        // Cart getters are persisted to localStorage, so a stale or cleared
        // entry can come back as null. Object.keys(null) throws straight out of
        // the click handler, and the click then does nothing at all.
        pick: function (value, key) {
            return value && Object.keys(value).length > 0 ? value[key] : 0;
        },
        // Turns whatever axios rejected with into something the customer can
        // read. Only Laravel validation failures carry `errors`; the order
        // service reports stock, coupon and wallet problems as a plain
        // `message`, and a dropped connection carries no response at all.
        // Those last two used to fall through silently, which is what made the
        // button look like it simply did nothing.
        notifyOrderError: function (err) {
            const data = err && err.response ? err.response.data : null;

            if (data && data.errors && typeof data.errors === 'object') {
                forEach(data.errors, (error) => {
                    alertService.error(Array.isArray(error) ? error[0] : error);
                });
                return;
            }

            if (data && data.message) {
                alertService.error(data.message);
                return;
            }

            alertService.error(this.$t('message.something_went_wrong'));
        },
        confirmOrder: function () {
            if (this.submitting) {
                return;
            }

            if (!this.agreed) {
                alertService.error(this.$t('message.agree_to_store_policies'));
                return;
            }

            // Reachable with no address - a bookmarked or reloaded payment URL,
            // or a cart that lost it - and the order endpoint would refuse it
            // with a bare validation error. Send them back to fill it in.
            if (!this.deliverTo) {
                alertService.error(this.$t(this.orderType === orderTypeEnum.PICK_UP
                    ? 'message.select_a_store_location'
                    : 'message.save_your_address'));
                this.$router.push({ name: 'frontend.checkout.checkout' });
                return;
            }

            // The server recomputes the total and refuses an order whose figure
            // differs, so send the one the current lines actually add up to.
            this.$store.dispatch('frontendCart/recalculate').then().catch();

            // Check if full payment is covered by wallet
            const isFullyPaidByWallet = this.appliedWalletAmount > 0 && this.remainingAmount === 0;

            // If wallet covers full payment, no need to select payment method
            // If partial wallet payment, require payment method selection for remaining amount
            const paymentSlug = this.pick(this.paymentMethod, 'slug') || '';

            if (!isFullyPaidByWallet && !paymentSlug) {
                alertService.error(this.$t('message.payment_method_required'));
                return;
            }

            this.form = {
                subtotal: this.subtotal,
                discount: this.discount,
                tax: this.totalTax,
                shipping_charge: this.shippingCharge,
                total: this.totalBeforeWallet, // Send total BEFORE wallet discount
                total_amount_for_cashback: this.remainingAmount, // Amount after wallet discount for cashback calculation
                order_type: this.orderType,
                shipping_id: this.pick(this.getShippingAddress, 'id'),
                billing_id: this.pick(this.getBillingAddress, 'id'),
                // null, not 0. A delivery order has no outlet, and
                // orders.outlet_id is a foreign key to outlets — MySQL refuses
                // 0 because no outlet has that id, and the whole insert failed
                // with nothing but "A database error occurred" on screen.
                outlet_id: this.pick(this.getOutletAddress, 'id') || null,
                // OrderRequest rejects any coupon_id coming from a guest, and
                // the cart is persisted — so a coupon applied while logged in
                // would keep failing every later guest order. Never send one.
                coupon_id: this.isGuest ? 0 : this.pick(this.cartCoupon, 'id'),
                source: sourceEnum.WEB,
                payment_method: this.pick(this.paymentMethod, 'id'),
                wallet_discount: this.appliedWalletAmount,
                products: JSON.stringify(this.products)
            }

            // Locked only once the request is actually about to leave, so a
            // failure while assembling the payload cannot strand the button.
            this.submitting = true;
            this.placing = true;

            pixelService.addPaymentInfo(this.products, this.remainingAmount);

            this.$store.dispatch('frontendOrder/save', this.form).then(orderResponse => {
                const orderId = orderResponse.data.data.id;

                // The coupon is spent the moment the order row exists. The
                // cart is only cleared once the customer reaches the success
                // page, which never happens if they abandon the gateway — and
                // the stale coupon would then be sent with their next order
                // and rejected. Drop it as soon as the order is created.
                this.$store.dispatch('frontendCart/destroyCoupon').then().catch();

                // Refresh wallet balance if wallet was used
                if (this.appliedWalletAmount > 0) {
                    this.$store.dispatch('frontendCart/fetchWalletBalance').catch(err => {
                        // Silent error handling
                    });
                }

                // The order exists now, so stay locked through the redirect —
                // a second click here would create a duplicate order.
                if (isFullyPaidByWallet) {
                    window.location.href = ENV.API_URL + "/payment/successful/" + orderId;
                } else {
                    // The payment page needs no login (a browser navigation
                    // carries no Bearer token), so the order id alone used to
                    // open anyone's order and wallet balance. The token proves
                    // this browser placed the order.
                    const paymentToken = orderResponse.data.data.payment_token || '';
                    window.location.href = ENV.API_URL + "/payment/" + paymentSlug + "/pay/" + orderId
                        + "?token=" + encodeURIComponent(paymentToken);
                }
            }).catch((err) => {
                // Always release the button, whatever came back — leaving it
                // disabled meant a reload was the only way to retry.
                this.placing = false;
                this.submitting = false;
                this.notifyOrderError(err);
            });
        }
    }
}
</script>

<style scoped>
.pay-deliver {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding-top: 13px;
    padding-bottom: 14px;
    font-size: 13.5px;
    line-height: 1.5;
    color: #6e7191;
}

.pay-deliver b {
    font-size: 14.5px;
    font-weight: 700;
    color: #1f1f39;
}

.co-back {
    display: block;
    margin-top: 12px;
    font-size: 12.5px;
    font-weight: 600;
    text-align: center;
    color: #6e7191;
}

.co-back:hover {
    color: rgb(var(--primary));
    text-decoration: underline;
}
</style>
