<template>
    <LoadingComponent :props="loading" />
    <div
        class="w-full max-w-3xl mx-auto rounded-2xl flex overflow-hidden gap-y-6 bg-white shadow-card mb-24 !sm:mb-0"
    >
        <img
            :src="APP_URL + '/images/required/auth.jpg'"
            alt="banners"
            class="w-full hidden sm:block sm:max-w-xs md:max-w-sm flex-shrink-0"
            loading="lazy"
        />
        <form class="w-full p-6" @submit.prevent="signup">
            <div class="text-center mb-8">
                <h3 class="capitalize text-2xl mb-2 font-bold text-primary">
                    {{ $t("label.sign_up") }}
                </h3>
            </div>
            <div class="mb-6">
                <label
                    for="formName"
                    class="text-sm font-medium capitalize mb-1 field-title required"
                    >{{ $t("label.name") }}</label
                >
                <input
                    v-model="form.name"
                    :class="errors.name ? 'invalid' : ''"
                    id="formName"
                    type="text"
                    class="w-full h-12 px-4 rounded-lg text-base border border-[#D9DBE9] hover:border-primary/30 focus-within:border-primary/30 transition-all duration-500"
                />
                <small class="db-field-alert" v-if="errors.name">{{
                    errors.name[0]
                }}</small>
            </div>

            <div class="mb-6">
                <label
                    for="phone"
                    class="text-sm font-medium capitalize mb-1 field-title required"
                >
                    {{ $t("label.phone") }}
                </label>
                <div
                    :class="errors.phone ? 'invalid' : ''"
                    class="flex items-center gap-1.5 px-4 h-12 rounded-lg border border-[#D9DBE9] hover:border-primary/30 focus-within:border-primary/30 transition-all duration-500"
                >
                    <div class="w-fit flex-shrink-0 px-2">
                        <span class="flex items-center gap-1">
                            <span
                                class="whitespace-nowrap flex-shrink-0 text-xs"
                                >+880</span
                            >
                        </span>
                    </div>
                    <input
                        v-model="form.phone"
                        v-on:keypress="phoneNumber($event)"
                        v-bind:class="errors.phone ? 'invalid' : ''"
                        type="text"
                        id="phone"
                        class="pl-2 text-sm w-full h-full"
                    />
                </div>
                <small class="db-field-alert" v-if="errors.phone">{{
                    errors.phone[0]
                }}</small>
                <small class="db-field-alert" v-if="errors.email_or_phone">{{
                    errors.email_or_phone
                }}</small>
            </div>

            <div class="mb-6">
                <label
                    for="formEmail"
                    class="text-sm font-medium capitalize mb-1 field-title"
                >
                    {{ $t("label.email") }}
                </label>
                <input
                    v-model="form.email"
                    :class="errors.email ? 'invalid' : ''"
                    id="formEmail"
                    type="email"
                    class="w-full h-12 px-4 rounded-lg text-base border border-[#D9DBE9] hover:border-primary/30 focus-within:border-primary/30 transition-all duration-500"
                />
                <small class="db-field-alert" v-if="errors.email">{{
                    errors.email[0]
                }}</small>
            </div>

            <div class="mb-6">
                <label
                    for="formPassword"
                    class="text-sm font-medium capitalize mb-1 field-title required"
                    >{{ $t("label.password") }}</label
                >
                <input
                    v-model="form.password"
                    :class="errors.password ? 'invalid' : ''"
                    id="formPassword"
                    type="password"
                    class="w-full h-12 px-4 rounded-lg text-base border border-[#D9DBE9] hover:border-primary/30 focus-within:border-primary/30 transition-all duration-500"
                />
                <small class="db-field-alert" v-if="errors.password">{{
                    errors.password[0]
                }}</small>
            </div>
            <button
                type="submit"
                class="font-bold text-center w-full h-12 leading-12 rounded-full bg-primary text-white capitalize mb-6"
            >
                {{ $t("label.sign_up") }}
            </button>
            <div class="flex items-center justify-center gap-1.5">
                <span class="font-medium text-text">{{
                    $t("message.already_have_account")
                }}</span>
                <router-link
                    class="capitalize font-bold text-primary"
                    :to="{ name: 'auth.login' }"
                >
                    {{ $t("label.sign_in") }}
                </router-link>
            </div>
        </form>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import appService from "../../../services/appService";
