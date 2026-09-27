import forEach from "lodash/forEach";
import axios from "axios";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import shippingMethodEnum from "../../../enums/modules/shippingMethodEnum";
import ShippingTypeEnum from "../../../enums/modules/shippingTypeEnum";
import AskEnum from "../../../enums/modules/askEnum";
import alertService from "../../../services/alertService";
import i18n from "../../../i18n";

/**
 * Price source of a cart line that came from the normal catalogue. Campaign
 * lines carry "campaign:<id>" instead, so the same product bought from a
 * campaign page and from the listing stays two lines at two prices.
 */
const CATALOGUE_SOURCE = "catalogue";

/**
 * A whole quantity between 1 and what the line may hold: its stock and its
 * product's purchase limit. Typed quantities arrive as strings - "0", "" or
 * "2.5" used to go into the cart as they were, leaving a line the server
 * refuses. A limit of 0 means none.
 */
function clampQuantity(line, value) {
    let quantity = parseInt(value, 10);
    if (!Number.isFinite(quantity) || quantity < 1) {
        quantity = 1;
    }
    if (Number(line.stock) > 0 && quantity > Number(line.stock)) {
        quantity = Number(line.stock);
    }
    if (Number(line.maximum_purchase_quantity) > 0 && quantity > Number(line.maximum_purchase_quantity)) {
        quantity = Number(line.maximum_purchase_quantity);
    }
    return quantity;
}

// Only the newest coupon re-quote may land; an older one answering late would
// otherwise put back a discount for a cart that has since changed.
let couponQuote = 0;

