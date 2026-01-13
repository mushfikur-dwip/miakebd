<template>
    <LoadingComponent :props="loading" />
    <form @submit.prevent="save" class="w-full">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t('menu.wallet_management') }}</h3>
                <div class="db-card-filter">
                    <div class="db-field-toggle">
                        <label class="db-field-label" for="cashback_status">{{ $t('label.cashback_reward_program') }}</label>
                        <div class="toggle">
                            <input v-model="form.cashback_status" type="checkbox" id="cashback_status" class="toggle-checkbox" />
                            <label for="cashback_status" class="toggle-label"></label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="db-card-body">
                <div class="form-row">
                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title required">{{ $t('label.process_cashback') }}</label>
                        <select v-model="form.process_cashback" class="db-field-control">
                            <option value="delivered">{{ $t('label.order_delivered') }}</option>
                        </select>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title required">{{ $t('label.cashback_rule') }}</label>
                        <select v-model="form.cashback_rule" class="db-field-control">
                            <option value="cart_wise">{{ $t('label.cart_wise') }}</option>
                        </select>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title required">{{ $t('label.cashback_type') }}</label>
                        <div class="pt-2">
                            <div class="db-field-radio pb-2">
                                <div class="custom-radio">
                                    <input v-model="form.cashback_type" value="percentage" id="percentage" type="radio" class="custom-radio-field" />
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="percentage" class="db-field-label">{{ $t('label.percentage') }}</label>
                            </div>
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input v-model="form.cashback_type" value="fixed" id="fixed" type="radio" class="custom-radio-field" />
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="fixed" class="db-field-label">{{ $t('label.fixed') }}</label>
                            </div>
                        </div>
                        <small class="db-field-alert" v-if="errors.cashback_type">{{ errors.cashback_type[0] }}</small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title required">
                            {{ $t('label.cashback_amount') }}
                            <span v-if="form.cashback_type === 'percentage'">(%)</span>
                        </label>
                        <input v-model="form.cashback_amount" type="number" step="0.01" min="0" class="db-field-control" 
                            :class="errors.cashback_amount ? 'invalid' : ''" />
                        <small class="db-field-alert" v-if="errors.cashback_amount">{{ errors.cashback_amount[0] }}</small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title">{{ $t('label.max_cashback_amount') }}</label>
                        <input v-model="form.max_cashback_amount" type="number" step="0.01" min="0" class="db-field-control" 
                            :class="errors.max_cashback_amount ? 'invalid' : ''" />
                        <small class="db-field-alert" v-if="errors.max_cashback_amount">{{ errors.max_cashback_amount[0] }}</small>
                    </div>

                    <div class="form-col-12">
                        <label class="db-field-title">{{ $t('label.payment_methods_for_cashback') }}</label>
                        <div class="pt-2 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                            <div class="db-field-checkbox" v-for="method in paymentMethods" :key="method.value">
                                <input 
                                    type="checkbox" 
                                    :id="'pm_' + method.value" 
                                    :value="method.value"
                                    v-model="form.payment_methods"
                                    class="db-field-checkbox-input" 
                                />
                                <label :for="'pm_' + method.value" class="db-field-checkbox-label">
                                    {{ method.label }}
                                </label>
                            </div>
                        </div>
                        <small class="db-field-alert" v-if="errors.payment_methods">{{ errors.payment_methods[0] }}</small>
                    </div>

                    <div class="form-col-12">
                        <button type="submit" class="db-btn text-white bg-primary">
                            <i class="lab lab-fill-save"></i>
                            <span>{{ $t('button.save') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</template>

<script>
import axios from 'axios';
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";

export default {
    name: "WalletSettingComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false
            },
            form: {
                cashback_status: false,
                cashback_rule: 'cart_wise',
                cashback_type: 'percentage',
                cashback_amount: 0,
                max_cashback_amount: null,
                payment_methods: [],
                process_cashback: 'delivered'
            },
            errors: {},
            paymentMethods: [
                { value: 'cash_on_delivery', label: 'Cash on Delivery' },
                { value: 'paypal', label: 'PayPal' },
                { value: 'stripe', label: 'Stripe' },
                { value: 'razorpay', label: 'Razorpay' },
                { value: 'paystack', label: 'Paystack' },
                { value: 'sslcommerz', label: 'SSLCommerz' },
                { value: 'bkash', label: 'bKash' },
                { value: 'nagad', label: 'Nagad' },
            ]
        }
    },
    mounted() {
        this.loading.isActive = true;
        axios.get('admin/setting/wallet-setting').then((res) => {
            this.form = {
                cashback_status: res.data.data.cashback_status,
                cashback_rule: res.data.data.cashback_rule,
                cashback_type: res.data.data.cashback_type,
                cashback_amount: res.data.data.cashback_amount,
                max_cashback_amount: res.data.data.max_cashback_amount,
                payment_methods: res.data.data.payment_methods || [],
                process_cashback: res.data.data.process_cashback
            };
        }).catch((err) => {
            alertService.error(err.response.data.message);
        }).finally(() => {
            this.loading.isActive = false;
        });
    },
    methods: {
        save() {
            this.loading.isActive = true;
            this.errors = {};
            
            axios.patch('admin/setting/wallet-setting', this.form).then((res) => {
                alertService.success(res.data.message);
                this.errors = {};
            }).catch((err) => {
                this.errors = err.response.data.errors || {};
                alertService.error(err.response.data.message);
            }).finally(() => {
                this.loading.isActive = false;
            });
        }
    }
}
</script>
