<template>
    <LoadingComponent :props="loading" />
    <div v-if="Object.keys(cartCoupon).length !== 0"
        class="mb-6 rounded-2xl border border-success flex items-center gap-3 p-4 cursor-pointer">
        <div class="relative flex-shrink-0">
            <i class="lab-fill-shape lab-font-size-2xl opacity-[0.3] text-success"></i>
            <i
                class="lab-line-percent lab-font-size-8 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-success"></i>
        </div>
        <div class="flex-auto overflow-hidden">
            <h4
                class="font-semibold leading-5 mb-1 whitespace-nowrap overflow-hidden text-ellipsis capitalize text-success">
                {{ $t('message.coupon_applied') }}</h4>
            <h5 class="text-xs font-normal whitespace-nowrap overflow-hidden text-ellipsis">
                {{ $t('message.you_saved', { amount: cartCoupon.currency_discount }) }}
            </h5>
        </div>
        <button @click.prevent="destroyCoupon" class="lab-line-trash lab-font-size-xl text-danger"></button>
    </div>

    <!-- Guest checkout: coupons need a real account, because limit_per_user is
         counted per user row and every guest checkout creates a new one. -->
    <div v-else-if="isGuest" class="coupon-locked mb-6">
        <div class="flex items-start gap-3">
            <span class="coupon-locked-icon flex-shrink-0" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" />
                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                </svg>
            </span>
            <div class="flex-auto">
                <h4 class="font-semibold leading-5 mb-1 capitalize text-heading">
                    {{ $t('message.coupon_needs_account_title') }}
                </h4>
                <p class="text-xs text-paragraph mb-3">
                    {{ $t('message.coupon_needs_account_note') }}
                </p>
                <router-link :to="{ name: 'auth.login' }" class="coupon-locked-cta">
                    {{ $t('button.one_click_login') }}
                </router-link>
            </div>
        </div>
    </div>

    <div v-else @click.prevent="showTarget('coupon-modal', 'modal-active')"
        class="mb-6 rounded-2xl border border-focus flex items-center gap-3 p-4 cursor-pointer">
        <div class="relative flex-shrink-0">
            <i class="lab lab-fill-shape lab-font-size-2xl opacity-[0.3] text-focus"></i>
            <i
                class="lab lab-line-percent lab-font-size-8 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-focus"></i>
        </div>
        <div class="flex-auto overflow-hidden">
            <h4
                class="font-semibold leading-5 mb-1 whitespace-nowrap overflow-hidden text-ellipsis capitalize text-focus">
                {{ $t('message.apply_coupon') }}</h4>
            <h5 class="text-xs font-normal whitespace-nowrap overflow-hidden text-ellipsis">
                {{ $t('message.get_discount_with_your_order') }}
            </h5>
        </div>
        <i class="lab lab-line-chevron-right rtl:rotate-180 lab-font-size-2xl text-focus"></i>
    </div>

    <div v-if="!isGuest" id="coupon-modal"
        class="fixed inset-0 z-50 p-3 w-screen h-dvh overflow-y-auto bg-black/50 transition-all duration-300 opacity-0 invisible">
        <div class="w-full rounded-xl mx-auto bg-white transition-all duration-300 max-w-[360px]">
            <div class="flex items-center justify-between gap-2 py-4 px-4 border-b border-slate-100">
                <h3 class="text-lg font-bold capitalize"> {{ $t('label.coupon_code') }}</h3>
                <button @click.prevent="hideTarget('coupon-modal', 'modal-active')" type="button"
                    class="lab-line-circle-cross text-lg text-[#E93C3C]"></button>
            </div>
            <form @submit.prevent="couponChecking" class="w-full flex items-center px-4 mt-4">
                <input :class="error ? 'invalid' : ''" type="text" v-model="code"
                    class="h-11 w-full px-3 ltr:rounded-tl-lg rtl:rounded-tr-lg ltr:rounded-bl-lg rtl:rounded-br-lg border ltr:border-r-0 rtl:border-l-0 border-[#D9DBE9]">
                <button type="submit" class="h-11 px-4 leading-11 ltr:rounded-tr-lg rtl:rounded-tl-lg rtl:rounded-bl-lg ltr:rounded-br-lg rtl:rounded-br-0 rtl:rounded-tr-0
                capitalize font-semibold text-white bg-[#007FE3]">
                    {{ $t('button.apply') }}
                </button>
            </form>
            <small class="w-full px-4 pt-0 db-field-alert" v-if="error">{{ error }}</small>

            <div v-if="coupons.length > 0" class="p-4 pt-4 flex flex-col gap-4">
                <div v-for="coupon in coupons" :key="coupon" class="bg-[#EEF7FF] p-4 relative rounded-xl">
                    <h3 class="py-1 px-2 rounded font-medium text-xs w-fit mb-2 bg-[#FFDB1F]">
                        {{ $t('label.code') }}: {{ coupon.code }}
                    </h3>
                    <h4 class="text-sm font-medium mb-3">
                        {{ coupon.description }}
                    </h4>
                    <p class="text-xs text-text">{{ coupon.convert_start_date }} - {{ coupon.convert_end_date }}</p>
                    <button @click.prevent="appCouponButton(coupon)" type="button"
                        class="absolute bottom-0 ltr:right-0 rtl:left-0 text-sm font-semibold capitalize py-1.5 px-3 rounded-br-xl rounded-tl-xl text-white bg-primary">
                        {{ $t('button.apply') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import targetService from "../../../services/targetService";
import alertService from "../../../services/alertService";
import LoadingComponent from "../components/LoadingComponent.vue";
import askEnum from "../../../enums/modules/askEnum";

export default {
    name: "CouponComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false
            },
            code: null,
            error: ""
        }
    },
    computed: {
        isGuest: function () {
            const user = this.$store.getters.authInfo;
            return !!user && parseInt(user.is_guest, 10) === askEnum.YES;
        },
        coupons: function () {
            return this.$store.getters['frontendCoupon/lists'];
        },
        subtotal: function () {
            return this.$store.getters['frontendCart/subtotal'];
        },
        cartCoupon: function () {
            return this.$store.getters['frontendCart/coupon'];
        }
    },
    mounted() {
        // A guest cannot apply any of them, so skip the request entirely.
        if (this.isGuest) {
            return;
        }

        this.loading.isActive = true;
        this.$store.dispatch("frontendCoupon/lists", {}).then(res => {
            this.loading.isActive = false;
        }).catch((err) => {
            this.loading.isActive = false;
        });
    },
    methods: {
        showTarget: function (id, cClass) {
            targetService.showTarget(id, cClass);
        },
        hideTarget: function (id, cClass) {
            this.code = null;
            this.error = "";
            targetService.hideTarget(id, cClass);
        },
        appCouponButton(coupon) {
            this.code = coupon.code;
        },
        couponChecking() {
            this.loading.isActive = true;
            this.$store.dispatch('frontendCoupon/checking', {
                total: this.subtotal,
                code: this.code
            }).then(res => {
                this.error = "";
                this.$store.dispatch("frontendCart/coupon", res.data.data);
                this.loading.isActive = false;
                this.hideTarget('coupon-modal', 'modal-active');
                alertService.success(this.$t('message.coupon_add'));
            }).catch((err) => {
                this.loading.isActive = false;
                this.error = err.response.data.message;
            });
        },
        destroyCoupon() {
            this.loading.isActive = true;
            this.$store.dispatch("frontendCart/destroyCoupon").then(res => {
                this.code = null;
                this.loading.isActive = false;
                alertService.success(this.$t('message.coupon_delete'));
            }).catch((err) => {
                this.loading.isActive = false;
                alertService.error(err);
            });
        }
    }
}
</script>
<style scoped>
.coupon-locked {
    border-radius: 1rem;
    padding: 1rem;
    background: linear-gradient(140deg, #ffffff 0%, rgb(var(--primary) / 0.05) 100%);
    border: 1px solid rgb(var(--primary) / 0.2);
}

.coupon-locked-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.75rem;
    color: rgb(var(--primary));
    background: rgb(var(--primary) / 0.1);
}

.coupon-locked-icon svg {
    width: 1.1rem;
    height: 1.1rem;
}

.coupon-locked-cta {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 2.25rem;
    padding: 0 1.1rem;
    border-radius: 9999px;
    font-size: 0.8rem;
    font-weight: 700;
    color: #ffffff;
    background: linear-gradient(120deg, rgb(var(--primary)) 0%, rgb(var(--primary) / 0.82) 100%);
    box-shadow: 0 8px 18px -8px rgb(var(--primary) / 0.75);
    transition: transform 0.2s cubic-bezier(0.22, 1.15, 0.36, 1), filter 0.2s ease;
}

.coupon-locked-cta:hover {
    transform: translateY(-1px);
    filter: brightness(1.05);
}

@media (prefers-reduced-motion: reduce) {
    .coupon-locked-cta {
        transition: none;
    }

    .coupon-locked-cta:hover {
        transform: none;
    }
}
</style>
