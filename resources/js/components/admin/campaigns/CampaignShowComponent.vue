<template>
    <LoadingComponent :props="loading" />

    <div class="col-12">
        <div id="campaign" class="db-tab-div active">
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 mb-5">
                <button @click.prevent="multiTargets($event, 'tab-action', 'tab-content', 'campaignInformation')"
                    class="tab-action active w-full flex items-center gap-3 h-10 px-4 rounded-lg bg-white hover:text-primary hover:bg-primary/5">
                    <i class="lab lab-fill-info lab-font-size-16"></i>
                    {{ $t("label.information") }}
                </button>

                <button type="button"
                    @click.prevent="multiTargets($event, 'tab-action', 'tab-content', 'campaignProduct')"
                    class="tab-action w-full flex items-center gap-3 h-10 px-4 rounded-lg transition bg-white hover:text-primary hover:bg-primary/5">
                    <i class="lab lab-fill-products lab-font-size-16"></i>
                    {{ $t('label.products') }}
                </button>
            </div>

            <div class="db-card tab-content active" id="campaignInformation">
                <div class="db-card-header">
                    <h3 class="db-card-title">{{ $t('label.information') }}</h3>
                </div>

                <div class="db-card-body">
                    <div class="row py-2">
                        <div class="col-12 sm:col-6 !py-1.5">
                            <div class="db-list-item p-0">
                                <span class="db-list-item-title w-full sm:w-1/2">{{ $t('label.name') }}</span>
                                <span class="db-list-item-text w-full sm:w-1/2">{{ campaign.name }}</span>
                            </div>
                        </div>
                        <div class="col-12 sm:col-6 !py-1.5">
                            <div class="db-list-item p-0">
                                <span class="db-list-item-title w-full sm:w-1/2">{{ $t('label.slug') }}</span>
                                <span class="db-list-item-text w-full sm:w-1/2">{{ campaign.slug }}</span>
                            </div>
                        </div>

                        <div class="col-12 sm:col-6 !py-1.5">
                            <div class="db-list-item p-0">
                                <span class="db-list-item-title w-full sm:w-1/2">{{ $t('label.type') }}</span>
                                <span class="db-list-item-text w-full sm:w-1/2">
                                    {{ enums.campaignTypeEnumArray[campaign.type] }}
                                </span>
                            </div>
                        </div>

                        <div class="col-12 sm:col-6 !py-1.5">
                            <div class="db-list-item p-0">
                                <span class="db-list-item-title w-full sm:w-1/2">{{ $t('label.status') }}</span>
                                <span class="db-list-item-text">
                                    <span :class="statusClass(campaign.status)">
                                        {{ enums.statusEnumArray[campaign.status] }}
                                    </span>
                                </span>
                            </div>
                        </div>

                        <div class="col-12 sm:col-6 !py-1.5">
                            <div class="db-list-item p-0">
                                <span class="db-list-item-title w-full sm:w-1/2">{{ $t('label.start_date') }}</span>
                                <span class="db-list-item-text w-full sm:w-1/2">{{ campaign.starts_at_label }}</span>
                            </div>
                        </div>

                        <div class="col-12 sm:col-6 !py-1.5">
                            <div class="db-list-item p-0">
                                <span class="db-list-item-title w-full sm:w-1/2">{{ $t('label.end_date') }}</span>
                                <span class="db-list-item-text w-full sm:w-1/2">{{ campaign.ends_at_label }}</span>
                            </div>
                        </div>

                        <div class="col-12 !py-1.5">
                            <!-- The one thing the admin actually needs to know:
                                 whether this campaign is pricing anything right
                                 now. ACTIVE + expired window prices nothing. -->
                            <div class="db-list-item p-0">
                                <span class="db-list-item-title w-full sm:w-1/4">{{ $t('label.campaign_state')
                                    }}</span>
                                <span class="db-list-item-text">
                                    <span v-if="campaign.is_running"
                                        class="px-2 py-1 rounded text-xs font-semibold bg-green-100 text-green-700">
                                        {{ $t('label.running') }}
                                    </span>
                                    <span v-else
                                        class="px-2 py-1 rounded text-xs font-semibold bg-slate-100 text-slate-600">
                                        {{ $t('label.not_running') }}
                                    </span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="db-card tab-content" id="campaignProduct">
                <CampaignProductListComponent :campaign="parseInt($route.params.id)" />
            </div>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import appService from "../../../services/appService";
import targetService from "../../../services/targetService";
import statusEnum from "../../../enums/modules/statusEnum";
import campaignTypeEnum from "../../../enums/modules/campaignTypeEnum";
import CampaignProductListComponent from "./product/CampaignProductListComponent";

export default {
    name: "CampaignShowComponent",
    components: {
        LoadingComponent,
        CampaignProductListComponent
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            enums: {
                statusEnum: statusEnum,
                campaignTypeEnum: campaignTypeEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive")
                },
                campaignTypeEnumArray: {
                    [campaignTypeEnum.FLASH]: this.$t("label.flash_sale"),
                    [campaignTypeEnum.CLEARANCE]: this.$t("label.clearance_sale"),
                },
            },
        }
    },
    computed: {
        campaign: function () {
            return this.$store.getters['campaign/show'];
        }
    },
    mounted() {
        this.loading.isActive = true;
        this.$store.dispatch('campaign/show', this.$route.params.id).then(() => {
            this.loading.isActive = false;
        }).catch(() => {
            this.loading.isActive = false;
        });
    },
    methods: {
        multiTargets: function (event, commonBtnClass, commonDivClass, targetID) {
            targetService.multiTargets(event, commonBtnClass, commonDivClass, targetID);
        },
        statusClass: function (status) {
            return appService.statusClass(status);
        },
    }
}
</script>
