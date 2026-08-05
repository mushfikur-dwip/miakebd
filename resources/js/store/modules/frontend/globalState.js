export const globalState = {
    namespaced: true,
    state: {
        lists: {},
    },
    getters: {
        lists: function (state) {
            return state.lists;
        }
    },
    actions: {
        init: function (context, payload) {
            return new Promise((resolve, reject) => {
                if(typeof payload === 'object') {
                    context.commit("init", payload);
                    resolve(payload);
                } else {
                    reject("object not found");
                }
            });
        },
        set: function (context, payload) {
            return new Promise((resolve, reject) => {
                if(typeof payload === 'object') {
                    context.commit("lists", payload);
                    resolve(payload);
                } else {
                    reject("object not found");
                }
            });
        }
    },
    mutations: {
        init: function(state, payload) {
            if (typeof payload === 'object') {
                for (const key in payload) {
                    if (payload.hasOwnProperty(key)) {
                        // Nullish, not truthiness. `!state.lists[key]` treated a
                        // legitimate 0 or "" as unset and overwrote it — which
                        // matters here because language_id and display_mode can
                        // both be 0, and two callers race to init this.
                        if (state.lists[key] === undefined || state.lists[key] === null) {
                            state.lists[key] = payload[key];
                        }
                    }
                }
            }
        },
        lists: function (state, payload) {
            if (typeof payload === 'object') {
                for (const key in payload) {
                    if (payload.hasOwnProperty(key)) {
                        state.lists[key] = payload[key];
                    }
                }
            }
        }
    },
};
