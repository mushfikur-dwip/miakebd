import axios from "axios";

export default {
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
    actions: {
        getBalance: function (context) {
            context.commit('loading', true);
            return new Promise((resolve, reject) => {
                axios.get('/frontend/wallet/balance').then((res) => {
                    context.commit('balance', res.data.data.balance);
                    context.commit('currencyBalance', res.data.data.currency_balance);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                }).finally(() => {
                    context.commit('loading', false);
                });
            });
        },
        getTransactions: function (context, payload) {
            context.commit('loading', true);
            return new Promise((resolve, reject) => {
                let url = '/frontend/wallet/transactions';
                if (payload && payload.page) {
                    url += `?page=${payload.page}`;
                }
                axios.get(url).then((res) => {
                    context.commit('transactions', res.data.data);
                    context.commit('pagination', res.data.meta);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                }).finally(() => {
                    context.commit('loading', false);
                });
            });
        },
        reset: function (context) {
            context.commit('reset');
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
            state.loading = false;
        }
    }
};
