<template>
    <LoadingComponent :props="loading" />
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('label.stock_adjustments') }}</h3>
                <div class="db-card-filter">
                    <router-link :to="{ name: 'admin.stock.adjustment.create' }" class="db-btn py-2 text-white bg-primary">
                        <i class="lab lab-add-circle-line"></i>
                        <span>{{ $t('button.add') }}</span>
                    </router-link>
                    <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                </div>
            </div>

            <div class="db-table-responsive">
                <table class="db-table stripe">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">{{ $t('label.date') }}</th>
                            <th class="db-table-head-th">{{ $t('label.reference_no') }}</th>
                            <th class="db-table-head-th">{{ $t('label.adjustment_type') }}</th>
                            <th class="db-table-head-th">{{ $t('label.from_branch') }}</th>
                            <th class="db-table-head-th">{{ $t('label.to_branch') }}</th>
                            <th class="db-table-head-th">{{ $t('label.total_items') }}</th>
                            <th class="db-table-head-th">{{ $t('label.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body" v-if="adjustments.length > 0">
                        <tr class="db-table-body-tr" v-for="adjustment in adjustments" :key="adjustment.id">
                            <td class="db-table-body-td">{{ adjustment.converted_date }}</td>
                            <td class="db-table-body-td">{{ adjustment.reference_no || '-' }}</td>
                            <td class="db-table-body-td">{{ typeLabel(adjustment.type) }}</td>
                            <!-- A blank branch on either side is the unassigned
                                 pool, not missing data, so it gets a name. -->
                            <td class="db-table-body-td">
                                {{ hasSource(adjustment.type) ? (adjustment.from_outlet || $t('label.unassigned')) : '-' }}
                            </td>
                            <td class="db-table-body-td">
                                {{ adjustment.type === enums.typeEnum.REMOVE ? '-' : (adjustment.to_outlet || $t('label.unassigned')) }}
                            </td>
                            <td class="db-table-body-td">{{ adjustment.total_items }}</td>
                            <td class="db-table-body-td">
                                <div class="flex justify-start items-center gap-1.5">
                                    <SmIconDeleteComponent @click="destroy(adjustment.id)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tbody class="db-table-body" v-else>
                        <tr class="db-table-body-tr">
                            <td class="db-table-body-td text-center" colspan="7">
                                <div class="p-4">
                                    <div class="max-w-[300px] mx-auto mt-2">
                                        <img class="w-full h-full"
                                            :src="ENV.API_URL + '/images/default/not-found/not_found.png'" alt="Not Found">
                                    </div>
                                    <span class="d-block mt-3 text-lg">{{ $t('message.no_data_found') }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6"
                v-if="adjustments.length > 0">
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
import alertService from "../../../services/alertService";
import stockAdjustmentTypeEnum from "../../../enums/modules/stockAdjustmentTypeEnum";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import TableLimitComponent from "../components/TableLimitComponent";
import SmIconDeleteComponent from "../components/buttons/SmIconDeleteComponent";
import appService from "../../../services/appService";
import ENV from "../../../config/env";

export default {
    name: "StockAdjustmentListComponent",
    components: {
        TableLimitComponent,
        PaginationSMBox,
        PaginationBox,
        PaginationTextComponent,
        LoadingComponent,
        SmIconDeleteComponent,
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            enums: {
                typeEnum: stockAdjustmentTypeEnum,
            },
            props: {
                search: {
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: 'id',
                    order_type: 'desc',
                }
            },
            ENV: ENV
        }
    },
    computed: {
        adjustments: function () {
            return this.$store.getters['stockAdjustment/lists'];
        },
        pagination: function () {
            return this.$store.getters['stockAdjustment/pagination'];
        },
        paginationPage: function () {
            return this.$store.getters['stockAdjustment/page'];
        },
    },
    mounted() {
        this.list();
    },
    methods: {
        hasSource: function (type) {
            // Add creates stock and recalculate names a single branch, so
            // neither has a "from" side to show.
            return type !== stockAdjustmentTypeEnum.ADD && type !== stockAdjustmentTypeEnum.RECALCULATE;
        },
        typeLabel: function (type) {
            if (type === stockAdjustmentTypeEnum.ADD) {
                return this.$t('label.add_stock');
            }
            if (type === stockAdjustmentTypeEnum.REMOVE) {
                return this.$t('label.remove_stock');
            }
            if (type === stockAdjustmentTypeEnum.RECALCULATE) {
                return this.$t('label.recalculate_stock');
            }
            return this.$t('label.transfer_stock');
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch('stockAdjustment/lists', this.props.search).then(() => {
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
        destroy: function (id) {
            // Deleting removes the stock rows the adjustment wrote, which puts
            // the branch counts back where they were.
            appService.destroyConfirmation().then(() => {
                this.loading.isActive = true;
                this.$store.dispatch('stockAdjustment/destroy', { id: id, search: this.props.search }).then(() => {
                    this.loading.isActive = false;
                    alertService.successFlip(null, this.$t('label.stock_adjustment'));
                }).catch((err) => {
                    this.loading.isActive = false;
                    alertService.error(err.response.data.message);
                });
            });
        },
    }
}
</script>
