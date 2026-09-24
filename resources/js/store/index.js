import { createStore } from "vuex";

import createPersistedState from "vuex-persistedstate";
import { auth } from "./modules/auth";
import { company } from "./modules/company";
import { cookies } from "./modules/cookies";
import { frontendAddress } from "./modules/frontend/frontendAddress";
import { frontendBenefit } from "./modules/frontend/frontendBenefit";
import { frontendBlog } from "./modules/frontend/frontendBlog";
import { frontendCampaign } from "./modules/frontend/frontendCampaign";
import { frontendCart } from "./modules/frontend/frontendCart";
import { frontendCountryCode } from "./modules/frontend/frontendCountryCode";
import { frontendCountryStateCity } from "./modules/frontend/frontendCountryStateCity";
import { frontendCoupon } from "./modules/frontend/frontendCoupon";
import { frontendEditProfile } from "./modules/frontend/frontendEditProfile";
import { frontendGuest } from "./modules/frontend/frontendGuest";
import { frontendLanguage } from "./modules/frontend/frontendLanguage";
import { frontendOrder } from "./modules/frontend/frontendOrder";
import { frontendOrderArea } from "./modules/frontend/frontendOrderArea";
import { frontendOutlet } from "./modules/frontend/frontendOutlet";
import { frontendOverview } from "./modules/frontend/frontendOverview";
import { frontendPage } from "./modules/frontend/frontendPage";
import { frontendPaymentGateway } from "./modules/frontend/frontendPaymentGateway";
import { frontendProduct } from "./modules/frontend/frontendProduct";
import { frontendProductBrand } from "./modules/frontend/frontendProductBrand";
import { frontendProductCategory } from "./modules/frontend/frontendProductCategory";
import { frontendProductReview } from "./modules/frontend/frontendProductReview";
import { frontendProductSection } from "./modules/frontend/frontendProductSection";
import { frontendProductVariation } from "./modules/frontend/frontendProductVariation";
import { frontendPromotion } from "./modules/frontend/frontendPromotion";
import { frontendReturnAndRefund } from "./modules/frontend/frontendReturnAndRefund";
import { frontendReturnReason } from "./modules/frontend/frontendReturnReason";
import { frontendSetting } from "./modules/frontend/frontendSetting";
import { frontendSignup } from "./modules/frontend/frontendSignup";
import { frontendSlider } from "./modules/frontend/frontendSlider";
import frontendWallet from "./modules/frontendWallet";
import { frontendWishlist } from "./modules/frontend/frontendWishlist";
import { globalState } from "./modules/frontend/globalState";
import { posCart } from "./modules/posCart";

/**
 * The storefront's store.
 *
 * Every one of the ~118 modules used to be imported here, so a first-time
 * shopper downloaded the state layer for every admin screen - purchases,
 * reports, stock, POS - before the home page could render. Only the modules a
 * shopper can actually reach are built in now; the admin ones arrive as a
 * separate chunk the moment an /admin route is entered (see
 * registerAdminModules, called from the router guard).
 */
const store = new createStore({
    state: {},
    mutations: {},
    actions: {},
    modules: {
        auth,
        company,
        cookies,
        frontendAddress,
        frontendBenefit,
        frontendBlog,
        frontendCampaign,
        frontendCart,
        frontendCountryCode,
        frontendCountryStateCity,
        frontendCoupon,
        frontendEditProfile,
        frontendGuest,
        frontendLanguage,
        frontendOrder,
        frontendOrderArea,
        frontendOutlet,
        frontendOverview,
        frontendPage,
        frontendPaymentGateway,
        frontendProduct,
        frontendProductBrand,
        frontendProductCategory,
        frontendProductReview,
        frontendProductSection,
        frontendProductVariation,
        frontendPromotion,
        frontendReturnAndRefund,
        frontendReturnReason,
        frontendSetting,
        frontendSignup,
        frontendSlider,
        frontendWallet,
        frontendWishlist,
        globalState,
        posCart,
    },
    plugins: [
        createPersistedState({
            paths: ["auth", "globalState", "frontendCart", "posCart"],
        }),
    ],
});

let adminRegistration = null;

/**
 * Loads and registers the admin store modules, once.
 *
 * Every admin route awaits this before it resolves, so a component never runs
 * against a module that is not registered yet. All four persisted paths belong
 * to core modules, so nothing here takes part in rehydration.
 */
export function registerAdminModules() {
    if (!adminRegistration) {
        adminRegistration = import("./adminModules").then(({ adminModules }) => {
            Object.keys(adminModules).forEach((name) => {
                if (!store.hasModule(name)) {
                    store.registerModule(name, adminModules[name]);
                }
            });
        });
    }

    return adminRegistration;
}

export default store;
