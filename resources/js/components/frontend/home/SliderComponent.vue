<template>
    <LoadingComponent :props="loading" />
    <section v-reveal="'fade'" class="mb-4 sm:mb-20">
        <div class="container">
            <Swiper
                v-if="sliders.length > 0"
                dir="rtl"
                :slides-per-view="1"
                :speed="1000"
                :loop="true"
                :navigation="true"
                :pagination="{ clickable: true }"
                :autoplay="{ delay: 2500 }"
                :modules="modules"
                class="banner-swiper"
            >
                <SwiperSlide v-for="(slider, index) in sliders">
                    <div class="relative overflow-hidden rounded-2xl sm:rounded-3xl aspect-[4/3] sm:aspect-[3/1] isolate">
                        <a v-if="safeLink(slider.link)" :href="safeLink(slider.link)" class="block w-full h-full">
                            <img class="w-full h-full object-cover" :src="slider.image" :alt="slider.title || 'Suglow'"
                                :loading="index === 0 ? 'eager' : 'lazy'"
                                :fetchpriority="index === 0 ? 'high' : 'auto'" decoding="async">
                        </a>
                        <img v-else class="w-full h-full object-cover" :src="slider.image" :alt="slider.title || 'Suglow'"
                            :loading="index === 0 ? 'eager' : 'lazy'"
                            :fetchpriority="index === 0 ? 'high' : 'auto'" decoding="async">

                        <div v-if="slider.title"
                            class="absolute inset-0 z-10 flex flex-col items-start justify-end gap-2 sm:gap-3 p-5 sm:p-10 bg-gradient-to-t from-black/60 via-black/20 to-transparent pointer-events-none">
                            <h2 class="max-w-xl text-lg sm:text-4xl font-bold text-white capitalize leading-snug sm:leading-11 line-clamp-2">
                                {{ slider.title }}
                            </h2>
                            <p v-if="slider.description"
                                class="hidden sm:block max-w-lg text-sm font-medium text-white/80 line-clamp-2">
                                {{ slider.description }}
                            </p>
                            <a v-if="safeLink(slider.link)" :href="safeLink(slider.link)"
                                class="pointer-events-auto mt-1 inline-flex items-center gap-2 py-2 px-5 sm:py-2.5 sm:px-7 rounded-full bg-primary text-white text-sm font-bold capitalize shadow-btn-primary transition-all duration-300 hover:bg-primary/90 active:scale-95">
                                {{ $t('label.shop_now') }}
                                <i class="lab-line-arrow-right text-xs rtl:rotate-180"></i>
                            </a>
                        </div>
                    </div>
                </SwiperSlide>
            </Swiper>
        </div>
    </section>
</template>

<script>
import 'swiper/css';
import {Navigation, Pagination, Autoplay} from 'swiper/modules';
import {Swiper, SwiperSlide} from 'swiper/vue';
import statusEnum from "../../../enums/modules/statusEnum";
import sliderPositionEnum from "../../../enums/modules/sliderPositionEnum";
import LoadingComponent from "../components/LoadingComponent";
import linkService from "../../../services/linkService";

export default {
    name: "SliderComponent",
    components: {
        Swiper,
        SwiperSlide,
        LoadingComponent
    },
    setup() {
        return {
            modules: [Navigation, Pagination, Autoplay],
        }
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            sliderProps: {
                search: {
                    paginate: 0,
                    order_column: 'id',
                    order_type: 'desc',
                    status: statusEnum.ACTIVE,
                    position: sliderPositionEnum.HERO
                }
            }
        }
    },
    computed: {
        sliders: function () {
            return this.$store.getters['frontendSlider/lists'];
        }
    },
    methods: {
        /**
         * The slide's link, or null when it is not safe to render.
         *
         * Same resolver the banner block uses, so a "javascript:" scheme saved
         * before App\Rules\SafeLink existed cannot execute here either.
         */
        safeLink: function (link) {
            const target = linkService.resolve(link);

            if (target.type === 'internal') {
                return target.path;
            }

            return target.type === 'external' ? target.href : null;
        }
    },
    mounted() {
        this.loading.isActive = true;
        this.$store.dispatch("frontendSlider/lists", this.sliderProps.search).then((res) => {
            this.loading.isActive = false;
        }).catch((err) => {
            this.loading.isActive = false;
        });
    }
}
</script>
