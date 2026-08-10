<template>
    <LoadingComponent :props="loading" />
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('label.stock_adjustment') }}</h3>
                <router-link :to="{ name: 'admin.stock.adjustment.list' }" class="db-btn py-2 text-white bg-gray-600">
                    <i class="lab lab-line-arrow-left"></i>
                    <span>{{ $t('button.cancel') }}</span>
                </router-link>
            </div>

            <form class="d-block w-full p-4 sm:p-5" @submit.prevent="save">
                <div class="form-row">
                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title required">{{ $t('label.adjustment_type') }}</label>
                        <vue-select v-model="props.form.type" class="db-field-control f-b-custom-select"
                            :options="typeOptions" label-by="name" value-by="id" :closeOnSelect="true"
                            :searchable="false" placeholder="--" search-placeholder="--"
                            @update:modelValue="onTypeChange" />
                        <small class="db-field-alert" v-if="errors.type">{{ errors.type[0] }}</small>
                        <small class="d-block mt-1 text-xs text-gray-500" v-if="isRecalculate">
                            {{ $t('message.recalculate_hint') }}
                        </small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title required">{{ $t('label.date') }}</label>
                        <Datepicker hideInputIcon autoApply v-model="props.form.date" :enableTimePicker="true"
                            :is24="false" :monthChangeOnScroll="false" utc="false"
                            :input-class-name="errors.date ? 'invalid' : ''">
                            <template #am-pm-button="{ toggle, value }">
                                <button @click="toggle">{{ value }}</button>
                            </template>
                        </Datepicker>
                        <small class="db-field-alert" v-if="errors.date">{{ errors.date[0] }}</small>
                    </div>

                    <!-- Each type uses a different side, so the one it does not
                         use is hidden rather than left to confuse. Recalculate
                         has a single branch: the one being counted. -->
                    <div class="form-col-12 sm:form-col-6"
                        v-if="props.form.type !== enums.typeEnum.ADD && props.form.type !== enums.typeEnum.RECALCULATE">
                        <label class="db-field-title" :class="props.form.type === enums.typeEnum.REMOVE ? 'required' : ''">
                            {{ $t('label.from_branch') }}
                        </label>
                        <vue-select v-model="props.form.from_outlet_id" class="db-field-control f-b-custom-select"
                            :options="outlets" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                            :clearOnClose="true" :placeholder="$t('label.unassigned')"
                            :search-placeholder="$t('label.search_branch')" />
                        <small class="db-field-alert" v-if="errors.from_outlet_id">{{ errors.from_outlet_id[0] }}</small>
                    </div>

                    <div class="form-col-12 sm:form-col-6" v-if="props.form.type !== enums.typeEnum.REMOVE">
                        <label class="db-field-title" :class="props.form.type === enums.typeEnum.ADD ? 'required' : ''">
                            {{ isRecalculate ? $t('label.branch') : $t('label.to_branch') }}
                        </label>
                        <vue-select v-model="props.form.to_outlet_id" class="db-field-control f-b-custom-select"
                            :options="outlets" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                            :clearOnClose="true" :placeholder="$t('label.unassigned')"
                            :search-placeholder="$t('label.search_branch')"
                            @update:modelValue="onBranchChange" />
                        <small class="db-field-alert" v-if="errors.to_outlet_id">{{ errors.to_outlet_id[0] }}</small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title">{{ $t('label.reference_no') }}</label>
                        <input v-model="props.form.reference_no" type="text" class="db-field-control" />
                        <small class="db-field-alert" v-if="errors.reference_no">{{ errors.reference_no[0] }}</small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title">{{ $t('label.note') }}</label>
                        <input v-model="props.form.note" type="text" class="db-field-control" />
                        <small class="db-field-alert" v-if="errors.note">{{ errors.note[0] }}</small>
                    </div>

                    <div class="form-col-12">
                        <label class="db-field-title required">{{ $t('label.add_products') }}</label>
                        <vue-select v-model="productId" class="db-field-control f-b-custom-select" :options="products"
                            label-by="name" value-by="id" :closeOnSelect="true" :searchable="true" :clearOnClose="true"
                            :placeholder="$t('label.select_one')" search-placeholder="--"
                            @update:modelValue="selectProduct($event)" />
                        <small class="db-field-alert" v-if="errors.products">{{ errors.products[0] }}</small>
                    </div>

                    <div class="form-col-12">
                        <div class="db-table-responsive border rounded-md">
                            <table class="db-table">
                                <thead class="db-table-head border-t-0">
                                    <tr class="db-table-head-tr">
                                        <th class="db-table-head-th">{{ $t('label.product') }}</th>
                                        <th class="db-table-head-th">{{ $t('label.sku') }}</th>
                                        <th class="db-table-head-th" v-if="isRecalculate">
                                            {{ $t('label.current_stock') }}
                                        </th>
                                        <th class="db-table-head-th">
                                            {{ isRecalculate ? $t('label.new_stock') : $t('label.quantity') }}
                                        </th>
                                        <th class="db-table-head-th">{{ $t('label.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="db-table-body">
                                    <tr v-for="(item, index) of datatable" :key="index" class="db-table-body-tr">
                                        <td class="db-table-body-td font-medium">
                                            {{ item.name }}
                                            <span v-if="item.variation_names"> ({{ item.variation_names }})</span>
                                        </td>
                                        <td class="db-table-body-td">{{ item.sku || '-' }}</td>
                                        <!-- What the branch holds right now, so the
                                             new figure is typed over a real number
                                             instead of from memory. -->
                                        <td class="db-table-body-td" v-if="isRecalculate">
                                            {{ item.current_stock === null ? '…' : item.current_stock }}
                                        </td>
                                        <td class="db-table-body-td">
                                            <input v-model="item.quantity" v-on:keypress="onlyNumber($event)"
                                                :min="isRecalculate ? 0 : 1" type="number"
                                                class="db-field-control max-w-28" />
                                        </td>
                                        <td class="db-table-body-td">
                                            <SmIconDeleteComponent @click="removeProduct(index)" />
                                        </td>
                                    </tr>
                                    <tr v-if="datatable.length === 0" class="db-table-body-tr">
                                        <td class="db-table-body-td text-center" :colspan="isRecalculate ? 5 : 4">
                                            {{ $t('message.no_data_found') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="form-col-12">
                        <button class="db-btn py-2 text-white bg-primary" type="submit">
                            <i class="lab lab-fill-save"></i>
                            <span>{{ $t('button.save') }}</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <StockAdjustmentProductModalComponent v-if="modal.isShowModal" v-on:submitItem="modalSubmit"
        :item="selectedProduct" :modal="modal" />
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";
import statusEnum from "../../../enums/modules/statusEnum";
import stockAdjustmentTypeEnum from "../../../enums/modules/stockAdjustmentTypeEnum";
import SmIconDeleteComponent from "../components/buttons/SmIconDeleteComponent";
import StockAdjustmentProductModalComponent from "./StockAdjustmentProductModalComponent";
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";

export default {
    name: "StockAdjustmentCreateComponent",
    components: {
        LoadingComponent,
        SmIconDeleteComponent,
        StockAdjustmentProductModalComponent,
        Datepicker,
    },
    data() {
        return {
            loading: { isActive: false },
            enums: { typeEnum: stockAdjustmentTypeEnum },
            props: {
                form: {
                    type: stockAdjustmentTypeEnum.TRANSFER,
                    from_outlet_id: null,
                    to_outlet_id: null,
                    date: new Date(),
                    reference_no: "",
                    note: "",
                }
            },
            datatable: [],
            productId: null,
            selectedProduct: {},
            modal: { isShowModal: false },
            errors: {},
        }
    },
    computed: {
        products: function () {
            // The whole catalogue, not the purchasable list the purchase screen
            // uses. That one filters to can_purchasable = YES and status =
            // ACTIVE, which hides most products here - and an adjustment exists
            // precisely to correct a count on any product the stock screen
            // shows, including inactive and non-purchasable ones.
            return this.$store.getters['product/simpleList'];
        },
        outlets: function () {
            return this.$store.getters['outlet/lists'];
        },
        typeOptions: function () {
            return [
                { id: stockAdjustmentTypeEnum.TRANSFER, name: this.$t('label.transfer_stock') },
                { id: stockAdjustmentTypeEnum.ADD, name: this.$t('label.add_stock') },
                { id: stockAdjustmentTypeEnum.REMOVE, name: this.$t('label.remove_stock') },
                { id: stockAdjustmentTypeEnum.RECALCULATE, name: this.$t('label.recalculate_stock') },
            ];
        },
        isRecalculate: function () {
            return this.props.form.type === stockAdjustmentTypeEnum.RECALCULATE;
        },
    },
    mounted() {
        this.loading.isActive = true;
        this.$store.dispatch('product/getSimpleProduct').then(() => {
            this.loading.isActive = false;
        }).catch(() => {
            this.loading.isActive = false;
        });
        this.$store.dispatch('outlet/lists', {
            paginate: 0,
            order_column: 'id',
            order_type: 'asc',
            status: statusEnum.ACTIVE
        });
    },
    methods: {
        onlyNumber: function (e) {
            return appService.onlyNumber(e);
        },
        // Both handlers take the new value as an argument rather than reading
        // the model back: v-model and this listener are two handlers on the
        // same event, and their order is not something to depend on.
        onTypeChange: function (type) {
            this.props.form.type = type;

            // A quantity means "move this many" under the delta types and "this
            // is the count" under recalculate. Carrying numbers across that
            // switch would silently mean something else, so the rows reload
            // against the new meaning.
            if (this.isRecalculate) {
                this.datatable.forEach(row => this.loadCurrentStock(row));
            } else {
                this.datatable.forEach(row => { row.current_stock = null; });
            }
        },
        onBranchChange: function (outletId) {
            this.props.form.to_outlet_id = outletId;

            if (this.isRecalculate) {
                this.datatable.forEach(row => this.loadCurrentStock(row));
            }
        },
        /**
         * Reads what the chosen branch holds today and prefills it, so saving
         * an untouched row is a no-op rather than a silent wipe to some
         * leftover number.
         */
        loadCurrentStock: function (row) {
            row.current_stock = null;
            this.$store.dispatch('stockAdjustment/itemQuantity', {
                product_id: row.product_id,
                variation_id: row.variation_id,
                outlet_id: this.props.form.to_outlet_id ?? 0,
            }).then((res) => {
                row.current_stock = res.data.data.quantity;
                row.quantity = res.data.data.quantity;
            }).catch(() => {
                row.current_stock = 0;
            });
        },
        selectProduct: function (id) {
            const product = this.products.find(product => product.id === id);
            if (!product) {
                return;
            }

            this.selectedProduct = {
                name: product.name,
                product_id: product.id,
                sku: product.sku,
                variation_id: 0,
                variation_names: "",
                quantity: 1,
                current_stock: null,
            };

            if (product.is_variation) {
                this.$store.commit('productVariation/initialVariation', []);
                this.loading.isActive = true;
                this.$store.dispatch("productVariation/initialVariation", product.id).then(() => {
                    this.loading.isActive = false;
                    this.modal.isShowModal = true;
                }).catch(() => {
                    this.loading.isActive = false;
                });
            } else {
                this.addProduct();
            }
        },
        modalSubmit: function (variation) {
            this.modal.isShowModal = false;
            if (variation) {
                this.$store.dispatch("productVariation/ancestorsToString", variation.id).then((res) => {
                    this.selectedProduct.variation_names = res.data.data;
                    this.addProduct();
                }).catch(() => {
                    this.addProduct();
                });
            } else {
                this.addProduct();
            }
        },
        addProduct: function () {
            const existing = this.datatable.find(row =>
                row.product_id === this.selectedProduct.product_id && row.variation_id === this.selectedProduct.variation_id
            );

            if (existing) {
                // Adding the same item twice bumps a delta, but under
                // recalculate the number is a count, not something to add to.
                if (!this.isRecalculate) {
                    existing.quantity = +existing.quantity + 1;
                }
            } else {
                const row = { ...this.selectedProduct };
                this.datatable.push(row);
                if (this.isRecalculate) {
                    this.loadCurrentStock(this.datatable[this.datatable.length - 1]);
                }
            }

            this.productId = null;
            this.selectedProduct = {};
        },
        removeProduct: function (index) {
            this.datatable.splice(index, 1);
        },
        save: function () {
            this.errors = {};
            const fd = new FormData();
            fd.append('type', this.props.form.type ?? "");
            fd.append('from_outlet_id', this.props.form.from_outlet_id ?? "");
            fd.append('to_outlet_id', this.props.form.to_outlet_id ?? "");
            fd.append('date', this.props.form.date ? this.props.form.date : "");
            fd.append('reference_no', this.props.form.reference_no ?? "");
            fd.append('note', this.props.form.note ?? "");
            fd.append('products', JSON.stringify(this.datatable));

            this.loading.isActive = true;
            this.$store.dispatch('stockAdjustment/save', { form: fd }).then(() => {
                this.loading.isActive = false;
                alertService.successFlip(0, this.$t('label.stock_adjustment'));
                this.$router.push({ name: 'admin.stock.adjustment.list' });
            }).catch((err) => {
                this.loading.isActive = false;
                this.errors = err.response.data.errors ?? {};
                if (this.errors.global) {
                    alertService.error(this.errors.global[0]);
                } else if (err.response.data.message) {
                    alertService.error(err.response.data.message);
                }
            });
        },
    }
}
</script>
