import  HomeComponent from "../../components/frontend/home/HomeComponent";
const  WishlistComponent = () => import("../../components/frontend/wishlist/WishlistComponent");
const  OrderHistoryComponent = () => import("../../components/frontend/account/orderHistory/OrderHistoryComponent");
const  ReturnOrdersComponent = () => import("../../components/frontend/account/returnOrders/ReturnOrdersComponent");
const  ReturnOrderDetailsComponent = () => import("../../components/frontend/account/returnOrders/ReturnOrderDetailsComponent");
const  ReturnOrderRequestComponent = () => import("../../components/frontend/account/returnOrders/ReturnOrderRequestComponent");
const  OrderDetailsComponent = () => import("../../components/frontend/account/orderDetails/OrderDetailsComponent");
const  ChangePasswordComponent = () => import("../../components/frontend/account/changePassword/ChangePasswordComponent");
const  AddressComponent = () => import("../../components/frontend/account/address/AddressComponent");
const  PageComponent = () => import("../../components/frontend/page/PageComponent");
const  ProductComponent = () => import("../../components/frontend/product/ProductComponent");
const  ProductDetailsComponent = () => import("../../components/frontend/product/ProductDetailsComponent");
const  PromotionProductComponent = () => import("../../components/frontend/product/PromotionProductComponent");
const  ProductSectionProductComponent = () => import("../../components/frontend/product/ProductSectionProductComponent");
const  CampaignProductComponent = () => import("../../components/frontend/product/CampaignProductComponent");
const  FlashSaleProductComponent = () => import("../../components/frontend/product/FlashSaleProductComponent");
const  OfferProductComponent = () => import("../../components/frontend/product/OfferProductComponent");
const  OverviewComponent = () => import("../../components/frontend/account/overview/OverviewComponent");
const  AccountComponent = () => import("../../components/frontend/account/AccountComponent");
const  AccountInfoComponent = () => import("../../components/frontend/account/accountInfo/AccountInfoComponent");
const  CheckoutComponent = () => import("../../components/frontend/checkout/CheckoutComponent");
const  CheckoutCartListComponent = () => import("../../components/frontend/checkout/cartList/CartListComponent");
const  CartListHeaderComponent = () => import("../../components/frontend/checkout/cartList/HeaderComponent");
const  CheckoutCheckoutComponent = () => import("../../components/frontend/checkout/checkout/CheckoutComponent");
const  CheckoutHeaderComponent = () => import("../../components/frontend/checkout/checkout/HeaderComponent");
const  CheckoutPaymentComponent = () => import("../../components/frontend/checkout/payment/PaymentComponent");
const  PaymentHeaderComponent = () => import("../../components/frontend/checkout/payment/HeaderComponent");
const  ProductReviewComponent = () => import("../../components/frontend/account/review/ProductReviewComponent");
const  MostPopularProductComponent = () => import("../../components/frontend/product/MostPopularProductComponent.vue");
const  BlogComponent = () => import("../../components/frontend/blog/BlogComponent");
const  BlogDetailsComponent = () => import("../../components/frontend/blog/BlogDetailsComponent");
const  BlogCategoryComponent = () => import("../../components/frontend/blog/BlogCategoryComponent");
const  BlogTagPageComponent = () => import("../../components/frontend/blog/BlogTagPageComponent");

