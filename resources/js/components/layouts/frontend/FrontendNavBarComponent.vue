<template>
    <!-- Offer Banner -->
    <div v-if="setting.site_offer_banner_text" class="hidden lg:block w-full bg-[rgb(232,194,179)] text-black py-1 px-4 text-center animate-slideDown">
        <p class="text-sm sm:text-base font-medium">{{ setting.site_offer_banner_text }}</p>
    </div>

    <header
        :class="isSticky === true ? 'fixed top-0 left-0 z-30 w-full mb-5 sm:mb-8 shadow-xs bg-white' : 'relative z-30 mb-5 sm:mb-8 shadow-xs bg-white'">
        <div class="container py-3.5 px-4 lg:py-0">
            <div class="flex items-center justify-between gap-5">
                <!--  Logo & Mobile Responsive Start -->
                <div class="flex items-center flex-shrink-0 gap-5">
                    <button type="button" class="leading-none block lg:hidden"
                        @click.prevent="showTarget('mobile-sidebar-canvas', 'canvas-active')">
                        <i class="lab-line-humburger text-xl"></i>
                    </button>

                    <router-link :to="{ name: 'frontend.home' }"
                        class="router-link-active router-link-exact-active flex-shrink-0">
                        <!-- Matches the preload in master.blade.php: this is the
                             first image on the page, so it should not queue
                             behind the product thumbnails. -->
                        <img class="w-28 sm:w-32" :src="setting.theme_logo" alt="logo"
                             fetchpriority="high" decoding="async">
                    </router-link>
                </div>

                <button type="button" class="leading-none block lg:hidden"
                    @click.prevent="showTarget('search', 'search-active')">
                    <i class="lab-line-search text-xl"></i>
                </button>
                <!--  Logo & Mobile Responsive End -->

                <!-- MenuBar Start -->
                <nav class="header-nav hidden lg:block">
                    <ul class="header-nav-list">
                        <li class="header-nav-item">
                            <router-link class="header-nav-menu"
                                :class="checkIsPathAndRoutePathSame('/home') ? 'router-link-active router-link-exact-active' : ''"
                                :to="{ name: 'frontend.home' }">
                                {{ $t("label.home") }}
                            </router-link>
                        </li>

                        <li class="header-nav-item">
                            <button type="button" class="header-nav-menu down-arrow">
                                {{ $t('label.categories') }}
                            </button>
                            <div
                                class="fixed top-[64px] left-0 z-10 w-full origin-top scale-y-0 transition-all duration-300">
                                <div class="container">
                                    <div class="w-full rounded-b-2xl shadow-paper bg-white">
                                        <nav class="w-full flex items-center justify-center">
                                            <router-link v-for="(category, index) in categories" :key="index"
                                                :to="{ name: 'frontend.productCategory', params: { slug: category.slug } }"
                                                @mouseover.prevent="activeTab = 'category_' + category.slug"
                                                class="capitalize text-sm font-semibold tracking-wide px-5 py-4 transition-all duration-300 relative before:content-[''] before:absolute before:bottom-0 before:left-0 before:h-0.5 before:bg-primary hover:text-primary"
                                                :class="{ 'text-primary before:w-full before:transition-all before:duration-300': activeTab === 'category_' + category.slug }">
                                                {{ category.name }}
                                            </router-link>
                                        </nav>
                                        <div v-for="category in categories">
                                            <div v-if="category.children.length > 0"
                                                :class="{ 'block': activeTab === 'category_' + category.slug, 'hidden': activeTab !== 'category_' + category.slug }"
                                                class="flex items-start gap-5 pb-5 border-t border-gray-200">
                                                <div class="w-60 h-80 flex-shrink-0 pt-5 ltr:pl-5 rtl:pr-5">
                                                    <img class="w-full h-full object-top object-cover rounded-lg"
                                                        :src="category.cover" alt="category" />
                                                </div>
                                                <div class="w-full h-80 thin-scrolling pt-5 ltr:pr-5 rtl:pl-5">
                                                    <div class="w-full grid gap-5 grid-cols-3">
                                                        <div v-for="children in category.children" class="self-start">
                                                            <h3
                                                                class="text-sm font-semibold capitalize pb-3 border-b border-slate-200">
                                                                <router-link
                                                                    :to="{ name: 'frontend.productCategory', params: { slug: children.slug } }"
                                                                    class="hover:text-primary transition-all duration-300">
                                                                    {{ children.name }}
                                                                </router-link>
                                                            </h3>

                                                            <nav v-if="children.children.length > 0"
                                                                class="flex flex-col mt-2">
                                                                <MenuChildrenComponent
                                                                    :categories="children.children" />
                                                            </nav>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </li>

                        <li class="header-nav-item">
                            <router-link class="header-nav-menu"
                                :class="checkIsPathAndRoutePathSame('/offers') ? 'router-link-active router-link-exact-active' : ''"
                                :to="{ name: 'frontend.offers' }">
                                {{ $t("label.offers") }}
                            </router-link>
                        </li>

                        <li class="header-nav-item">
                            <router-link class="header-nav-menu"
                                :class="checkIsPathAndRoutePathSame('/flash-sale') ? 'router-link-active router-link-exact-active' : ''"
                                :to="{ name: 'frontend.flashSale.products' }">
                                {{ $t("label.flash_sale") }}
                            </router-link>
                        </li>

                        <!-- Running campaigns plus Wholesale; see navSections.
                             Nothing is hardcoded, so a link never points at a
                             page that has not been set up or has expired. -->
                        <li class="header-nav-item" v-for="section in navSections" :key="section.id">
                            <router-link class="header-nav-menu"
                                :class="isNavSectionActive(section) ? 'router-link-active router-link-exact-active' : ''"
                                :to="{ name: section.routeName, params: { slug: section.slug } }">
                                {{ section.name }}
                            </router-link>
                        </li>

                        <li class="header-nav-item">
                            <router-link class="header-nav-menu"
                                :class="currentRoute.startsWith('/blog') ? 'router-link-active router-link-exact-active' : ''"
                                :to="{ name: 'frontend.blog' }">
                                {{ $t("label.blog") }}
                            </router-link>
                        </li>
                    </ul>
                </nav>
                <!-- MenuBar End -->

                <!-- Mobile Search Start -->
                <form @submit.prevent="search"
                    class="hidden w-full lg:w-80 h-10 rounded-3xl lg:flex items-center gap-2 px-4 border border-gray-100 bg-gray-100 transition-all duration-300 focus-within:border-primary focus-within:bg-white">
                    <button class="lab-line-search text-lg flex-shrink-0"></button>
                    <input v-model="searchProduct" class="w-full h-full" type="search"
                        :placeholder="$t('label.search') + '...'" />
                    <button @click="resetSearch" type="button" v-if="searchProduct" class="text-sm text-red-500 fa-regular fa-circle-xmark" ></button>
                </form>
                <!-- Mobile Search Start -->

                <!-- Community Start -->
                <!-- Invite link to the store's group, set in Settings > Site.
                     The whole item is hidden until a link is saved, so the
                     header never carries a dead menu entry. Opens in a new tab:
                     the group lives off-site and losing the cart mid-shop to a
                     Facebook redirect is worse than an extra tab. -->
                <a v-if="communityLink" :href="communityLink" target="_blank" rel="noopener noreferrer"
                    class="hidden lg:flex items-center gap-2 flex-shrink-0 group whitespace-nowrap">
                    <i class="lab-line-users text-2xl text-primary"></i>
                    <span
                        class="text-sm font-semibold capitalize text-heading transition-colors group-hover:text-primary">
                        {{ $t('label.community') }}
                    </span>
                </a>
                <!-- Community End -->

                <!-- Language Start -->
                <div v-if="setting.site_language_switch === enums.activityEnum.ENABLE"
                    class="relative group hidden lg:block">
                    <button type="button" class="flex items-center gap-2 py-5 down-arrow">
                        <img :src="language.image" alt="language" class="w-4 h-4 rounded-full" />
                        <span class="font-semibold capitalize">{{ language.name }}</span>
                    </button>

                    <ul
                        class="w-40 absolute top-16 ltr:right-0 rtl:left-0 shadow-paper rounded-lg z-10 p-2 bg-white transition-all duration-300 origin-top scale-y-0 group-hover:scale-y-100">
                        <li v-for="(LoopLanguage, index) in languages" :key="index"
                            @click.prevent="changeLanguage(LoopLanguage.id, LoopLanguage.code, LoopLanguage.display_mode)"
                            class="flex items-center gap-3 px-2 py-1.5 rounded-lg relative w-full cursor-pointer transition-all duration-300 hover:bg-slate-100">
                            <img :src="LoopLanguage.image" alt="flags" class="w-4 flex-shrink-0" />
                            <span class="text-sm font-medium capitalize flex-auto">{{ LoopLanguage.name }}</span>
                        </li>
                    </ul>
                </div>
                <!-- Language End -->


                <!-- Wishlist Start -->
                <router-link class="hidden lg:block relative" :to="{ name: 'frontend.wishlist' }">
                    <i class="lab-line-heart text-xl"></i>
                    <span v-if="wishlists.length > 0"
                        class="absolute top-2 ltr:-right-2 rtl:-left-2 text-[10px] font-medium h-4 px-1 !leading-[14px] text-center rounded-full border border-white text-white bg-primary">
                        {{ wishlists.length }}
                    </span>
                </router-link>
                <!-- WishList End -->


                <!-- 24/7 helpline. tel: so a tap dials on mobile. Falls back to
                     the store's own number if company_phone is unset. -->
                <a :href="'tel:' + helplineNumber"
                    class="hidden xl:flex items-center gap-2.5 flex-shrink-0 group">
                    <i class="lab-line-call text-2xl text-primary"></i>
                    <span class="flex flex-col leading-tight">
                        <span class="text-[11px] font-bold uppercase tracking-wide text-primary">
                            {{ $t('label.support_24_7') }}
                        </span>
                        <span dir="ltr" class="text-sm font-semibold text-heading group-hover:text-primary transition-colors">
                            {{ helplineNumber }}
                        </span>
                    </span>
                </a>

                <!-- My Account Start -->
                <!-- Click to open, NOT hover. A hover panel closes the instant
                     the pointer leaves the trigger, which left no time to reach
                     the buttons inside, and it is unusable on touch. It now
                     stays open until you pick something, click away, or press
                     Escape. -->
                <div class="relative hidden lg:block" ref="accountMenu">
                    <button type="button" class="lab-line-user text-xl py-5" :aria-expanded="accountOpen"
                        aria-haspopup="true" @click.stop="accountOpen = !accountOpen"></button>
                    <div v-if="logged" v-show="accountOpen"
                        class="w-60 absolute top-full ltr:-right-10 rtl:-left-10 z-50 rounded-2xl overflow-hidden shadow-card bg-white"
                        @click="accountOpen = false">
                        <div class="flex items-center gap-3 p-4 border-b border-[#EFF0F6]">
                            <img :src="profile.image" alt="avatar"
                                class="w-11 h-11 rounded-full object-cover flex-shrink-0">
                            <dl class="w-full">
                                <dt class="font-semibold capitalize whitespace-nowrap mb-0.5">
                                    {{ textShortener(profile.name, 20) }}
                                </dt>
                                <dd class="text-sm font-medium whitespace-nowrap text-text" v-if="profile.phone">
                                    <span dir="ltr">{{ profile.country_code }}{{ profile.phone }}</span>
                                </dd>
                            </dl>
                        </div>
                        <nav class="flex flex-col py-2">
                            <router-link
                                v-if="profile.role_id !== enums.roleEnum.CUSTOMER && Object.keys(authDefaultPermission).length > 0"
                                class="flex items-center gap-3 px-4 py-2 transition-all duration-500 hover:bg-gray-100"
                                :to="{ path: '/admin/' + defaultMenu?.url }">
                                <i class="text-sm text-[#A0A3BD]" :class="defaultMenu?.icon"></i>
                                <span class="text-sm font-medium capitalize whitespace-nowrap">
                                    {{ $t('menu.' + defaultMenu?.language) }}
                                </span>
                            </router-link>

                            <router-link
                                class="flex items-center gap-3 px-4 py-2 transition-all duration-500 hover:bg-gray-100"
                                :to="{ name: 'frontend.account.orderHistory' }">
                                <i class="text-sm text-[#A0A3BD] lab-fill-bag"></i>
                                <span class="text-sm font-medium capitalize whitespace-nowrap">
                                    {{ $t('menu.order_history') }}
                                </span>
                            </router-link>

                            <router-link
                                class="flex items-center gap-3 px-4 py-2 transition-all duration-500 hover:bg-gray-100"
                                :to="{ name: 'frontend.account.returnOrders' }">
                                <i class="text-sm text-[#A0A3BD] lab-fill-refresh"></i>
                                <span class="text-sm font-medium capitalize whitespace-nowrap">
                                    {{ $t('menu.return_orders') }}
                                </span>
                            </router-link>

                            <router-link
                                class="flex items-center gap-3 px-4 py-2 transition-all duration-500 hover:bg-gray-100"
                                :to="{ name: 'frontend.account.accountInfo' }">
                                <i class="text-sm text-[#A0A3BD] lab-fill-user"></i>
                                <span class="text-sm font-medium capitalize whitespace-nowrap">
                                    {{ $t('menu.account_info') }}
                                </span>
                            </router-link>

                            <router-link
                                class="flex items-center gap-3 px-4 py-2 transition-all duration-500 hover:bg-gray-100"
                                :to="{ name: 'frontend.account.changePassword' }">
                                <i class="text-sm text-[#A0A3BD] lab-fill-key"></i>
                                <span class="text-sm font-medium capitalize whitespace-nowrap">
                                    {{ $t('menu.change_password') }}
                                </span>
                            </router-link>

                            <router-link
                                class="flex items-center gap-3 px-4 py-2 transition-all duration-500 hover:bg-gray-100"
                                :to="{ name: 'frontend.account.address' }">
                                <i class="text-sm text-[#A0A3BD] lab-fill-location"></i>
                                <span class="text-sm font-medium capitalize whitespace-nowrap">
                                    {{ $t('menu.address') }}
                                </span>
                            </router-link>

                            <button @click.prevent="logout()"
                                class="flex items-center gap-3 px-4 py-2 transition-all duration-500 hover:bg-gray-100">
                                <i class="text-sm text-[#A0A3BD] lab-fill-logout"></i>
                                <span class="text-sm font-medium capitalize whitespace-nowrap">
                                    {{ $t('button.logout') }}
                                </span>
                            </button>
                        </nav>
                    </div>

                    <div v-else v-show="accountOpen"
                        class="w-64 absolute top-full ltr:-right-10 rtl:-left-10 z-50 p-4 rounded-2xl overflow-hidden shadow-card bg-white"
                        @click="accountOpen = false">
                        <router-link
                            class="!text-primary !bg-[#FFF4F1] w-full text-center h-12 leading-12 font-semibold tracking-wide rounded-full whitespace-nowrap"
                            :to="{ name: 'auth.signup' }">
                            {{ $t('button.register_your_account') }}
                        </router-link>
                        <span class="block font-medium uppercase text-center py-3">{{ $t('label.or') }}</span>
                        <router-link
                            class="w-full text-center h-12 leading-12 font-semibold tracking-wide rounded-full whitespace-nowrap text-white bg-primary"
                            :to="{ name: 'auth.login' }">
                            {{ $t('button.login_to_your_account') }}
                        </router-link>
                    </div>
                </div>
                <!-- My Account End -->

                <!-- Card Button Start -->
                <button @click.prevent="openCanvas('cart-canvas')" type="button"
                    class="hidden lg:block flex-shrink-0 relative" :class="cartBump ? 'cart-bump' : ''">
                    <i
                        class="lab-line-bag text-xl w-10 h-10 !leading-10 text-center rounded-full bg-secondary text-white"></i>
                    <span v-if="carts.length > 0"
                        class="absolute top-4 ltr:right-1 rtl:left-1 text-[10px] font-medium h-4 px-1 leading-[14px] text-center rounded-full border border-heading text-white bg-primary">
                        {{ carts.length }}
                    </span>
                </button>
                <!-- Card Button End -->
            </div>
        </div>
    </header>

    <!-- Mobile Search Start -->
    <form @submit.prevent="search" id="search"
        class="w-full  lg:w-auto fixed inset-0 z-30 py-5 px-4 bg-white transition-all duration-500 origin-top scale-y-0">
        <div class="flex items-center justify-between mb-4">
            <router-link :to="{ name: 'frontend.home' }"
                class="router-link-active router-link-exact-active flex-shrink-0">
                <img class="w-28 sm:w-32" :src="setting.theme_logo" alt="logo">
            </router-link>
            <button type="button">
                <i @click.prevent="hideTarget('search', 'search-active')"
                    class="lab-line-circle-cross text-xl text-danger"></i>
            </button>
        </div>
        <div
            class="w-full h-10 rounded-3xl flex items-center gap-2 px-4 mb-4 border border-gray-100 bg-gray-100 transition-all duration-300 focus-within:border-primary focus-within:bg-white">
            <button class="lab-line-search text-lg flex-shrink-0"></button>
            <input id="searchSomething" v-model="searchProduct" @keyup="searchElement" class="w-full h-full"
                type="search" :placeholder="$t('label.search') + '...'">
        </div>
        <div class="lg:hidden h-[calc(100vh_-_140px)] rounded-xl overflow-y-auto p-4 bg-gray-100">
            <ul v-if="searchProductLists.length > 0" id="searchProductLists">
                <li :key="searchProductList.name"
                    class="py-1 hover:px-2 whitespace-nowrap overflow-hidden text-ellipsis rounded-lg transition-all duration-300 hover:bg-white hover:text-primary"
                    @click.prevent="goSearchProduct(searchProductList.slug)"
                    v-for="searchProductList in searchProductLists">{{ searchProductList.name }}</li>
            </ul>
        </div>
    </form>
    <!-- Mobile Search End -->

    <!-- Notification Start -->
    <div id="order-modal" v-if="orderNotificationStatus" ref="orderNotificationModal" class="modal active ff-modal">
        <div class="modal-dialog max-w-[360px] p-6 text-center relative">
            <button @click.prevent="closeOrderNotificationModal('order-modal', 'modal-active')"
                class="modal-close absolute top-4 right-4">
                <!-- Iconly, like every other storefront icon: Font Awesome's
                     stylesheet is admin-only now, so this was the one icon on
                     the shop that would have rendered as nothing. -->
                <i class="lab lab-close-circle-line"></i>
            </button>
            <h3 class="text-[18px] font-semibold leading-8 mb-6">
                {{ orderNotificationMessage }}
                <span class="block">{{ $t('message.please_check_your_order_list') }}</span>
            </h3>
            <router-link :to="{ path: '/admin/' + orderNotification.url }"
                class="db-btn h-[38px] shadow-[0px_6px_10px_rgba(255,_0,_107,_0.24)] bg-primary text-white">
                {{ $t('button.let_me_check') }}
            </router-link>
        </div>
    </div>
    <!-- Notification End -->

