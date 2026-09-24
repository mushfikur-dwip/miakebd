<template>
    <SliderComponent />

    <HomeBannerComponent />

    <MobileSectionComponent />

    <CategoryComponent />

    <!-- Below-the-fold sections render (and fetch) only as they approach the
         viewport — see LazyRenderComponent. -->
    <LazyRenderComponent>
        <PromotionComponent />
    </LazyRenderComponent>

    <LazyRenderComponent>
        <ProductSectionComponent />
    </LazyRenderComponent>

    <LazyRenderComponent>
        <MostPopularComponent />
    </LazyRenderComponent>

    <LazyRenderComponent>
        <FlashSaleComponent />
    </LazyRenderComponent>

    <LazyRenderComponent>
        <ProductBrandComponent />
    </LazyRenderComponent>

    <LazyRenderComponent>
        <BenefitComponent />
    </LazyRenderComponent>
</template>

<script>
import SliderComponent from "./SliderComponent.vue";
import HomeBannerComponent from "./HomeBannerComponent.vue";
import MobileSectionComponent from "./MobileSectionComponent.vue";
import CategoryComponent from "./CategoryComponent.vue";
import PromotionComponent from "./PromotionComponent.vue";
import BenefitComponent from "./BenefitComponent.vue";
import ProductSectionComponent from "./ProductSectionComponent.vue";
import FlashSaleComponent from "./FlashSaleComponent.vue";
import ProductBrandComponent from "./ProductBrandComponent.vue";
import MostPopularComponent from "./MostPopularComponent.vue";
import LazyRenderComponent from "../components/LazyRenderComponent.vue";

export default {
    name: "HomeComponent",
    components: {
        MostPopularComponent,
        FlashSaleComponent,
        BenefitComponent,
        SliderComponent,
        HomeBannerComponent,
        MobileSectionComponent,
        CategoryComponent,
        PromotionComponent,
        ProductSectionComponent,
        ProductBrandComponent,
        LazyRenderComponent,
    },
};
</script>