import ENV from "../../../config/env";
import askEnum from "../../../enums/modules/askEnum";
import alertService from "../../../services/alertService";
import router from "../../../router";
export default {
    name: "LoginComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },
            form: {
                name: "",
                email: "",
                phone: "",
                country_code: "+880",
                password: "",
            },
            flag: "",
            errors: {},
            demo: ENV.DEMO,
            APP_URL: ENV.API_URL,
        };
    },
    computed: {
        setting: function () {
            return this.$store.getters["frontendSetting/lists"];
        },
        carts: function () {
            return this.$store.getters["frontendCart/lists"];
        },
    },
    mounted() {
        this.loading.isActive = true;
        this.$store
            .dispatch("frontendSetting/lists")
            .then((res) => {
                this.loading.isActive = false;
            })
            .catch((err) => {
                this.loading.isActive = false;
            });
    },
    methods: {
        phoneNumber(e) {
            return appService.phoneNumber(e);
        },
        signup: function () {
            try {
                this.loading.isActive = true;
                this.form.country_code = "+880";
                this.$store
                    .dispatch("frontendSignup/signupValidation", this.form)
                    .then((res) => {
                        // form is valid
                        if (
                            this.setting.site_phone_verification ===
                                askEnum.YES &&
                            this.form.phone !== "" &&
                            (this.demo !== "true" ||
                                this.demo !== "TRUE" ||
                                this.demo !== "True" ||
                                this.demo !== "1" ||
                                this.demo !== 1)
                        ) {
                            // otp to phone
                            this.$store
                                .dispatch("frontendSignup/otpPhone", this.form)
                                .then((res) => {
                                    this.loading.isActive = false;
                                    alertService.success(
                                        res.data.message,
                                        "bottom-center"
                                    );
                                    this.$router.push({
                                        name: "auth.signupVerify",
                                    });
                                })
                                .catch((err) => {
                                    this.loading.isActive = false;
                                    alertService.error(
                                        err.response.data.message
                                    );
                                });
                        } else if (
                            this.setting.site_email_verification ===
                                askEnum.YES &&
                            this.form.email !== "" &&
                            (this.demo !== "true" ||
                                this.demo !== "TRUE" ||
                                this.demo !== "True" ||
                                this.demo !== "1" ||
                                this.demo !== 1)
                        ) {
                            //otp to email
                            this.$store
                                .dispatch("frontendSignup/otpEmail", this.form)
                                .then((res) => {
                                    this.loading.isActive = false;
                                    alertService.success(
                                        res.data.message,
                                        "bottom-center"
                                    );
                                    this.$router.push({
                                        name: "auth.signupVerify",
                                    });
                                })
                                .catch((err) => {
                                    this.loading.isActive = false;
                                    alertService.error(
                                        err.response.data.message
                                    );
                                });
                        } else {
                            // save user
                            this.$store
                                .dispatch("frontendSignup/signup", this.form)
                                .then((res) => {
                                    this.loading.isActive = false;
                                    this.$store
                                        .dispatch(
                                            "signupLoginVerify",
                                            this.form
                                        )
                                        .then((res) => {
                                            this.loading.isActive = false;
                                            alertService.success(
                                                res.data.message,
                                                "bottom-center"
                                            );
                                            this.$store.dispatch(
                                                "frontendSignup/reset"
                                            );
                                            this.$router.push({
                                                name: "frontend.home",
                                            });
                                        })
                                        .catch((err) => {
                                            this.loading.isActive = false;
                                            this.errors =
                                                err.response.data.message;
                                        });
                                    this.$router.push({
                                        name: "frontend.home",
                                    });
                                    this.form = {
                                        name: "",
                                        email: "",
                                        phone: "",
                                        code: "",
                                        country_code: "+880",
                                        password: "",
                                    };
                                    this.errors = {};
                                })
                                .catch((err) => {
                                    this.loading.isActive = false;
                                    this.errors = err.response.data.errors;
                                });
                        }
                    })
                    .catch((err) => {
                        this.loading.isActive = false;
                        this.errors = err.response.data.errors;
                    });
            } catch (err) {
                this.loading.isActive = false;
            }
        },
    },
};
</script>
