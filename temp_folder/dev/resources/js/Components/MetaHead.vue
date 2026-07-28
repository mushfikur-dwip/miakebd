<!--
  suglow.com — SEO meta tags for Inertia pages

  UPLOAD TO:  dev/resources/js/Components/MetaHead.vue

  BUG 10 FIX: every tag now carries a `head-key`. Without it Inertia APPENDS a new
  copy of each meta tag on every client-side navigation instead of replacing it —
  after browsing a few products the page carries several conflicting descriptions.

  USAGE inside any page component — see USAGE-product-page.txt in this package
  for the full copy-paste example. In short: import this component in your
  script setup block, then place <MetaHead ... /> at the top of your template,
  passing title, description, keywords, image and url from the product.

  NOTE: the long 3-block description from the spreadsheet is trimmed to its first
  block here, because a meta description tag must be a single short line. The
  AEO and GEO blocks are rendered as visible page content instead (see
  ProductSeoBlocks.vue) which is where crawlers actually want them.
-->
<script setup>
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'

const props = defineProps({
  title:       { type: String, default: 'Suglow — Online Cosmetics Store in Bangladesh' },
  description: { type: String, default: 'Buy authentic cosmetics, skincare and personal care products in Bangladesh. Cash on delivery nationwide. Shop at Suglow.com.' },
  keywords:    { type: String, default: 'cosmetics bangladesh, skincare bangladesh, buy cosmetics online bd' },
  image:       { type: String, default: 'https://suglow.com/og-default.jpg' },
  url:         { type: String, default: 'https://suglow.com' },
  type:        { type: String, default: 'product' },
})

// Only the first block belongs in a <meta name="description"> tag.
const metaDescription = computed(() => {
  const first = (props.description || '').split(/\n\s*\n/)[0].trim()
  return first.length > 160 ? first.slice(0, 157).replace(/\s\S*$/, '') + '…' : first
})

const absoluteImage = computed(() =>
  props.image?.startsWith('http') ? props.image : `https://suglow.com${props.image || '/og-default.jpg'}`
)
</script>

<template>
  <Head>
    <title>{{ title }}</title>

    <meta head-key="description" name="description"  :content="metaDescription" />
    <meta head-key="keywords"    name="keywords"     :content="keywords" />
    <meta head-key="robots"      name="robots"       content="index, follow, max-image-preview:large" />
    <link head-key="canonical"   rel="canonical"     :href="url" />

    <!-- Open Graph — drives Facebook & WhatsApp link previews, big traffic source in BD -->
    <meta head-key="og:type"        property="og:type"        :content="type" />
    <meta head-key="og:title"       property="og:title"       :content="title" />
    <meta head-key="og:description" property="og:description" :content="metaDescription" />
    <meta head-key="og:image"       property="og:image"       :content="absoluteImage" />
    <meta head-key="og:url"         property="og:url"         :content="url" />
    <meta head-key="og:site_name"   property="og:site_name"   content="Suglow" />
    <meta head-key="og:locale"      property="og:locale"      content="en_US" />

    <meta head-key="tw:card"        name="twitter:card"        content="summary_large_image" />
    <meta head-key="tw:title"       name="twitter:title"       :content="title" />
    <meta head-key="tw:description" name="twitter:description" :content="metaDescription" />
    <meta head-key="tw:image"       name="twitter:image"       :content="absoluteImage" />
  </Head>
</template>
