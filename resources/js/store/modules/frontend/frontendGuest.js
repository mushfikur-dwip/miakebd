import axios from "axios";

/**
 * Guest checkout.
 *
 * start()   creates an is_guest user and returns a Sanctum token in the same
 *           shape as the login response, so the existing `authLogin` mutation
 *           handles it unchanged and the rest of the order flow is untouched.
 * sendOtp() SMS code, only for numbers that already have a guest order.
 * claim()   verifies the code, upgrades the guest to a real customer and merges
 *           every past guest order on that number.
 *
 * Nothing the customer types is stored or replayed — each form is filled in by
 * hand, including the phone number on the claim step.
 */
export const frontendGuest = {
    namespaced: true,
    state: {
        mergedOrders: 0,
    },
    getters: {
        mergedOrders: function (state) {
            return state.mergedOrders;
        },
    },
    actions: {
        start: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios
                    .post("auth/guest/start", payload)
                    .then((res) => {
                        context.commit("authLogin", res.data, { root: true });
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        sendOtp: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios
                    .post("auth/guest/send-otp", payload)
                    .then((res) => {
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        claim: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios
                    .post("auth/guest/claim", payload)
                    .then((res) => {
                        context.commit("mergedOrders", res.data.merged_orders);
                        context.commit("authLogin", res.data, { root: true });
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
    },
    mutations: {
        mergedOrders: function (state, payload) {
            state.mergedOrders = payload || 0;
        },
    },
};