export default [
    {
        path: "/",
        component: HomeComponent,
        name: "frontend.home",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    // Old links and bookmarks. The server answers /home with a 301 too.
    {
        path: "/home",
        redirect: "/",
    },
    {
        path: "/product",
        component: ProductComponent,
        name: "frontend.product",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        // "All Product" is an ordinary category that happens to be the root of
        // the tree, so listing it showed only its descendants — 336 of the 440
        // live products. Baby Care, Fragrance and Moisturizer are top-level
        // categories in their own right and sit outside it, taking 104 products
        // with them.
        //
        // Redirecting here rather than rewriting the nine templates that build
        // category links means every entry point is covered at once — footer,
        // mega menu, mobile menu, homepage tiles, breadcrumb — along with old
        // bookmarks and anything Google has already indexed. Declared before
        // /product-category/:slug so the literal slug wins over the parameter.
        path: "/product-category/all-product",
        // Function form so the query survives the hop — the object form drops
        // it, and ProductComponent reads ?name=, ?brand= and ?category= off the
        // route. Mirrors the 301 in routes/web.php.
        redirect: (to) => ({ name: "frontend.product", query: to.query }),
    },
    {
        // Clean category URL. Renders the same listing component as /product;
        // the component reads route.params.slug when present and falls back to
        // route.query.category so old links keep working.
        path: "/product-category/:slug",
        component: ProductComponent,
        name: "frontend.productCategory",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        // A brand's own page, served with its own title and structured data
        // by RootController::brand(). The param is `brandSlug`, not `slug`:
        // ProductComponent reads route.params.slug as a category.
        path: "/brand/:brandSlug",
        component: ProductComponent,
        name: "frontend.brand",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        path: "/product/:slug",
        component: ProductDetailsComponent,
        name: "frontend.product.details",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        path: "/blog",
        component: BlogComponent,
        name: "frontend.blog",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        // Declared before /blog/:slug so "category" is not matched as a post
        // slug — the same ordering the server-side routes use.
        path: "/blog/category/:slug",
        component: BlogCategoryComponent,
        name: "frontend.blog.category",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        // Also declared before /blog/:slug, same reason as the category route.
        path: "/blog/tag/:slug",
        component: BlogTagPageComponent,
        name: "frontend.blog.tag",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        path: "/blog/:slug",
        component: BlogDetailsComponent,
        name: "frontend.blog.details",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        path: "/offers",
        component: OfferProductComponent,
        name: "frontend.offers",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        path: "/promotion/:slug",
        component: PromotionProductComponent,
        name: "frontend.promotion.products",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        path: "/product-section/:slug",
        component: ProductSectionProductComponent,
        name: "frontend.productSection.products",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    // Campaign pages (Clearance, Flash). The special price lives on this page
    // only — every other listing keeps the retail price.
    {
        path: "/campaign/:slug",
        component: CampaignProductComponent,
        name: "frontend.campaign.products",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        path: "/most-popular",
        component: MostPopularProductComponent,
        name: "frontend.mostPopular.products",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },

    {
        path: "/flash-sale",
        component: FlashSaleProductComponent,
        name: "frontend.flashSale.products",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        path: "/wishlist",
        component: WishlistComponent,
        name: "frontend.wishlist",
        meta: {
            isFrontend: true,
            auth: true,
        },
    },
    {
        path: "/page/:slug",
        component: PageComponent,
        name: "frontend.page",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        path: "/account",
        component: AccountComponent,
        name: "frontend.account",
        redirect: {name: "frontend.account.overview"},
        meta: {
            isFrontend: true,
            auth: true,
        },
        children: [
            {
                path: "overview",
                component: OverviewComponent,
                name: "frontend.account.overview",
                meta: {
                    isFrontend: true,
                    auth: true
                }
            },
            {
                path: "order-history",
                component: OrderHistoryComponent,
                name: "frontend.account.orderHistory",
                meta: {
                    isFrontend: true,
                    auth: true,
                }
            },
            {
                path: "return-orders",
                component: ReturnOrdersComponent,
                name: "frontend.account.returnOrders",
                meta: {
                    isFrontend: true,
                    auth: true,
                }
            },
            {
                path: "return-order-details/:id",
                component: ReturnOrderDetailsComponent,
                name: "frontend.account.returnOrder.details",
                meta: {
                    isFrontend: true,
                    auth: true,
                }
            },
            {
                path: "return-request/:id",
                component: ReturnOrderRequestComponent,
                name: "frontend.account.returnOrder.request",
                meta: {
                    isFrontend: true,
                    auth: true,
                }
            },
            {
                path: "write-review/:slug",
                component: ProductReviewComponent,
                name: "frontend.account.productReview",
                meta: {
                    isFrontend: true,
                    auth: true,
                }
            },
            {
                path: "edit-review/:slug/:id",
                component: ProductReviewComponent,
                name: "frontend.account.productReview.edit",
                meta: {
                    isFrontend: true,
                    auth: true,
                }
            },
            {
                path: "order-details/:id",
                component: OrderDetailsComponent,
                name: "frontend.account.orderDetails",
                meta: {
                    isFrontend: true,
                    auth: true,
                },
            },
            {
                path: "account-info",
                component: AccountInfoComponent,
                name: "frontend.account.accountInfo",
                meta: {
                    isFrontend: true,
                    auth: true,
                },
            },
            {
                path: "change-password",
                component: ChangePasswordComponent,
                name: "frontend.account.changePassword",
                meta: {
                    isFrontend: true,
                    auth: true,
                },
            },
            {
                path: "wallet",
                component: () => import("../../components/frontend/account/wallet/WalletComponent.vue"),
                name: "frontend.account.wallet",
                meta: {
                    isFrontend: true,
                    auth: true,
                },
            },
            {
                path: "address",
                component: AddressComponent,
                name: "frontend.account.address",
                meta: {
                    isFrontend: true,
                    auth: true,
                },
            }
        ]
    },
    {
        path: "/checkout",
        component: CheckoutComponent,
        name: "frontend.checkout",
        redirect: {name: "frontend.checkout.checkout"},
        meta: {
            isFrontend: true,
            auth: false,
        },
        children: [
            {
                path: "cart-list",
                components: {default : CheckoutCartListComponent, header: CartListHeaderComponent},
                name: "frontend.checkout.cartList",
                meta: {
                    isFrontend: true,
                    auth: false
                }
            },
            {
                path: "checkout",
                components: {default: CheckoutCheckoutComponent, header: CheckoutHeaderComponent},
                name: "frontend.checkout.checkout",
                meta: {
                    isFrontend: true,
                    auth: false
                }
            },
            {
                path: "payment",
                components: {default: CheckoutPaymentComponent, header: PaymentHeaderComponent},
                name: "frontend.checkout.payment",
                meta: {
                    isFrontend: true,
                    auth: false
                }
            }
        ]
    }
];
