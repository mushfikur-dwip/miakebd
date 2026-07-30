<template>
    <div class="claim" v-if="!done">
        <div class="claim-glow" aria-hidden="true"></div>

        <div class="relative">
            <!-- Step 1 — the invitation. -->
            <div v-if="step === 'intro'" class="claim-step">
                <span class="claim-badge">
                    <span class="claim-badge-dot"></span>
                    {{ $t('label.guest_order') }}
                </span>

                <h3 class="text-xl sm:text-2xl font-bold text-heading mt-4 mb-1.5">
                    {{ $t('label.save_your_order_history') }}
                </h3>
                <p class="text-sm text-paragraph mb-6 max-w-lg">
                    {{ $t('message.guest_claim_intro') }}
                </p>

                <button type="button" class="claim-cta" @click="step = 'phone'">
                    <span class="flex items-center justify-center gap-2">
                        {{ $t('button.create_my_account') }}
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </span>
                </button>
            </div>

            <!-- Step 2 — the customer types the number the order was placed on. -->
            <form v-else-if="step === 'phone'" class="claim-step" @submit.prevent="sendOtp" novalidate>
                <h3 class="text-lg sm:text-xl font-bold text-heading mb-1.5">
                    {{ $t('label.verify_your_number') }}
                </h3>
                <p class="text-sm text-paragraph mb-6 max-w-lg">
                    {{ $t('message.guest_claim_phone_help') }}
                </p>

                <div class="mb-6 max-w-sm">
                    <label for="claimPhone" class="field-title required">{{ $t('label.phone') }}</label>
                    <div class="claim-input claim-input--group" :class="{ 'claim-input--error': error }">
                        <span class="claim-dial">{{ form.country_code }}</span>
                        <input
                            v-model.trim="form.phone"
                            @keypress="phoneNumber($event)"
                            @input="error = null"
                            id="claimPhone"
                            type="tel"
                            inputmode="numeric"
                            autocomplete="tel-national"
                            placeholder="1XXXXXXXXX"
                            class="w-full h-full bg-transparent text-sm font-medium outline-none"
                        />
                    </div>
                    <small class="field-alert text-xs" v-if="error">{{ error }}</small>
                </div>

                <div class="flex flex-col sm:flex-row gap-3">
                    <button type="submit" class="claim-cta sm:w-auto sm:px-8" :disabled="loading">
                        <span v-if="!loading">{{ $t('button.send_code') }}</span>
                        <span v-else class="flex items-center justify-center gap-2">
                            <span class="claim-spinner" aria-hidden="true"></span>
                            {{ $t('label.please_wait') }}
                        </span>
                    </button>
                    <button type="button" class="claim-ghost" @click="step = 'intro'">
                        {{ $t('button.cancel') }}
                    </button>
                </div>
            </form>

            <!-- Step 3 — the code. -->
            <form v-else-if="step === 'code'" class="claim-step" @submit.prevent="claim" novalidate>
                <h3 class="text-lg sm:text-xl font-bold text-heading mb-1.5">
                    {{ $t('label.enter_the_code') }}
                </h3>
                <p class="text-sm text-paragraph mb-6">
                    {{ $t('message.code_sent_to') }}
                    <span class="font-semibold text-heading">{{ form.country_code }}{{ form.phone }}</span>
                </p>

                <div
                    class="claim-otp"
                    :class="{ 'claim-otp--error': error }"
                    :style="{ gridTemplateColumns: 'repeat(' + otpLength + ', minmax(0, 1fr))' }"
                >
                    <input
                        v-for="(digit, index) in digits"
                        :key="index"
                        :ref="'otp' + index"
                        v-model="digits[index]"
                        type="text"
                        inputmode="numeric"
                        maxlength="1"
                        autocomplete="one-time-code"
                        class="claim-otp-box"
                        :class="{ 'claim-otp-box--filled': digits[index] }"
                        :aria-label="$t('label.enter_the_code') + ' ' + (index + 1)"
                        @input="onDigit($event, index)"
                        @keydown.delete="onBackspace($event, index)"
                        @paste.prevent="onPaste"
                        @focus="$event.target.select()"
                    />
                </div>

                <small class="field-alert text-xs block mb-4" v-if="error">{{ error }}</small>

                <div class="mb-6 max-w-sm">
                    <label for="claimPassword" class="field-title">
                        {{ $t('label.password') }}
                        <span class="font-normal normal-case text-paragraph">({{ $t('label.optional') }})</span>
                    </label>
                    <input
                        v-model="form.password"
                        id="claimPassword"
                        type="password"
                        autocomplete="new-password"
                        :placeholder="$t('message.min_6_characters')"
                        class="claim-input"
                    />
                    <small class="block mt-2 text-xs text-paragraph">
                        {{ $t('message.password_optional_hint') }}
                    </small>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <button type="submit" class="claim-cta sm:w-auto sm:px-8" :disabled="loading || !isComplete">
                        <span v-if="!loading">{{ $t('button.verify_and_create') }}</span>
                        <span v-else class="flex items-center justify-center gap-2">
                            <span class="claim-spinner" aria-hidden="true"></span>
                            {{ $t('label.please_wait') }}
                        </span>
                    </button>

                    <button
                        type="button"
                        class="claim-ghost"
                        :disabled="countdown > 0 || loading"
                        @click="sendOtp"
                    >
                        <span v-if="countdown > 0">
                            {{ $t('button.resend_code') }} {{ formattedCountdown }}
                        </span>
                        <span v-else>{{ $t('button.resend_code') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Done. -->
    <div class="claim claim--done" v-else>
        <div class="claim-confetti" aria-hidden="true">
            <i v-for="n in 14" :key="n" :style="confettiStyle(n)"></i>
        </div>

        <div class="relative text-center py-2">
            <span class="claim-check" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 6 9 17l-5-5" />
                </svg>
            </span>

            <h3 class="text-xl sm:text-2xl font-bold text-heading mt-4 mb-1.5">
                {{ $t('label.account_created') }}
            </h3>
            <p class="text-sm text-paragraph mb-6">
                <span v-if="mergedOrders > 1">
                    {{ $t('message.orders_merged', { count: mergedOrders }) }}
                </span>
                <span v-else>{{ $t('message.account_created_success') }}</span>
            </p>

            <router-link :to="{ name: 'frontend.account.orderHistory' }" class="claim-cta inline-flex sm:w-auto sm:px-8">
                {{ $t('label.order_history') }}
            </router-link>
        </div>
    </div>
</template>

<script>
import appService from "../../../../services/appService";
import alertService from "../../../../services/alertService";

// Fallback only. The real length comes from the otp_digit_limit site setting,
// the same one OtpManagerService generates against for forgot-password, so the
// boxes always match the number of digits the customer is actually sent.
const OTP_FALLBACK_LENGTH = 4;
const RESEND_SECONDS = 45;

export default {
    name: "GuestClaimComponent",
    data() {
        return {
            step: "intro",
            loading: false,
            done: false,
            error: null,
            countdown: 0,
            timer: null,
            digits: [],
            form: {
                phone: "",
                country_code: "+880",
                password: "",
            },
        };
    },
    computed: {
        setting: function () {
            return this.$store.getters["frontendSetting/lists"];
        },
        otpLength: function () {
            const limit = parseInt(this.setting ? this.setting.otp_digit_limit : 0, 10);
            return limit > 0 ? limit : OTP_FALLBACK_LENGTH;
        },
        code: function () {
            return this.digits.join("");
        },
        isComplete: function () {
            return this.code.length === this.otpLength && !this.digits.includes("");
        },
        formattedCountdown: function () {
            const m = Math.floor(this.countdown / 60);
            const s = this.countdown % 60;
            return `${m}:${s < 10 ? "0" : ""}${s}`;
        },
        mergedOrders: function () {
            return this.$store.getters["frontendGuest/mergedOrders"];
        },
    },
    beforeUnmount() {
        this.stopCountdown();
    },
    methods: {
        phoneNumber: function (e) {
            return appService.phoneNumber(e);
        },
        confettiStyle: function (n) {
            // Deterministic spread — no randomness, so it looks the same every
            // time and never lands badly.
            const palette = ["#1AB759", "#FFBC1F", "#0072F4", "#FD0063", "#9353DE"];
            return {
                left: `${(n * 7) % 100}%`,
                background: palette[n % palette.length],
                animationDelay: `${(n % 7) * 0.09}s`,
            };
        },
        startCountdown: function () {
            this.stopCountdown();
            this.countdown = RESEND_SECONDS;
            this.timer = window.setInterval(() => {
                this.countdown -= 1;
                if (this.countdown <= 0) {
                    this.stopCountdown();
                }
            }, 1000);
        },
        stopCountdown: function () {
            if (this.timer) {
                window.clearInterval(this.timer);
                this.timer = null;
            }
            this.countdown = 0;
        },
        focusBox: function (index) {
            this.$nextTick(() => {
                const ref = this.$refs["otp" + index];
                const el = Array.isArray(ref) ? ref[0] : ref;
                if (el) {
                    el.focus();
                }
            });
        },
        onDigit: function (e, index) {
            this.error = null;
            const value = e.target.value.replace(/\D/g, "");
            this.digits[index] = value.slice(-1);

            if (this.digits[index] && index < this.otpLength - 1) {
                this.focusBox(index + 1);
            }
        },
        onBackspace: function (e, index) {
            // Empty box + backspace steps back, which is what people expect.
            if (!this.digits[index] && index > 0) {
                this.digits[index - 1] = "";
                this.focusBox(index - 1);
            }
        },
        onPaste: function (e) {
            const pasted = (e.clipboardData || window.clipboardData)
                .getData("text")
                .replace(/\D/g, "")
                .slice(0, this.otpLength);

            if (!pasted) {
                return;
            }

            this.error = null;
            for (let i = 0; i < this.otpLength; i += 1) {
                this.digits[i] = pasted[i] || "";
            }
            this.focusBox(Math.min(pasted.length, this.otpLength - 1));
        },
        sendOtp: function () {
            if (this.loading || this.countdown > 0) {
                return;
            }

            if (!/^0?1[0-9]{9}$/.test(this.form.phone)) {
                this.error = this.$t("message.phone_invalid");
                return;
            }

            this.loading = true;
            this.error = null;

            this.$store
                .dispatch("frontendGuest/sendOtp", {
                    phone: this.form.phone.replace(/^0/, ""),
                    country_code: this.form.country_code,
                })
                .then((res) => {
                    this.loading = false;
                    this.form.phone = this.form.phone.replace(/^0/, "");
                    this.digits = Array(this.otpLength).fill("");
                    this.step = "code";
                    this.startCountdown();
                    this.focusBox(0);
                    alertService.success(res.data.message, "bottom-center");
                })
                .catch((err) => {
                    this.loading = false;
                    const data = err.response ? err.response.data : {};
                    this.error = data.message || this.$t("message.something_went_wrong");
                });
        },
        claim: function () {
            if (this.loading || !this.isComplete) {
                return;
            }

            this.loading = true;
            this.error = null;

            this.$store
                .dispatch("frontendGuest/claim", {
                    phone: this.form.phone,
                    country_code: this.form.country_code,
                    token: this.code,
                    password: this.form.password,
                })
                .then(() => {
                    this.loading = false;
                    this.stopCountdown();
                    this.done = true;
                    this.$emit("claimed");
                })
                .catch((err) => {
                    this.loading = false;
                    const data = err.response ? err.response.data : {};
                    this.error = data.message || this.$t("message.something_went_wrong");
                    this.digits = Array(this.otpLength).fill("");
                    this.focusBox(0);
                });
        },
    },
};
</script>

<style scoped>
.claim {
    position: relative;
    overflow: hidden;
    border-radius: 1.25rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    background: linear-gradient(150deg, #ffffff 0%, #fffaf8 55%, rgb(var(--primary) / 0.07) 100%);
    border: 1px solid rgb(var(--primary) / 0.16);
    box-shadow: 0 18px 44px -22px rgb(var(--primary) / 0.5);
    animation: claim-rise 0.55s cubic-bezier(0.22, 1.15, 0.36, 1) both;
}

@media (min-width: 640px) {
    .claim {
        padding: 2rem;
    }
}

.claim--done {
    background: linear-gradient(150deg, #ffffff 0%, #f4fdf7 60%, rgb(26 183 89 / 0.1) 100%);
    border-color: rgb(26 183 89 / 0.28);
    box-shadow: 0 18px 44px -22px rgb(26 183 89 / 0.5);
}

.claim-glow {
    position: absolute;
    top: -60%;
    right: -20%;
    width: 22rem;
    height: 22rem;
    border-radius: 9999px;
    background: radial-gradient(circle, rgb(var(--primary) / 0.2) 0%, transparent 68%);
    pointer-events: none;
    animation: claim-drift 9s ease-in-out infinite alternate;
}

.claim-step {
    animation: claim-slide 0.4s cubic-bezier(0.22, 1.15, 0.36, 1) both;
}

.claim-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.3rem 0.7rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: rgb(var(--primary));
    background: rgb(var(--primary) / 0.1);
    border: 1px solid rgb(var(--primary) / 0.2);
}

.claim-badge-dot {
    width: 0.4rem;
    height: 0.4rem;
    border-radius: 9999px;
    background: rgb(var(--primary));
    animation: claim-pulse 1.8s ease-in-out infinite;
}

.claim-input {
    display: flex;
    align-items: center;
    width: 100%;
    height: 3rem;
    padding: 0 1rem;
    font-size: 0.95rem;
    font-weight: 500;
    background: #ffffff;
    border: 1px solid #d9dbe9;
    border-radius: 0.75rem;
    transition: border-color 0.25s ease, box-shadow 0.25s ease;
}

.claim-input:focus,
.claim-input:focus-within {
    outline: none;
    border-color: rgb(var(--primary) / 0.55);
    box-shadow: 0 0 0 4px rgb(var(--primary) / 0.1);
}

.claim-input--error {
    border-color: #e93c3c;
    box-shadow: 0 0 0 4px rgb(233 60 60 / 0.09);
}

.claim-input--group {
    gap: 0.6rem;
}

.claim-dial {
    flex-shrink: 0;
    padding-right: 0.6rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: #6e7191;
    border-right: 1px solid #eff0f6;
}

/* OTP grid */
.claim-otp {
    display: grid;
    /* Column count is set inline from the otp_digit_limit setting. */
    gap: 0.5rem;
    max-width: 18rem;
    margin-bottom: 1rem;
}

@media (min-width: 640px) {
    .claim-otp {
        gap: 0.65rem;
    }
}

.claim-otp-box {
    width: 100%;
    height: 3.25rem;
    text-align: center;
    font-size: 1.15rem;
    font-weight: 700;
    color: #1f1f39;
    background: #ffffff;
    border: 1.5px solid #d9dbe9;
    border-radius: 0.75rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s cubic-bezier(0.22, 1.15, 0.36, 1);
}

.claim-otp-box:focus {
    outline: none;
    border-color: rgb(var(--primary));
    box-shadow: 0 0 0 4px rgb(var(--primary) / 0.12);
    transform: translateY(-2px);
}

.claim-otp-box--filled {
    border-color: rgb(var(--primary) / 0.55);
    background: rgb(var(--primary) / 0.04);
}

.claim-otp--error .claim-otp-box {
    border-color: #e93c3c;
    animation: claim-shake 0.4s ease;
}

.claim-cta {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 3rem;
    border-radius: 9999px;
    font-weight: 700;
    text-transform: capitalize;
    color: #ffffff;
    background: linear-gradient(120deg, rgb(var(--primary)) 0%, rgb(var(--primary) / 0.82) 100%);
    box-shadow: 0 10px 22px -8px rgb(var(--primary) / 0.75);
    transition: transform 0.22s cubic-bezier(0.22, 1.15, 0.36, 1), box-shadow 0.22s ease, filter 0.22s ease;
}

.claim--done .claim-cta {
    background: linear-gradient(120deg, #1ab759 0%, #14a04d 100%);
    box-shadow: 0 10px 22px -8px rgb(26 183 89 / 0.7);
}

.claim-cta:hover:not(:disabled) {
    transform: translateY(-2px);
    filter: brightness(1.04);
}

.claim-cta:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.claim-ghost {
    height: 3rem;
    padding: 0 1.5rem;
    border-radius: 9999px;
    font-weight: 600;
    font-size: 0.9rem;
    color: #6e7191;
    background: transparent;
    border: 1.5px solid #eff0f6;
    transition: color 0.2s ease, border-color 0.2s ease;
}

.claim-ghost:hover:not(:disabled) {
    color: rgb(var(--primary));
    border-color: rgb(var(--primary) / 0.4);
}

.claim-ghost:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.claim-spinner {
    width: 1rem;
    height: 1rem;
    border-radius: 9999px;
    border: 2px solid rgb(255 255 255 / 0.35);
    border-top-color: #ffffff;
    animation: claim-spin 0.7s linear infinite;
}

/* Success */
.claim-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 4rem;
    height: 4rem;
    border-radius: 9999px;
    color: #ffffff;
    background: linear-gradient(140deg, #1ab759 0%, #14a04d 100%);
    box-shadow: 0 14px 30px -12px rgb(26 183 89 / 0.9);
    animation: claim-pop 0.6s cubic-bezier(0.22, 1.4, 0.36, 1) both;
}

.claim-check svg {
    width: 2rem;
    height: 2rem;
}

.claim-confetti {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
}

.claim-confetti i {
    position: absolute;
    top: -12%;
    width: 0.45rem;
    height: 0.8rem;
    border-radius: 1px;
    opacity: 0;
    animation: claim-fall 1.5s ease-in forwards;
}

@keyframes claim-rise {
    from {
        opacity: 0;
        transform: translateY(14px) scale(0.985);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes claim-slide {
    from {
        opacity: 0;
        transform: translateX(16px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes claim-drift {
    from {
        transform: translate3d(0, 0, 0) scale(1);
    }
    to {
        transform: translate3d(-1.5rem, 1rem, 0) scale(1.12);
    }
}

@keyframes claim-pulse {
    0%, 100% {
        opacity: 1;
        transform: scale(1);
    }
    50% {
        opacity: 0.45;
        transform: scale(0.8);
    }
}

@keyframes claim-spin {
    to {
        transform: rotate(360deg);
    }
}

@keyframes claim-pop {
    0% {
        opacity: 0;
        transform: scale(0.3);
    }
    100% {
        opacity: 1;
        transform: scale(1);
    }
}

@keyframes claim-shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-4px); }
    75% { transform: translateX(4px); }
}

@keyframes claim-fall {
    0% {
        opacity: 1;
        transform: translateY(0) rotate(0deg);
    }
    100% {
        opacity: 0;
        transform: translateY(20rem) rotate(520deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .claim,
    .claim-step,
    .claim-glow,
    .claim-badge-dot,
    .claim-check,
    .claim-confetti i {
        animation: none;
    }

    .claim-confetti {
        display: none;
    }

    .claim-cta,
    .claim-otp-box {
        transition: none;
    }

    .claim-cta:hover:not(:disabled),
    .claim-otp-box:focus {
        transform: none;
    }
}
</style>
