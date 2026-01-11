import axios from "axios";

export const customerWallet = {
    namespaced: true,
    state: {
        balance: 0,
        currencyBalance: '',
        transactions: [],
        pagination: {},
        loading: false
    },
    getters: {
        balance: function (state) {
            return state.balance;
        },
        currencyBalance: function (state) {
            return state.currencyBalance;
        },
        transactions: function (state) {
            return state.transactions;
        },
        pagination: function (state) {
            return state.pagination;
        },
        loading: function (state) {
            return state.loading;
        }
    },
    mutations: {
        balance: function (state, payload) {
            state.balance = payload;
        },
        currencyBalance: function (state, payload) {
            state.currencyBalance = payload;
        },
        transactions: function (state, payload) {
            state.transactions = payload;
        },
        pagination: function (state, payload) {
            state.pagination = payload;
        },
        loading: function (state, payload) {
            state.loading = payload;
        },
        reset: function (state) {
            state.balance = 0;
            state.currencyBalance = '';
            state.transactions = [];
            state.pagination = {};
        }
    },
    actions: {
        getBalance: function (context, customerId) {
            return new Promise((resolve, reject) => {
                context.commit('loading', true);
                axios.get(`admin/customer/wallet/${customerId}`).then((res) => {
                    context.commit('balance', res.data.data.balance);
                    context.commit('currencyBalance', res.data.data.currency_balance);
                    context.commit('loading', false);
                    resolve(res);
                }).catch((err) => {
                    context.commit('loading', false);
                    reject(err);
                });
            });
        },
        getTransactions: function (context, payload) {
            return new Promise((resolve, reject) => {
                context.commit('loading', true);
                axios.get(`admin/customer/wallet-transactions/${payload.customerId}`, {
                    params: payload.search
                }).then((res) => {
                    if (typeof payload.search.paginate !== "undefined" && payload.search.paginate === 1) {
                        context.commit('transactions', res.data.data);
                        context.commit('pagination', res.data.meta);
                    } else {
                        context.commit('transactions', res.data.data);
                        context.commit('pagination', []);
                    }
                    context.commit('loading', false);
                    resolve(res);
                }).catch((err) => {
                    context.commit('loading', false);
                    reject(err);
                });
            });
        },
        addCredit: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post(`admin/customer/wallet-credit/${payload.customerId}`, payload.form).then((res) => {
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        deductCredit: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post(`admin/customer/wallet-debit/${payload.customerId}`, payload.form).then((res) => {
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        reset: function (context) {
            context.commit('reset');
        }
    }
};
