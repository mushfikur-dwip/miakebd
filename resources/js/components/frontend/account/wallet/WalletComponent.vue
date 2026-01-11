<template>
    <LoadingComponent :props="loading" />
    <h2 class="capitalize text-2xl font-bold mb-2 text-primary">{{ $t('button.wallet') }}</h2>
    <p class="mb-7 font-medium">{{ $t('label.current_available_balance') }}</p>
    
    <!-- Balance Card -->
    <div class="p-6 rounded-2xl shadow-card bg-white mb-8">
        <div class="flex items-center gap-4">
            <i class="lab-fill-wallet bg-shopperz-blue shadow-blue w-16 h-16 leading-[4rem] rounded-lg text-2xl text-center text-white"></i>
            <div>
                <p class="text-sm text-text mb-1">{{ $t('label.wallet_balance') }}</p>
                <h3 class="text-3xl font-bold text-shopperz-blue">{{ currencyBalance }}</h3>
            </div>
        </div>
    </div>

    <!-- Transaction History -->
    <div class="flex items-center justify-between mb-5">
        <h4 class="text-xl font-bold capitalize">{{ $t('label.transaction_history') }}</h4>
    </div>
    
    <div class="rounded-2xl shadow-card bg-white mobile:mb-20">
        <div class="max-md:overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="font-semibold border-b-2 border-gray-200">
                    <tr>
                        <th class="p-4">{{ $t('label.transaction_no') }}</th>
                        <th class="p-4">{{ $t('label.date') }}</th>
                        <th class="p-4">{{ $t('label.type') }}</th>
                        <th class="p-4">{{ $t('label.amount') }}</th>
                        <th class="p-4">{{ $t('label.balance') }}</th>
                        <th class="p-4">{{ $t('label.note') }}</th>
                    </tr>
                </thead>
                <tbody class="font-medium" v-if="transactions.length > 0">
                    <tr v-for="transaction in transactions" :key="transaction.id">
                        <td class="p-4 border-t border-gray-100">
                            <span class="font-semibold">{{ transaction.transaction_no }}</span>
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            <p class="text-sm">{{ transaction.date }}</p>
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            <span class="px-3 py-1 rounded-full text-xs font-semibold capitalize"
                                :class="getTypeClass(transaction.type)">
                                {{ getTypeLabel(transaction.type) }}
                            </span>
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            <span class="font-semibold" :class="getAmountClass(transaction.sign)">
                                {{ transaction.sign }}{{ transaction.amount }}
                            </span>
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            {{ transaction.balance_after }}
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            <span class="text-sm text-text">{{ transaction.note || '-' }}</span>
                        </td>
                    </tr>
                </tbody>
                <tbody v-else>
                    <tr>
                        <td class="text-center p-4" colspan="6">
                            <div class="p-4">
                                <div class="max-w-[300px] mx-auto mt-2">
                                    <img class="w-full h-full" :src="setting.not_found" alt="Not Found">
                                </div>
                                <span class="block mt-3 text-lg">{{ $t('message.no_data_found') }}</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="pagination-section" v-if="pagination && pagination.total > pagination.per_page">
            <PaginationComponent :Props="pagination" :Skeleton="loading" @paginate="handlePaginate" />
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../../components/LoadingComponent.vue";
import PaginationComponent from "../../components/PaginationComponent.vue";

export default {
    name: "WalletComponent",
    components: {
        LoadingComponent,
        PaginationComponent
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
        };
    },
    computed: {
        balance() {
            return this.$store.getters['frontendWallet/balance'];
        },
        currencyBalance() {
            return this.$store.getters['frontendWallet/currencyBalance'];
        },
        transactions() {
            return this.$store.getters['frontendWallet/transactions'];
        },
        pagination() {
            return this.$store.getters['frontendWallet/pagination'];
        },
        setting() {
            return this.$store.getters['frontendSetting/lists'];
        }
    },
    mounted() {
        this.loadWalletData();
    },
    methods: {
        loadWalletData() {
            this.loading.isActive = true;
            Promise.all([
                this.$store.dispatch('frontendWallet/getBalance'),
                this.$store.dispatch('frontendWallet/getTransactions', { page: 1 })
            ]).finally(() => {
                this.loading.isActive = false;
            });
        },
        handlePaginate(page) {
            this.loading.isActive = true;
            this.$store.dispatch('frontendWallet/getTransactions', { page: page })
                .finally(() => {
                    this.loading.isActive = false;
                });
        },
        getTypeClass(type) {
            const classes = {
                'payment': 'bg-blue-100 text-blue-700',
                'cash_back': 'bg-green-100 text-green-700',
                'manual_credit': 'bg-emerald-100 text-emerald-700',
                'manual_debit': 'bg-red-100 text-red-700'
            };
            return classes[type] || 'bg-gray-100 text-gray-700';
        },
        getAmountClass(sign) {
            return sign === '+' ? 'text-green-600' : 'text-red-600';
        },
        getTypeLabel(type) {
            return type ? type.replace('_', ' ').toUpperCase() : '';
        }
    },
};
</script>
