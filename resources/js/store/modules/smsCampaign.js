import axios from "axios";

export const smsCampaign = {
    namespaced: true,
    state: {
        lists: [],
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
    },
    actions: {
        lists: function (context) {
            return new Promise((resolve, reject) => {
                axios.get("admin/customer-message").then((res) => {
                    context.commit("lists", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        audience: function () {
            return axios.get("admin/customer-message/audience");
        },
        save: function (context, payload) {
            return axios.post("admin/customer-message", payload);
        },
        test: function (context, payload) {
            return axios.post("admin/customer-message/test", payload);
        },
        // One batch. The page calls this repeatedly; see CustomerMessageComponent.
        batch: function (context, id) {
            return axios.post(`admin/customer-message/${id}/batch`);
        },
        pause: function (context, id) {
            return axios.post(`admin/customer-message/${id}/pause`);
        },
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload;
        },
    },
};
