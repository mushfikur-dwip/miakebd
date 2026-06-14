import axios from 'axios'
import appService from "../../services/appService";

export const storeSalesReport = {
    namespaced: true,
    state: {
        lists: [],
        branchSummary: [],
        overview: {},
        page: {},
        pagination: [],
    },
    getters: {
        lists: state => state.lists,
        branchSummary: state => state.branchSummary,
        overview: state => state.overview,
        pagination: state => state.pagination,
        page: state => state.page,
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/store-sales-report';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if (typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit('lists', res.data.data);
                        context.commit('page', res.data.meta);
                        context.commit('pagination', res.data);
                    }
                    resolve(res);
                }).catch((err) => reject(err));
            });
        },
        overview: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/store-sales-report/overview';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    context.commit('overview', res.data.data);
                    resolve(res);
                }).catch((err) => reject(err));
            });
        },
        branchSummary: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/store-sales-report/branch-summary';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    context.commit('branchSummary', res.data.data);
                    resolve(res);
                }).catch((err) => reject(err));
            });
        },
        export: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/store-sales-report/export';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url, { responseType: 'blob' }).then((res) => resolve(res)).catch((err) => reject(err));
            });
        },
        exportPdf: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/store-sales-report/export-pdf';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url, { responseType: 'blob' }).then((res) => resolve(res)).catch((err) => reject(err));
            });
        },
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload;
        },
        branchSummary: function (state, payload) {
            state.branchSummary = payload;
        },
        overview: function (state, payload) {
            state.overview = payload;
        },
        pagination: function (state, payload) {
            state.pagination = payload;
        },
        page: function (state, payload) {
            if (typeof payload !== "undefined" && payload !== null) {
                state.page = {
                    from: payload.from,
                    to: payload.to,
                    total: payload.total
                }
            }
        },
    },
}
