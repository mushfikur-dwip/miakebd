<template>
    <div class="col-12">
        <BreadcrumbComponent />
    </div>

    <LoadingComponent :props="loading" />
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t("button.edit_profile") }}</h3>
            </div>

            <div class="db-card-body">
                <form @submit.prevent="save" class="w-full d-block">
                    <div class="form-row">
                        <div class="form-col-12 sm:form-col-6">
                            <label for="name" class="db-field-title required">{{
                                $t("label.name")
                            }}</label>
                            <input
                                type="text"
                                id="name"
                                class="db-field-control"
                                v-model="form.name"
                                :class="errors.name ? 'invalid' : ''"
                            />
                            <small class="db-field-alert" v-if="errors.name">
                                {{ errors.name[0] }}
                            </small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label
                                for="email"
                                class="db-field-title required"
                                >{{ $t("label.email") }}</label
                            >
                            <input
                                type="text"
                                id="email"
                                class="db-field-control"
                                v-model="form.email"
                                :class="errors.email ? 'invalid' : ''"
                            />
                            <small class="db-field-alert" v-if="errors.email">
                                {{ errors.email[0] }}
                            </small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label
                                for="phone"
                                class="db-field-title required"
                                >{{ $t("label.phone") }}</label
                            >
                            <div
                                :class="errors.phone ? 'invalid' : ''"
                                class="db-field-control flex items-center"
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

                            <small class="db-field-alert" v-if="errors.phone">
                                {{ errors.phone[0] }}
                            </small>
                        </div>

                        <div class="form-col-12">
                            <div class="flex flex-wrap gap-3">
                                <button
                                    type="submit"
                                    class="db-btn py-2 text-white bg-primary"
                                >
                                    <i class="lab lab-fill-save"></i>
                                    <span>{{ $t("button.save") }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script>
import BreadcrumbComponent from "../components/BreadcrumbComponent";
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";

export default {
    name: "ProfileEditProfileComponent",
    components: {
        BreadcrumbComponent,
        LoadingComponent,
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
            form: {
                name: "",
                email: "",
                phone: "",
                country_code: "",
            },
            errors: {},
        };
    },
    mounted() {
        try {
            this.loading.isActive = true;
            const profile = this.$store.getters.authInfo;
            this.form = {
                name: profile.name,
                email: profile.email,
                phone: profile.phone,
                country_code: "+880",
            };
            this.loading.isActive = false;
        } catch (err) {
            this.loading.isActive = false;
            alertService.error(err);
        }
    },
    methods: {
        phoneNumber(e) {
            return appService.phoneNumber(e);
        },
        save: function () {
            try {
                this.loading.isActive = true;
                this.$store
                    .dispatch("frontendEditProfile/updateProfile", this.form)
                    .then((res) => {
                        this.$store
                            .dispatch("updateAuthInfo", res.data.data)
                            .then((res) => {
                                this.loading.isActive = false;
                                alertService.success(
                                    this.$t("message.profile_update")
                                );
                                this.errors = {};
                            })
                            .catch((err) => {
                                this.loading.isActive = false;
                                alertService.error(err);
                            });
                    })
                    .catch((err) => {
                        this.loading.isActive = false;
                        this.errors = err.response.data.errors;
                    });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        },
    },
};
</script>
