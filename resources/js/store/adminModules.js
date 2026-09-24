import { administrator } from "./modules/administrator";
import { administratorAddress } from "./modules/administratorAddress";
import { analytic } from "./modules/analytic";
import { analyticSection } from "./modules/analyticSection";
import { barcode } from "./modules/barcode";
import { benefit } from "./modules/benefit";
import { blogCategory } from "./modules/blogCategory";
import { blogPost } from "./modules/blogPost";
import { blogTag } from "./modules/blogTag";
import { campaign } from "./modules/campaign";
import { campaignProduct } from "./modules/campaignProduct";
import { city } from "./modules/city";
import { country } from "./modules/country";
import { countryCode } from "./modules/countryCode";
import { coupon } from "./modules/coupon";
import { creditBalanceReport } from "./modules/creditBalanceReport";
import { currency } from "./modules/currency";
import { customer } from "./modules/customer";
import { customerAddress } from "./modules/customerAddress";
import { customerWallet } from "./modules/customerWallet";
import { damage } from "./modules/damage";
import { dashboard } from "./modules/dashboard";
import { employee } from "./modules/employee";
import { employeeAddress } from "./modules/employeeAddress";
import { language } from "./modules/language";
import { license } from "./modules/license";
import { mail } from "./modules/mail";
import { menuSection } from "./modules/menuSection";
import { menuTemplate } from "./modules/menuTemplate";
import { myOrderDetails } from "./modules/myOrderDetails";
import { notification } from "./modules/notification";
import { notificationAlert } from "./modules/notificationAlert";
import { onlineOrder } from "./modules/onlineOrder";
import { orderArea } from "./modules/orderArea";
import { otp } from "./modules/otp";
import { outlet } from "./modules/outlet";
import { page } from "./modules/page";
import { paymentGateway } from "./modules/paymentGateway";
import { permission } from "./modules/permission";
import { posOrder } from "./modules/posOrder";
import { posProduct } from "./modules/posProduct";
import { posProductCategory } from "./modules/posProductCategory";
import { posProductVariation } from "./modules/posProductVariation";
import { product } from "./modules/product";
import { productAttribute } from "./modules/productAttribute";
import { productAttributeOption } from "./modules/productAttributeOption";
import { productBrand } from "./modules/productBrand";
import { productCategory } from "./modules/productCategory";
import { productSection } from "./modules/productSection";
import { productSectionProduct } from "./modules/productSectionProduct";
import { productSeo } from "./modules/productSeo";
import { productsReport } from "./modules/productsReport";
import { productVariation } from "./modules/productVariation";
import { productVideo } from "./modules/productVideo";
import { promotion } from "./modules/promotion";
import { promotionProduct } from "./modules/promotionProduct";
import { purchase } from "./modules/purchase";
import { pushNotification } from "./modules/pushNotification";
import { smsCampaign } from "./modules/smsCampaign";
import { returnAndRefund } from "./modules/returnAndRefund";
import { returnOrder } from "./modules/returnOrder";
import { returnReason } from "./modules/returnReason";
import { review } from "./modules/review";
import { role } from "./modules/role";
import { salesReport } from "./modules/salesReport";
import { shippingSetup } from "./modules/shippingSetup";
import { site } from "./modules/site";
import { slider } from "./modules/slider";
import { smsGateway } from "./modules/smsGateway";
import { socialMedia } from "./modules/socialMedia";
import { state } from "./modules/state";
import { stock } from "./modules/stock";
import { stockAdjustment } from "./modules/stockAdjustment";
import { storeSalesReport } from "./modules/storeSalesReport";
import { subscriber } from "./modules/subscriber";
import { supplier } from "./modules/supplier";
import { tax } from "./modules/tax";
import { telegram } from "./modules/telegram";
import { theme } from "./modules/theme";
import { timezone } from "./modules/timezone";
import { transaction } from "./modules/transaction";
import { unit } from "./modules/unit";
import { user } from "./modules/user";

/**
 * The admin-only Vuex modules, in their own chunk.
 *
 * Imported by registerAdminModules() in ./index.js when an /admin route is
 * entered, so the storefront never downloads them.
 */
export const adminModules = {
    administrator,
    administratorAddress,
    analytic,
    analyticSection,
    barcode,
    benefit,
    blogCategory,
    blogPost,
    blogTag,
    campaign,
    campaignProduct,
    city,
    country,
    countryCode,
    coupon,
    creditBalanceReport,
    currency,
    customer,
    customerAddress,
    customerWallet,
    damage,
    dashboard,
    employee,
    employeeAddress,
    language,
    license,
    mail,
    menuSection,
    menuTemplate,
    myOrderDetails,
    notification,
    notificationAlert,
    onlineOrder,
    orderArea,
    otp,
    outlet,
    page,
    paymentGateway,
    permission,
    posOrder,
    posProduct,
    posProductCategory,
    posProductVariation,
    product,
    productAttribute,
    productAttributeOption,
    productBrand,
    productCategory,
    productSection,
    productSectionProduct,
    productSeo,
    productsReport,
    productVariation,
    productVideo,
    promotion,
    promotionProduct,
    purchase,
    pushNotification,
    smsCampaign,
    returnAndRefund,
    returnOrder,
    returnReason,
    review,
    role,
    salesReport,
    shippingSetup,
    site,
    slider,
    smsGateway,
    socialMedia,
    state,
    stock,
    stockAdjustment,
    storeSalesReport,
    subscriber,
    supplier,
    tax,
    telegram,
    theme,
    timezone,
    transaction,
    unit,
    user,
};
