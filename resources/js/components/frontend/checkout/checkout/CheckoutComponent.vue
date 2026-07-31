<template>
    <LoadingComponent :props="loading"/>
    <!-- Shop notice, written and toggled from Settings → Site. Rendered above
         everything so it is read before any payment decision. -->
    <div v-if="checkoutNotice" class="checkout-notice col-12">
        <i class="lab lab-line-info flex-shrink-0" aria-hidden="true"></i>
        <p>{{ checkoutNotice }}</p>
    </div>

    <div class="row">
        <div class="col-12 lg:col-8">
            <div class="flex flex-col gap-4">
                <!-- Anonymous visitors choose a path first. Everything below needs
                     a token: the address book, the outlets and the order endpoint
                     are all per-user. -->
                <GuestGateComponent v-if="!authStatus" />

                <template v-else>
                    <div class="co-card">
                        <div class="co-card-head">
                            <span class="co-card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                     stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 3h13v13H1zM14 8h4l3 3v5h-7z" />
                                    <circle cx="5.5" cy="18.5" r="2.5" /><circle cx="18.5" cy="18.5" r="2.5" />
                                </svg>
                            </span>
                            <h3>{{ $t('label.delivery') }}</h3>
                        </div>

                        <div class="co-card-body">
                            <span class="co-group-label">{{ $t('message.select_delivery_method') }}</span>
                            <div class="co-opts">
                                <button type="button" class="co-opt"
                                        :class="orderType === orderTypeEnum.DELIVERY ? 'selected' : ''"
                                        @click.prevent="changeOrderType(orderTypeEnum.DELIVERY)">
                                    <span class="co-radio" aria-hidden="true"></span>
                                    <span class="co-opt-text">
                                        <b>{{ $t('label.delivery') }}</b>
                                        <span>{{ money(shippingCharge) }}</span>
                                    </span>
                                </button>

                                <button v-if="outlets.length > 0" type="button" class="co-opt"
                                        :class="orderType === orderTypeEnum.PICK_UP ? 'selected' : ''"
                                        @click.prevent="changeOrderType(orderTypeEnum.PICK_UP)">
                                    <span class="co-radio" aria-hidden="true"></span>
                                    <span class="co-opt-text">
                                        <b>{{ $t('label.pick_up') }}</b>
                                        <span>{{ $t('label.store_location') }}</span>
                                    </span>
                                </button>
                            </div>

                            <p v-if="orderType === orderTypeEnum.DELIVERY" class="co-selbar">
                                {{ $t('message.shipping_charge_from_address') }}
                            </p>

                            <template v-if="orderType === orderTypeEnum.PICK_UP && outlets.length > 0">
                                <span class="co-group-label mt-4">{{ $t('label.store_location') }}</span>
                                <div class="co-opts">
                                    <button v-for="outlet in outlets" :key="outlet.id" type="button"
                                            class="co-opt"
                                            :class="modelOutlet && modelOutlet.id === outlet.id ? 'selected' : ''"
                                            @click.prevent="outletAddress(outlet)">
                                        <span class="co-radio" aria-hidden="true"></span>
                                        <span class="co-opt-text">
                                            <b>{{ outlet.name }}</b>
                                            <span>{{ outlet.address }}, {{ outlet.state }} {{ outlet.zip_code }}</span>
                                        </span>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <AddressComponent v-if="orderType === orderTypeEnum.DELIVERY" :slug="'shipping'"
                                      :title="$t('label.shipping_address')" :show="true" :selectedAddress="getShippingAddress"
                                      :method="shippingAddress"/>

                    <label v-if="orderType === orderTypeEnum.DELIVERY" for="shipping-and-billing-is-same"
                           class="flex items-start gap-3 cursor-pointer">
                        <input checked="checked" :value="shippingAndBillingCheck" @click="checkBillingCheckBox($event)"
                               type="checkbox" id="shipping-and-billing-is-same" class="cs-custom-checkbox">
                        <span class="font-medium leading-tight">{{
                                $t('message.save_shipping_address_as_a_billing_address')
                            }}</span>
                    </label>

                    <AddressComponent v-if="orderType === orderTypeEnum.DELIVERY" :slug="'billing'"
                                      :title="$t('label.billing_address')" :show="billingStatus"
                                      :selectedAddress="getBillingAddress" :method="billingAddress"/>
                </template>
            </div>
        </div>

        <div class="col-12 lg:col-4">
            <div class="co-sticky">
                <SummeryComponent>
                    <template #promo>
                        <ExtraComponent v-if="authStatus" />
                    </template>

                    <template #action>
                        <!-- Desktop keeps the action in the card; on a phone it
                             lives in the bar pinned to the bottom instead. -->
                        <div v-if="authStatus" class="max-lg:hidden mt-4">
                            <button type="button" class="co-place" @click.prevent="selectAddress">
                                {{ $t('button.save_and_pay') }}
                            </button>
                            <router-link :to="{ name: 'frontend.checkout.cartList' }"
                                         class="co-back">
                                {{ $t('button.back_to_cart') }}
                            </router-link>
                        </div>
                    </template>
                </SummeryComponent>
            </div>
        </div>
    </div>

    <div v-if="authStatus" class="co-bar">
        <div class="co-bar-total">
            <span>{{ $t('label.total') }}</span>
            <b>{{ money(total) }}</b>
        </div>
        <button type="button" @click.prevent="selectAddress">{{ $t('button.save_and_pay') }}</button>
    </div>
