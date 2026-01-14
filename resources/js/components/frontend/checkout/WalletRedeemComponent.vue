<template>
    <div v-if="isLoggedIn" class="mb-6 rounded-2xl border border-[#6366F1] bg-gradient-to-r from-[#F9FAFB] to-[#EEF2FF] overflow-hidden">
        <div class="flex items-center gap-3 p-4">
            <div class="relative flex-shrink-0">
                <i class="lab-fill-wallet lab-font-size-2xl text-[#6366F1]"></i>
            </div>
            <div class="flex-auto overflow-hidden">
                <h4 class="font-semibold leading-5 mb-1 text-[#6366F1] capitalize">
                    {{ $t('label.available_wallet_balance') }}
                </h4>
                <h5 class="text-sm font-medium text-heading">
                    {{ currencyFormat(walletBalance) }}
                </h5>
            </div>
        </div>

        <div v-if="walletBalance > 0">
            <div v-if="!walletApplied" class="px-4 pb-4 pt-2">
                <div class="flex items-center gap-2">
                    <input 
                        v-model="walletAmount" 
                        type="number" 
                        step="0.01"
                        :max="maxWalletAmount"
                        min="0"
                        :placeholder="$t('label.enter_amount')"
                        class="h-10 flex-auto px-3 rounded-lg border border-[#D9DBE9] text-sm"
                        :class="error ? 'border-danger' : ''"
                    >
                    <button 
                        @click.prevent="applyWallet" 
                        type="button"
                        class="h-10 px-4 rounded-lg font-semibold text-sm capitalize text-white bg-[#6366F1] hover:bg-[#5558E3] transition"
                    >
                        {{ $t('button.apply') }}
                    </button>
                </div>
                <small class="db-field-alert mt-1" v-if="error">{{ error }}</small>
                <p class="text-xs text-text mt-2">
                    {{ $t('message.max_wallet_amount', { amount: currencyFormat(maxWalletAmount) }) }}
                </p>
            </div>

            <div v-else class="px-4 pb-4 pt-2 border-t border-[#E0E7FF]">
                <div class="flex items-center justify-between">
                    <div>
                        <h5 class="text-sm font-medium text-heading mb-1">{{ $t('label.wallet_discount') }}</h5>
                        <p class="text-xs text-success font-semibold">
                            {{ $t('message.you_saved', { amount: currencyFormat(appliedWalletAmount) }) }}
                        </p>
                    </div>
                    <button 
                        @click.prevent="removeWallet" 
                        type="button"
                        class="lab-line-trash lab-font-size-xl text-danger hover:text-danger/80 transition"
                    ></button>
                </div>
            </div>
        </div>
        
        <div v-else class="px-4 pb-4 pt-2 text-center">
            <p class="text-xs text-text">{{ $t('message.no_wallet_balance') }}</p>
        </div>
    </div>
</template>

<script>
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";

