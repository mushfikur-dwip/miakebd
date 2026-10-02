import { createRouter, createWebHistory } from "vue-router";
// Lazy: the dashboard renders three <apexchart> children, so a static import
// puts the whole chart library in the bundle every storefront visitor loads.
// ExceptionComponent and NotFoundComponent stay eager — they are small and need
// to render instantly when something has already gone wrong.
const DashboardComponent = () =>
    import("../components/admin/dashboard/DashboardComponent");
import ExceptionComponent from "../components/exception/ExceptionComponent.vue";
import NotFoundComponent from "../components/exception/NotFoundComponent.vue";
import ENV from "../config/env";
import appService from "../services/appService";
import store, { registerAdminModules } from "../store";
import pixelService from "../services/pixelService";
import administratorRoutes from "./modules/administratorRoutes";
import authRoutes from "./modules/authRoutes";
import blogRoutes from "./modules/blogRoutes";
import couponRoutes from "./modules/couponRoutes";
import creditBalanceReportRoutes from "./modules/creditBalanceReportRoutes";
import customerRoutes from "./modules/customerRoutes";
import customerMessageRoutes from "./modules/customerMessageRoutes";
import damageRoutes from "./modules/damageRoutes";
import employeeRoutes from "./modules/employeeRoutes";
import frontendRoutes from "./modules/frontendRoutes";
import onlineOrderRoutes from "./modules/onlineOrderRoutes";
import posOrderRoutes from "./modules/posOrderRoutes";
import posRoutes from "./modules/posRoutes";
import ProductSectionRoutes from "./modules/ProductSectionRoutes";
import productsReportRoutes from "./modules/productsReportRoutes";
import productsRoutes from "./modules/productsRoutes";
import profileRoutes from "./modules/profileRoutes";
import PromotionRoutes from "./modules/PromotionRoutes";
import CampaignRoutes from "./modules/CampaignRoutes";
import purchaseRoutes from "./modules/purchaseRoutes";
import pushNotificationRoutes from "./modules/pushNotificationRoutes";
import returnAndRefundRoutes from "./modules/returnAndRefundRoutes";
import returnOrderRoutes from "./modules/returnOrderRoutes";
import reviewRoutes from "./modules/reviewRoutes";
import salesReportRoutes from "./modules/salesReportRoutes";
import settingRoutes from "./modules/settingRoutes";
import stockRoutes from "./modules/stockRoutes";
import cashCalculationRoutes from "./modules/cashCalculationRoutes";
import storeSalesReportRoutes from "./modules/storeSalesReportRoutes";
import subscriberRoutes from "./modules/subscriberRoutes";
import transactionRoutes from "./modules/transactionRoutes";
import mobileSectionRoutes from "./modules/mobileSectionRoutes";

// The homepage is "/" itself (frontendRoutes). It used to redirect to /home,
// which put a second homepage URL in every address bar and shared link.
const baseRoutes = [
    {
        path: "/:pathMatch(.*)*",
        name: "route.notFound",
        component: NotFoundComponent,
        meta: {
            isFrontend: true,
        },
    },
    {
        path: "/exception",
        name: "route.exception",
        component: ExceptionComponent,
    },
    {
        path: "/admin/dashboard",
        component: DashboardComponent,
        name: "admin.dashboard",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "dashboard",
            breadcrumb: "dashboard",
        },
    },
];

const routes = baseRoutes.concat(
    frontendRoutes,
    authRoutes,
    settingRoutes,
    profileRoutes,
    productsRoutes,
    administratorRoutes,
    customerRoutes,
    customerMessageRoutes,
    employeeRoutes,
    transactionRoutes,
    salesReportRoutes,
    storeSalesReportRoutes,
    creditBalanceReportRoutes,
    pushNotificationRoutes,
    productsRoutes,
    couponRoutes,
    PromotionRoutes,
    CampaignRoutes,
    ProductSectionRoutes,
    purchaseRoutes,
    stockRoutes,
    cashCalculationRoutes,
    returnOrderRoutes,
    damageRoutes,
    onlineOrderRoutes,
    productsReportRoutes,
    posOrderRoutes,
    posRoutes,
    returnAndRefundRoutes,
    subscriberRoutes,
    reviewRoutes,
    mobileSectionRoutes,
    blogRoutes
);

const permission = store.getters.authPermission;
appService.recursiveRouter(routes, permission);

const API_URL = ENV.API_URL;
const router = createRouter({
    linkActiveClass: "active",
    mode: "history",
    history: createWebHistory(),
    routes,
    scrollBehavior() {
        return { left: 0, top: 0 };
    },
});

router.beforeEach(async (to, from, next) => {
    // The admin store modules are not in the storefront bundle (see
    // store/index.js). Registering them here, before the route resolves, means
    // an admin screen never runs against a module that is not there yet.
    // Anything that is not explicitly a storefront route renders the admin
    // chrome (see DefaultComponent's theme watcher), including routes that
    // declare no meta at all, such as /exception. Loading the modules for all
    // of them keeps that impossible to get wrong; the storefront, which is what
    // this split is for, is the only path that skips it.
    if (to.meta.isFrontend !== true) {
        await registerAdminModules();
    }

    if (to.meta.auth === true) {
        if (!store.getters.authStatus) {
            next({ name: "auth.login" });
        } else {
            if (to.meta.isFrontend === false) {
                if (to.meta.access === false) {
                    next({
                        name: "route.exception",
                    });
                } else {
                    next();
                }
            } else {
                next();
            }
        }
    } else if (
        (to.name === "auth.login" ||
            to.name === "auth.signup" ||
            to.name === "auth.forgotPassword") &&
        store.getters.authStatus
    ) {
        next({ name: "frontend.home" });
    } else {
        next();
    }
});

/**
 * The shop is a single-page app: the Pixel's base code fires PageView once,
 * for the screen the visitor landed on, and would never hear about any other.
 * Meta's "people who visited" audiences are built from these, so every screen
 * has to report itself.
 *
 * `from.name` is null only for that first navigation, which the base code has
 * already counted - counting it again would double every landing.
 */
router.afterEach((to, from) => {
    if (from.name) {
        pixelService.pageView();
    }
});

export default router;
