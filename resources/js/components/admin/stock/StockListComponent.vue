<template>
    <LoadingComponent :props="loading" />
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('menu.stock') }}</h3>
                <div class="db-card-filter">
                    <!-- Kept out in the open rather than behind the Filter
                         toggle: a scanner fires the code then Enter, so the
                         field has to already have focus available. -->
                    <div class="flex items-center h-10 px-3 rounded-md border border-[#EFF0F6] bg-white">
                        <i class="lab lab-line-qrcode ltr:mr-2 rtl:ml-2"></i>
                        <input id="scanSku" ref="skuField" v-model="props.search.sku" type="search"
                            :placeholder="$t('label.scan_or_type_barcode')" class="w-40 sm:w-52 text-sm"
                            @keyup.enter.prevent="search" />
                        <button v-if="props.search.sku" @click.prevent="clearSku" type="button"
                            class="text-sm text-red-500 fa-regular fa-circle-xmark"></button>
                    </div>
                    <router-link :to="{ name: 'admin.stock.adjustment.list' }"
                        class="db-btn py-2 text-white bg-primary">
                        <i class="lab lab-line-stock"></i>
                        <span>{{ $t('label.stock_adjustment') }}</span>
                    </router-link>
                    <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                    <FilterComponent @click.prevent="handleSlide('stock-filter')" />
                    <div class="dropdown-group">
                        <ExportComponent />
                        <div class="dropdown-list db-card-filter-dropdown-list">
                            <PrintComponent :props="printObj" />
                            <ExcelComponent :method="xls" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-filter-div" id="stock-filter">
                <form class="p-4 sm:p-5 mb-5 w-full d-block" @submit.prevent="search">
                    <div class="row">
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchName" class="db-field-title after:hidden">
                                {{ $t("label.name") }}
                            </label>
                            <input id="searchName" v-model="props.search.product_name" type="text"
                                class="db-field-control" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchBranch" class="db-field-title after:hidden">
                                {{ $t("label.branch") }}
                            </label>
                            <!-- 0 is the unassigned pool: everything recorded
                                 before branch-wise stock, plus delivery orders. -->
                            <vue-select class="db-field-control f-b-custom-select" id="searchBranch"
                                v-model="props.search.outlet_id" :options="branchOptions" label-by="name" value-by="id"
                                :closeOnSelect="true" :searchable="true" :clearOnClose="true"
                                :placeholder="$t('label.all_branch')" :search-placeholder="$t('label.search_branch')" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchStatus" class="db-field-title after:hidden">
                                {{ $t("label.status") }}
                            </label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchStatus"
                                v-model="props.search.status"
                                :options="[{ id: enums.statusEnum.ACTIVE, name: $t('label.active') }, { id: enums.statusEnum.INACTIVE, name: $t('label.inactive') }]"
                                label-by="name" value-by="id" :closeOnSelect="true" :searchable="true" :clearOnClose="true"
                                placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12">
                            <div class="flex flex-wrap gap-3 mt-4">
                                <button class="db-btn py-2 text-white bg-primary">
                                    <i class="lab lab-line-search lab-font-size-16"></i>
                                    <span>{{ $t("button.search") }}</span>
                                </button>
                                <button class="db-btn py-2 text-white bg-gray-600" @click="clear">
                                    <i class="lab lab-line-cross lab-font-size-22"></i>
                                    <span>{{ $t("button.clear") }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="db-table-responsive">
                <table class="db-table stripe" id="print">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">
                                {{ $t('label.name') }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t('label.sku') }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t('label.quantity') }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t('label.branch_stock') }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t('label.status') }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t('label.actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body" v-if="stocks.length > 0">
                        <tr class="db-table-body-tr" v-for="stock in stocks" :key="stock">
                            <td class="db-table-body-td">
                                {{ textShortener(stock.product_name, 40) }}
                                <span v-if="stock.variation_names"> ( {{ $t('label.variation') }} : {{ stock.variation_names
                                }} )</span>
                            </td>
                            <td class="db-table-body-td">{{ stock.sku || '-' }}</td>
                            <td class="db-table-body-td">
                                {{ stock.stock }}
                                <!-- Can Purchasable = No: the storefront ignores
                                     this count and treats the product as always
                                     available. The real figure still shows here
                                     so it can be corrected. -->
                                <span v-if="!stock.stock_tracked"
                                    class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">
                                    {{ $t('label.not_stock_controlled') }}
                                </span>
                            </td>
                            <td class="db-table-body-td">
                                <div class="flex flex-wrap gap-1.5">
                                    <span v-for="outletStock in stock.outlet_stocks" :key="outletStock.outlet_name"
                                        class="whitespace-nowrap text-xs px-2 py-1 rounded"
                                        :class="outletStock.quantity > 0 ? 'bg-gray-100' : 'bg-gray-50 text-gray-400'">
                                        {{ outletStock.outlet_name }}: <b>{{ outletStock.quantity }}</b>
                                    </span>
                                </div>
                            </td>
                            <td class="db-table-body-td">
                                <span :class="statusClass(stock.status)">
                                    {{ enums.statusEnumArray[stock.status] }}
                                </span>
                            </td>
                            <td class="db-table-body-td hidden-print">
                                <!-- A plain button, not SmIconEditComponent -
                                     that one is a router-link to an edit page,
                                     and this edit happens in a dialog. -->
                                <button class="db-table-action edit" @click.prevent="edit(stock)">
                                    <i class="lab lab-line-edit"></i>
                                    <span class="db-tooltip">{{ $t('button.edit') }}</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tbody class="db-table-body" v-else>
                        <tr class="db-table-body-tr">
                            <td class="db-table-body-td text-center" colspan="6">
                                <div class="p-4">
                                    <div class="max-w-[300px] mx-auto mt-2">
                                        <img class="w-full h-full" :src="ENV.API_URL+'/images/default/not-found/not_found.png'" alt="Not Found">
                                    </div>
                                    <span class="d-block mt-3 text-lg">{{ $t('message.no_data_found') }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6" v-if="stocks.length > 0">
                <PaginationSMBox :pagination="pagination" :method="list" />
                <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                    <PaginationTextComponent :props="{ page: paginationPage }" />
                    <PaginationBox :pagination="pagination" :method="list" />
                </div>
            </div>
        </div>
    </div>

    <!-- Keyed on the row so reopening on a different product rebuilds the
         branch lines instead of showing the previous product's numbers. -->
    <StockEditModalComponent v-if="editModal.isShowModal" :key="editKey" :item="editItem" :modal="editModal"
        :outlets="outlets" v-on:saved="list(props.search.page)" />
</template>
<script>
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";
import statusEnum from "../../../enums/modules/statusEnum";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import appService from "../../../services/appService";
import TableLimitComponent from "../components/TableLimitComponent";
import SmIconSidebarModalEditComponent from "../components/buttons/SmIconSidebarModalEditComponent";
import SmIconDeleteComponent from "../components/buttons/SmIconDeleteComponent";
import SmIconViewComponent from "../components/buttons/SmIconViewComponent";
import FilterComponent from "../components/buttons/collapse/FilterComponent";
import ExportComponent from "../components/buttons/export/ExportComponent";
import PrintComponent from "../components/buttons/export/PrintComponent";
import ExcelComponent from "../components/buttons/export/ExcelComponent";
import _ from "lodash";
import ENV from "../../../config/env";
import StockEditModalComponent from "./StockEditModalComponent";

export default {
    name: "StockListComponent",
    components: {
        StockEditModalComponent,
        TableLimitComponent,
        PaginationSMBox,
        PaginationBox,
        PaginationTextComponent,
        LoadingComponent,
        SmIconSidebarModalEditComponent,
        SmIconDeleteComponent,
        SmIconViewComponent,
        FilterComponent,
        ExportComponent,
        PrintComponent,
        ExcelComponent
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            enums: {
                statusEnum: statusEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive")
                },
            },
            printLoading: true,
            printObj: {
                id: "print",
                popTitle: this.$t("menu.stock"),
            },
            props: {
                search: {
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: 'id',
                    order_type: 'desc',
                    product_name: "",
                    sku: "",
                    outlet_id: null,
                    status: null,
                }
            },
            editModal: { isShowModal: false },
            editItem: {},
            editKey: 0,
            ENV: ENV
        }
    },
    computed: {
        stocks: function () {
            return this.$store.getters['stock/lists'];
        },
        outlets: function () {
            return this.$store.getters['outlet/lists'];
        },
        branchOptions: function () {
            // Id 0 stands for the unassigned pool, which has no outlet row of
            // its own but is still a thing you need to be able to filter on.
            return [{ id: 0, name: this.$t('label.unassigned') }].concat(this.outlets);
        },
        pagination: function () {
            return this.$store.getters['stock/pagination'];
        },
        paginationPage: function () {
            return this.$store.getters['stock/page'];
        },
    },
    mounted() {
        this.list();
        this.$store.dispatch('outlet/lists', {
            paginate: 0,
            order_column: 'id',
            order_type: 'asc',
            status: statusEnum.ACTIVE
        });
    },
    methods: {
        permissionChecker(e) {
            return appService.permissionChecker(e);
        },
        statusClass: function (status) {
            return appService.statusClass(status);
        },
        textShortener: function (text, number = 30) {
            return appService.textShortener(text, number);
        },
        handleSlide: function (id) {
            return appService.handleSlide(id);
        },
        search: function () {
            this.list();
        },
        edit: function (stock) {
            this.editItem = stock;
            this.editKey += 1;
            this.editModal.isShowModal = true;
        },
        clearSku: function () {
            this.props.search.sku = "";
            this.list();
            // Hand focus straight back so the next scan lands in the field.
            this.$nextTick(() => this.$refs.skuField?.focus());
        },
        clear: function () {
            this.props.search.paginate = 1;
            this.props.search.page = 1;
            this.props.search.product_name = "";
            this.props.search.sku = "";
            this.props.search.outlet_id = null;
            this.props.search.status = null;
            this.list();
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch('stock/lists', this.props.search).then(res => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
        xls: function () {
            this.loading.isActive = true;
            this.$store.dispatch("stock/export", this.props.search).then((res) => {
                this.loading.isActive = false;
                const blob = new Blob([res.data], {
                    type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                });
                const link = document.createElement("a");
                link.href = URL.createObjectURL(blob);
                link.download = this.$t("menu.stock") + ".xlsx";
                link.click();
                URL.revokeObjectURL(link.href);
            }).catch((err) => {
                this.loading.isActive = false;
                alertService.downloadError(err);
            });
        },
    }
}
</script>

<style scoped>
@media print {
    .hidden-print {
        display: none !important;
    }
}</style>
