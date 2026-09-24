import axios from "axios";
import appService from "../../../services/appService";

/**
 * The in-flight settings request, shared between concurrent callers.
 * Module scope rather than store state: it holds a Promise, which Vuex state
 * should never carry — vuex-persistedstate would try to serialise it.
 */
let inFlight = null;

/** The server-rendered settings are good for the first dispatch only. */
let bootUsed = false;

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

            // The page carries the settings (see master.blade.php), so the
            // first visit does not wait for a round trip before the header,
            // logo and prices can be drawn. Used once, for the plain dispatch:
            // an explicit one (language switch, admin save) still refetches.
            if (!payload && !bootUsed) {
                bootUsed = true;
                const boot = typeof window !== "undefined" ? window.__BOOT_SETTING__ : null;

                if (boot && Object.keys(boot).length > 0) {
                    context.commit("lists", boot);

                    // Same shape the callers read (res.data.data).
                    return Promise.resolve({ data: { data: boot } });
                }
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
