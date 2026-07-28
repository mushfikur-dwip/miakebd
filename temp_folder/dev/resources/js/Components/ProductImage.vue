<!--
  suglow.com — image component that fixes your CLS score (1.209 -> target under 0.1)

  UPLOAD TO:  dev/resources/js/Components/ProductImage.vue

  BUG 9 FIX: the old @error handler reassigned .src on failure. If the placeholder
  also 404s the error event fires again, reassigns again, and loops forever hammering
  your server. Now guarded so the fallback is attempted exactly once.

  USAGE — replace product <img> tags with:
    <ProductImage :src="product.image_url" :alt="product.name" />

  For the MAIN image on a product page add `eager` so it loads immediately
  (this is your LCP element, currently 7.6s):
    <ProductImage :src="product.image_url" :alt="product.name" eager />
-->
<script setup>
import { ref } from 'vue'

const props = defineProps({
  src:     { type: String, required: true },
  alt:     { type: String, required: true },
  width:   { type: Number, default: 800 },
  height:  { type: Number, default: 800 },
  eager:   { type: Boolean, default: false },
  imgClass:{ type: String, default: 'w-full h-full object-cover' },
})

const failed = ref(false)
const PLACEHOLDER = '/images/placeholder.jpg'

function onError (e) {
  if (failed.value) return          // already fell back once — stop here
  failed.value = true
  e.target.src = PLACEHOLDER
}
</script>

<template>
  <!--
    The wrapper reserves the exact space before the image arrives.
    This is what actually stops the layout jumping, which is what CLS measures.
  -->
  <div
    class="relative overflow-hidden bg-gray-100"
    :style="{ aspectRatio: `${width} / ${height}` }"
  >
    <img
      :src="src"
      :alt="alt"
      :width="width"
      :height="height"
      :loading="eager ? 'eager' : 'lazy'"
      :fetchpriority="eager ? 'high' : 'auto'"
      decoding="async"
      :class="imgClass"
      @error="onError"
    />
  </div>
</template>
