<template>
    <LoadingComponent :props="loading" />
    <SmModalCreateComponent :props="addButton" />

    <div id="modal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ $t("menu.product_brands") }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500"
                    @click="reset"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12 sm:form-col-6">
                            <label for="name" class="db-field-title required">
                                {{ $t("label.name") }}
                            </label>
                            <input v-model="props.form.name" type="text" id="name"
                                v-bind:class="errors.name ? 'invalid' : ''" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.name">{{
                                errors.name[0]
                                }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required" for="active">{{ $t('label.status') }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.statusEnum.ACTIVE" v-model="props.form.status" id="active"
                                            type="radio" class="custom-radio-field">
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="active" class="db-field-label">{{ $t('label.active') }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.statusEnum.INACTIVE" v-model="props.form.status"
                                            type="radio" id="inactive" class="custom-radio-field">
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="inactive" class="db-field-label">{{ $t('label.inactive') }}</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-col-12 sm:form-col-12">
                            <label for="image" class="db-field-title">{{ $t('label.image') }} (108px,108px)</label>
                            <input @change="changeImage" v-bind:class="errors.image ? 'invalid' : ''" id="image"
                                type="file" class="db-field-control" ref="imageProperty"
                                accept="image/png, image/jpeg, image/jpg">
                            <small class="db-field-alert" v-if="errors.image">{{ errors.image[0] }}</small>
                        </div>

                        <div class="form-col-12">
                            <label for="description" class="db-field-title">{{ $t("label.description") }}</label>
                            <textarea v-model="props.form.description"
                                v-bind:class="errors.description ? 'invalid' : ''" id="description"
                                class="db-field-control"></textarea>
                            <small class="db-field-alert" v-if="errors.description">{{
                                errors.description[0]
                                }}</small>
                        </div>

                        <!-- The brand page's search listing and intro. All
                             optional: left empty, the page writes its own from
                             live prices and best sellers. -->
                        <div class="form-col-12">
                            <h4 class="text-base font-semibold mb-1">{{ $t("label.seo") }}</h4>
                            <p class="text-xs text-gray-500">{{ $t("message.seo_fields_auto_hint") }}</p>
                        </div>

                        <div class="form-col-12">
                            <label for="seo_title" class="db-field-title">{{ $t("label.meta_title") }}</label>
                            <input v-model="props.form.seo_title" type="text" id="seo_title" maxlength="190"
                                v-bind:class="errors.seo_title ? 'invalid' : ''" class="db-field-control" />
                            <small class="text-xs" :class="(props.form.seo_title || '').length > 65 ? 'text-red-500' : 'text-gray-500'">
                                {{ (props.form.seo_title || '').length }}/65 · {{ $t("message.meta_title_hint") }}
                            </small>
                            <small class="db-field-alert" v-if="errors.seo_title">{{ errors.seo_title[0] }}</small>
                        </div>

                        <div class="form-col-12">
                            <label for="seo_description" class="db-field-title">{{ $t("label.meta_description") }}</label>
                            <textarea v-model="props.form.seo_description" id="seo_description" rows="2" maxlength="320"
                                :placeholder="$t('label.meta_description_placeholder')"
                                v-bind:class="errors.seo_description ? 'invalid' : ''" class="db-field-control"></textarea>
                            <small class="text-xs" :class="(props.form.seo_description || '').length > 158 ? 'text-red-500' : 'text-gray-500'">
                                {{ (props.form.seo_description || '').length }}/158 · {{ $t("message.meta_description_hint") }}
                            </small>
                            <small class="db-field-alert" v-if="errors.seo_description">{{ errors.seo_description[0] }}</small>
                        </div>

                        <div class="form-col-12">
                            <label for="seo_content" class="db-field-title">{{ $t("label.page_intro") }}</label>
                            <textarea v-model="props.form.seo_content" id="seo_content" rows="4" maxlength="5000"
                                v-bind:class="errors.seo_content ? 'invalid' : ''" class="db-field-control"></textarea>
                            <small class="text-xs text-gray-500">{{ $t("message.page_intro_hint") }}</small>
                            <small class="db-field-alert" v-if="errors.seo_content">{{ errors.seo_content[0] }}</small>
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
import SmModalCreateComponent from "../../components/buttons/SmModalCreateComponent";
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";
import statusEnum from "../../../../enums/modules/statusEnum";

export default {
    name: "ProductBrandCreateComponent",
    components: { SmModalCreateComponent, LoadingComponent },
    props: ["props"],
    data() {
        return {
            loading: {
                isActive: false,
            },
            enums: {
                statusEnum: statusEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive")
                }
            },
            image: "",
            errors: {},
        };
    },
    computed: {
        addButton: function () {
            return { title: this.$t("button.add_product_brand") }
        }
    },
    methods: {
        changeImage: function (e) {
            this.image = e.target.files[0];
        },
        reset: function () {
            appService.modalHide();
            this.$store.dispatch("productBrand/reset").then().catch();
            this.errors = {};
            this.$props.props.form = {
                name: "",
                description: "",
                status: statusEnum.ACTIVE,
                seo_title: "",
                seo_description: "",
                seo_content: "",
            };
            if (this.image) {
                this.image = "";
                this.$refs.imageProperty.value = null;
            }
        },

        save: function () {
            try {
                const fd = new FormData();
                fd.append('name', this.props.form.name);
                fd.append('status', this.props.form.status);
                fd.append('description', this.props.form.description);
                // `|| ""`: FormData would send a missing value as the text "null".
                fd.append('seo_title', this.props.form.seo_title || "");
                fd.append('seo_description', this.props.form.seo_description || "");
                fd.append('seo_content', this.props.form.seo_content || "");
                if (this.image) {
                    fd.append('image', this.image);
                }

                const tempId = this.$store.getters["productBrand/temp"].temp_id;
                this.loading.isActive = true;
                this.$store.dispatch('productBrand/save', {
                    form: fd,
                    search: this.props.search
                }).then((res) => {
                    appService.modalHide();
                    this.loading.isActive = false;
                    alertService.successFlip(
                        tempId === null ? 0 : 1,
                        this.$t("menu.product_brands")
                    );
                    this.props.form = {
                        name: "",
                        description: "",
                        status: statusEnum.ACTIVE,
                        seo_title: "",
                        seo_description: "",
                        seo_content: "",
                    };
                    this.image = "";
                    this.errors = {};
                    this.$refs.imageProperty.value = null;
                }).catch((err) => {
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