<template>
    <LoadingComponent :props="loading" />
    
    <div class="mb-6">
        <div class="p-6 rounded-2xl shadow-card bg-white">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-lg font-bold capitalize mb-1">{{ $t('label.wallet_balance') }}</h3>
                    <p class="text-sm text-text">{{ $t('label.current_available_balance') }}</p>
                </div>
                <div class="text-right">
                    <h2 class="text-3xl font-bold text-primary">{{ currencyBalance }}</h2>
                </div>
            </div>
            
            <div class="flex gap-3">
                <button type="button" @click="showAddCreditModal" 
                    class="flex-1 py-2.5 px-4 rounded-lg bg-primary text-white font-semibold transition-all duration-300 hover:bg-primary/90">
                    <i class="lab-line-plus-circle mr-2"></i>
                    {{ $t('button.add_credit') }}
                </button>
                <button type="button" @click="showDeductCreditModal"
                    class="flex-1 py-2.5 px-4 rounded-lg bg-red-500 text-white font-semibold transition-all duration-300 hover:bg-red-600">
                    <i class="lab-line-minus-circle mr-2"></i>
                    {{ $t('button.deduct_credit') }}
                </button>
            </div>
        </div>
    </div>

    <div class="rounded-2xl shadow-card bg-white">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-bold capitalize">{{ $t('label.transaction_history') }}</h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="font-semibold border-b-2 border-gray-200">
                    <tr>
                        <th class="p-4">{{ $t('label.transaction_no') }}</th>
                        <th class="p-4">{{ $t('label.date') }}</th>
                        <th class="p-4">{{ $t('label.type') }}</th>
                        <th class="p-4">{{ $t('label.amount') }}</th>
                        <th class="p-4">{{ $t('label.balance') }}</th>
                        <th class="p-4">{{ $t('label.note') }}</th>
                        <th class="p-4">{{ $t('label.admin') }}</th>
                    </tr>
                </thead>
                <tbody class="font-medium" v-if="transactions.length > 0">
                    <tr v-for="transaction in transactions" :key="transaction.id">
                        <td class="p-4 border-t border-gray-100">
                            <span class="font-semibold">{{ transaction.transaction_no }}</span>
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            {{ transaction.date }}
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            <span class="px-2 py-1 text-xs rounded-full capitalize"
                                :class="getTypeClass(transaction.type)">
                                {{ transaction.type.replace('_', ' ') }}
                            </span>
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            <span class="font-semibold" 
                                :class="transaction.sign === '+' ? 'text-green-600' : 'text-red-600'">
                                {{ transaction.sign }}{{ transaction.amount }}
                            </span>
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            {{ transaction.balance_after }}
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            <span class="text-xs text-text">{{ transaction.note || '-' }}</span>
                        </td>
                        <td class="p-4 border-t border-gray-100">
                            <span class="text-xs">{{ transaction.admin_name || '-' }}</span>
                        </td>
                    </tr>
                </tbody>
                <tbody v-else>
                    <tr>
                        <td class="p-8 text-center text-text" colspan="7">
                            {{ $t('message.no_data_found') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-gray-200" v-if="pagination && pagination.total > pagination.per_page">
            <PaginationSMBox :pagination="pagination" :method="getTransactions" />
            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                <PaginationTextComponent :props="{ page: search.page }" />
                <PaginationBox :pagination="pagination" :method="getTransactions" />
            </div>
        </div>
    </div>

    <WalletAddCreditModal v-if="addCreditModalActive" :customerId="customerId" @close="closeAddCreditModal" @success="handleSuccess" />
    <WalletDeductCreditModal v-if="deductCreditModalActive" :customerId="customerId" @close="closeDeductCreditModal" @success="handleSuccess" />
</template>

<script>
import LoadingComponent from "../components/LoadingComponent.vue";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent.vue";
import PaginationBox from "../components/pagination/PaginationBox.vue";
import PaginationSMBox from "../components/pagination/PaginationSMBox.vue";
import WalletAddCreditModal from "./WalletAddCreditModal.vue";
import WalletDeductCreditModal from "./WalletDeductCreditModal.vue";

export default {
    name: "CustomerWalletComponent",
    components: { LoadingComponent, PaginationTextComponent, PaginationBox, PaginationSMBox, WalletAddCreditModal, WalletDeductCreditModal },
    props: {
        customerId: {
            type: Number,
            required: true
        }
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            search: {
                paginate: 1,
                page: 1,
                per_page: 10,
                order_column: 'id',
                order_by: 'desc'
            },
            addCreditModalActive: false,
            deductCreditModalActive: false
        }
    },
    computed: {
        currencyBalance() {
            return this.$store.getters['customerWallet/currencyBalance'];
        },
        transactions() {
            return this.$store.getters['customerWallet/transactions'];
        },
        pagination() {
            return this.$store.getters['customerWallet/pagination'];
        }
    },
    mounted() {
        this.loadWalletData();
    },
    methods: {
        loadWalletData() {
            this.loading.isActive = true;
            this.$store.dispatch('customerWallet/getBalance', this.customerId).then(() => {
                this.getTransactions();
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
        getTransactions(page = 1) {
            this.loading.isActive = true;
            this.search.page = page;
            this.$store.dispatch('customerWallet/getTransactions', {
                customerId: this.customerId,
                search: this.search
            }).then(() => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
        showAddCreditModal() {
            this.addCreditModalActive = true;
        },
        closeAddCreditModal() {
            this.addCreditModalActive = false;
        },
        showDeductCreditModal() {
            this.deductCreditModalActive = true;
        },
        closeDeductCreditModal() {
            this.deductCreditModalActive = false;
        },
        handleSuccess() {
            this.addCreditModalActive = false;
            this.deductCreditModalActive = false;
            this.loadWalletData();
        },
        getTypeClass(type) {
            const classes = {
                'payment': 'bg-blue-100 text-blue-600',
                'cash_back': 'bg-green-100 text-green-600',
                'manual_credit': 'bg-emerald-100 text-emerald-600',
                'manual_debit': 'bg-red-100 text-red-600'
            };
            return classes[type] || 'bg-gray-100 text-gray-600';
        }
    }
}
</script>
