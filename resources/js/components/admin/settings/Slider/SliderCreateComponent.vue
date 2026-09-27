<template>
    <LoadingComponent :props="loading" />
    <SmModalCreateComponent :props="addButton" />

    <div id="modal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ $t("menu.sliders") }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500"
                    @click="reset"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12">
                            <label for="name" class="db-field-title">{{
                                $t("label.title")
                                }}</label>
                            <input v-model="props.form.title" v-bind:class="errors.title ? 'invalid' : ''" type="text"
                                id="name" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.title">{{
                                errors.title[0]
                                }}</small>
                        </div>

                        <div class="form-col-12">
                            <label for="name" class="db-field-title">{{
                                $t("label.link")
                                }}</label>
                            <input v-model="props.form.link" v-bind:class="errors.link ? 'invalid' : ''" type="text"
                                id="name" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.link">{{
                                errors.link[0]
                                }}</small>
                        </div>

                        <div class="form-col-12">
                            <label class="db-field-title required">{{ $t("label.position") }}</label>
                            <div class="db-field-radio-group">
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.sliderPositionEnum.HERO" v-model="props.form.position"
                                            id="position_hero" type="radio" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="position_hero" class="db-field-label">{{ $t("label.position_hero")
                                        }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.sliderPositionEnum.GRID" v-model="props.form.position"
                                            id="position_grid" type="radio" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="position_grid" class="db-field-label">{{ $t("label.position_grid")
                                        }}</label>
                                </div>
                                <div class="db-field-radio">
                                    <div class="custom-radio">
                                        <input :value="enums.sliderPositionEnum.WIDE" v-model="props.form.position"
                                            id="position_wide" type="radio" class="custom-radio-field" />
                                        <span class="custom-radio-span"></span>
                                    </div>
                                    <label for="position_wide" class="db-field-label">{{ $t("label.position_wide")
                                        }}</label>
                                </div>
                            </div>
                            <small class="db-field-alert" v-if="errors.position">{{
                                errors.position[0]
                                }}</small>
                        </div>


                        <div class="form-col-12  sm:form-col-6">
                            <label for="image" class="db-field-title required">
                                {{ $t("label.image") }} ({{ imageHint }})
                            </label>
                            <input @change="changeImage" v-bind:class="errors.image ? 'invalid' : ''" id="image"
                                type="file" class="db-field-control" ref="imageProperty"
                                accept="image/png, image/jpeg, image/jpg" />
                            <small class="db-field-alert" v-if="errors.image">{{
                                errors.image[0]
                                }}</small>
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
                        </div>

                        <div class="form-col-12">
                            <label for="description" class="db-field-title">{{
                                $t("label.description")
                                }}</label>
                            <textarea v-model="props.form.description" v-bind:class="errors.description ? 'invalid' : ''
                                " id="description" class="db-field-control"></textarea>
                            <small class="db-field-alert" v-if="errors.description">{{ errors.description[0] }}</small>
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
import statusEnum from "../../../../enums/modules/statusEnum";
import sliderPositionEnum from "../../../../enums/modules/sliderPositionEnum";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";

export default {
    name: "SliderCreateComponent",
    components: { SmModalCreateComponent, LoadingComponent },
    props: ["props"],
    data() {
        return {
            loading: {
                isActive: false,
            },
            enums: {
                statusEnum: statusEnum,
                sliderPositionEnum: sliderPositionEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive"),
                },
            },
            image: "",
            errors: {},
        };
    },
    computed: {
        addButton: function () {
            return { title: this.$t("button.add_slider") }
        },
        // A grid tile is rendered at 540x336; uploading a 1689x600 hero image
        // into that slot gets it cropped to a letterbox strip.
        imageHint: function () {
            return this.props.form.position === sliderPositionEnum.GRID ? "540px, 336px" : "1689px, 600px";
        }
    },
    methods: {
        changeImage: function (e) {
            this.image = e.target.files[0];
        },
        reset: function () {
            appService.modalHide();
            this.$store.dispatch("slider/reset").then().catch();
            this.errors = {};
            this.$props.props.form = {
                title: "",
                link: "",
                position: sliderPositionEnum.HERO,
                description: "",
                status: statusEnum.ACTIVE,
            };
            if (this.image) {
                this.image = "";
                this.$refs.imageProperty.value = null;
            }
        },

        save: function () {
            try {
                const fd = new FormData();
                // FormData turns null into the text "null", which would be
                // saved; an untitled slider being edited has a null title.
                fd.append("title", this.props.form.title ?? "");
                fd.append("link", this.props.form.link);
                fd.append("position", this.props.form.position);
                fd.append("status", this.props.form.status);
                fd.append("description", this.props.form.description ?? "");
                if (this.image) {
                    fd.append("image", this.image);
                }

                const tempId = this.$store.getters["slider/temp"].temp_id;
                this.loading.isActive = true;
                this.$store
                    .dispatch("slider/save", {
                        form: fd,
                        search: this.props.search,
                    })
                    .then((res) => {
                        appService.modalHide();
                        this.loading.isActive = false;
                        alertService.successFlip(
                            tempId === null ? 0 : 1,
                            this.$t("menu.sliders")
                        );
                        this.props.form = {
                            title: "",
                            link: "",
                            position: sliderPositionEnum.HERO,
                            description: "",
                            status: statusEnum.ACTIVE,
                        };
                        this.image = "";
                        this.errors = {};
                        this.$refs.imageProperty.value = null;
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
