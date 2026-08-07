<template>
    <LoadingComponent :props="loading" />

    <footer class="site-footer mobile:hidden">
        <div class="container">
            <!-- Brand + newsletter band. Splitting these off the link grid is
                 what stops the columns fighting for width — the old layout put
                 seven .col-2 blocks (117% of one row) into a single flex-wrap,
                 so "Our Stores" was pushed onto a line of its own. -->
            <div class="flex flex-wrap items-center justify-between gap-8 py-12">
                <router-link :to="{ name: 'frontend.home' }" class="flex-shrink-0">
                    <img class="w-32 h-auto object-contain" :src="footerLogo" @error="onLogoError" alt="Suglow">
                </router-link>

                <form @submit.prevent="saveSubscription" class="w-full max-w-sm">
                    <label class="footer-heading block">
                        {{ $t('message.subscribe_to_our_newsletter') }}
                    </label>
                    <div class="flex w-full h-12 rounded-full p-1 bg-white/95 ring-1 ring-white/15">
                        <input type="email" v-model="subscriptionProps.post.email"
                            :placeholder="$t('label.your_email_address')"
                            class="w-full h-full pl-5 pr-2 bg-transparent rounded-l-full text-sm">
                        <button type="submit"
                            class="text-sm font-semibold capitalize flex-shrink-0 px-6 h-full rounded-full bg-primary text-white whitespace-nowrap transition-all duration-300 hover:brightness-110">
                            {{ $t('button.subscribe') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- auto-fit rather than a fixed column count: Top Categories and
                 Help are admin-driven and hidden when empty, so the number of
                 tracks varies. auto-fit redistributes the free space instead of
                 leaving a hole or overflowing onto a second row. -->
            <div class="footer-grid border-t border-white/10 py-12">
                <div v-if="featuredCategories.length > 0">
                    <h4 class="footer-heading">{{ $t('label.top_categories') }}</h4>
                    <nav class="flex flex-col gap-3.5">
                        <router-link v-for="category in featuredCategories" :key="category.id" class="footer-link"
                            :to="{ name: 'frontend.productCategory', params: { slug: category.slug } }">
                            {{ category.name }}
                        </router-link>
                    </nav>
                </div>

                <div>
                    <h4 class="footer-heading">Quick Links</h4>
                    <nav class="flex flex-col gap-3.5">
                        <!-- Blog sits in Quick Links so every page links to it.
                             Internal links from the footer are what let Google
                             discover and keep re-crawling the articles. -->
                        <router-link class="footer-link" :to="{ name: 'frontend.blog' }">
                            {{ $t('label.blog') }}
                        </router-link>
                        <router-link v-for="supportPage in supportPages" :key="supportPage.id" class="footer-link"
                            :to="{ name: 'frontend.page', params: { slug: supportPage.slug } }">
                            {{ supportPage.title }}
                        </router-link>
                    </nav>
                </div>

                <div v-if="legalPages.length > 0">
                    <h4 class="footer-heading">{{ $t('label.legal') }}</h4>
                    <nav class="flex flex-col gap-3.5">
                        <router-link v-for="legalPage in legalPages" :key="legalPage.id" class="footer-link"
                            :to="{ name: 'frontend.page', params: { slug: legalPage.slug } }">
                            {{ legalPage.title }}
                        </router-link>
                    </nav>
                </div>

                <div v-if="helpPages.length > 0">
                    <h4 class="footer-heading">{{ $t('label.help') }}</h4>
                    <nav class="flex flex-col gap-3.5">
                        <router-link v-for="helpPage in helpPages" :key="helpPage.id" class="footer-link"
                            :to="{ name: 'frontend.page', params: { slug: helpPage.slug } }">
                            {{ helpPage.title }}
                        </router-link>
                    </nav>
                </div>

                <!-- Offer links. Flash Sale is a real route; Clearance Sale and
                     Wholesale render only when those Product Sections exist, so
                     this column never shows a dead link. -->
                <div>
                    <h4 class="footer-heading">{{ $t('label.offer_link') }}</h4>
                    <nav class="flex flex-col gap-3.5">
                        <router-link class="footer-link" :to="{ name: 'frontend.offers' }">
                            {{ $t('label.offers') }}
                        </router-link>
                        <router-link class="footer-link" :to="{ name: 'frontend.flashSale.products' }">
                            {{ $t('label.flash_sale') }}
                        </router-link>
                        <router-link v-for="section in navSections" :key="section.id" class="footer-link"
                            :to="{ name: 'frontend.productSection.products', params: { slug: section.slug } }">
                            {{ section.name }}
                        </router-link>
                    </nav>
                </div>

                <!-- Our stores. Physical outlets, which is also what the Store
                     schema on the homepage declares — the two must agree. This
                     column gets two tracks so street lines break at the <br>
                     rather than mid-name. -->
                <div class="footer-stores">
                    <h4 class="footer-heading">{{ $t('label.our_stores') }}</h4>
                    <div class="flex flex-col gap-4">
                        <p class="text-[13px] text-white/60 leading-relaxed">
                            Shop 52, Level 1<br>RAMC Shopping Complex, Rangpur
                        </p>
                        <p class="text-[13px] text-white/60 leading-relaxed">
                            Prime Medical College Gate<br>Badarganj Road, Rangpur
                        </p>
                        <a :href="'tel:' + helplineNumber" class="footer-helpline">
                            <i class="lab-line-call text-base"></i>
                            <span dir="ltr">{{ helplineNumber }}</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="border-t border-white/10">
            <div class="container flex flex-wrap items-center justify-between gap-4 pt-5 pb-24 lg:py-5">
                <p class="text-xs text-white/45">{{ setting.site_copyright }}</p>

                <nav v-if="setting.social_media_facebook || setting.social_media_twitter || setting.social_media_instagram || setting.social_media_youtube"
                    class="flex flex-wrap items-center gap-3">
                    <a v-if="setting.social_media_facebook" target="_blank" :href="setting.social_media_facebook"
                        aria-label="Facebook" class="lab-fill-facebook footer-social"></a>
                    <a v-if="setting.social_media_twitter" target="_blank" :href="setting.social_media_twitter"
                        aria-label="X" class="lab-fill-x footer-social"></a>
                    <a v-if="setting.social_media_instagram" target="_blank" :href="setting.social_media_instagram"
                        aria-label="Instagram" class="lab-fill-instagram footer-social"></a>
                    <a v-if="setting.social_media_youtube" target="_blank" :href="setting.social_media_youtube"
                        aria-label="YouTube" class="lab-fill-youtube footer-social"></a>
                </nav>
            </div>
        </div>
    </footer>
</template>


<script>
import statusEnum from "../../../enums/modules/statusEnum";
import axios from "axios";
import alertService from "../../../services/alertService";
import LoadingComponent from "../../frontend/components/LoadingComponent";
import menuSectionEnum from "../../../enums/modules/menuSectionEnum";
import _ from "lodash";

export default {
    name: "FrontendFooterComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },
            legalPages: [],
            supportPages: [],
            helpPages: [],
            featuredCategories: [],
            // theme_footer_logo is a separate upload from the header logo and is
            // routinely left unset — which rendered a broken-image icon in the
            // corner of every page. Flipped by @error, see onLogoError().
            logoFailed: false,
            enums: {
                statusEnum: statusEnum,
                menuSectionEnum: menuSectionEnum
            },
            subscriptionProps: {
                post: {
                    email: ""
                }
            },
            errors: {},
        }
    },
    computed: {
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        footerLogo: function () {
            if (this.logoFailed) {
                return this.setting?.theme_logo;
            }

            return this.setting?.theme_footer_logo || this.setting?.theme_logo;
        },
        helplineNumber: function () {
            return this.setting?.company_phone || '01709786330';
        },
        // Same promoted sections as the header; reads the store the navbar
        // already fills, so the footer adds no request of its own.
        navSections: function () {
            const promoted = ['clearance-sale', 'wholesale'];
            const sections = this.$store.getters['frontendProductSection/lists'] || [];

            return promoted
                .map(slug => sections.find(section => section.slug === slug))
                .filter(Boolean);
        }
    },
    mounted() {
        this.loading.isActive = true;

        // Fetch pages
        this.$store.dispatch("frontendPage/lists", {
            paginate: 0,
            order_column: "id",
            order_type: "asc",
            status: this.enums.statusEnum.ACTIVE
        }).then(res => {
            if (res.data.data.length > 0) {
                _.forEach(res.data.data, (page) => {
                    if (page.menu_section_id === this.enums.menuSectionEnum.LEGAL) {
                        this.legalPages.push(page);
                    } else if (page.menu_section_id === this.enums.menuSectionEnum.HELP) {
                        this.helpPages.push(page);
                    } else {
                        this.supportPages.push(page);
                    }
                });
            }
        }).catch((err) => {
            console.error(err);
        });

        // Featured categories. This used to dispatch productCategory/lists,
        // which is the admin module — it calls admin/setting/product-category,
        // a route gated by a permission no customer has. Every storefront page
        // renders this footer, so every visitor got a 403 and the list was
        // always empty. The frontend route runs the same service and accepts
        // the same is_featured filter.
        //
        // Called directly rather than through frontendProductCategory/lists
        // because that module's state is shared with the homepage category
        // section, which wants every category, not just the featured ones.
        axios.get("frontend/product-category", {
            params: {
                paginate: 0,
                order_column: "id",
                order_type: "asc",
                status: this.enums.statusEnum.ACTIVE,
                is_featured: 1
            }
        }).then(res => {
            if (res.data.data && res.data.data.length > 0) {
                this.featuredCategories = res.data.data;
            }
            this.loading.isActive = false;
        }).catch((err) => {
            this.loading.isActive = false;
            console.error(err);
        });
    },
    methods: {
        // Fall back to the header logo once, rather than re-firing @error in a
        // loop when that one is missing too — footerLogo returns theme_logo
        // after the flip, and if it is also broken the alt text stands in.
        onLogoError: function () {
            this.logoFailed = true;
        },
        saveSubscription: function () {
            try {
                const url = '/frontend/subscriber';
                this.loading.isActive = true;
                axios.post(url, this.subscriptionProps.post).then(res => {
                    this.loading.isActive = false;
                    this.subscriptionProps.post.email = "";
                    this.errors = {};
                    alertService.success(this.$t("message.subscribe"));
                }).catch((err) => {
                    this.loading.isActive = false;
                    alertService.error(err.response.data.errors.email[0]);
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        }
    }
}
</script>
