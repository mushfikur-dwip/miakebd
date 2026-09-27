<template>
    <LoadingComponent :props="loading" />

    <section v-reveal class="mb-10 sm:mb-20" v-if="brands.length > 1">
        <div class="container">
            <div class="flex items-center justify-between gap-4 mb-5 sm:mb-7">
                <h2 class="capitalize text-2xl sm:text-4xl font-bold">
                    {{ $t('label.shop_by_brand') }}
                </h2>
                <!-- There is no brands-only page; the shop is where brand
                     filtering actually lives, so "show more" goes there. -->
                <router-link v-if="brands.length > visibleLimit" :to="{ name: 'frontend.product' }"
                    class="py-2 px-4 text-sm sm:py-3 sm:px-6 rounded-3xl capitalize sm:text-base font-semibold whitespace-nowrap bg-primary-slate text-primary transition-all duration-300 hover:bg-primary hover:text-white">
                    {{ $t('label.show_more') }}
                </router-link>
            </div>

            <!-- A grid, not a carousel: with a few hundred brands a carousel
                 hides all but six of them behind an arrow nobody clicks. The
                 list is capped instead, and the rest live behind Show More. -->
            <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-3 sm:gap-4">
                <!-- The brand's own page, which Google can index; the
                     ?brand= filter only for a brand saved without a slug. -->
                <router-link v-for="brand in visibleBrands" :key="brand.id"
                    :to="brand.slug ? { name: 'frontend.brand', params: { brandSlug: brand.slug } } : { name: 'frontend.product', query: { brand: brand.id } }"
                    class="group rounded-2xl border border-gray-100 bg-white shadow-xs transition-all duration-300 hover:border-primary hover:shadow-card">
                    <figure class="w-full h-20 sm:h-24 flex items-center justify-center p-3">
                        <!-- A brand without a logo gets the server's grey "No Image
                             Available"; its initials read far better. -->
                        <span v-if="!hasLogo(brand)" aria-hidden="true"
                            class="w-14 h-14 sm:w-16 sm:h-16 rounded-full flex items-center justify-center text-lg sm:text-xl font-bold uppercase tracking-wide bg-primary-slate text-primary transition-all duration-300 group-hover:bg-primary group-hover:text-white">
                            {{ initials(brand.name) }}
                        </span>
                        <img v-else :src="brand.cover" :alt="brand.name" loading="lazy" decoding="async"
                            class="max-h-full max-w-full object-contain">
                    </figure>
                    <span
                        class="block text-xs sm:text-sm font-medium capitalize text-center px-2 pb-3 truncate group-hover:text-primary">
                        {{ brand.name }}
                    </span>
                </router-link>
            </div>
        </div>
    </section>
</template>

<script>
import statusEnum from "../../../enums/modules/statusEnum";
import LoadingComponent from "../components/LoadingComponent";

export default {
    name: "ProductBrandComponent",
    components: {
        LoadingComponent
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
            // Two full rows at the widest breakpoint.
            visibleLimit: 16,
        }
    },
    computed: {
        brands: function () {
            return this.$store.getters["frontendProductBrand/lists"];
        },
        visibleBrands: function () {
            return this.brands.slice(0, this.visibleLimit);
        },
    },
    methods: {
        hasLogo: function (brand) {
            return !!brand.cover && !brand.cover.includes("/images/default/");
        },
        // "Dot & Key" -> "DK", "CeraVe" -> "CE", "L.A. GIRL" -> "LG".
        initials: function (name) {
            const words = String(name || "").replace(/[^\p{L}\p{N}\s]/gu, " ").trim().split(/\s+/).filter(Boolean);
            if (words.length === 0) {
                return "?";
            }
            if (words.length === 1) {
                return words[0].slice(0, 2);
            }
            return words[0][0] + words[1][0];
        },
    },
    mounted() {
        this.loading.isActive = true;
        this.$store.dispatch("frontendProductBrand/lists", {
            paginate: 0,
            order_column: "id",
            order_type: "asc",
            status: statusEnum.ACTIVE,
        }).then(res => {
            this.loading.isActive = false;
        }).catch((err) => {
            this.loading.isActive = false;
        });
    }
}
</script>
