<template>
    <LoadingComponent :props="loading" />
    <div class="db-card-header border-none">
        <h3 class="db-card-title">{{ $t('menu.products') }}</h3>
        <div class="db-card-filter">
            <CampaignProductCreateComponent :props="campaignProps" />
            <TableLimitComponent :method="list" :search="campaignProps.search" :page="paginationPage" />
        </div>
    </div>
    <div class="db-table-responsive">
        <table class="db-table stripe">
            <thead class="db-table-head">
                <tr class="db-table-head-tr">
                    <th class="db-table-head-th">{{ $t("label.name") }}</th>
                    <!-- Only the campaign price. The retail price is not shown
                         anywhere on this screen, by design. -->
                    <th class="db-table-head-th">{{ $t("label.special_price") }}</th>
                    <th class="db-table-head-th">{{ $t("label.status") }}</th>
                    <th class="db-table-head-th">{{ $t("label.action") }}</th>
                </tr>
            </thead>
            <tbody class="db-table-body" v-if="campaignProducts.length > 0">
                <tr class="db-table-body-tr" v-for="campaignProduct in campaignProducts" :key="campaignProduct.id">
                    <td class="db-table-body-td">
                        <div class="flex items-center gap-3">
                            <img v-if="campaignProduct.campaign_product_cover"
                                :src="campaignProduct.campaign_product_cover"
                                :alt="campaignProduct.campaign_product_name"
                                class="w-10 h-10 rounded-lg object-cover shrink-0" />
                            <div class="min-w-0">
                                <p class="truncate">{{ campaignProduct.campaign_product_name }}</p>
                                <p class="text-xs text-slate-400" v-if="campaignProduct.campaign_product_sku">
                                    {{ campaignProduct.campaign_product_sku }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="db-table-body-td font-semibold">
                        {{ campaignProduct.special_price }}
                    </td>
                    <td class="db-table-body-td">
                        <span :class="statusClass(campaignProduct.campaign_product_status)">
                            {{ enums.statusEnumArray[campaignProduct.campaign_product_status] }}
                        </span>
                    </td>
                    <td class="db-table-body-td">
                        <div class="flex justify-start items-center gap-1.5">
                            <SmIconModalEditComponent @click="edit(campaignProduct)" />
                            <SmIconDeleteComponent @click="destroy(campaignProduct.id)" />
                        </div>
                    </td>
                </tr>
            </tbody>
            <tbody class="db-table-body" v-else>
                <tr class="db-table-body-tr">
                    <td class="db-table-body-td text-center" colspan="4">
                        <span class="d-block p-4 text-lg">{{ $t('message.no_data_found') }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6"
        v-if="campaignProducts.length > 0">
        <PaginationSMBox :pagination="pagination" :method="list" />
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <PaginationTextComponent :props="{ page: paginationPage }" />
            <PaginationBox :pagination="pagination" :method="list" />
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";
import statusEnum from "../../../../enums/modules/statusEnum";
import appService from "../../../../services/appService";
import SmIconDeleteComponent from "../../components/buttons/SmIconDeleteComponent";
import SmIconModalEditComponent from "../../components/buttons/SmIconModalEditComponent";
import CampaignProductCreateComponent from "./CampaignProductCreateComponent";
import PaginationTextComponent from "../../components/pagination/PaginationTextComponent";
import PaginationBox from "../../components/pagination/PaginationBox";
import PaginationSMBox from "../../components/pagination/PaginationSMBox";
import TableLimitComponent from "../../components/TableLimitComponent";

export default {
    name: "CampaignProductListComponent",
    components: {
        LoadingComponent,
        CampaignProductCreateComponent,
        SmIconModalEditComponent,
        SmIconDeleteComponent,
        TableLimitComponent,
        PaginationTextComponent,
        PaginationBox,
        PaginationSMBox
    },
    props: {
        campaign: { type: Number },
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
                    [statusEnum.INACTIVE]: this.$t("label.inactive"),
                },
            },
            campaignProps: {
                id: this.campaign,
                form: {
                    product_id: null,
                    special_price: "",
                },
                search: {
                    id: this.campaign,
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: 'id',
                    order_type: 'desc',
                }
            },
        }
    },
    mounted() {
        this.list();
    },
    computed: {
        campaignProducts: function () {
            return this.$store.getters['campaignProduct/lists'];
        },
        pagination: function () {
            return this.$store.getters["campaignProduct/pagination"];
        },
        paginationPage: function () {
            return this.$store.getters["campaignProduct/page"];
        },
    },
    methods: {
        statusClass: function (status) {
            return appService.statusClass(status);
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.campaignProps.search.page = page;
            this.$store.dispatch("campaignProduct/lists", this.campaignProps.search).then(() => {
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
        edit: function (campaignProduct) {
            this.$store.dispatch("campaignProduct/edit", campaignProduct.id).then().catch();
            this.campaignProps.form = {
                product_id: campaignProduct.campaign_product_id,
                // flat_*, not the currency-formatted string — the symbol would
                // fail the numeric rule on save.
                special_price: campaignProduct.flat_special_price,
            };
            appService.modalShow();
        },
        destroy: function (id) {
            appService.destroyConfirmation().then(() => {
                try {
                    this.loading.isActive = true;
                    this.$store.dispatch('campaignProduct/destroy', {
                        campaign: this.campaign,
                        id: id,
                        search: this.campaignProps.search
                    }).then(() => {
                        this.loading.isActive = false;
                        alertService.successFlip(null, this.$t('label.product'));
                    }).catch((err) => {
                        this.loading.isActive = false;
                        alertService.error(err.response?.data?.message);
                    });
                } catch (err) {
                    this.loading.isActive = false;
                    alertService.error(err);
                }
            }).catch(() => {
                this.loading.isActive = false;
            });
        }
    }
}
</script>
