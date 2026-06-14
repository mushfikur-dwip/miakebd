<template>
    <LoadingComponent :props="loading" />

    <div id="telegram" class="db-card db-tab-div active">
        <div class="db-card-header">
            <h3 class="db-card-title">{{ $t("menu.telegram") }}</h3>
        </div>
        <div class="db-card-body">
            <form @submit.prevent="save" class="w-full d-block">
                <div class="form-row">
                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title required" for="telegram_status">
                            {{ $t("label.telegram_status") }}
                        </label>
                        <div class="db-field-radio-group">
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input :value="enums.askEnum.YES" v-model="form.telegram_status"
                                        id="telegram_status_active" type="radio" class="custom-radio-field" />
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="telegram_status_active" class="db-field-label">
                                    {{ $t("label.active") }}
                                </label>
                            </div>
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input :value="enums.askEnum.NO" v-model="form.telegram_status"
                                        id="telegram_status_inactive" type="radio" class="custom-radio-field" />
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="telegram_status_inactive" class="db-field-label">
                                    {{ $t("label.inactive") }}
                                </label>
                            </div>
                        </div>
                        <small class="db-field-alert" v-if="errors.telegram_status">
                            {{ errors.telegram_status[0] }}
                        </small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label for="telegram_bot_token" class="db-field-title">
                            {{ $t("label.telegram_bot_token") }}
                        </label>
                        <input v-model="form.telegram_bot_token"
                            v-bind:class="errors.telegram_bot_token ? 'invalid' : ''" type="text"
                            id="telegram_bot_token" class="db-field-control" />
                        <small class="db-field-alert" v-if="errors.telegram_bot_token">
                            {{ errors.telegram_bot_token[0] }}
                        </small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label for="telegram_chat_id" class="db-field-title">
                            {{ $t("label.telegram_chat_id") }}
                        </label>
                        <input v-model="form.telegram_chat_id" v-bind:class="errors.telegram_chat_id ? 'invalid' : ''"
                            type="text" id="telegram_chat_id" class="db-field-control" />
                        <small class="db-field-alert" v-if="errors.telegram_chat_id">
                            {{ errors.telegram_chat_id[0] }}
                        </small>
                    </div>

                    <div class="form-col-12">
                        <button type="submit" class="db-btn text-white bg-primary">
                            <i class="lab lab-fill-save"></i>
                            <span>{{ $t("button.save") }}</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";
import askEnum from "../../../../enums/modules/askEnum";

export default {
    name: "TelegramComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },
            enums: {
                askEnum: askEnum,
            },
            form: {
                telegram_status: askEnum.NO,
                telegram_bot_token: "",
                telegram_chat_id: "",
            },
            errors: {},
        };
    },
    mounted() {
        try {
            this.loading.isActive = true;
            this.$store
                .dispatch("telegram/lists")
                .then((res) => {
                    this.form = {
                        telegram_status: res.data.data.telegram_status,
                        telegram_bot_token: res.data.data.telegram_bot_token,
                        telegram_chat_id: res.data.data.telegram_chat_id,
                    };
                    this.loading.isActive = false;
                })
                .catch(() => {
                    this.loading.isActive = false;
                });
        } catch (err) {
            this.loading.isActive = false;
            alertService.error(err);
        }
    },
    methods: {
        save: function () {
            try {
                this.loading.isActive = true;
                this.$store
                    .dispatch("telegram/save", this.form)
                    .then((res) => {
                        this.loading.isActive = false;
                        alertService.successFlip(
                            res.config.method === "put" ?? 0,
                            this.$t("menu.telegram")
                        );
                        this.errors = {};
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
