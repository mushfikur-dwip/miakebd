<template>
    <LoadingComponent :props="loading" />
    <SmModalCreateComponent @click="createButtonClick" :props="addButton" />

    <div id="videoModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ $t("menu.product_video") }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500" @click.prevent="reset"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12">
                            <label for="video_provider" class="db-field-title required">{{$t("label.video_provider") }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="video_provider"
                                v-bind:class="errors.video_provider ? 'invalid' : ''" v-model="props.form.video_provider"
                                :options="enums.videoProviderEnum" label-by="name" value-by="id" :closeOnSelect="true"
                                :searchable="true" :clearOnClose="true" placeholder="--" search-placeholder="--" />
                            <small class="db-field-alert" v-if="errors.video_provider">
                                {{ errors.video_provider[0] }}
                            </small>
                        </div>

                        <!-- The frame on the product page takes this shape. Auto plays
                             Facebook videos and Shorts/reels vertical, the rest wide. -->
                        <div class="form-col-12">
                            <label for="orientation" class="db-field-title">{{ $t("label.video_orientation") }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="orientation"
                                v-bind:class="errors.orientation ? 'invalid' : ''" v-model="props.form.orientation"
                                :options="enums.videoOrientationEnum" label-by="name" value-by="id" :closeOnSelect="true"
                                :searchable="false" placeholder="--" />
                            <small class="db-field-alert" v-if="errors.orientation">
                                {{ errors.orientation[0] }}
                            </small>
                        </div>

                        <div class="form-col-12">
                            <label for="link" class="db-field-title required">{{ $t("label.link") }}</label>
                            <!-- The ordinary link is fine - the storefront turns it into the
                                 provider's player. For Facebook the server also takes a
                                 Share link or the Embed code and stores the reel address. -->
                            <textarea v-model="props.form.link" v-bind:class="errors.link ? 'invalid' : ''" id="link"
                                :placeholder="props.form.video_provider === 20
                                    ? 'https://www.facebook.com/reel/...'
                                    : 'https://www.youtube.com/watch?v=...'"
                                class="db-field-control"></textarea>
                            <small class="db-field-alert" v-if="errors.link">
                                {{ errors.link[0] }}
                            </small>
                            <p v-else-if="props.form.video_provider === 20" class="text-xs text-gray-500 mt-1.5">
                                {{ $t('message.facebook_video_help') }}
                            </p>
                        </div>

                        <div class="form-col-12">
                            <div class="modal-btns">
                                <button type="button" class="modal-btn-outline modal-close" @click.prevent="reset">
                                    <i class="lab lab-fill-close-circle"></i>
                                    <span>{{ $t('button.close') }}</span>
                                </button>

                                <button type="submit" class="db-btn py-2 text-white bg-primary">
                                    <i class="lab lab-fill-save"></i>
                                    <span>{{ $t('button.save') }}</span>
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
import videoProviderEnum from "../../../../enums/modules/videoProviderEnum";
import videoOrientationEnum from "../../../../enums/modules/videoOrientationEnum";

export default {
    name: "ProductVideoCreateComponent",
    components: { SmModalCreateComponent, LoadingComponent },
    props: ["props"],
    data() {
        return {
            loading: {
                isActive: false
            },
            errors: {},
            enums: {
                videoProviderEnum: videoProviderEnum,
                videoOrientationEnum: videoOrientationEnum,
            },
        }
    },
    computed: {
        addButton: function () {
              return {title: this.$t("button.add_video")}
        }
    },
    methods: {
        createButtonClick: function() {
            appService.modalShow('#videoModal');
        },
        reset: function () {
            appService.modalHide('#videoModal');
            appService.modalHide('#variationModal');
            this.$store.dispatch('productVideo/reset').then().catch();
            this.errors = {};
            this.$props.props.form = {
                video_provider: null,
                link: "",
                orientation: 0,
            }
        },
        save: function () {
            try {
                this.loading.isActive = true;
                this.$store.dispatch('productVideo/save', this.props).then((res) => {
                    appService.modalHide('#videoModal');
                    appService.modalHide('#variationModal');
                    this.loading.isActive = false;
                    alertService.successFlip((res.config.method === 'put' ?? 0), this.$t('label.product_video'));
                    // Facebook refused to play the reel on other websites (its
                    // audience is not Public) - say so now, not after a customer does.
                    if (res.data && res.data.warning) {
                        alertService.warning(res.data.warning);
                    }
                    this.props.form = {
                        video_provider: null,
                        link: "",
                        orientation: 0,
                    }
                    this.errors = {}
                }).catch((err) => {
                    this.loading.isActive = false;
                    this.errors = err.response.data.errors;
                })
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err.response.data.message);
            }
        }
    }
};
</script>
