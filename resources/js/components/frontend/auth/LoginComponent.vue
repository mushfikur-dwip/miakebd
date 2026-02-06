<template>
    <LoadingComponent :props="loading" />
    <div
        class="w-full max-w-3xl mx-auto rounded-2xl flex overflow-hidden gap-y-6 bg-white shadow-card mb-24 !sm:mb-0"
    >
        <img
            :src="APP_URL + '/images/required/auth.jpg'"
            alt="banners"
            class="w-full hidden sm:block sm:max-w-xs md:max-w-sm flex-shrink-0"
        />
        <form class="w-full p-6" @submit.prevent="login">
            <div class="text-center mb-8">
                <h3 class="capitalize text-2xl mb-2 font-bold text-primary">
                    {{ $t("label.sign_in") }}
                </h3>
                <p class="font-medium">{{ $t("message.continue_shopping") }}</p>
                <div
                    v-if="errors.validation"
                    class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 mb-5 rounded relative"
                    role="alert"
                >
                    <span class="block sm:inline">{{ errors.validation }}</span>
                    <span
                        class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer"
                        @click="close"
                    >
                        <i
                            class="lab lab-close-circle-line margin-top-5-px"
                        ></i>
                    </span>
                </div>
            </div>

            <div :class="!toggleValue ? 'mb-6' : ''">
                <div class="flex items-center justify-between">
                    <label
                        :for="!toggleValue ? 'formEmail' : 'phone'"
                        class="text-sm font-medium capitalize mb-1 field-title required"
                    >
                        {{ inputLabel }}
                    </label>
                    <button
                        type="button"
                        class="text-sm font-medium capitalize mb-1 underline text-primary"
                        @click="handleField()"
                    >
                        {{ inputButton }}
                    </button>
                </div>
                <div
                    v-if="toggleValue"
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
                <input
                    v-if="!toggleValue"
                    v-model="form.email"
                    :class="errors.email ? 'invalid' : ''"
                    id="formEmail"
                    type="text"
                    class="w-full h-12 px-4 rounded-lg text-base border border-[#D9DBE9] hover:border-primary/30 focus-within:border-primary/30 transition-all duration-500"
                />
                <small class="db-field-alert" v-if="errors.email_or_phone">{{
                    errors.email_or_phone
                }}</small>
                <span v-else>
                    <small
                        class="db-field-alert"
                        v-if="errors.phone && toggleValue"
                        >{{ errors.phone[0] }}</small
                    >
                    <small
                        class="db-field-alert"
                        v-if="errors.email && !toggleValue"
                        >{{ errors.email[0] }}</small
                    >
                </span>
            </div>

            <div class="mb-3">
                <label
                    for="formPassword"
                    class="text-sm font-medium capitalize mb-1 field-title required"
                >
                    {{ $t("label.password") }}
                </label>
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
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        id="formRemember"
                        class="custom-checkbox"
                    />
                    <label
                        for="formRemember"
                        class="text-sm -mb-0.5 capitalize cursor-pointer whitespace-nowrap"
                        >{{ $t("label.remember_me") }}</label
                    >
                </div>
                <router-link
                    :to="{ name: 'auth.forgotPassword' }"
                    class="field-label text-primary"
                >
                    {{ $t("label.forgot_password") }}
                </router-link>
            </div>
            <button
                type="submit"
                class="font-bold text-center w-full h-12 leading-12 rounded-full bg-primary text-white capitalize mb-6"
            >
                {{ $t("label.sign_in") }}
            </button>
            <div class="flex items-center justify-center gap-1.5">
                <span class="font-medium text-text">{{
                    $t("message.dont_have_account")
                }}</span>
                <router-link
                    class="capitalize font-bold text-primary"
                    :to="{ name: 'auth.signup' }"
                >
                    {{ $t("label.sign_up") }}
                </router-link>
            </div>

            <div
                v-if="
                    demo === 'true' ||
                    demo === 'TRUE' ||
                    demo === 'True' ||
                    demo === '1' ||
                    demo === 1
                "
                class="mt-6"
            >
                <h2 class="mb-6 text-center text-lg font-medium text-heading">
                    {{ $t("message.for_quick_demo") }}
                </h2>
                <nav class="grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        @click.prevent="setupCredit('admin')"
                        class="click-to-prop w-full h-10 leading-10 rounded-lg text-center text-sm capitalize text-white bg-orange-500"
                        id="adminClick"
                    >
                        {{ $t("label.admin") }}
                    </button>
                    <button
                        type="button"
                        @click.prevent="setupCredit('customer')"
                        class="click-to-prop w-full h-10 leading-10 rounded-lg text-center text-sm capitalize text-white bg-emerald-500"
                        id="customerClick"
                    >
                        {{ $t("label.customer") }}
                    </button>
                    <button
                        type="button"
                        @click.prevent="setupCredit('manager')"
                        class="click-to-prop w-full h-10 leading-10 rounded-lg text-center text-sm capitalize text-white bg-sky-600"
                        id="branchManagerClick"
                    >
                        {{ $t("label.manager") }}
                    </button>
                    <button
                        type="button"
                        @click.prevent="setupCredit('posOperator')"
                        class="click-to-prop w-full h-10 leading-10 rounded-lg text-center text-sm capitalize text-white bg-purple-500"
                        id="posOperatorClick"
                    >
                        {{ $t("label.pos_operator") }}
                    </button>
                </nav>
            </div>
        </form>
    </div>
