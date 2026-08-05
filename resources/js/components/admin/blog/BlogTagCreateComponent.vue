<template>
    <LoadingComponent :props="loading" />
    <SmModalCreateComponent :props="addButton" />

    <div id="modal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ $t("menu.blog_tags") }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500"
                    @click="reset"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12 sm:form-col-6">
                            <label for="name" class="db-field-title required">{{ $t("label.name") }}</label>
                            <input v-model="props.form.name" :class="errors.name ? 'invalid' : ''" type="text" id="name"
                                class="db-field-control" :placeholder="$t('label.tag_name_placeholder')" />
                            <small class="db-field-alert" v-if="errors.name">{{ errors.name[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required" for="active">{{ $t("label.status") }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.statusEnum.ACTIVE" v-model="props.form.status" id="active"
                                            type="radio" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="active" class="db-field-label">{{ $t("label.active") }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.statusEnum.INACTIVE" v-model="props.form.status"
                                            type="radio" id="inactive" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="inactive" class="db-field-label">{{ $t("label.inactive") }}</label>
                                </div>
                            </div>
                            <small class="db-field-alert" v-if="errors.status">{{ errors.status[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="priority" class="db-field-title">{{ $t("label.priority") }}</label>
                            <input v-model="props.form.priority" :class="errors.priority ? 'invalid' : ''" type="number"
                                id="priority" class="db-field-control" />
                            <small class="text-slate-500">{{ $t("message.priority_hint") }}</small>
                            <small class="db-field-alert" v-if="errors.priority">{{ errors.priority[0] }}</small>
                        </div>

                        <div class="form-col-12">
                            <label for="description" class="db-field-title">{{ $t("label.description") }}</label>
                            <textarea v-model="props.form.description" :class="errors.description ? 'invalid' : ''"
                                id="description" rows="2" class="db-field-control" maxlength="500"></textarea>
                            <small class="db-field-alert" v-if="errors.description">{{ errors.description[0] }}</small>
                        </div>

                        <div class="form-col-12">
                            <p class="db-field-title mt-2 mb-1">{{ $t("label.seo") }}</p>
                            <small class="text-slate-500 block mb-3">{{ $t("message.tag_seo_hint") }}</small>
                        </div>

                        <div class="form-col-12">
                            <label for="meta_title" class="db-field-title">{{ $t("label.meta_title") }}</label>
                            <input v-model="props.form.meta_title" :class="errors.meta_title ? 'invalid' : ''"
                                type="text" id="meta_title" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.meta_title">{{ errors.meta_title[0] }}</small>
                        </div>

                        <div class="form-col-12">
                            <label for="meta_description" class="db-field-title">
                                {{ $t("label.meta_description") }}
                            </label>
                            <textarea v-model="props.form.meta_description"
                                :class="errors.meta_description ? 'invalid' : ''" id="meta_description" rows="2"
                                class="db-field-control" maxlength="500"></textarea>
                            <small class="db-field-alert" v-if="errors.meta_description">
                                {{ errors.meta_description[0] }}
                            </small>
                        </div>

                        <div class="form-col-12">
                            <label for="meta_keywords" class="db-field-title">{{ $t("label.meta_keywords") }}</label>
                            <MetaKeywordsInput v-model="props.form.meta_keywords" :invalid="!!errors.meta_keywords" />
                            <small class="db-field-alert" v-if="errors.meta_keywords">
                                {{ errors.meta_keywords[0] }}
                            </small>
                        </div>

                        <div class="form-col-12">
                            <div class="modal-btns">
                                <button type="button" class="modal-btn-outline modal-close" @click="reset">
                                    <i class="lab lab-fill-close-circle"></i>
                                    <span>{{ $t("button.close") }}</span>
                                </button>
                                <button type="submit" class="db-btn py-2 text-white bg-primary">
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
import SmModalCreateComponent from "../components/buttons/SmModalCreateComponent";
import LoadingComponent from "../components/LoadingComponent";
import MetaKeywordsInput from "./MetaKeywordsInput";
import statusEnum from "../../../enums/modules/statusEnum";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";

export default {
    name: "BlogTagCreateComponent",
    components: { SmModalCreateComponent, LoadingComponent, MetaKeywordsInput },
    props: ["props"],
    data() {
        return {
            loading: { isActive: false },
            enums: { statusEnum: statusEnum },
            errors: {},
        };
    },
    computed: {
        addButton: function () {
            return { title: this.$t("button.add_tag") };
        },
    },
    methods: {
        blankForm: function () {
            return {
                name: "",
                description: "",
                meta_title: "",
                meta_description: "",
                meta_keywords: "",
                priority: 0,
                status: statusEnum.ACTIVE,
            };
        },
        reset: function () {
            appService.modalHide();
            this.$store.dispatch("blogTag/reset").then().catch();
            this.errors = {};
            this.$props.props.form = this.blankForm();
        },
        save: function () {
            try {
                const fd = new FormData();
                fd.append("name", this.props.form.name);
                fd.append("description", this.props.form.description || "");
                fd.append("meta_title", this.props.form.meta_title || "");
                fd.append("meta_description", this.props.form.meta_description || "");
                fd.append("meta_keywords", this.props.form.meta_keywords || "");
                fd.append("priority", this.props.form.priority || 0);
                fd.append("status", this.props.form.status);

                const tempId = this.$store.getters["blogTag/temp"].temp_id;
                this.loading.isActive = true;
                this.$store.dispatch("blogTag/save", {
                    form: fd,
                    search: this.props.search,
                }).then(() => {
                    appService.modalHide();
                    this.loading.isActive = false;
                    alertService.successFlip(tempId === null ? 0 : 1, this.$t("menu.blog_tags"));
                    this.props.form = this.blankForm();
                    this.errors = {};
                }).catch((err) => {
                    this.loading.isActive = false;
                    this.errors = err.response?.data?.errors || {};
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        },
    },
};
</script>
