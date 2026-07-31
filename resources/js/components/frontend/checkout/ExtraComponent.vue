<template>
    <LoadingComponent :props="loading" />

    <!-- Guest checkout: coupons are counted per user row and every guest
         checkout creates a new one, so they need a real account. -->
    <div v-if="isGuest" class="co-lock">
        <span class="co-lock-icon flex-shrink-0" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
        </span>
        <div class="flex-auto">
            <b>{{ $t('message.coupon_needs_account_title') }}</b>
            <p>{{ $t('message.coupon_needs_account_note') }}</p>
            <router-link :to="{ name: 'auth.login' }" class="co-lock-cta">
                {{ $t('button.one_click_login') }}
            </router-link>
        </div>
    </div>

    <div v-else class="co-extra">
        <h4>
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M11.59 2H4a2 2 0 0 0-2 2v7.59a2 2 0 0 0 .59 1.41l8.41 8.41a2 2 0 0 0 2.83 0l7.17-7.17a2 2 0 0 0 0-2.83L13 2.59A2 2 0 0 0 11.59 2ZM7 8.5A1.5 1.5 0 1 1 8.5 7 1.5 1.5 0 0 1 7 8.5Z" />
            </svg>
            {{ $t('label.coupon_code') }}
        </h4>

        <!-- Shown before the input so the saving is visible without the
             customer having to remember what they typed. -->
        <div v-if="hasCoupon" class="co-applied">
            <div class="co-applied-text">
                <b>{{ $t('message.coupon_applied') }}</b>
                <span>{{ $t('message.you_saved', { amount: cartCoupon.currency_discount }) }}</span>
            </div>
            <button @click.prevent="destroyCoupon" type="button" class="co-applied-remove"
                    :aria-label="$t('button.remove')">
                <i class="lab-line-trash" aria-hidden="true"></i>
            </button>
        </div>

        <template v-else>
            <form class="co-cpn" @submit.prevent="applyCoupon" novalidate>
                <input v-model.trim="code" type="text" :class="couponError ? 'invalid' : ''"
                       :placeholder="$t('label.enter_coupon_code')" @input="couponError = ''" />
                <button type="submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m5 12.5 4.5 4.5L19 7" />
                    </svg>
                    {{ $t('button.apply') }}
                </button>
            </form>
            <small class="db-field-alert" v-if="couponError">{{ couponError }}</small>

            <div v-if="coupons.length > 0" class="co-coupons">
                <button v-for="coupon in coupons" :key="coupon.id" type="button"
                        @click.prevent="pickCoupon(coupon)" class="co-coupon">
                    <b>{{ coupon.code }}</b>
                    <span>{{ coupon.description }}</span>
                </button>
            </div>
        </template>
    </div>
</template>

<script>
import alertService from "../../../services/alertService";
import LoadingComponent from "../components/LoadingComponent.vue";
import askEnum from "../../../enums/modules/askEnum";

export default {
    name: "ExtraComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false
            },
            code: null,
            couponError: ""
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
        cartCoupon: function () {
            return this.$store.getters['frontendCart/coupon'];
        },
        hasCoupon: function () {
            return Object.keys(this.cartCoupon || {}).length !== 0;
        },
        subtotal: function () {
            return this.$store.getters['frontendCart/subtotal'];
        }
    },
    mounted() {
        // A guest cannot apply any of them, so skip the request entirely.
        if (this.isGuest) {
            // A coupon left over from an earlier order is still sitting in
            // localStorage, and the guest panel renders no remove button — so
            // there was no way to get rid of it. OrderRequest rejects any
            // coupon_id from a guest, which made every order after the first
            // one fail with nothing on screen explaining why. Drop it here so
            // an already-stuck browser heals itself on the next checkout.
            if (this.hasCoupon) {
                this.$store.dispatch("frontendCart/destroyCoupon").then().catch();
            }

            return;
        }

        this.loading.isActive = true;
        this.$store.dispatch("frontendCoupon/lists", {}).then(() => {
            this.loading.isActive = false;
        }).catch(() => {
            this.loading.isActive = false;
        });
    },
    methods: {
        pickCoupon(coupon) {
            this.code = coupon.code;
            this.couponError = "";
            this.applyCoupon();
        },
        applyCoupon() {
            if (!this.code) {
                return;
            }

            this.loading.isActive = true;
            this.$store.dispatch('frontendCoupon/checking', {
                total: this.subtotal,
                code: this.code
            }).then(res => {
                this.couponError = "";
                this.$store.dispatch("frontendCart/coupon", res.data.data);
                this.loading.isActive = false;
                alertService.success(this.$t('message.coupon_add'));
            }).catch((err) => {
                this.loading.isActive = false;
                // The endpoint reports "expired", "minimum not met" and the
                // rest as a plain message; without this the field just cleared.
                const data = err && err.response ? err.response.data : null;
                this.couponError = (data && data.message) || this.$t('message.something_went_wrong');
            });
        },
        destroyCoupon() {
            this.loading.isActive = true;
            this.$store.dispatch("frontendCart/destroyCoupon").then(() => {
                this.code = null;
                this.loading.isActive = false;
                alertService.success(this.$t('message.coupon_delete'));
            }).catch(() => {
                this.loading.isActive = false;
                alertService.error(this.$t('message.something_went_wrong'));
            });
        }
    }
}
</script>

