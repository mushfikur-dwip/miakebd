<!--
  suglow.com — renders the AEO answer + GEO entity line as VISIBLE page content

  UPLOAD TO:  dev/resources/js/Components/ProductSeoBlocks.vue

  WHY THIS EXISTS
  The spreadsheet Description column has three blocks:
     block 1  short meta description   -> goes in the <meta> tag (MetaHead.vue)
     block 2  AEO question + answer    -> must be VISIBLE text to win snippets
     block 3  GEO entity line          -> must be VISIBLE text for AI citation
  Search engines and AI crawlers only credit text they can see on the page.
  Hiding it in a meta tag wastes it. This component prints blocks 2 and 3.

  USAGE on your product page, near the bottom of the description area:
    <ProductSeoBlocks :description="product.meta_description" />
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({
  description: { type: String, default: '' },
})

const blocks = computed(() =>
  (props.description || '').split(/\n\s*\n/).map(b => b.trim()).filter(Boolean)
)

// Block 2 looks like:  "Q: Is X available in Bangladesh?\nYes — X is ..."
const faq = computed(() => {
  const b = blocks.value[1]
  if (!b) return null
  const lines = b.split('\n').map(l => l.trim()).filter(Boolean)
  const q = (lines[0] || '').replace(/^Q:\s*/i, '')
  const a = lines.slice(1).join(' ')
  return q && a ? { q, a } : null
})

const geo = computed(() => {
  const b = blocks.value[2]
  if (!b) return []
  return b
    .replace(/^Product details\s*[—-]\s*/i, '')
    .split('|')
    .map(part => {
      const [k, ...rest] = part.split(':')
      return { k: (k || '').trim(), v: rest.join(':').trim() }
    })
    .filter(p => p.k && p.v)
})
</script>

<template>
  <section v-if="faq || geo.length" class="mt-8 space-y-6">
    <!-- AEO block: visible Q&A wins featured snippets and voice answers -->
    <div v-if="faq">
      <h2 class="text-lg font-semibold mb-2">{{ faq.q }}</h2>
      <p class="text-gray-700 leading-relaxed">{{ faq.a }}</p>
    </div>

    <!-- GEO block: a clean spec table is what AI assistants quote -->
    <div v-if="geo.length">
      <h3 class="text-base font-semibold mb-2">Product details</h3>
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-sm">
        <div v-for="item in geo" :key="item.k" class="flex gap-2 py-1 border-b border-gray-100">
          <dt class="text-gray-500 min-w-[110px]">{{ item.k }}</dt>
          <dd class="text-gray-900 font-medium">{{ item.v }}</dd>
        </div>
      </dl>
    </div>
  </section>
</template>