</template>

<script>
import router from "../../../router";
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";
import ENV from "../../../config/env";

export default {
    name: "LoginComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },
            form: {
                email: "",
                phone: "",
                country_code: "+880",
                password: "",
            },
            flag: "",
            errors: {},
            permissions: {},
            firstMenu: null,
            demo: ENV.DEMO,
            APP_URL: ENV.API_URL,
            toggleValue: true,
            inputLabel: this.$t("label.phone"),
            inputButton: this.$t("label.use_email_instead"),
        };
    },
    computed: {
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
        login: function () {
            try {
                this.loading.isActive = true;
                this.form.country_code = "+880";
                this.$store
                    .dispatch("login", this.form)
                    .then((res) => {
                        this.loading.isActive = false;
                        alertService.success(res.data.message);
                        this.$store
                            .dispatch("frontendWishlist/lists")
                            .then()
                            .catch();
                        if (this.carts.length > 0) {
                            router.push({ name: "frontend.checkout" });
                        } else {
                            router.push({ name: "frontend.home" });
                        }
                        router.push({ name: "frontend.home" });
                        setTimeout(() => {
                            appService.recursiveRouter(
                                router.options.routes,
                                this.$store.getters.authPermission
                            );
                        }, 1000);
                    })
                    .catch((err) => {
                        this.loading.isActive = false;
                        if (err.response && err.response.data && err.response.data.errors) {
                            this.errors = err.response.data.errors;
                        } else {
                            this.errors = { validation: "An error occurred. Please try again." };
                        }
                    });
            } catch (err) {
                this.loading.isActive = false;
            }
        },
        handleField: function () {
            this.toggleValue = !this.toggleValue;

            if (this.toggleValue) {
                this.form.email = "";
                this.inputLabel = this.$t("label.phone");
                this.inputButton = this.$t("label.use_email_instead");
            } else {
                this.form.phone = "";
                this.inputLabel = this.$t("label.email");
                this.inputButton = this.$t("label.use_phone_instead");
            }
        },
        close: function () {
            this.errors = {};
        },
        setupCredit: function (e) {
            if (e === "admin") {
                this.form.email = "admin@example.com";
                this.form.password = "123456";
            } else if (e === "customer") {
                this.form.email = "customer@example.com";
                this.form.password = "123456";
            } else if (e === "manager") {
                this.form.email = "manager@example.com";
                this.form.password = "123456";
            } else if (e === "posOperator") {
                this.form.email = "posoperator@example.com";
                this.form.password = "123456";
            }
        },
    },
};
</script>
