<template>
    <LoadingComponent :props="loading" />

    <button type="button" @click="add" data-modal="#campaignProductModal" class="db-btn h-[37px] text-white bg-primary">
        <i class="lab lab-line-add-circle"></i>
        <span>{{ addButton.title }}</span>
    </button>

    <div id="campaignProductModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ $t("menu.products") }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500"
                    @click="reset"></button>
            </div>
            <div class="modal-body">
                <div class="form-row" v-if="message">
                    <div class="form-col-12 db-field-alert">
                        {{ message }}
                    </div>
                </div>
                <form @submit.prevent="save" class="d-block w-full">
                    <div class="form-row">
                        <div class="form-col-12">
                            <label for="product_id" class="db-field-title required">
                                {{ $t("label.product") }}
                            </label>
                            <!-- Products are listed by name only. This picker
                                 deliberately never shows the retail price: the
                                 campaign price is entered from scratch, and a
                                 price column next to the input turns that into
                                 "discount the listed price", which is not what
                                 campaign pricing does here. -->
                            <vue-select class="db-field-control f-b-custom-select" id="product_id"
                                v-bind:class="errors.product_id ? 'invalid' : ''" v-model="props.form.product_id"
                                :options="products" label-by="name" value-by="id" :closeOnSelect="true"
                                :searchable="true" :clearOnClose="true" :disabled="isEditing" placeholder="--"
                                search-placeholder="--" />
                            <small class="db-field-alert" v-if="errors.product_id">
                                {{ errors.product_id[0] }}
                            </small>
                        </div>

                        <!-- Title, image and description so the admin can tell
                             two similar products apart. No price of any kind. -->
                        <div class="form-col-12" v-if="selectedProduct">
                            <div class="flex gap-4 p-3 rounded-lg bg-slate-50">
                                <img v-if="selectedProduct.cover" :src="selectedProduct.cover"
                                    :alt="selectedProduct.name"
                                    class="w-16 h-16 rounded-lg object-cover shrink-0" />
                                <div class="min-w-0">
                                    <p class="font-semibold text-slate-700 truncate">{{ selectedProduct.name }}</p>
                                    <p class="text-xs text-slate-500 mt-0.5">
                                        <span v-if="selectedProduct.sku">{{ $t('label.sku') }}: {{
                                            selectedProduct.sku }}</span>
                                        <span v-if="selectedProduct.category_name"> &middot; {{
                                            selectedProduct.category_name }}</span>
                                    </p>
                                    <p class="text-xs text-slate-500 mt-1 line-clamp-2" v-if="plainDescription">
                                        {{ plainDescription }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="form-col-12">
                            <label for="special_price" class="db-field-title required">
                                {{ $t("label.special_price") }}
                            </label>
                            <input v-model="props.form.special_price" v-on:keypress="floatNumber($event)"
                                v-bind:class="errors.special_price ? 'invalid' : ''" type="text" id="special_price"
                                class="db-field-control" autocomplete="off" />
                            <small class="db-field-alert" v-if="errors.special_price">
                                {{ errors.special_price[0] }}
                            </small>
                            <p class="text-xs text-slate-500 mt-1">{{ $t('message.special_price_hint') }}</p>
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
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";
import statusEnum from "../../../../enums/modules/statusEnum";

export default {
    name: "CampaignProductCreateComponent",
    components: { LoadingComponent },
    props: ["props"],
    data() {
        return {
            loading: {
                isActive: false,
            },
            errors: {},
            message: null,
        };
    },
    computed: {
        products: function () {
            return this.$store.getters['product/lists'];
        },
        isEditing: function () {
            // The row's product is fixed once saved — changing it would be a
            // different campaign_products row, and the unique index treats it
            // as one. Editing changes the price only.
            return this.$store.getters['campaignProduct/temp'].isEditing;
        },
        selectedProduct: function () {
            if (!this.props.form.product_id) {
                return null;
            }
            return (this.products || []).find(product => product.id === this.props.form.product_id) || null;
        },
        plainDescription: function () {
            const html = this.selectedProduct?.description || '';
            if (!html) {
                return '';
            }
            // Descriptions are stored as rich text; rendering the raw markup in
            // this preview would spill the editor's tags into the modal.
            const text = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            return text.length > 160 ? text.substring(0, 160) + '…' : text;
        },
        addButton: function () {
            return { title: this.$t("button.add_product") };
        }
    },
    mounted() {
        this.loading.isActive = true;
        this.$store.dispatch('product/lists', {
            paginate: 0,
            order_column: 'name',
            order_type: 'asc',
            status: statusEnum.ACTIVE
        }).then(() => {
            this.loading.isActive = false;
        }).catch(() => {
            this.loading.isActive = false;
        });
    },
    methods: {
        add: function () {
            // Always a fresh add: without this, opening the modal straight
            // after an edit would PUT to the previously edited row.
            this.$store.dispatch("campaignProduct/reset").then().catch();
            this.$props.props.form = {
                product_id: null,
                special_price: "",
            };
            this.errors = {};
            this.message = null;
            appService.modalShow();
        },
        floatNumber: function (e) {
            return appService.floatNumber(e);
        },
        reset: function () {
            appService.modalHide();
            this.$store.dispatch("campaignProduct/reset").then().catch();
            this.errors = {};
            this.$props.props.form = {
                product_id: null,
                special_price: "",
            };
            this.message = null;
        },
        save: function () {
            try {
                const tempId = this.$store.getters["campaignProduct/temp"].temp_id;
                this.loading.isActive = true;
                this.$store.dispatch("campaignProduct/save", this.props).then(() => {
                    appService.modalHide();
                    this.loading.isActive = false;
                    alertService.successFlip(
                        tempId === null ? 0 : 1,
                        this.$t("label.product")
                    );
                    this.props.form = {
                        product_id: null,
                        special_price: "",
                    };
                    this.errors = {};
                    this.message = null;
                }).catch((err) => {
                    this.loading.isActive = false;
                    const data = err.response?.data;
                    if (data?.errors === undefined) {
                        this.errors = {};
                        this.message = data?.message ?? null;
                    } else {
                        this.message = null;
                        this.errors = data.errors;
                    }
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        },
    }
};
</script>
