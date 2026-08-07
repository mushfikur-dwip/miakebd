import axios from "axios";
import appService from "../../../services/appService";

export const frontendCampaign = {
    namespaced: true,
    state: {
        // Campaigns inside their active window. Populated once per full page
        // load and read by the header, footer and mobile sidebar.
        lists: [],
        show: {},
        products: [],
        productPage: {},
        productPagination: []
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        show: function (state) {
            return state.show;
        },
        products: function (state) {
            return state.products;
        },
        productPage: function (state) {
            return state.productPage;
        },
        productPagination: function (state) {
            return state.productPagination;
        }
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "frontend/campaign";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    context.commit("lists", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        show: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.get(`frontend/campaign/show/${payload}`).then((res) => {
                    context.commit('show', res.data.data);
                    resolve(res);
                }).catch((err) => {
                    // Clear the previous campaign so an expired slug cannot
                    // leave the last one's name and countdown on screen.
                    context.commit('show', {});
                    reject(err);
                });
            });
        },
        products: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = `frontend/campaign/products/${payload.slug}`;
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if (typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit("products", res.data.data);
                        context.commit("productPage", res.data.meta);
                        context.commit("productPagination", res.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    context.commit("products", []);
                    reject(err);
                });
            });
        },
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload;
        },
        show: function (state, payload) {
            state.show = payload;
        },
        products: function (state, payload) {
            state.products = payload;
        },
        productPagination: function (state, payload) {
            state.productPagination = payload;
        },
        productPage: function (state, payload) {
            if (typeof payload !== "undefined" && payload !== null) {
                state.productPage = {
                    from: payload.from,
                    to: payload.to,
                    total: payload.total,
                };
            }
        },
    },
};
