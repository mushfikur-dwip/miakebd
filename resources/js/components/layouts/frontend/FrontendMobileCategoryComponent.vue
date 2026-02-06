<template>
    <div id="mobile-category-canvas"
        class="fixed inset-0 z-50 bg-black/50 duration-300 transition-all invisible opacity-0">
        <div class="w-full h-dvh bg-white flex flex-col">
            
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Shop</h2>
                <button type="button" @click.prevent="hideTarget('mobile-category-canvas', 'canvas-active')"
                    class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100">
                    <i class="lab-line-circle-cross text-gray-600"></i>
                </button>
            </div>

            <!-- Quick Actions -->
            <div class="px-4 py-4 bg-gray-50">
                <div class="grid grid-cols-2 gap-3">
                    <router-link v-for="action in quickActions" 
                                :key="action.name"
                                :to="action.route"
                                @click="hideTarget('mobile-category-canvas', 'canvas-active')"
                                class="flex items-center justify-center gap-2 p-3 bg-white rounded-lg text-sm font-medium transition-all duration-200 hover:bg-gray-50 active:scale-95 border border-gray-100">
                        <!-- <i :class="action.icon"></i> -->
                        <span>{{ action.name }}</span>
                    </router-link>
                </div>
            </div>

            <!-- Categories Grid -->
            <div class="flex-1 overflow-y-auto p-4">
                <h3 class="text-sm font-medium text-gray-700 mb-3">Categories</h3>
                <div v-if="categories.length > 0" class="grid grid-cols-3 gap-4">
                    <router-link v-for="category in limitedCategories" 
                                :key="category.id"
                                :to="{ name: 'frontend.product', query: { category: category.slug } }"
                                @click="hideTarget('mobile-category-canvas', 'canvas-active')"
                                class="flex flex-col items-center p-3 bg-white rounded-lg border border-gray-100 transition-all duration-200 hover:shadow-md active:scale-95">
                        <div class="w-12 h-12 mb-2 rounded-full bg-primary/10 flex items-center justify-center">
                            <img v-if="category.image" 
                                :src="category.image" 
                                :alt="category.name" 
                                class="w-8 h-8 rounded-full object-cover">
                            <i v-else 
                            :class="getCategoryIcon(category.name)" 
                            class="text-lg text-primary"></i>
                        </div>
                        <span class="text-xs text-center text-gray-700 capitalize leading-tight">
                            {{ category.name }}
                        </span>
                    </router-link>
                </div>
                
                <!-- View All Button -->
                <div class="mt-6 text-center" v-if="categories.length > 12 && !showAll">
                    <button @click="showAllCategories" 
                            class="px-6 py-2 text-sm font-medium text-primary border border-primary rounded-lg hover:bg-primary/5">
                        View All Categories
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import targetService from "../../../services/targetService";

export default {
    name: "FrontendMobileCategoryComponent",
    data() {
        return {
            showAll: false,
            quickActions: [
                { 
                    name: 'Best Selling', 
                    icon: 'lab-line-star text-yellow-500', 
                    route: { name: 'frontend.product', query: { sort: 'best-selling' } } 
                },
                { 
                    name: 'New Arrivals', 
                    icon: 'lab-line-sparkles text-green-500', 
                    route: { name: 'frontend.product', query: { sort: 'new' } } 
                },
                { 
                    name: 'My Wishlist', 
                    icon: 'lab-line-heart text-red-500', 
                    route: { name: 'frontend.wishlist' } 
                },
                { 
                    name: 'Special Offers', 
                    icon: 'lab-line-tag text-blue-500', 
                    route: { name: 'frontend.product', query: { discount: 'true' } } 
                }
            ]
        };
    },
    computed: {
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        categories: function () {
            return this.$store.getters['frontendProductCategory/trees'];
        },
        limitedCategories: function () {
            return this.showAll ? this.categories : this.categories.slice(0, 12);
        }
    },
    methods: {
        showTarget: function (id, cClass) {
            targetService.showTarget(id, cClass);
        },
        hideTarget: function (id, cClass) {
            targetService.hideTarget(id, cClass);
        },
        getCategoryIcon: function (categoryName) {
            const iconMap = {
                'electronics': 'lab-line-phone',
                'electronic': 'lab-line-phone',
                'clothing': 'lab-line-tshirt',
                'fashion': 'lab-line-tshirt',
                'apparel': 'lab-line-tshirt',
                'food': 'lab-line-utensils',
                'grocery': 'lab-line-utensils',
                'books': 'lab-line-book',
                'book': 'lab-line-book',
                'sports': 'lab-line-football',
                'sport': 'lab-line-football',
                'beauty': 'lab-line-palette',
                'cosmetics': 'lab-line-palette',
                'home': 'lab-line-home',
                'furniture': 'lab-line-home',
                'toys': 'lab-line-gamepad',
                'toy': 'lab-line-gamepad',
                'health': 'lab-line-cross',
                'medicine': 'lab-line-cross',
                'automotive': 'lab-line-car',
                'auto': 'lab-line-car',
                'jewelry': 'lab-line-diamond',
                'jewellery': 'lab-line-diamond'
            };
            const key = categoryName.toLowerCase().trim();
            return iconMap[key] || 'lab-line-category';
        },
        showAllCategories: function () {
            this.showAll = true;
        }
    }
}
</script>