<template>
    <!-- Guest checkout in one step.
         Name, mobile, district and address on one form with one button: the
         button starts the guest session, saves the address and selects it, then
         goes straight to payment. It used to be two forms - name and mobile,
         then an empty address card whose form hid behind "Add New" and asked
         for the name and mobile again. Signing in stays a link, not a
         competing panel. -->
    <div class="co-card" ref="card">
        <div class="co-card-head">
            <span class="co-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                    <circle cx="12" cy="10" r="3" />
                </svg>
            </span>
            <h3>{{ $t('label.delivery_details') }}</h3>
            <router-link :to="{ name: 'auth.login' }" class="co-card-link">
                {{ $t('label.login_to_autofill') }}
            </router-link>
        </div>

        <form class="co-card-body block w-full" @submit.prevent="submit" novalidate>
            <DeliveryAddressFields :form="form" :errors="errors" :phoneHint="true" />

            <p class="guest-tip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                    <path d="m9 12 2 2 4-4" />
                </svg>
                <span>{{ $t('message.guest_can_create_account_later') }}</span>
            </p>

            <button type="submit" class="guest-cta" :disabled="loading">
                <span v-if="!loading" class="flex items-center justify-center gap-2">
                    {{ $t('button.continue_to_payment') }}
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 12h14M13 6l6 6-6 6" />
                    </svg>
                </span>
                <span v-else class="flex items-center justify-center gap-2">
                    <span class="guest-spinner" aria-hidden="true"></span>
                    {{ $t('label.please_wait') }}
                </span>
            </button>
        </form>
    </div>
</template>

<script>
import alertService from "../../../../services/alertService";
import deliveryFormService from "../../../../services/deliveryFormService";
import phoneService from "../../../../services/phoneService";
import orderTypeEnum from "../../../../enums/modules/orderTypeEnum";
import DeliveryAddressFields from "./DeliveryAddressFields.vue";

export default {
    name: "GuestGateComponent",
    components: { DeliveryAddressFields },
    data() {
        return {
            loading: false,
            form: deliveryFormService.empty(),
            errors: deliveryFormService.noErrors(),
            // The shipping charge depends on the district's order area, and
            // the server refuses an order whose total disagrees with its own.
            // Requested as soon as the form shows (the endpoint is public), so
            // it is in hand long before the customer has finished typing.
            areas: null,
        };
    },
    mounted() {
        this.areas = this.loadAreas();
    },
    methods: {
        loadAreas: function () {
            return this.$store.dispatch("frontendOrderArea/lists").catch(() => null);
        },
        focusFirstError: function () {
            this.$nextTick(() => {
                const card = this.$refs.card;
                const field = card ? card.querySelector(".invalid input, .invalid select, textarea.invalid, input.invalid, .invalid") : null;

                if (field) {
                    field.scrollIntoView({ behavior: "smooth", block: "center" });
                    const input = field.matches("input, select, textarea") ? field : field.querySelector("input, select, textarea");
                    if (input) {
                        input.focus({ preventScroll: true });
                    }
                }
            });
        },
        submit: async function () {
            if (this.loading) {
                return;
            }

            this.errors = deliveryFormService.validate(this.form, this.$t);

            if (deliveryFormService.hasErrors(this.errors)) {
                this.focusFirstError();
                return;
            }

            this.loading = true;

            try {
                // Skipped on a retry: if the session started but the address
                // save failed (a dropped connection), pressing the button again
                // must not mint a second guest account.
                if (!this.$store.getters.authStatus) {
                    await this.$store.dispatch("frontendGuest/start", {
                        name: String(this.form.full_name).trim(),
                        phone: phoneService.local(this.form.phone),
                        country_code: "+880",
                    });
                }

                // A failed or empty area list would price every district at the
                // default cost; try once more before relying on it.
                const areas = await this.areas;
                if (!areas) {
                    this.areas = this.loadAreas();
                    await this.areas;
                }

                await this.$store.dispatch("frontendAddress/reset");
                const response = await this.$store.dispatch("frontendAddress/save", {
                    form: deliveryFormService.payload(this.form),
                    search: { paginate: 0, order_column: "id", order_type: "desc" },
                });
                const address = response.data.data;

                await this.$store.dispatch("frontendCart/updateOrderType", orderTypeEnum.DELIVERY);
                await this.$store.dispatch("frontendCart/shippingAddress", address);
                await this.$store.dispatch("frontendCart/billingAddress", address);

                this.$router.push({ name: "frontend.checkout.payment" });
            } catch (err) {
                this.loading = false;

                const result = deliveryFormService.fromResponse(err, this.$t);

                // 409: a real account owns this number. Guest checkout must not
                // hand out a session for it, so send them to log in.
                if (result.data && result.data.account_exists) {
                    alertService.error(result.data.message);
                    this.$router.push({ name: "auth.login" });
                    return;
                }

                this.errors = Object.assign(deliveryFormService.noErrors(), result.fields);

                if (result.message) {
                    alertService.error(result.message);
                }

                if (deliveryFormService.hasErrors(this.errors)) {
                    this.focusFirstError();
                }
            }
        },
    },
};
</script>

<style scoped>
/* Card chrome comes from the shared .co-card set so this reads as the same
   card as delivery, address and summary. Only the form is styled here. */
.guest-tip {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    margin: 16px 0 0;
    padding: 10px 13px;
    border-radius: 9px;
    background: #f7f8fa;
    font-size: 12.5px;
    color: #6e7191;
}

.guest-tip svg {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    margin-top: 1px;
    color: #2ac769;
}

.guest-cta {
    width: 100%;
    height: 50px;
    margin-top: 16px;
    border-radius: 9999px;
    font-size: 15.5px;
    font-weight: 700;
    color: #ffffff;
    background: rgb(var(--primary));
    transition: transform 0.2s ease, filter 0.2s ease;
}

.guest-cta:hover:not(:disabled) {
    transform: translateY(-1px);
    filter: brightness(1.06);
}

.guest-cta:active:not(:disabled) {
    transform: scale(0.99);
}

.guest-cta:disabled {
    opacity: 0.75;
    cursor: wait;
}

.guest-spinner {
    width: 1rem;
    height: 1rem;
    border-radius: 9999px;
    border: 2px solid rgb(255 255 255 / 0.35);
    border-top-color: #ffffff;
    animation: guest-spin 0.7s linear infinite;
}

@keyframes guest-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .guest-cta {
        transition: none;
    }

    .guest-cta:hover:not(:disabled),
    .guest-cta:active:not(:disabled) {
        transform: none;
    }

    .guest-spinner {
        animation: none;
    }
}
</style>
