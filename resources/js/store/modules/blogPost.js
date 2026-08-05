import axios from "axios";
import appService from "../../services/appService";

export const blogPost = {
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
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        pagination: function (state) {
            return state.pagination;
        },
        page: function (state) {
            return state.page;
        },
        show: function (state) {
            return state.show;
        },
        temp: function (state) {
            return state.temp;
        },
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "admin/blog";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if (typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit("lists", res.data.data);
                        context.commit("page", res.data.meta);
                        context.commit("pagination", res.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        save: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "/admin/blog";
                if (this.state["blogPost"].temp.isEditing) {
                    url = `/admin/blog/${this.state["blogPost"].temp.temp_id}`;
                }
                // payload.form is a FormData built by the component (same as
                // SliderCreateComponent) because the cover is a File — axios
                // sets the multipart boundary itself when handed one.
                axios.post(url, payload.form).then((res) => {
                    context.dispatch("lists", payload.search).then().catch();
                    context.commit("reset");
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        edit: function (context, payload) {
            context.commit("temp", payload);
        },
        destroy: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.delete(`admin/blog/${payload.id}`).then((res) => {
                    context.dispatch("lists", payload.search).then().catch();
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        show: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.get(`admin/blog/show/${payload}`).then((res) => {
                    context.commit("show", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        reset: function (context) {
            context.commit("reset");
        },
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload;
        },
        pagination: function (state, payload) {
            state.pagination = payload;
        },
        page: function (state, payload) {
            state.page = payload;
        },
        show: function (state, payload) {
            state.show = payload;
        },
        temp: function (state, payload) {
            state.temp.temp_id = payload;
            state.temp.isEditing = true;
        },
        reset: function (state) {
            state.temp.temp_id = null;
            state.temp.isEditing = false;
        },
    },
};
