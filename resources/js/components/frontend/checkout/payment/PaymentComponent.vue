<template>
    <LoadingComponent :props="loading" />
    <div class="row">
        <div class="col-12 lg:col-8">
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
                    <div class="co-opts">
                        <button v-if="Object.keys(cashOnDelivery).length > 0 && setting.site_cash_on_delivery === ActivityEnum.ENABLE"
                                type="button" class="co-opt"
                                :class="isSelected(cashOnDelivery) ? 'selected' : ''"
                                @click.prevent="selectPaymentMethod(cashOnDelivery)">
                            <span class="co-radio" aria-hidden="true"></span>
                            <span class="co-opt-text">
                                <img :src="cashOnDelivery.image" :alt="cashOnDelivery.name" />
                                <b>{{ cashOnDelivery.name }}</b>
                            </span>
                        </button>

                        <button v-if="appliedWalletAmount === 0 && walletStatus && Object.keys(credit).length > 0"
                                type="button" class="co-opt"
                                :class="isSelected(credit) ? 'selected' : ''"
                                @click.prevent="selectPaymentMethod(credit)">
                            <span class="co-radio" aria-hidden="true"></span>
                            <span class="co-opt-text">
                                <img :src="credit.image" :alt="credit.name" />
                                <b>{{ credit.name }}</b>
                                <span>{{ profile.balance }}</span>
                            </span>
                        </button>

                        <template v-if="setting.site_online_payment_gateway === ActivityEnum.ENABLE">
                            <button v-for="paymentGateway in paymentGateways" :key="paymentGateway.id"
                                    type="button" class="co-opt"
                                    :class="isSelected(paymentGateway) ? 'selected' : ''"
                                    @click.prevent="selectPaymentMethod(paymentGateway)">
                                <span class="co-radio" aria-hidden="true"></span>
                                <span class="co-opt-text">
                                    <img :src="paymentGateway.image" :alt="paymentGateway.name" />
                                    <b>{{ paymentGateway.name }}</b>
                                </span>
                            </button>
                        </template>
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
                            <button type="button" class="co-place" :disabled="submitting"
                                    @click.prevent="confirmOrder">
                                {{ $t('button.confirm_order') }}
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
        <button type="button" :disabled="submitting" @click.prevent="confirmOrder">
            {{ $t('button.confirm_order') }}
        </button>
    </div>
</template>

<script>
import statusEnum from "../../../../enums/modules/statusEnum";
import SummeryComponent from "../SummeryComponent.vue";
import ExtraComponent from "../ExtraComponent.vue";
import LoadingComponent from "../../components/LoadingComponent.vue";
import _ from "lodash";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";
import sourceEnum from "../../../../enums/modules/sourceEnum";
import askEnum from "../../../../enums/modules/askEnum";
import menuSectionEnum from "../../../../enums/modules/menuSectionEnum";
import ENV from "../../../../config/env";
import ActivityEnum from "../../../../enums/modules/activityEnum";

export default {
    name: "PaymentComponent",
    components: { ExtraComponent, SummeryComponent, LoadingComponent },
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
    },
    mounted() {
        this.loading.isActive = true;
        this.$store.dispatch('frontendPaymentGateway/lists', { status: this.statusEnum.ACTIVE }).then(res => {
            if (res.data.data.length > 0) {
                _.forEach(res.data.data, (gateway) => {
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
                _.forEach(data.errors, (error) => {
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
            this.loading.isActive = true;

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
                    window.location.href = ENV.API_URL + "/payment/" + paymentSlug + "/pay/" + orderId;
                }
            }).catch((err) => {
                // Always release the button, whatever came back — leaving it
                // disabled meant a reload was the only way to retry.
                this.loading.isActive = false;
                this.submitting = false;
                this.notifyOrderError(err);
            });
        }
    }
}
</script>

<style scoped>
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