export const frontendCart = {
    namespaced: true,
    state: {
        lists: [],
        subtotal: 0,
        total: 0,
        coupon: {},
        discount: 0,
        walletBalance: 0,
        walletDiscount: 0,
        appliedWalletAmount: 0,
        orderType: null,
        shippingAddress: {},
        billingAddress: {},
        outletAddress: {},
        paymentMethod: {},
        totalTax: 0,
        shippingCharge: 0,
        isList: false,
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        subtotal: function (state) {
            return state.subtotal;
        },
        coupon: function (state) {
            return state.coupon;
        },
        discount: function (state) {
            return state.discount;
        },
        walletBalance: function (state) {
            return state.walletBalance;
        },
        walletDiscount: function (state) {
            return state.walletDiscount;
        },
        appliedWalletAmount: function (state) {
            return state.appliedWalletAmount;
        },
        total: function (state) {
            return state.total;
        },
        orderType: function (state) {
            return state.orderType;
        },
        shippingAddress: function (state) {
            return state.shippingAddress;
        },
        billingAddress: function (state) {
            return state.billingAddress;
        },
        outletAddress: function (state) {
            return state.outletAddress;
        },
        paymentMethod: function (state) {
            return state.paymentMethod;
        },
        totalTax: function (state) {
            return state.totalTax;
        },
        shippingCharge: function (state) {
            return state.shippingCharge;
        },
        isList: function (state) {
            return state.isList;
        }
    },
    actions: {
        listChecker: function (context) {
            return new Promise((resolve, reject) => {
                if (context.state.lists.length > 0) {
                    context.commit('isList', true);
                    resolve({ status: true });
                } else {
                    context.commit('isList', false);
                    resolve({ status: false });
                }
                reject({
                    message: "no data found",
                    status: false
                });
            });
        },
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let changed = false;
                if (Object.keys(payload).length > 0) {
                    let isNew = false;
                    let productMatch = false;
                    // A quantity typed on the product page arrives as text, and
                    // `1 + "2"` merged into an existing line as "12".
                    payload = { ...payload, quantity: parseInt(payload.quantity, 10) || 1 };
                    if (context.state.lists.length === 0) {
                        isNew = true;
                    } else {
                        const payloadSource = payload.price_source || CATALOGUE_SOURCE;

                        forEach(context.state.lists, (list, listKey) => {
                            // Keyed by price_source as well as product and
                            // variation. The same product added from a campaign
                            // page and from the normal listing is two lines at
                            // two prices — that is intended. Merging on product
                            // alone would collapse them into one line and keep
                            // whichever price happened to land first, silently
                            // charging the wrong amount.
                            //
                            // Falls back to CATALOGUE_SOURCE on both sides: the
                            // cart is persisted to localStorage, so lines saved
                            // before campaigns existed carry no price_source
                            // and must still merge with a normal add.
                            const listSource = list.price_source || CATALOGUE_SOURCE;

                            if (
                                list.product_id === payload.product_id
                                && list.variation_id === payload.variation_id
                                && listSource === payloadSource
                            ) {
                                productMatch = true;
                                if ((payload.quantity + list.quantity) <= list.stock) {
                                    if ((payload.quantity + list.quantity) <= list.maximum_purchase_quantity) {
                                        context.state.lists[listKey].quantity += payload.quantity;
                                        changed = true;
                                    } else {
                                        reject({
                                            message: "maximum_quantity",
                                            status: false
                                        });
                                    }
                                } else {
                                    reject({
                                        message: "stockOut",
                                        status: false
                                    });
                                }
                            }
                        });

                        if (!productMatch) {
                            isNew = true;
                        }
                        productMatch = false;
                    }

                    if (isNew) {
                        context.state.lists.push({
                            name: payload.name,
                            product_id: payload.product_id,
                            image: payload.image,
                            variation_names: payload.variation_names,
                            variation_id: payload.variation_id,
                            sku: payload.sku,
                            stock: payload.stock,
                            taxes: payload.taxes,
                            shipping: payload.shipping,
                            quantity: clampQuantity(payload, payload.quantity),
                            discount: payload.discount,
                            price: payload.price,
                            old_price: payload.old_price,
                            total_tax: 0,
                            subtotal: 0,
                            total: 0,
                            total_price: payload.total_price,
                            maximum_purchase_quantity: payload.maximum_purchase_quantity,
                            price_source: payload.price_source || CATALOGUE_SOURCE,
                            campaign_id: payload.campaign_id || null
                        });
                        isNew = false;
                        changed = true;
                    }
                }
                context.commit("taxCalculation");
                context.commit("shippingCharge", {
                    setting: context.rootState.frontendSetting.lists,
                    area: context.rootState.frontendOrderArea.lists
                });
                context.commit("subtotal");
                context.dispatch('listChecker').then().catch();
                if (changed) {
                    context.dispatch('linesChanged').then().catch();
                }
                resolve({ data: context.state.lists, status: true });
            });
        },
        quantity: function (context, payload) {
            const line = context.state.lists[payload.id];
            const before = line ? line.quantity : null;
            context.commit("quantity", payload);
            context.commit("taxCalculation");
            context.commit("shippingCharge", {
                setting: context.rootState.frontendSetting.lists,
                area: context.rootState.frontendOrderArea.lists
            });
            context.commit("subtotal");
            // A click that hits a limit changes nothing, and must not cost the
            // customer their wallet payment.
            if (line && line.quantity !== before) {
                context.dispatch('linesChanged').then().catch();
            }
        },
        remove: function (context, payload) {
            context.commit("remove", payload);
            context.commit("taxCalculation");
            context.commit("shippingCharge", {
                setting: context.rootState.frontendSetting.lists,
                area: context.rootState.frontendOrderArea.lists
            });
            context.commit("subtotal");
            context.dispatch('listChecker').then().catch();
            context.dispatch('linesChanged').then().catch();
        },
        /**
         * After any change to the lines, the money taken off them has to be
         * worked out again, or checkout sends a total the server refuses as
         * "price changed" and the customer cannot order.
         *
         * A wallet payment must cover the whole total exactly, so it is
         * dropped and the customer told to apply it again. A coupon is quoted
         * afresh for the new subtotal - a percentage coupon is worth a
         * different amount now - and dropped, with the reason, if it no
         * longer applies (e.g. the cart fell under its minimum order).
         */
        linesChanged: function (context) {
            if (context.state.walletDiscount > 0) {
                context.commit('walletDiscount', 0);
                context.commit('appliedWalletAmount', 0);
                context.commit("subtotal");
                alertService.warning(i18n.global.t('message.wallet_removed_cart_changed'));
            }
            if (Object.keys(context.state.coupon).length > 0) {
                return context.dispatch('requoteCoupon');
            }
            return Promise.resolve();
        },
        requoteCoupon: function (context) {
            const code = context.state.coupon && context.state.coupon.code;
            if (!code) {
                return Promise.resolve();
            }
            const ticket = ++couponQuote;

            return context.dispatch('frontendCoupon/checking', {
                total: context.state.subtotal,
                code: code
            }, { root: true }).then((res) => {
                if (ticket !== couponQuote || Object.keys(context.state.coupon).length === 0) {
                    return;
                }
                context.commit("coupon", res.data.data);
                context.commit("subtotal");
            }).catch((err) => {
                if (ticket !== couponQuote) {
                    return;
                }
                context.commit("coupon", {});
                context.commit("subtotal");
                const reason = err && err.response && err.response.data && err.response.data.message;
                alertService.warning(i18n.global.t('message.coupon_remove') + (reason ? ": " + reason : ""));
            });
        },
        coupon: function (context, payload) {
            context.commit("coupon", payload);
            context.commit("subtotal");
        },
        // Carts are persisted, so one saved before a pricing change keeps its
        // old totals until something is edited. Re-derives tax and totals from
        // the lines. Shipping is left alone: it needs the order-area list,
        // which the payment step does not load.
        recalculate: function (context) {
            context.commit("taxCalculation");
            context.commit("subtotal");
        },
        destroyCoupon: function (context) {
            context.commit('coupon', {});
            context.commit("subtotal");
        },
        fetchWalletBalance: function (context) {
            return new Promise((resolve, reject) => {
                axios.get('/frontend/wallet/balance').then((res) => {
                    // More defensive response handling
                    const balance = res.data && res.data.data && res.data.data.balance ? parseFloat(res.data.data.balance) : 0;
                    context.commit('walletBalance', balance);
                    resolve(res);
                }).catch((err) => {
                    // Set balance to 0 on error
                    context.commit('walletBalance', 0);
                    reject(err);
                });
            });
        },
        applyWalletDiscount: function (context, amount) {
            return new Promise((resolve, reject) => {
                const appliedAmount = parseFloat(amount);
                context.commit('walletDiscount', appliedAmount);
                context.commit('appliedWalletAmount', appliedAmount);
                context.commit("subtotal");
                resolve();
            });
        },
        removeWalletDiscount: function (context) {
            context.commit('walletDiscount', 0);
            context.commit('appliedWalletAmount', 0);
            context.commit("subtotal");
        },
        initOrderType: function (context, payload) {
            context.commit('orderTypeInit', payload);
            context.commit("shippingCharge", {
                setting: context.rootState.frontendSetting.lists,
                area: context.rootState.frontendOrderArea.lists
            });
            context.commit("subtotal");
        },
        updateOrderType: function (context, payload) {
            context.commit('updateOrderType', payload);
            context.commit("shippingCharge", {
                setting: context.rootState.frontendSetting.lists,
                area: context.rootState.frontendOrderArea.lists
            });
            context.commit("subtotal");
        },
        shippingAddress: function (context, payload) {
            context.commit('shippingAddress', payload);
            context.commit("shippingCharge", {
                setting: context.rootState.frontendSetting.lists,
                area: context.rootState.frontendOrderArea.lists
            });
            context.commit("subtotal");
        },
        billingAddress: function (context, payload) {
            context.commit('billingAddress', payload);
            context.commit("subtotal");
        },
        outletAddress: function (context, payload) {
            context.commit('outletAddress', payload);
            context.commit("subtotal");
        },
        paymentMethod: function (context, payload) {
            context.commit('paymentMethod', payload);
        },
        resetCart: function (context) {
            context.commit('resetCart');
        },
    },
    mutations: {
        subtotal: function (state) {
            state.total = 0;
            if (state.lists.length > 0) {
                let subtotal = 0;
                let total = 0;
                forEach(state.lists, (list, listKey) => {
                    state.lists[listKey].subtotal = state.lists[listKey].price * state.lists[listKey].quantity;
                    // `discount` is NOT subtracted: `price` is already the offer
                    // price, so taking it off again discounted offer items twice
                    // (and by a raw percentage when added from a listing). The
                    // server computes the same total and refuses any other.
                    state.lists[listKey].total = (state.lists[listKey].price * state.lists[listKey].quantity) + state.lists[listKey].total_tax;
                    subtotal += state.lists[listKey].subtotal;
                    total += state.lists[listKey].total;
                });
                state.subtotal = subtotal;
                state.total = total;
            } else {
                state.subtotal = 0;
                state.total = 0;
            }

            if (state.shippingCharge > 0) {
                state.total += state.shippingCharge;
            }

            if (Object.keys(state.coupon).length > 0) {
                state.total -= state.coupon.convert_discount;
            }

            if (state.walletDiscount > 0) {
                state.total -= state.walletDiscount;
            }
        },
        quantity: function (state, payload) {
            const line = state.lists[payload.id];
            if (!line) {
                return;
            }

            let quantity = payload.status;
            if (payload.status === "increment") {
                quantity = line.quantity + 1;
            } else if (payload.status === "decrement") {
                quantity = line.quantity - 1;
            }

            line.quantity = clampQuantity(line, quantity);
            line.total_price = line.price * line.quantity;
        },
        remove: function (state, payload) {
            state.lists.splice(payload.id, 1);
        },
        coupon: function (state, payload) {
            state.coupon = payload;
            if (Object.keys(payload).length > 0) {
                state.discount = payload.convert_discount;
            } else {
                state.discount = 0;
            }
        },
        walletBalance: function (state, payload) {
            state.walletBalance = payload;
        },
        appliedWalletAmount: function (state, payload) {
            state.appliedWalletAmount = payload;
        },
        walletDiscount: function (state, payload) {
            state.walletDiscount = payload;
        },
        orderTypeInit: function (state, payload) {
            if (state.orderType === null) {
                state.orderType = payload.order_type;
            }
        },
        updateOrderType: function (state, payload) {
            if (orderTypeEnum.DELIVERY === payload || orderTypeEnum.PICK_UP === payload) {
                state.orderType = payload;
            } else {
                state.orderType = null;
            }
        },
        shippingAddress: function (state, payload) {
            state.shippingAddress = payload;
        },
        billingAddress: function (state, payload) {
            state.billingAddress = payload;
        },
        outletAddress: function (state, payload) {
            state.outletAddress = payload;
        },
        paymentMethod: function (state, payload) {
            state.paymentMethod = payload;
        },
        taxCalculation: function (state) {
            let stateTotalTax = 0;
            forEach(state.lists, (list, listKey) => {
                if (list.taxes.length > 0) {
                    let taxes = [];
                    let total_tax = 0;
                    forEach(list.taxes, (tax, taxKey) => {
                        if (tax.tax_rate > 0) {
                            let taxPercentagePrice = ((list.price / 100) * parseFloat(tax.tax_rate));
                            total_tax += taxPercentagePrice;
                            taxes.push({
                                id: tax.id,
                                name: tax.name,
                                code: tax.code,
                                tax_rate: parseFloat(tax.tax_rate),
                                tax_amount: parseFloat(taxPercentagePrice)
                            })
                        }
                    });
                    state.lists[listKey].taxes = taxes;
                    state.lists[listKey].total_tax = (total_tax * state.lists[listKey].quantity);
                }
                stateTotalTax += state.lists[listKey].total_tax;
            });
            state.totalTax = stateTotalTax;
        },
        shippingCharge: function (state, payload) {
            if (state.orderType === orderTypeEnum.DELIVERY) {
                if (payload.setting.shipping_setup_method === shippingMethodEnum.FLAT_RATE_WISE) {
                    state.shippingCharge = parseFloat(payload.setting.shipping_setup_flat_rate_wise_cost);
                } else if (payload.setting.shipping_setup_method === shippingMethodEnum.PRODUCT_WISE) {
                    let totalShippingCost = 0;
                    forEach(state.lists, (list, listKey) => {
                        if (list.shipping.shipping_type === ShippingTypeEnum.FLAT_RATE) {
                            if (list.shipping.is_product_quantity_multiply === AskEnum.YES) {
                                totalShippingCost += (parseFloat(list.shipping.shipping_cost) * list.quantity);
                            } else {
                                totalShippingCost += (parseFloat(list.shipping.shipping_cost));
                            }
                        }
                    });
                    state.shippingCharge = totalShippingCost;
                } else if (payload.setting.shipping_setup_method === shippingMethodEnum.AREA_WISE) {
                    if (Object.keys(state.shippingAddress).length > 0) {
                        let status = false;
                        forEach(payload.area, (list, listKey) => {
                            if (list.country === state.shippingAddress.country && list.state === state.shippingAddress.state) {
                                status = true;
                                state.shippingCharge = parseFloat(list.shipping_cost);
                            }
                        });

                        if (!status) {
                            state.shippingCharge = parseFloat(payload.setting.shipping_setup_area_wise_default_cost);
                        }
                    }
                }
            } else {
                state.shippingCharge = 0;
            }
        },
        isList: function (state, payload) {
            state.isList = payload;
        },
        resetCart: function (state) {
            state.appliedWalletAmount = 0;
            state.lists = [];
            state.subtotal = 0;
            state.total = 0;
            state.coupon = {};
            state.discount = 0;
            state.walletDiscount = 0;
            state.shippingAddress = {};
            state.billingAddress = {};
            state.outletAddress = {};
            state.paymentMethod = {};
            state.totalTax = 0;
            state.shippingCharge = 0;
        }
    },
};
