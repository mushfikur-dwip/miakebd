<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t('label.branch_sales_summary') }}</h3>
            </div>
            <div class="db-card-body">
                <div class="row" v-if="branchSalesSummary.length > 0">
                    <div class="col-12 md:col-6" v-for="branch in branchSalesSummary" :key="branch.id">
                        <div class="border border-[#EFF0F6] rounded-lg p-4">
                            <h4 class="font-semibold text-heading mb-3">{{ branch.branch_name }}</h4>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <p class="text-xs text-paragraph mb-1">{{ $t('label.today_sales') }}</p>
                                    <p class="text-base font-bold text-heading">{{ branch.today_sales }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-paragraph mb-1">{{ $t('label.monthly_sales') }}</p>
                                    <p class="text-base font-bold text-heading">{{ branch.monthly_sales }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="text-sm text-paragraph">
                    {{ $t('message.no_data_found') }}
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: "BranchSalesSummaryComponent",
    mounted() {
        this.$store.dispatch("dashboard/branchSalesSummary").then().catch();
    },
    computed: {
        branchSalesSummary: function () {
            return this.$store.getters["dashboard/branchSalesSummary"];
        }
    }
}
</script>