export default {
    name: "WalletRedeemComponent",
    data() {
        return {
            walletAmount: '',
            error: '',
            walletApplied: false,
            appliedWalletAmount: 0
        }
    },
    computed: {
        setting: function() {
            return this.$store.getters['frontendSetting/lists'];
        },
        isLoggedIn: function() {
            return this.$store.getters.authStatus;
        },
        walletBalance: function() {
            return this.$store.getters['frontendCart/walletBalance'] || 0;
        },
        subtotal: function() {
            return this.$store.getters['frontendCart/subtotal'];
        },
        total: function() {
            // Get total after discounts and shipping
            return this.$store.getters['frontendCart/total'];
        },
        maxWalletAmount: function() {
            // Maximum wallet amount is the minimum of wallet balance and remaining total
            // This allows partial payment (wallet + other payment method)
            return Math.min(this.walletBalance, this.total);
        }
    },
    methods: {
        currencyFormat(amount) {
            const setting = this.$store.getters['frontendSetting/lists'];
            return appService.currencyFormat(
                amount,
                setting.site_digit_after_decimal_point,
                setting.site_default_currency_symbol,
                setting.site_currency_position
            );
        },
        applyWallet() {
            console.log('🔵 [Wallet] Apply button clicked');
            console.log('🔵 [Wallet] Input amount:', this.walletAmount);
            console.log('🔵 [Wallet] Current wallet balance:', this.walletBalance);
            console.log('🔵 [Wallet] Current order total:', this.total);
            
            this.error = '';
            
            const amount = parseFloat(this.walletAmount);
            console.log('🔵 [Wallet] Parsed amount:', amount);
            
            if (!amount || amount <= 0) {
                console.error('❌ [Wallet] Invalid amount entered');
                this.error = this.$t('message.please_enter_valid_amount');
                return;
            }
            
            if (amount > this.walletBalance) {
                console.error('❌ [Wallet] Insufficient balance. Requested:', amount, 'Available:', this.walletBalance);
                this.error = this.$t('message.insufficient_wallet_balance');
                return;
            }
            
            if (amount > this.total) {
                console.error('❌ [Wallet] Amount exceeds order total. Requested:', amount, 'Order total:', this.total);
                this.error = this.$t('message.wallet_amount_exceeds_order_total');
                return;
            }
            
            console.log('✅ [Wallet] Validation passed. Applying wallet discount...');
            this.$store.dispatch('frontendCart/applyWalletDiscount', amount).then(() => {
                this.walletApplied = true;
                this.appliedWalletAmount = amount;
                console.log('✅ [Wallet] Wallet discount applied successfully:', amount);
                console.log('✅ [Wallet] Wallet applied status:', this.walletApplied);
                alertService.success(this.$t('message.wallet_applied_successfully'));
            }).catch((error) => {
                console.error('❌ [Wallet] Failed to apply wallet discount:', error);
                this.error = error.response?.data?.message || this.$t('message.something_went_wrong');
            });
        },
        removeWallet() {
            console.log('🔴 [Wallet] Remove wallet button clicked');
            console.log('🔴 [Wallet] Current applied amount:', this.appliedWalletAmount);
            
            this.$store.dispatch('frontendCart/removeWalletDiscount').then(() => {
                this.walletApplied = false;
                this.appliedWalletAmount = 0;
                this.walletAmount = '';
                this.error = '';
                console.log('✅ [Wallet] Wallet discount removed successfully');
                alertService.success(this.$t('message.wallet_removed_successfully'));
            });
        }
    },
    mounted() {
        console.log('🟢 [Wallet Component] Mounted');
        console.log('🟢 [Wallet Component] User logged in:', this.isLoggedIn);
        
        // Fetch user's wallet balance only if logged in
        if (this.isLoggedIn) {
            console.log('🟢 [Wallet Component] Fetching wallet balance...');
            this.$store.dispatch('frontendCart/fetchWalletBalance').then(() => {
                console.log('🟢 [Wallet Component] Balance fetched. Current state:');
                console.log('  - walletBalance:', this.walletBalance);
                console.log('  - walletApplied:', this.walletApplied);
                console.log('  - appliedWalletAmount:', this.appliedWalletAmount);
                console.log('  - total:', this.total);
                console.log('  - maxWalletAmount:', this.maxWalletAmount);
            }).catch((error) => {
                console.error('❌ [Wallet Component] Failed to fetch wallet balance:', error);
            });
        } else {
            console.log('⚠️ [Wallet Component] User not logged in, skipping wallet balance fetch');
        }
    },
    watch: {
        walletBalance(newVal, oldVal) {
            console.log('🔄 [Wallet Component] walletBalance changed from', oldVal, 'to', newVal);
        },
        walletApplied(newVal, oldVal) {
            console.log('🔄 [Wallet Component] walletApplied changed from', oldVal, 'to', newVal);
        }
    }
}
</script>