</template>

<script>

import { loadLocale } from "../../../i18n";
import statusEnum from "../../../enums/modules/statusEnum";
import { onMounted, ref } from "vue";
import targetService from "../../../services/targetService";
import appService from "../../../services/appService";
import activityEnum from "../../../enums/modules/activityEnum";
import roleEnum from "../../../enums/modules/roleEnum";
import MenuChildrenComponent from "../../frontend/components/MenuChildrenComponent";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import campaignTypeEnum from "../../../enums/modules/campaignTypeEnum";
import forEach from "lodash/forEach";
import axios from 'axios';
import { useCanvas } from "../../../composables/canvas";
import cartBump from "../../../composables/cartBump";


export default {
    name: "FrontendNavbarComponent",
    components: { MenuChildrenComponent },
    mixins: [cartBump],
    setup() {
        const isSticky = ref();
        const { openCanvas } = useCanvas();
        onMounted(() => {
            window.addEventListener('scroll', function () {
                let windowScroll = this.scrollY;
                if (windowScroll > 0) {
                    isSticky.value = true;
                } else {
                    isSticky.value = false;
                }
            })
        })
        return {
            isSticky,
            openCanvas
        }
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
            searchProductLists: [],
            currentRoute: "",
            defaultLanguage: null,
            // Account dropdown open state. Click-driven — see the markup above.
            accountOpen: false,
            enums: {
                activityEnum: activityEnum,
                roleEnum: roleEnum
            },
            languageProps: {
                paginate: 0,
                order_column: "id",
                order_type: "asc",
                status: statusEnum.ACTIVE
            },
            categoryTabStatus: false,
            activeTab: null,
            searchProduct: "",
            orderNotificationStatus: false,
            orderNotificationMessage: "",
            orderNotification: {
                permission: false,
                url: ""
            },
        }
    },
    computed: {
        logged: function () {
            return this.$store.getters.authStatus;
        },
        authDefaultPermission: function () {
            return this.$store.getters.authDefaultPermission;
        },
        profile: function () {
            return this.$store.getters.authInfo;
        },
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        language: function () {
            return this.$store.getters['frontendLanguage/show'];
        },
        languages: function () {
            return this.$store.getters['frontendLanguage/lists'];
        },
        categories: function () {
            return this.$store.getters['frontendProductCategory/trees'];
        },
        /**
         * Extra links promoted into the main nav.
         *
         * Clearance is a Campaign now (Admin -> Promo -> Campaigns), not a
         * Product Section: only campaigns inside their active window come back
         * from the API, so an expired sale drops out of the header on its own
         * with no one having to unpublish anything.
         *
         * Flash campaigns are excluded — /flash-sale already has its own
         * hardcoded link above and renders the running flash campaign, so
         * including them here would show the same sale twice.
         *
         * Wholesale is unchanged: still a Product Section, still hidden until
         * that section exists.
         */
        navSections: function () {
            const campaigns = (this.$store.getters['frontendCampaign/lists'] || [])
                .filter(campaign => campaign.type !== campaignTypeEnum.FLASH)
                .map(campaign => ({
                    id: 'campaign-' + campaign.id,
                    name: campaign.name,
                    slug: campaign.slug,
                    routeName: 'frontend.campaign.products',
                }));

            const sections = this.$store.getters['frontendProductSection/lists'] || [];
            const wholesale = sections.find(section => section.slug === 'wholesale');

            if (wholesale) {
                campaigns.push({
                    id: 'section-' + wholesale.id,
                    name: wholesale.name,
                    slug: wholesale.slug,
                    routeName: 'frontend.productSection.products',
                });
            }

            return campaigns;
        },
        helplineNumber: function () {
            return this.setting?.company_phone || '01709786330';
        },
        /**
         * The Community link, normalised for use in href.
         *
         * A group link is usually pasted straight out of the browser bar, so it
         * often arrives without a scheme. "facebook.com/groups/x" in an href is
         * treated as a relative path and would send the shopper to our own
         * /groups/x, so the scheme is added here when it is missing.
         *
         * Returns null for an empty or whitespace-only setting, which is what
         * hides the header item.
         */
        communityLink: function () {
            const link = (this.setting?.site_community_link || '').trim();

            if (!link) {
                return null;
            }

            const lower = link.toLowerCase();

            if (lower.startsWith('http://') || lower.startsWith('https://')) {
                return link;
            }

            return 'https://' + link;
        },
        wishlists: function () {
            return this.$store.getters['frontendWishlist/lists'];
        },
        carts: function () {
            return this.$store.getters['frontendCart/lists'];
        },
        defaultMenu: function () {
            return this.$store.getters.authDefaultMenu;
        },
    },
    mounted() {
        this.currentRoute = this.$route.path;
        this.loading.isActive = true;
        this.orderPermissionCheck();

        // Close the account dropdown on an outside click or Escape. The
        // trigger stops propagation, so its own click never reaches this.
        document.addEventListener('click', this.closeAccountMenu);
        document.addEventListener('keydown', this.onAccountKeydown);
        this.$store.dispatch('frontendSetting/lists').then(res => {
            this.defaultLanguage = res.data.data.site_default_language;
            const globalState = this.$store.getters['globalState/lists'];
            if (globalState.language_id > 0) {
                this.defaultLanguage = globalState.language_id;
            }

            this.loading.isActive = false;
            this.$store.dispatch('frontendLanguage/lists', this.languageProps).then().catch();
            this.$store.dispatch('frontendLanguage/show', this.defaultLanguage).then(res => {
                loadLocale(res.data.data.code);
                this.$store.dispatch("globalState/init", {
                    language_code: res.data.data.code,
                    display_mode: res.data.data.display_mode
                });
            }).catch();

            window.setTimeout(() => {
                this.$store.dispatch('frontendCart/initOrderType', { order_type: orderTypeEnum.DELIVERY });

                if (this.$store.getters.authStatus && res.data.data.notification_fcm_api_key && res.data.data.notification_fcm_auth_domain && res.data.data.notification_fcm_project_id && res.data.data.notification_fcm_storage_bucket && res.data.data.notification_fcm_messaging_sender_id && res.data.data.notification_fcm_app_id && res.data.data.notification_fcm_measurement_id) {
                    // Loaded on demand: push notifications are optional, only
                    // apply to logged-in users, and this block is already 3s
                    // behind first paint. A static import put the whole Firebase
                    // SDK on the critical path of every storefront page instead.
                    Promise.all([
                        import("firebase/app"),
                        import("firebase/messaging")
                    ]).then(([{ initializeApp }, { getMessaging, getToken, onMessage }]) => {
                        initializeApp({
                            apiKey: res.data.data.notification_fcm_api_key,
                            authDomain: res.data.data.notification_fcm_auth_domain,
                            projectId: res.data.data.notification_fcm_project_id,
                            storageBucket: res.data.data.notification_fcm_storage_bucket,
                            messagingSenderId: res.data.data.notification_fcm_messaging_sender_id,
                            appId: res.data.data.notification_fcm_app_id,
                            measurementId: res.data.data.notification_fcm_measurement_id
                        });
                        const messaging = getMessaging();

                        Notification.requestPermission().then((permission) => {
                            if (permission === 'granted') {
                                getToken(messaging, { vapidKey: res.data.data.notification_fcm_public_vapid_key }).then((currentToken) => {
                                    if (currentToken) {
                                        axios.post('/frontend/device-token/web', { token: currentToken }).then().catch((error) => {
                                            if (error.response.data.message === 'Unauthenticated.') {
                                                this.$store.dispatch('loginDataReset');
                                            }
                                        });
                                    }
                                }).catch();
                            }
                        });

                        onMessage(messaging, (payload) => {
                            const notificationTitle = payload.notification.title;
                            const notificationOptions = {
                                body: payload.notification.body,
                                icon: '/images/required/firebase-logo.png'
                            };
                            new Notification(notificationTitle, notificationOptions);

                            if (payload.data.topicName === 'new-order-found' && this.orderNotification.permission) {
                                this.orderNotificationStatus = true;
                                this.orderNotificationMessage = payload.notification.body;
                                const audio = new Audio(res.data.data.notification_audio);
                                audio.play();
                            }
                        });
                    }).catch();
                }
            }, 3000);

            this.loading.isActive = false;
        }).catch((err) => {
            this.loading.isActive = false;
        });

        this.loading.isActive = true;
        this.$store.dispatch('frontendProductCategory/trees').then(res => {
            this.loading.isActive = false;
        }).catch((err) => {
            this.loading.isActive = false;
        });

        // Both feed navSections. Only fetched when the store is empty — Vuex
        // state survives SPA navigation, so this costs one request per full
        // page load, not one per route change. Fire-and-forget: the nav renders
        // without them and the extra links appear when they land.
        if ((this.$store.getters['frontendProductSection/lists'] || []).length === 0) {
            this.$store.dispatch('frontendProductSection/lists', { paginate: 0 }).catch(() => {});
        }

        // Running campaigns. The flash-sale page reads the same store, so a
        // customer arriving anywhere on the site has it primed.
        if ((this.$store.getters['frontendCampaign/lists'] || []).length === 0) {
            this.$store.dispatch('frontendCampaign/lists').catch(() => {});
        }

        if (this.logged) {
            this.loading.isActive = true;
            this.$store.dispatch("frontendWishlist/lists").then((res) => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        }

    },
    beforeUnmount() {
        // Listeners are on document, so they outlive the component unless
        // removed — every navigation would otherwise leak another pair.
        document.removeEventListener('click', this.closeAccountMenu);
        document.removeEventListener('keydown', this.onAccountKeydown);
    },
    methods: {
        closeAccountMenu: function (event) {
            if (!this.accountOpen) {
                return;
            }
            // Ignore clicks that landed inside the menu itself.
            if (this.$refs.accountMenu && this.$refs.accountMenu.contains(event.target)) {
                return;
            }
            this.accountOpen = false;
        },
        onAccountKeydown: function (event) {
            if (event.key === 'Escape') {
                this.accountOpen = false;
            }
        },
        showTarget: function (id, cClass) {
            targetService.showTarget(id, cClass);
        },
        hideTarget: function (id, cClass) {
            targetService.hideTarget(id, cClass);
        },
        textShortener: function (text, number = 30) {
            return appService.textShortener(text, number);
        },
        checkIsPathAndRoutePathSame(path) {
            if (this.currentRoute === path) {
                return true;
            }
        },
        // navSections now mixes two route types, so the highlight can no
        // longer assume the /product-section/ prefix.
        isNavSectionActive: function (section) {
            const prefix = section.routeName === 'frontend.campaign.products'
                ? '/campaign/'
                : '/product-section/';

            return this.currentRoute === prefix + section.slug;
        },
        changeLanguage: function (id, code, mode) {
            this.defaultLanguage = id;
            this.$store.dispatch("globalState/set", {
                language_id: id,
                language_code: code,
                display_mode: mode
            }).then(res => {
                this.$store.dispatch('frontendLanguage/show', id).then(res => {
                    loadLocale(res.data.data.code);
                }).catch();
            }).catch();
        },
        logout: function () {
            this.$store.dispatch("logout").then(res => {
                this.$store.dispatch("frontendWishlist/reset");
                this.$router.push({ name: "frontend.home" });
            }).catch();
        },
        search: function () {
            if (typeof this.searchProduct !== "undefined" && this.searchProduct !== "") {
                this.$router.push({ name: "frontend.product", query: { name: this.searchProduct } });
                this.searchProduct = "";
                this.hideTarget('search', 'search-active')
            }
        },
        orderPermissionCheck: function () {
            const permissions = this.$store.getters.authPermission;
            if (permissions.length > 0) {
                forEach(permissions, (permission) => {
                    if (permission.name === 'online-orders') {
                        if (permission.access === true) {
                            this.orderNotification.permission = true;
                            this.orderNotification.url = permission.url;
                        }
                    }
                });
            }
        },
        closeOrderNotificationModal: function (id, cClass) {
            targetService.hideTarget(id, cClass);
            this.orderNotificationStatus = false;
        },
        searchElement: function () {
            if (this.searchProduct && this.searchProduct.length > 2) {
                let url = `frontend/product`;
                url = url + appService.requestHandler({ name: this.searchProduct });
                axios.get(url).then((res) => {
                    this.searchProductLists = res.data.data;
                }).catch();
            } else {
                this.searchProductLists = [];
            }
        },
        goSearchProduct: function (slug) {
            targetService.hideTarget('search', 'search-active');
            this.$router.push({ name: 'frontend.product.details', params: { slug: slug } })
        },
        resetSearch: function(){
            this.searchProduct = "";
        }
    },
    watch: {
        $route(to, from) {
            this.currentRoute = to.path;
            // Navigating away must dismiss the menu, or it stays pinned open
            // over the new page after choosing Log In / Register.
            this.accountOpen = false;
        },
    }
}
</script>
