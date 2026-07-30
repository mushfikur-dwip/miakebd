<template>
    <!-- Customer information.
         Presented as the first step of the checkout form rather than a
         guest-or-signin fork: signing in is a link, not a competing panel. The
         name and mobile are collected before the address because the address
         saves through an authenticated endpoint, so the guest session has to
         exist first. -->
    <div class="guest-card mb-6">
        <div class="guest-card-head">
            <span class="guest-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="9" cy="21" r="1" /><circle cx="20" cy="21" r="1" />
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                </svg>
            </span>
            <h3>{{ $t('label.customer_information') }}</h3>
            <router-link :to="{ name: 'auth.login' }" class="guest-card-login">
                {{ $t('label.login_to_autofill') }}
            </router-link>
        </div>

        <form class="guest-card-body" @submit.prevent="start" novalidate>
            <div class="guest-fields">
                <div>
                    <label for="guestName" class="field-title required">{{ $t('label.name') }}</label>
                    <input
                        v-model.trim="form.name"
                        id="guestName"
                        type="text"
                        autocomplete="name"
                        :placeholder="$t('label.name')"
                        class="guest-input"
                        :class="{ 'guest-input--error': errors.name }"
                        @input="errors.name = null"
                    />
                    <small class="field-alert text-xs" v-if="errors.name">{{ errors.name }}</small>
                </div>

                <div>
                    <label for="guestPhone" class="field-title required">{{ $t('label.phone') }}</label>
                    <div
                        class="guest-input guest-input--group"
                        :class="{ 'guest-input--error': errors.phone }"
                    >
                        <span class="guest-dial">{{ form.country_code }}</span>
                        <input
                            v-model.trim="form.phone"
                            @keypress="phoneNumber($event)"
                            @input="errors.phone = null"
                            id="guestPhone"
                            type="tel"
                            inputmode="numeric"
                            autocomplete="tel-national"
                            placeholder="1XXXXXXXXX"
                            class="w-full h-full bg-transparent text-sm font-medium outline-none"
                        />
                    </div>
                    <small class="field-alert text-xs" v-if="errors.phone">{{ errors.phone }}</small>
                    <small class="guest-help" v-else>{{ $t('message.guest_phone_hint') }}</small>
                </div>
            </div>

            <p class="guest-tip">
                <span aria-hidden="true">ℹ️</span>
                <span>{{ $t('message.guest_can_create_account_later') }}</span>
            </p>

            <button type="submit" class="guest-cta" :disabled="loading">
                <span v-if="!loading" class="flex items-center justify-center gap-2">
                    {{ $t('button.continue_to_delivery') }}
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
import appService from "../../../../services/appService";
import alertService from "../../../../services/alertService";

export default {
    name: "GuestGateComponent",
    data() {
        return {
            loading: false,
            form: {
                name: "",
                phone: "",
                country_code: "+880",
            },
            errors: {
                name: null,
                phone: null,
            },
        };
    },
    methods: {
        phoneNumber: function (e) {
            return appService.phoneNumber(e);
        },
        validate: function () {
            this.errors.name = null;
            this.errors.phone = null;

            if (!this.form.name) {
                this.errors.name = this.$t("message.name_required");
            }

            // Bangladeshi mobile numbers are 10 digits once the leading 0 is
            // dropped; accept 11 with it and normalise below.
            if (!this.form.phone) {
                this.errors.phone = this.$t("message.phone_required");
            } else if (!/^0?1[0-9]{9}$/.test(this.form.phone)) {
                this.errors.phone = this.$t("message.phone_invalid");
            }

            return !this.errors.name && !this.errors.phone;
        },
        start: function () {
            if (this.loading || !this.validate()) {
                return;
            }

            this.loading = true;

            this.$store
                .dispatch("frontendGuest/start", {
                    name: this.form.name,
                    phone: this.form.phone.replace(/^0/, ""),
                    country_code: this.form.country_code,
                })
                .then(() => {
                    this.loading = false;
                    // The parent re-renders the rest of the checkout as soon as
                    // authStatus flips, so there is nothing to route to.
                })
                .catch((err) => {
                    this.loading = false;
                    const data = err.response ? err.response.data : {};

                    // 409: a real account owns this number. Guest checkout must
                    // not hand out a session for it, so send them to log in.
                    if (data.account_exists) {
                        alertService.error(data.message);
                        this.$router.push({ name: "auth.login" });
                        return;
                    }

                    if (data.errors) {
                        this.errors.name = data.errors.name ? data.errors.name[0] : null;
                        this.errors.phone = data.errors.phone ? data.errors.phone[0] : null;
                        return;
                    }

                    alertService.error(data.message || this.$t("message.something_went_wrong"));
                });
        },
    },
};
</script>

<style scoped>
.guest-card {
    background: #ffffff;
    border: 1px solid #e8e9ee;
    border-radius: 14px;
    overflow: hidden;
}

.guest-card-head {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 14px 18px;
    border-bottom: 1px solid #f0f1f4;
}

.guest-card-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 27px;
    height: 27px;
    border-radius: 8px;
    color: rgb(var(--primary));
    background: rgb(var(--primary) / 0.08);
}

.guest-card-icon svg {
    width: 15px;
    height: 15px;
}

.guest-card-head h3 {
    flex: 1;
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    text-transform: capitalize;
}

.guest-card-login {
    font-size: 13px;
    font-weight: 600;
    color: #006cc0;
    white-space: nowrap;
}

.guest-card-login:hover {
    text-decoration: underline;
}

.guest-card-body {
    padding: 18px;
}

.guest-fields {
    display: grid;
    gap: 15px;
}

@media (min-width: 640px) {
    .guest-fields {
        grid-template-columns: 1fr 1fr;
    }
}

.guest-input {
    display: flex;
    align-items: center;
    width: 100%;
    height: 46px;
    padding: 0 14px;
    font-size: 14.5px;
    font-weight: 500;
    background: #ffffff;
    border: 1px solid #d9dbe9;
    border-radius: 9px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.guest-input:focus,
.guest-input:focus-within {
    outline: none;
    border-color: rgb(var(--primary) / 0.55);
    box-shadow: 0 0 0 3px rgb(var(--primary) / 0.08);
}

.guest-input--error {
    border-color: #e93c3c;
    box-shadow: 0 0 0 3px rgb(233 60 60 / 0.08);
}

.guest-input--group {
    gap: 10px;
}

.guest-dial {
    flex-shrink: 0;
    padding-right: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #6e7191;
    border-right: 1px solid #eff0f6;
}

.guest-help {
    display: block;
    margin-top: 5px;
    font-size: 11.5px;
    color: #6e7191;
}

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

.guest-cta {
    width: 100%;
    height: 48px;
    margin-top: 16px;
    border-radius: 9999px;
    font-weight: 700;
    color: #ffffff;
    background: rgb(var(--primary));
    transition: transform 0.2s ease, filter 0.2s ease;
}

.guest-cta:hover:not(:disabled) {
    transform: translateY(-1px);
    filter: brightness(1.06);
}

.guest-cta:disabled {
    opacity: 0.7;
    cursor: not-allowed;
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
    .guest-cta,
    .guest-input {
        transition: none;
    }

    .guest-cta:hover:not(:disabled) {
        transform: none;
    }

    .guest-spinner {
        animation: none;
    }
}
</style>
