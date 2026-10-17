import axios from 'axios'
import appService from "../../services/appService";


export const stock = {
    namespaced: true,
    state: {
        lists: [],
        page: {},
        pagination: [],
        show: {},
        temp: {
            temp_id: null,
            isEditing: false,
        },
        // The "stock cannot be zero" switch. Starts on, the safe position, so
        // the page never briefly offers the looser rule while it loads.
        setup: {
            stock_cannot_be_zero: true,
        },
},
    getters: {
        lists: function (state) {
            return state.lists;
        },

        pagination: function (state) {
            return state.pagination
        },
        page: function(state) {
            return state.page;
        },
        show: function (state) {
            return state.show;
        },
        setup: function (state) {
            return state.setup;
        },
        temp: function (state) {
            return state.temp;
        }
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/stock';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if(typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit('lists', res.data.data);
                        context.commit('page', res.data.meta);
                        context.commit('pagination', res.data);
                    }

                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        // Sets one product's stock branch by branch. The caller refreshes the
        // list itself - the search it is holding is the only thing that knows
        // which page and filters to come back to.
        updateItem: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post('admin/stock/update-item', payload).then((res) => {
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        // The "stock cannot be zero" switch. Reading it is part of loading the
        // page; changing it needs the branch PIN, so the caller handles the
        // refusal (wrong PIN 422, locked out 429) itself.
        setup: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.get('admin/stock/setup').then((res) => {
                    context.commit('setup', res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        updateSetup: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.put('admin/stock/setup', payload).then((res) => {
                    context.commit('setup', res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        export: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/stock/export';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url, { responseType: 'blob' }).then((res) => {
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload
        },
        pagination: function (state, payload) {
            state.pagination = payload;
        },
        page: function (state, payload) {
            if(typeof payload !== "undefined" && payload !== null) {
                state.page = {
                    from: payload.from,
                    to: payload.to,
                    total: payload.total
                }
            }
        },
        show: function (state, payload) {
            state.show = payload;
        },
        setup: function (state, payload) {
            if (typeof payload !== "undefined" && payload !== null) {
                state.setup = payload;
            }
        },
        temp: function (state, payload) {
            state.temp.temp_id = payload;
            state.temp.isEditing = true;
        },
        reset: function(state) {
            state.temp.temp_id = null;
            state.temp.isEditing = false;
        }
    },
}
