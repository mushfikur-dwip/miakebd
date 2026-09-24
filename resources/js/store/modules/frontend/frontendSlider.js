import axios from "axios";
import appService from "../../../services/appService";

export const frontendSlider = {
    namespaced: true,
    state: {
        lists: [],
    },
    getters: {
        lists: function (state) {
            return state.lists;
        }
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "frontend/slider";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    // The hero carousel renders straight out of this state, so
                    // a caller asking for one position only (the banner block)
                    // passes vuex: false and keeps its rows locally - without
                    // it the second fetch would blank the carousel.
                    if (typeof payload === "undefined" || payload === null
                        || typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit("lists", res.data.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        }
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload;
        }
    },
};
