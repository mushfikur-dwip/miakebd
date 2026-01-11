<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="close">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold capitalize">{{ $t('button.deduct_credit') }}</h3>
                    <button type="button" @click="close" class="text-gray-400 hover:text-gray-600">
                        <i class="lab-line-close text-2xl"></i>
                    </button>
                </div>
            </div>

            <form @submit.prevent="submit" class="p-6">
                <div class="mb-4">
                    <label for="amount" class="text-sm font-medium capitalize mb-2 field-title required">
                        {{ $t('label.amount') }}
                    </label>
                    <input v-model="form.amount" :class="errors.amount ? 'invalid' : ''" id="amount" type="number"
                        step="0.01" min="0.01" 
                        class="w-full h-12 px-4 rounded-lg text-base border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20" 
                        :placeholder="$t('label.enter_amount')" />
                    <small class="db-field-alert" v-if="errors.amount">{{ errors.amount[0] }}</small>
                </div>

                <div class="mb-6">
                    <label for="note" class="text-sm font-medium capitalize mb-2 field-title required">
                        {{ $t('label.note') }}
                    </label>
                    <textarea v-model="form.note" :class="errors.note ? 'invalid' : ''" id="note" rows="4"
                        class="w-full px-4 py-3 rounded-lg text-base border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20" 
                        :placeholder="$t('label.enter_reason')"></textarea>
                    <small class="db-field-alert" v-if="errors.note">{{ errors.note[0] }}</small>
                </div>

                <div class="flex gap-3">
                    <button type="button" @click="close"
                        class="flex-1 py-2.5 px-4 rounded-lg border border-gray-300 text-gray-700 font-semibold transition-all duration-300 hover:bg-gray-50">
                        {{ $t('button.cancel') }}
                    </button>
                    <button type="submit" :disabled="loading"
                        class="flex-1 py-2.5 px-4 rounded-lg bg-red-500 text-white font-semibold transition-all duration-300 hover:bg-red-600 disabled:opacity-50">
                        <span v-if="!loading">{{ $t('button.deduct_credit') }}</span>
                        <span v-else>{{ $t('label.loading') }}...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script>
import alertService from "../../../services/alertService";

export default {
    name: "WalletDeductCreditModal",
    props: {
        customerId: {
            type: Number,
            required: true
        }
    },
    data() {
        return {
            loading: false,
            form: {
                amount: '',
                note: ''
            },
            errors: {}
        }
    },
    methods: {
        close() {
            this.$emit('close');
        },
        submit() {
            this.loading = true;
            this.errors = {};
            
            this.$store.dispatch('customerWallet/deductCredit', {
                customerId: this.customerId,
                form: this.form
            }).then((res) => {
                this.loading = false;
                alertService.success(res.data.message);
                this.$emit('success');
                this.form = { amount: '', note: '' };
            }).catch((err) => {
                this.loading = false;
                if (err.response && err.response.data.errors) {
                    this.errors = err.response.data.errors;
                } else {
                    alertService.error(err.response.data.message);
                }
            });
        }
    }
}
</script>
