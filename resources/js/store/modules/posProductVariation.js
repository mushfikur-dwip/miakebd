import axios from "axios";

// These actions were called with a bare id long before branch-wise stock
// existed, so both shapes stay valid: an id, or { id, outlet_id }.
const variationId = (payload) => (payload !== null && typeof payload === "object" ? payload.id : payload);
const outletQuery = (payload) => (payload !== null && typeof payload === "object" && payload.outlet_id ? `?outlet_id=${payload.outlet_id}` : "");

export const posProductVariation = {
    namespaced: true,
    state: {
        initialVariation: [],
        childrenVariation: [],
        ancestorsToString: "",
        barcodeVariationProduct: []
    },
    getters: {
        initialVariation: function (state) {
            return state.initialVariation;
        },
        childrenVariation: function (state) {
            return state.childrenVariation;
        },
        ancestorsToString: function (state) {
            return state.ancestorsToString;
        },
        barcodeVariationProduct: function (state) {
            return state.barcodeVariationProduct
        }
    },
    actions: {
        initialVariation: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = `admin/product/initial-variation/${variationId(payload)}${outletQuery(payload)}`;
                axios.get(url).then((res) => {
                    context.commit("initialVariation", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        childrenVariation: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = `admin/product/children-variation/${variationId(payload)}${outletQuery(payload)}`;
                axios.get(url).then((res) => {
                    context.commit("childrenVariation", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        ancestorsToString: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = `admin/product/variation/ancestors-and-self/${payload}`;
                axios.get(url).then((res) => {
                    context.commit("ancestorsToString", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        barcodeVariationProduct: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = `admin/product/variation/barcode-variation-product/${variationId(payload)}${outletQuery(payload)}`;
                axios.get(url).then((res) => {
                    context.commit('barcodeVariationProduct', res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
    },
    mutations: {
        initialVariation: function (state, payload) {
            state.initialVariation = payload;
        },
        childrenVariation: function (state, payload) {
            state.childrenVariation = payload;
        },
        ancestorsToString: function (state, payload) {
            state.ancestorsToString = payload;
        },
        barcodeVariationProduct: function (state, payload) {
            state.barcodeVariationProduct = payload;
        },
    },
};
