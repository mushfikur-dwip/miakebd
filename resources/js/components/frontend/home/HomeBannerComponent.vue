<template>
    <!-- The banner block under the hero: a row of small tiles, then the wide
         strip. Both come from sliders, filtered by position, so they reuse the
         slider admin instead of needing a module of their own. -->
    <section v-if="grid.length > 0 || wide.length > 0" class="mb-10 sm:mb-20">
        <div class="container">
            <div v-if="grid.length > 0" class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
                <BannerLinkComponent v-for="banner in grid" :key="banner.id" :link="banner.link">
                    <img class="w-full h-full object-cover rounded-2xl aspect-[540/336]" :src="banner.tile"
                        :alt="banner.title" loading="lazy" decoding="async">
                </BannerLinkComponent>
            </div>

            <div v-if="wide.length > 0" class="flex flex-col gap-4 sm:gap-6"
                :class="grid.length > 0 ? 'mt-4 sm:mt-6' : ''">
                <BannerLinkComponent v-for="banner in wide" :key="banner.id" :link="banner.link">
                    <img class="w-full rounded-2xl" :src="banner.image" :alt="banner.title" loading="lazy"
                        decoding="async">
                </BannerLinkComponent>
            </div>
        </div>
    </section>
</template>

<script>
import statusEnum from "../../../enums/modules/statusEnum";
import sliderPositionEnum from "../../../enums/modules/sliderPositionEnum";
import BannerLinkComponent from "./BannerLinkComponent.vue";

export default {
    name: "HomeBannerComponent",
    components: {
        BannerLinkComponent
    },
    data() {
        return {
            grid: [],
            wide: [],
        }
    },
    mounted() {
        this.fetch(sliderPositionEnum.GRID).then(rows => this.grid = rows);
        this.fetch(sliderPositionEnum.WIDE).then(rows => this.wide = rows);
    },
    methods: {
        /**
         * vuex: false is the point here - the hero carousel reads
         * frontendSlider/lists out of the store, and committing a filtered
         * response would empty it. These rows stay local to this component.
         */
        fetch: function (position) {
            return this.$store.dispatch("frontendSlider/lists", {
                paginate: 0,
                order_column: "id",
                order_type: "asc",
                status: statusEnum.ACTIVE,
                position: position,
                vuex: false
            }).then(res => res.data.data).catch(() => []);
        }
    }
}
</script>
