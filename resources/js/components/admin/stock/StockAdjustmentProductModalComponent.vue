<template>
    <div class="fixed inset-0 z-50 p-3 w-screen h-dvh overflow-y-auto bg-black/50 transition-all duration-300"
        :class="{ 'modal-active': modal.isShowModal }">
        <div class="w-full rounded-xl mx-auto bg-white transition-all duration-300 max-w-3xl">
            <div class="flex items-center justify-between gap-2 py-4 px-4 border-b border-slate-100">
                <h3 class="text-lg font-bold capitalize">{{ item.name }}</h3>
                <button @click="closeItemModal" type="button" class="lab-line-circle-cross text-lg text-danger"></button>
            </div>
            <form class="d-block w-full p-4">
                <div class="form-row">
                    <!-- An adjustment moves goods, so the only thing to pick
                         here is which variation. Price and tax belong to a
                         purchase or a sale, not to a stock correction. -->
                    <ProductVariationsComponent v-if="initialVariations.length > 0" v-on:method="selectedVariation"
                        :variations="initialVariations" mode="add" :item="item" />
                </div>
                <div class="modal-btns mt-8">
                    <button :disabled="!finalVariation" @click="submit" type="button"
                        class="modal-btn-fill disabled:opacity-25">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>{{ $t('button.save') }}</span>
                    </button>
                    <button type="button" class="modal-btn-outline" @click="closeItemModal">
                        <i class="fa-solid fa-circle-xmark"></i>
                        <span>{{ $t('button.close') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script>
import ProductVariationsComponent from "../components/product/ProductVariationsComponent";

export default {
    name: "StockAdjustmentProductModalComponent",
    components: { ProductVariationsComponent },
    props: ["item", "modal"],
    emits: ["submitItem"],
    data() {
        return {
            finalVariation: null,
        };
    },
    computed: {
        initialVariations: function () {
            return this.$store.getters["productVariation/initialVariation"];
        },
    },
    methods: {
        selectedVariation: function (variation) {
            this.finalVariation = variation;
            if (variation) {
                this.item.variation_id = variation.id;
                this.item.sku = variation.sku;
            }
        },
        closeItemModal: function () {
            this.modal.isShowModal = false;
        },
        submit: function () {
            this.$emit("submitItem", this.finalVariation);
        },
    },
};
</script>