</template>

<script>
import orderTypeEnum from "../../../../enums/modules/orderTypeEnum";
import AddressComponent from "./AddressComponent.vue";
import GuestGateComponent from "./GuestGateComponent.vue";
import SummeryComponent from "../SummeryComponent.vue";
import ExtraComponent from "../ExtraComponent.vue";
import router from "../../../../router";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";
import LoadingComponent from "../../components/LoadingComponent.vue";
import statusEnum from "../../../../enums/modules/statusEnum";
import activityEnum from "../../../../enums/modules/activityEnum";


export default {
    name: "CheckoutComponent",
    components: {ExtraComponent, SummeryComponent, AddressComponent, GuestGateComponent, LoadingComponent},
    data() {
        return {
            loading: {
                isActive: false
            },
            enums : {
                statusEnum: statusEnum,
                activityEnum: activityEnum
            },
            orderTypeEnum: orderTypeEnum,
            shippingAndBillingCheck: true,
            billingStatus: false
        }
    },
    computed: {
        authStatus: function () {
            return this.$store.getters.authStatus;
        },
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        // Hidden when the admin switches it off OR leaves the text empty, so
        // clearing the box is enough — no need to also flip the toggle.
        checkoutNotice: function () {
            const setting = this.setting || {};

            if (parseInt(setting.site_checkout_notice_status, 10) === this.enums.activityEnum.DISABLE) {
                return null;
            }

            const text = (setting.site_checkout_notice || '').trim();

            return text !== '' ? text : null;
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
        // Read from the store rather than a local copy, so the selected outlet
        // survives a step back and forward.
        modelOutlet: function () {
            return this.$store.getters['frontendCart/outletAddress'];
        },
        outlets: function () {
            return this.$store.getters['frontendOutlet/lists'];
        },
        shippingCharge: function () {
            return this.$store.getters['frontendCart/shippingCharge'];
        },
        total: function () {
            return this.$store.getters['frontendCart/total'];
        }
    },
    mounted() {
        this.loadCheckoutData();
    },
    watch: {
        // A guest who has just started a session needs the same data a
        // logged-in customer gets, without a page reload.
        authStatus: function (value) {
            if (value) {
                this.loadCheckoutData();
            }
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
        loadCheckoutData: function () {
            // Skipped for anonymous visitors — the guest gate is all they see,
            // so these two requests would only delay it.
            if (!this.authStatus) {
                return;
            }

            this.loading.isActive = true;
            this.$store.dispatch('frontendOrderArea/lists').then(res => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });

            this.loading.isActive = true;
            this.$store.dispatch('frontendOutlet/lists', {
                status : this.enums.statusEnum.ACTIVE
            }).then(res => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
        changeOrderType: function (e) {
            this.$store.dispatch('frontendCart/updateOrderType', e)
        },
        shippingAddress: function (e) {
            this.$store.dispatch('frontendCart/shippingAddress', e).then().catch();
            if (this.shippingAndBillingCheck) {
                this.$store.dispatch('frontendCart/billingAddress', e).then().catch();
            }
        },
        billingAddress: function (e) {
            this.$store.dispatch('frontendCart/billingAddress', e).then().catch();
        },
        outletAddress: function(outlet) {
            this.$store.dispatch('frontendCart/outletAddress', outlet).then().catch();
        },
        checkBillingCheckBox: function (e) {
            if (e.target.checked) {
                this.billingStatus           = false;
                this.shippingAndBillingCheck = true;
                this.$store.dispatch('frontendCart/billingAddress', this.getShippingAddress).then().catch();
            } else {
                this.billingStatus           = true;
                this.shippingAndBillingCheck = false;
            }
        },
        selectAddress: function () {
            if (this.orderType === orderTypeEnum.DELIVERY) {
                if (Object.keys(this.getShippingAddress).length === 0 || Object.keys(this.getBillingAddress).length === 0) {
                    alertService.error(this.$t("message.shipping_and_billing_address"));
                    return;
                }
            } else if (Object.keys(this.modelOutlet || {}).length === 0) {
                // Pick-up without an outlet reaches the order endpoint as
                // outlet_id 0, which it rejects — catch it here instead.
                alertService.error(this.$t("message.select_a_store_location"));
                return;
            }

            router.push({name: "frontend.checkout.payment"});
        }
    }
}
</script>


<style scoped>
/* Shop notice above checkout. Mint ground so it reads as information, not an
   error — it is a standing policy statement, not something the customer did. */
.checkout-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 13px 16px;
    margin-bottom: 20px;
    border-radius: 12px;
    background: #E9F6F1;
    border: 1px solid #C9E9DC;
    color: #1B5E43;
}

.checkout-notice i {
    font-size: 16px;
    line-height: 1.5;
}

.checkout-notice p {
    margin: 0;
    font-size: 13.5px;
    line-height: 1.6;
}

@media (min-width: 640px) {
    .checkout-notice p {
        font-size: 14px;
    }
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
