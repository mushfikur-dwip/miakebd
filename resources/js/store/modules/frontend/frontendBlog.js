import axios from "axios";
import appService from "../../../services/appService";

export const frontendBlog = {
    namespaced: true,
    state: {
        lists: [],
        pagination: [],
        page: {},
        show: {},
        related: [],
        categories: [],
        tags: [],
        sections: [],
        featured: null,
        recent: [],
        popular: [],
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
        related: function (state) {
            return state.related;
        },
        categories: function (state) {
            return state.categories;
        },
        tags: function (state) {
            return state.tags;
        },
        sections: function (state) {
            return state.sections;
        },
        featured: function (state) {
            return state.featured;
        },
        recent: function (state) {
            return state.recent;
        },
        popular: function (state) {
            return state.popular;
        },
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "blog";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    context.commit("lists", res.data.data);
                    context.commit("page", res.data.meta);
                    context.commit("pagination", res.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        // One request for the whole landing page — hero, grid and sidebar.
        // Three parallel calls made the page wait on the slowest before
        // anything painted.
        overview: function (context) {
            return new Promise((resolve, reject) => {
                axios.get("blog/overview").then((res) => {
                    context.commit("featured", res.data.featured);
                    context.commit("recent", res.data.recent);
                    context.commit("popular", res.data.popular);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        categories: function (context) {
            return new Promise((resolve, reject) => {
                axios.get("blog/categories").then((res) => {
                    context.commit("categories", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        // Concerns — acne, sunburn, tan.
        tags: function (context) {
            return new Promise((resolve, reject) => {
                axios.get("blog/tags").then((res) => {
                    context.commit("tags", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        // Category-wise rows for the landing page.
        sections: function (context) {
            return new Promise((resolve, reject) => {
                axios.get("blog/sections").then((res) => {
                    context.commit("sections", res.data.sections);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        show: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.get(`blog/show/${payload}`).then((res) => {
                    context.commit("show", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        related: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.get(`blog/related/${payload}`).then((res) => {
                    context.commit("related", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
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
        related: function (state, payload) {
            state.related = payload;
        },
        categories: function (state, payload) {
            state.categories = payload;
        },
        tags: function (state, payload) {
            state.tags = payload;
        },
        sections: function (state, payload) {
            state.sections = payload;
        },
        featured: function (state, payload) {
            state.featured = payload;
        },
        recent: function (state, payload) {
            state.recent = payload;
        },
        popular: function (state, payload) {
            state.popular = payload;
        },
    },
};
