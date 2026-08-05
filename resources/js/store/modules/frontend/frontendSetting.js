import axios from "axios";
import appService from "../../../services/appService";

/**
 * The in-flight settings request, shared between concurrent callers.
 * Module scope rather than store state: it holds a Promise, which Vuex state
 * should never carry — vuex-persistedstate would try to serialise it.
 */
let inFlight = null;

export const frontendSetting = {
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
            // DefaultComponent.beforeMount() and FrontendNavBarComponent
            // .mounted() both dispatch this on every storefront page load, so
            // the endpoint was fetched twice per page. Both callers need the
            // response, so neither can simply be deleted — instead the first
            // call's promise is cached and handed to the second.
            //
            // Cleared on settle, so a later dispatch (language switch, admin
            // saving settings) still refetches rather than serving a stale
            // snapshot forever.
            if (inFlight && !payload) {
                return inFlight;
            }

            let url = "frontend/setting";
            if (payload) {
                url = url + appService.requestHandler(payload);
            }

            const request = axios.get(url).then((res) => {
                context.commit("lists", res.data.data);
                if (!payload) {
                    inFlight = null;
                }
                return res;
            }).catch((err) => {
                if (!payload) {
                    inFlight = null;
                }
                throw err;
            });

            if (!payload) {
                inFlight = request;
            }

            return request;
        }
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload;
        }
    },
};
