<template>
    <LoadingComponent :props="loading" />

    <footer class="pt-12 bg-secondary mobile:hidden">
        <div class="container">
            <!-- First Row: Logo and Menu Columns -->
            <div class="row mb-5 flex flex-wrap justify-around">
                <div class="col-12 sm:col-6 md:col-3 lg:col-2 mb-6 sm:mb-0">
                    <router-link :to="{ name: 'frontend.home' }">
                        <img class="w-36" :src="setting.theme_footer_logo" alt="logo">
                    </router-link>
                </div>
                <div class="col-6 sm:col-6 md:col-3 lg:col-2 mb-4 sm:mb-0">
                    <h4 class="text-[22px] font-semibold capitalize mb-6 text-white">{{ $t('label.top_categories') }}
                    </h4>
                    <nav v-if="featuredCategories.length > 0" class="flex flex-col gap-4">
                        <router-link v-for="category in featuredCategories" :key="category.id"
                            class="w-fit text-sm font-medium capitalize text-white transition-all duration-300 hover:text-primary"
                            :to="{ name: 'frontend.product', query: { category: category.slug } }">
                            {{ category.name }}
                        </router-link>
                    </nav>
                </div>
                <div class="col-6 sm:col-6 md:col-3 lg:col-2 mb-4 sm:mb-0">
                    <h4 class="text-[22px] font-semibold capitalize mb-6 text-white">{{ $t('label.support') }}
                    </h4>
                    <nav v-if="supportPages.length > 0" class="flex flex-col gap-4">
                        <router-link v-for="supportPage in supportPages" :key="supportPage.id"
                            class="w-fit text-sm font-medium capitalize text-white transition-all duration-300 hover:text-primary"
                            :to="{ name: 'frontend.page', params: { slug: supportPage.slug } }">
                            {{ supportPage.title }}
                        </router-link>
                    </nav>
                </div>
                <div class="col-6 sm:col-6 md:col-3 lg:col-2 mb-4 sm:mb-0">
                    <h4 class="text-[22px] font-semibold capitalize mb-6 text-white">{{ $t('label.legal') }}
                    </h4>
                    <nav v-if="legalPages.length > 0" class="flex flex-col gap-4">
                        <router-link v-for="legalPage in legalPages" :key="legalPage.id"
                            class="w-fit text-sm font-medium capitalize text-white transition-all duration-300 hover:text-primary"
                            :to="{ name: 'frontend.page', params: { slug: legalPage.slug } }">
                            {{ legalPage.title }}
                        </router-link>
                    </nav>
                </div>
                <div class="col-6 sm:col-6 md:col-3 lg:col-2">
                    <h4 class="text-[22px] font-semibold capitalize mb-6 text-white">{{ $t('label.help') }}</h4>
                    <nav v-if="helpPages.length > 0" class="flex flex-col gap-4">
                        <router-link v-for="helpPage in helpPages" :key="helpPage.id"
                            class="w-fit text-sm font-medium capitalize text-white transition-all duration-300 hover:text-primary"
                            :to="{ name: 'frontend.page', params: { slug: helpPage.slug } }">
                            {{ helpPage.title }}
                        </router-link>
                    </nav>
                </div>
            </div>

            <!-- Second Row: Newsletter (Centered) -->
            <div class="row border-t border-white/10 pt-8">
                <div class="col-12 flex justify-center">
                    <div class="w-full max-w-md">
                        <form @submit.prevent="saveSubscription" class="block">
                            <label class="mb-3 font-medium text-white text-center block">
                                {{ $t('message.subscribe_to_our_newsletter') }}
                            </label>
                            <div class="flex w-full h-12 rounded-3xl p-1 bg-white">
                                <input type="email" v-model="subscriptionProps.post.email"
                                    :placeholder="$t('label.your_email_address')" class="w-full h-full pl-4 pr-2 rounded-l-3xl">
                                <button type="submit"
                                    class="text-sm font-semibold capitalize flex-shrink-0 px-6 h-full rounded-3xl bg-primary text-white">
                                    {{ $t('button.subscribe') }}
                                </button>
                            </div>
                        </form>
                        <nav v-if="setting.social_media_facebook || setting.social_media_twitter || setting.social_media_instagram || setting.social_media_youtube"
                        class="flex flex-wrap justify-center items-center gap-6 mt-2">
                        <a v-if="setting.social_media_facebook" target="_blank"
                            :href="setting.social_media_facebook"
                            class="lab-fill-facebook w-7 h-7 !leading-7 text-center rounded-full text-sm text-secondary bg-white transition-all duration-300 hover:text-white hover:bg-primary"></a>
                        <a v-if="setting.social_media_twitter" target="_blank" :href="setting.social_media_twitter"
                            class="lab-fill-x w-7 h-7 !leading-7 text-center rounded-full text-sm text-secondary bg-white transition-all duration-300 hover:text-white hover:bg-primary"></a>
                        <a v-if="setting.social_media_instagram" target="_blank"
                            :href="setting.social_media_instagram"
                            class="lab-fill-instagram w-7 h-7 !leading-7 text-center rounded-full text-sm text-secondary bg-white transition-all duration-300 hover:text-white hover:bg-primary"></a>
                        <a v-if="setting.social_media_youtube" target="_blank" :href="setting.social_media_youtube"
                            class="lab-fill-youtube w-7 h-7 !leading-7 text-center rounded-full text-sm text-secondary bg-white transition-all duration-300 hover:text-white hover:bg-primary"></a>
                    </nav>
                    </div>
                </div>
            </div>
        </div>
        <div class="pt-4 pb-24 lg:py-4 mt-8 text-center border-t border-white/5">
            <p class="text-xs font-medium text-white">{{ setting.site_copyright }}</p>
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

        // Fetch featured categories
        this.$store.dispatch("productCategory/lists", {
            paginate: 0,
            order_column: "id",
            order_type: "asc",
            status: this.enums.statusEnum.ACTIVE,
            is_featured: 1
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
