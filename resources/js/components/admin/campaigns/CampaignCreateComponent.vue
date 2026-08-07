<template>
    <LoadingComponent :props="loading" />
    <SmSidebarModalCreateComponent :props="addButton" />

    <div id="sidebar" @click.self="reset"
        class="fixed inset-0 z-50 bg-black/50 duration-500 transition-all invisible opacity-0">
        <div
            class="w-full max-w-xl h-screen overflow-x-hidden thin-scrolling bg-white ms-auto ltr:translate-x-full rtl:-translate-x-full">
            <div class="drawer-header">
                <h3 class="drawer-title">{{ $t('menu.campaigns') }}</h3>
                <button class="fa-solid fa-xmark close-btn" @click="reset"></button>
            </div>
            <div class="drawer-body">
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12 sm:form-col-12">
                            <label for="name" class="db-field-title required">{{ $t("label.name") }}</label>
                            <input v-model="props.form.name" v-bind:class="errors.name ? 'invalid' : ''" type="text"
                                id="name" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.name">{{ errors.name[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required">{{ $t("label.type") }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input type="radio" v-model="props.form.type" id="flash"
                                            :value="enums.campaignTypeEnum.FLASH" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="flash" class="db-field-label">{{ $t("label.flash_sale") }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input type="radio" class="custom-radio-field" v-model="props.form.type"
                                            id="clearance" :value="enums.campaignTypeEnum.CLEARANCE" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="clearance" class="db-field-label">{{ $t("label.clearance_sale")
                                        }}</label>
                                </div>
                            </div>
                            <small class="db-field-alert" v-if="errors.type">{{ errors.type[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required">{{ $t("label.status") }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input type="radio" v-model="props.form.status" id="active"
                                            :value="enums.statusEnum.ACTIVE" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="active" class="db-field-label">{{ $t("label.active") }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input type="radio" class="custom-radio-field" v-model="props.form.status"
                                            id="inactive" :value="enums.statusEnum.INACTIVE" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="inactive" class="db-field-label">{{ $t("label.inactive") }}</label>
                                </div>
                            </div>
                            <small class="db-field-alert" v-if="errors.status">{{ errors.status[0] }}</small>
                        </div>

                        <!-- model-type pins v-model to the exact string the API
                             stores and returns, so the value survives the edit
                             round trip without a timezone-shifting Date hop. -->
                        <div class="form-col-12 sm:form-col-6">
                            <label for="starts_at" class="db-field-title required">{{ $t("label.start_date") }}</label>
                            <Datepicker hideInputIcon autoApply v-model="props.form.starts_at" :enableTimePicker="true"
                                :is24="false" :monthChangeOnScroll="false" utc="false"
                                model-type="yyyy-MM-dd HH:mm:ss"
                                :input-class-name="errors.starts_at ? 'invalid' : ''">
                                <template #am-pm-button="{ toggle, value }">
                                    <button @click="toggle">{{ value }}</button>
                                </template>
                            </Datepicker>
                            <small class="db-field-alert" v-if="errors.starts_at">{{ errors.starts_at[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="ends_at" class="db-field-title required">{{ $t("label.end_date") }}</label>
                            <Datepicker hideInputIcon autoApply v-model="props.form.ends_at" :enableTimePicker="true"
                                :is24="false" :monthChangeOnScroll="false" utc="false"
                                model-type="yyyy-MM-dd HH:mm:ss"
                                :input-class-name="errors.ends_at ? 'invalid' : ''">
                                <template #am-pm-button="{ toggle, value }">
                                    <button @click="toggle">{{ value }}</button>
                                </template>
                            </Datepicker>
                            <small class="db-field-alert" v-if="errors.ends_at">{{ errors.ends_at[0] }}</small>
                        </div>

                        <div class="form-col-12">
                            <p class="text-sm text-slate-500">
                                {{ $t('message.campaign_window_hint') }}
                            </p>
                        </div>

                        <div class="form-col-12">
                            <div class="flex flex-wrap gap-3 mt-4">
                                <button type="submit" class="db-btn py-2 text-white bg-primary">
                                    <i class="lab lab-fill-save"></i>
                                    <span>{{ $t("label.save") }}</span>
                                </button>

                                <button type="button" class="modal-btn-outline modal-close" @click="reset">
                                    <i class="lab lab-fill-close-circle"></i>
                                    <span>{{ $t("button.close") }}</span>
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
import SmSidebarModalCreateComponent from "../components/buttons/SmSidebarModalCreateComponent";
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import LoadingComponent from "../components/LoadingComponent";
import statusEnum from "../../../enums/modules/statusEnum";
import campaignTypeEnum from "../../../enums/modules/campaignTypeEnum";
import alertService from "../../../services/alertService";
import { useCanvas } from "../../../composables/canvas";

export default {
    name: "CampaignCreateComponent",
    components: { SmSidebarModalCreateComponent, LoadingComponent, Datepicker },
    props: ["props"],
    data() {
        return {
            loading: {
                isActive: false,
            },
            enums: {
                statusEnum: statusEnum,
                campaignTypeEnum: campaignTypeEnum,
            },
            errors: {},
        };
    },
    computed: {
        addButton: function () {
            return { title: this.$t("button.add_campaign") }
        }
    },
    methods: {
        reset: function () {
            useCanvas().closeCanvas('sidebar');
            this.$store.dispatch("campaign/reset").then().catch();
            this.errors = {};
            this.$props.props.form = {
                name: "",
                type: campaignTypeEnum.CLEARANCE,
                starts_at: "",
                ends_at: "",
                status: statusEnum.ACTIVE,
            };
        },
        save: function () {
            try {
                const fd = new FormData();
                fd.append("name", this.props.form.name);
                fd.append("type", this.props.form.type);
                fd.append("starts_at", this.props.form.starts_at ?? "");
                fd.append("ends_at", this.props.form.ends_at ?? "");
                fd.append("status", this.props.form.status);

                const tempId = this.$store.getters["campaign/temp"].temp_id;
                this.loading.isActive = true;
                this.$store.dispatch("campaign/save", {
                    form: fd,
                    search: this.props.search,
                }).then((res) => {
                    useCanvas().closeCanvas('sidebar');
                    this.loading.isActive = false;
                    alertService.successFlip(
                        tempId === null ? 0 : 1,
                        this.$t("menu.campaigns")
                    );
                    this.props.form = {
                        name: "",
                        type: campaignTypeEnum.CLEARANCE,
                        starts_at: "",
                        ends_at: "",
                        status: statusEnum.ACTIVE,
                    };
                    this.errors = {};
                }).catch((err) => {
                    this.loading.isActive = false;
                    this.errors = err.response?.data?.errors ?? {};
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        },
    },
};
</script>
