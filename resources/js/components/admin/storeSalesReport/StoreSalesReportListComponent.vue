<template>
    <LoadingComponent :props="loading" />
    <div class="col-12">
        <div class="db-card db-tab-div active">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t('menu.store_sales_report') }}</h3>
                <div class="db-card-filter">
                    <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                    <FilterComponent @click.prevent="handleSlide('store-sales-report-filter')" />
                    <div class="dropdown-group">
                        <ExportComponent />
                        <div class="dropdown-list db-card-filter-dropdown-list">
                            <PrintComponent :props="printObj" />
                            <ExcelComponent :method="xls" />
                            <PdfComponent :method="downloadPdf" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-filter-div" id="store-sales-report-filter">
                <form class="p-4 sm:p-5 mb-5" @submit.prevent="search">
                    <div class="row">
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="branch" class="db-field-title after:hidden">{{ $t('label.branch') }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="branch"
                                v-model="props.search.outlet_id" :options="outlets" label-by="name" value-by="id"
                                :closeOnSelect="true" :searchable="true" :clearOnClose="true" placeholder="--"
                                search-placeholder="--" />
                        </div>
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="order_id" class="db-field-title after:hidden">{{ $t('label.order_id') }}</label>
                            <input id="order_id" v-model="props.search.order_serial_no" type="text"
                                class="db-field-control">
                        </div>
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="status" class="db-field-title after:hidden">{{ $t('label.status') }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="status"
                                v-model="props.search.status" :options="orderStatusOptions" label-by="name"
                                value-by="id" :closeOnSelect="true" :searchable="true" :clearOnClose="true"
                                placeholder="--" search-placeholder="--" />
                        </div>
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="date" class="db-field-title after:hidden">{{ $t('label.date') }}</label>
                            <Datepicker hideInputIcon autoApply :enableTimePicker="false" utc="false"
                                @update:modelValue="handleDate" v-model="modelValue" :range="true" />
                        </div>
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="payment_type" class="db-field-title after:hidden">{{ $t('label.payment_type') }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="payment_type"
                                v-model="props.search.payment_method" :options="paymentTypes" label-by="name"
                                value-by="id" :closeOnSelect="true" :searchable="true" :clearOnClose="true"
                                placeholder="--" search-placeholder="--" />
                        </div>
                        <div class="col-12">
                            <div class="flex flex-wrap gap-3 mt-4">
                                <button class="db-btn py-2 text-white bg-primary">
                                    <i class="lab lab-line-search lab-font-size-16"></i>
                                    <span>{{ $t('button.search') }}</span>
                                </button>
                                <button class="db-btn py-2 text-white bg-gray-600" @click.prevent="clear">
                                    <i class="lab lab-line-cross lab-font-size-22"></i>
                                    <span>{{ $t('button.clear') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="row px-5 mt-5 mb-5">
                <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
                    <div class="border flex items-center gap-4 p-4 rounded-lg">
                        <div class="bg-[#F7F7F7] w-12 h-12 rounded-full flex items-center justify-center">
                            <i class="lab-fill-box text-[#6E7191] text-2xl lab-font-size-24"></i>
                        </div>
                        <div>
                            <h3 class="font-medium text-sm capitalize tracking-wide mb-1">{{ $t('label.total_orders') }}</h3>
                            <h4 class="font-bold text-lg text-[#6E7191]">{{ overview.total_orders }}</h4>
                        </div>
                    </div>
                </div>
                <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
                    <div class="border flex items-center gap-4 p-4 rounded-lg">
                        <div class="bg-[#F7F7F7] w-12 h-12 rounded-full flex items-center justify-center">
                            <i class="lab-fill-dollar-circle text-[#6E7191] text-2xl lab-font-size-24"></i>
                        </div>
                        <div>
                            <h3 class="font-medium text-sm capitalize tracking-wide mb-1">{{ $t('label.total_earnings') }}</h3>
                            <h4 class="font-bold text-lg text-[#6E7191]">{{ overview.total_earnings }}</h4>
                        </div>
                    </div>
                </div>
                <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
                    <div class="border flex items-center gap-4 p-4 rounded-lg">
                        <div class="bg-[#F7F7F7] w-12 h-12 rounded-full flex items-center justify-center">
                            <i class="lab-fill-ticket-discount text-[#6E7191] text-2xl lab-font-size-24"></i>
                        </div>
                        <div>
                            <h3 class="font-medium text-sm capitalize tracking-wide mb-1">{{ $t('label.total_discounts') }}</h3>
                            <h4 class="font-bold text-lg text-[#6E7191]">{{ overview.total_discounts }}</h4>
                        </div>
                    </div>
                </div>
                <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
                    <div class="border flex items-center gap-4 p-4 rounded-lg">
                        <div class="bg-[#F7F7F7] w-12 h-12 rounded-full flex items-center justify-center">
                            <i class="lab lab-line-branches text-[#6E7191] lab-font-size-24"></i>
                        </div>
                        <div>
                            <h3 class="font-medium text-sm capitalize tracking-wide mb-1">{{ $t('label.total_branches') }}</h3>
                            <h4 class="font-bold text-lg text-[#6E7191]">{{ overview.total_branches }}</h4>
                        </div>
                    </div>
                </div>
            </div>

            <div id="print">
                <div class="db-table-responsive px-5 pb-5">
                    <h3 class="font-bold text-base mb-3">{{ $t('label.branch_summary') }}</h3>
                    <table class="db-table stripe">
                        <thead class="db-table-head">
                            <tr class="db-table-head-tr">
                                <th class="db-table-head-th">{{ $t('label.branch') }}</th>
                                <th class="db-table-head-th">{{ $t('label.total_orders') }}</th>
                                <th class="db-table-head-th">{{ $t('label.total_sales') }}</th>
                                <th class="db-table-head-th">{{ $t('label.total_discounts') }}</th>
                            </tr>
                        </thead>
                        <tbody class="db-table-body" v-if="branchSummary.length > 0">
                            <tr class="db-table-body-tr" v-for="summary in branchSummary" :key="summary.outlet_id">
                                <td class="db-table-body-td">{{ summary.branch_name }}</td>
                                <td class="db-table-body-td">{{ summary.total_orders }}</td>
                                <td class="db-table-body-td">{{ summary.total_sales_currency }}</td>
                                <td class="db-table-body-td">{{ summary.total_discounts_currency }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="db-table-responsive">
                    <h3 class="font-bold text-base mb-3 px-5">{{ $t('label.order_details') }}</h3>
                    <table class="db-table stripe">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">{{ $t('label.branch') }}</th>
                            <th class="db-table-head-th">{{ $t('label.order_id') }}</th>
                            <th class="db-table-head-th">{{ $t('label.date') }}</th>
                            <th class="db-table-head-th">{{ $t('label.customer') }}</th>
                            <th class="db-table-head-th">{{ $t('label.total') }}</th>
                            <th class="db-table-head-th">{{ $t('label.discount') }}</th>
                            <th class="db-table-head-th">{{ $t('label.payment_type') }}</th>
                            <th class="db-table-head-th">{{ $t('label.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body" v-if="reports.length > 0">
                        <tr class="db-table-body-tr" v-for="report in reports" :key="report.id">
                            <td class="db-table-body-td">{{ report.branch_name }}</td>
                            <td class="db-table-body-td">
                                <router-link class="text-primary font-medium"
                                    :to="{ name: 'admin.pos.orders.show', params: { id: report.id } }">
                                    {{ report.order_serial_no }}
                                </router-link>
                            </td>
                            <td class="db-table-body-td">{{ report.order_datetime }}</td>
                            <td class="db-table-body-td">{{ report.customer_name }}</td>
                            <td class="db-table-body-td">{{ report.total_amount_price }}</td>
                            <td class="db-table-body-td">{{ report.discount_amount_price }}</td>
                            <td class="db-table-body-td">{{ report.pos_payment_method_name }}</td>
                            <td class="db-table-body-td"><span :class="statusClass(report.status)">{{ report.status_name }}</span></td>
                        </tr>
                    </tbody>
                    <tbody class="db-table-body" v-else>
                        <tr class="db-table-body-tr">
                            <td class="db-table-body-td text-center" colspan="8">
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
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6" v-if="reports.length > 0">
                <PaginationSMBox :pagination="pagination" :method="list" />
                <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                    <PaginationTextComponent :props="{ page: paginationPage }" />
                    <PaginationBox :pagination="pagination" :method="list" />
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import TableLimitComponent from "../components/TableLimitComponent";
import FilterComponent from "../components/buttons/collapse/FilterComponent";
import ExportComponent from "../components/buttons/export/ExportComponent";
import PrintComponent from "../components/buttons/export/PrintComponent";
import ExcelComponent from "../components/buttons/export/ExcelComponent";
import PdfComponent from "../components/buttons/export/PdfComponent";
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";
import statusEnum from "../../../enums/modules/statusEnum";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import ENV from "../../../config/env";

export default {
    name: "StoreSalesReportListComponent",
    components: {
        LoadingComponent,
        PaginationTextComponent,
        PaginationBox,
        PaginationSMBox,
        TableLimitComponent,
        FilterComponent,
        ExportComponent,
        PrintComponent,
        ExcelComponent,
        PdfComponent,
        Datepicker,
    },
    data() {
        return {
            loading: { isActive: false },
            printObj: {
                id: "print",
                popTitle: this.$t('menu.store_sales_report')
            },
            props: {
                search: {
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: 'id',
                    order_serial_no: "",
                    outlet_id: null,
                    status: null,
                    payment_method: null,
                    from_date: "",
                    to_date: "",
                }
            },
            modelValue: null,
            orderStatusOptions: [
                { id: orderStatusEnum.PENDING, name: this.$t('label.pending') },
                { id: orderStatusEnum.CONFIRMED, name: this.$t('label.confirmed') },
                { id: orderStatusEnum.ON_THE_WAY, name: this.$t('label.on_the_way') },
                { id: orderStatusEnum.DELIVERED, name: this.$t('label.delivered') },
                { id: orderStatusEnum.CANCELED, name: this.$t('label.canceled') },
                { id: orderStatusEnum.REJECTED, name: this.$t('label.rejected') },
            ],
            paymentTypes: [
                { id: posPaymentMethodEnum.CASH, name: this.$t("label.cash") },
                { id: posPaymentMethodEnum.CARD, name: this.$t("label.card") },
                { id: posPaymentMethodEnum.MOBILE_BANKING, name: this.$t("label.mobile_banking") },
                { id: posPaymentMethodEnum.OTHER, name: this.$t("label.other") },
            ],
            ENV: ENV,
        }
    },
    mounted() {
        this.outletList();
        this.list();
    },
    computed: {
        reports: function () {
            return this.$store.getters['storeSalesReport/lists'];
        },
        branchSummary: function () {
            return this.$store.getters['storeSalesReport/branchSummary'];
        },
        overview: function () {
            return this.$store.getters['storeSalesReport/overview'];
        },
        pagination: function () {
            return this.$store.getters['storeSalesReport/pagination'];
        },
        paginationPage: function () {
            return this.$store.getters['storeSalesReport/page'];
        },
        outlets: function () {
            return this.$store.getters['outlet/lists'];
        },
    },
    methods: {
        statusClass: function (status) {
            return appService.statusClass(status);
        },
        handleSlide: function (id) {
            return appService.handleSlide(id);
        },
        handleDate: function (e) {
            if (e) {
                this.props.search.from_date = e[0];
                this.props.search.to_date = e[1];
            } else {
                this.props.search.from_date = null;
                this.props.search.to_date = null;
            }
        },
        outletList: function () {
            this.$store.dispatch("outlet/lists", {
                paginate: 0,
                order_column: 'id',
                order_type: 'asc',
                status: statusEnum.ACTIVE
            }).then().catch();
        },
        search: function () {
            this.list();
        },
        clear: function () {
            this.props.search.paginate = 1;
            this.props.search.page = 1;
            this.props.search.order_serial_no = "";
            this.props.search.outlet_id = null;
            this.props.search.status = null;
            this.props.search.payment_method = null;
            this.props.search.from_date = "";
            this.props.search.to_date = "";
            this.modelValue = null;
            this.list();
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            Promise.all([
                this.$store.dispatch('storeSalesReport/lists', this.props.search),
                this.$store.dispatch('storeSalesReport/overview', this.props.search),
                this.$store.dispatch('storeSalesReport/branchSummary', this.props.search),
            ]).then(() => {
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
        xls: function () {
            this.loading.isActive = true;
            this.$store.dispatch('storeSalesReport/export', this.props.search).then(res => {
                this.loading.isActive = false;
                const blob = new Blob([res.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = this.$t("menu.store_sales_report");
                link.click();
                URL.revokeObjectURL(link.href);
            }).catch((err) => {
                this.loading.isActive = false;
                alertService.error(err.response.data.message);
            });
        },
        downloadPdf: function () {
            this.loading.isActive = true;
            this.$store.dispatch("storeSalesReport/exportPdf", this.props.search).then((res) => {
                this.loading.isActive = false;
                const blob = new Blob([res.data], { type: 'application/pdf' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = "Store-sales-report.pdf";
                link.click();
                URL.revokeObjectURL(link.href);
            }).catch((err) => {
                this.loading.isActive = false;
                alertService.error(err.response.data.message);
            });
        }
    }
}
</script>