<style scoped>
.co-lock {
    display: flex;
    gap: 11px;
    align-items: flex-start;
    margin: 14px 0;
    padding: 14px;
    border-radius: 11px;
    background: linear-gradient(140deg, #ffffff, rgb(var(--primary) / 0.045));
    border: 1px solid rgb(var(--primary) / 0.16);
}

.co-lock-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 9px;
    color: rgb(var(--primary));
    background: rgb(var(--primary) / 0.08);
}

.co-lock-icon svg {
    width: 15px;
    height: 15px;
}

.co-lock b {
    display: block;
    margin-bottom: 2px;
    font-size: 14px;
    font-weight: 700;
}

.co-lock p {
    margin: 0 0 10px;
    font-size: 12px;
    color: #6e7191;
}

.co-lock-cta {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 36px;
    padding: 0 16px;
    border-radius: 99px;
    font-size: 12.5px;
    font-weight: 700;
    color: #ffffff;
    background: rgb(var(--primary));
    transition: filter 0.2s ease, transform 0.2s ease;
}

.co-lock-cta:hover {
    filter: brightness(1.2);
    transform: translateY(-1px);
}

/* Applied coupon */
.co-applied {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    border-radius: 12px;
    background: #f0fbf4;
    border: 1px solid #c9e9d8;
}

.co-applied-text {
    flex: 1;
    min-width: 0;
}

.co-applied-text b {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: #1b5e43;
}

.co-applied-text span {
    display: block;
    font-size: 11.5px;
    color: #4b7c66;
}

.co-applied-remove {
    flex-shrink: 0;
    font-size: 16px;
    color: #e93c3c;
    transition: transform 0.2s ease;
}

.co-applied-remove:hover {
    transform: scale(1.12);
}

/* Ready-made codes the shop is running */
.co-coupons {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 10px;
}

/* White, not --primary-slate: the panel behind them is now that same tint,
   so the chips had no edge to read against. */
.co-coupon {
    padding: 7px 12px;
    text-align: start;
    border-radius: 10px;
    border: 1px dashed rgb(var(--primary) / 0.3);
    background: #ffffff;
    transition: border-color 0.2s ease, transform 0.2s ease;
}

.co-coupon:hover {
    border-color: rgb(var(--primary));
    transform: translateY(-1px);
}

.co-coupon b {
    display: block;
    font-size: 12px;
    font-weight: 700;
}

.co-coupon span {
    display: block;
    max-width: 190px;
    overflow: hidden;
    font-size: 10.5px;
    line-height: 1.4;
    color: #6e7191;
    text-overflow: ellipsis;
    white-space: nowrap;
}

@media (prefers-reduced-motion: reduce) {
    .co-lock-cta,
    .co-applied-remove,
    .co-coupon {
        transition: none;
    }

    .co-lock-cta:hover,
    .co-applied-remove:hover,
    .co-coupon:hover {
        transform: none;
    }
}
</style>
