# Suglow.com — Full Developer Implementation Guide

**Project:** suglow.com (Online Cosmetics Store, Bangladesh)  
**Stack:** Laravel + Inertia.js + Vue 3 + Vite  
**Hosting:** cPanel shared hosting — `dev/` (Laravel root) + `public_html/` (web root)  
**Goal:** Fix SEO, AEO, GEO, Core Web Vitals — get to top of Google BD + AI citations

---

## Table of Contents

1. [Current Problems](#1-current-problems)
2. [Stack Detection](#2-stack-detection)
3. [Phase 1 — robots.txt and llms.txt](#3-phase-1--robotstxt-and-llmstxt)
4. [Phase 2 — Database Migration + SEO Data Import](#4-phase-2--database-migration--seo-data-import)
5. [Phase 3 — Sitemap](#5-phase-3--sitemap)
6. [Phase 4 — Structured Data (SeoSchema)](#6-phase-4--structured-data-seoschema)
7. [Phase 5 — Meta Tags + Vue Components](#7-phase-5--meta-tags--vue-components)
8. [Phase 6 — CLS Fix (Core Web Vitals)](#8-phase-6--cls-fix-core-web-vitals)
9. [Phase 7 — JavaScript Bundle Splitting](#9-phase-7--javascript-bundle-splitting)
10. [Phase 8 — Server-Side Rendering (SSR)](#10-phase-8--server-side-rendering-ssr)
11. [Verification Checklist](#11-verification-checklist)
12. [File Upload Reference](#12-file-upload-reference)

---

## 1. Current Problems

### PageSpeed Scores (as measured)

| Metric | Current | Target |
|--------|---------|--------|
| Performance | 31 | 80+ |
| CLS (layout shift) | 1.209 | < 0.1 |
| LCP (load time) | 7.6s | < 2.5s |
| FCP | 4.8s | < 1.8s |
| Unused JS | 468 KB / 705 KB | < 150 KB |
| Agentic Browsing | 0/3 | 3/3 |

### Root Cause Diagnosis

```bash
# This is what Google and ChatGPT see when they crawl suglow.com:
curl -s https://suglow.com/ | grep -E "<title>|<meta|<h1"
# Output: <title>SuGlow</title>
# That is it. No description, no content, nothing.
```

**Why:** suglow.com is a client-side SPA. The server returns an empty HTML shell. JavaScript builds the page in the browser. Google's first-pass crawler and ALL AI crawlers (GPTBot, ClaudeBot, PerplexityBot) do not execute JavaScript — so they see nothing.

**Also:**
- `https://suglow.com/sitemap.xml` returns `Content-Type: text/html` because Laravel's catch-all route `Route::get('/{any}', ...)` intercepts it before the file can be served.
- Same problem with `/robots.txt` and `/llms.txt`.
- **Fix:** Files placed in `public_html/` are served by Nginx/Apache BEFORE Laravel runs. So `public_html/robots.txt` works. Laravel never sees the request.

---

## 2. Stack Detection

Run these on the server before starting:

```bash
# Check Inertia version
cat /home/YOUR_CPANEL_USER/dev/composer.json | grep inertia

# Check frontend framework
head -30 /home/YOUR_CPANEL_USER/dev/resources/js/app.js

# Check if SSR is already configured
ls /home/YOUR_CPANEL_USER/dev/resources/js/ssr.js 2>/dev/null && echo "SSR exists" || echo "No SSR"

# Check PHP version
php -v

# Check Node version
node -v

# Check if supervisor is available (usually not on shared hosting)
which supervisorctl 2>/dev/null || echo "No supervisor"
```

**Expected for this project:**
- `@inertiajs/vue3` → Inertia + Vue 3 ✓
- `createInertiaApp` in app.js → Vue confirmed ✓
- PHP 8.1+
- Node may not be available on cPanel shared hosting

---

## 3. Phase 1 — robots.txt and llms.txt

**No commands needed. Just upload. Takes 2 minutes.**

### 3.1 Upload robots.txt

**Upload to:** `public_html/robots.txt`

```
User-agent: *
Disallow: /admin/
Disallow: /api/
Disallow: /login
Disallow: /register
Disallow: /cart
Disallow: /checkout
Disallow: /account/
Allow: /

User-agent: GPTBot
Disallow: /admin/
Disallow: /api/
Allow: /

User-agent: ClaudeBot
Disallow: /admin/
Disallow: /api/
Allow: /

User-agent: PerplexityBot
Disallow: /admin/
Disallow: /api/
Allow: /

User-agent: Google-Extended
Allow: /

Sitemap: https://suglow.com/sitemap.xml
```

> **Note:** Each `User-agent` block is independent. A bot that finds its own block ignores the `*` block entirely. Disallow rules must be repeated in every named block — not just in `*`.

### 3.2 Upload llms.txt

**Upload to:** `public_html/llms.txt`

Use the `Suglow_llms.txt` file from the earlier session — rename it to `llms.txt` when uploading.

This tells ChatGPT, Claude, Gemini, and Perplexity what your store sells, which brands you carry, and how to describe you to users. The format is strict — line 1 must be `# Suglow`.

### 3.3 Verify

```bash
curl -I https://suglow.com/robots.txt
# Must show: Content-Type: text/plain  (NOT text/html)

curl https://suglow.com/llms.txt | head -1
# Must show: # Suglow
```

---

## 4. Phase 2 — Database Migration + SEO Data Import

### 4.1 Upload the migration file

**Upload to:** `dev/database/migrations/2026_07_28_120000_add_seo_columns_to_products.php`

This adds three nullable columns to the `products` table:
- `meta_title` varchar(70)
- `meta_description` text
- `meta_keywords` text

### 4.2 Run the migration

```bash
cd /home/YOUR_CPANEL_USER/dev
php artisan migrate
```

Expected output:
```
Running migrations...
2026_07_28_120000_add_seo_columns_to_products ........ DONE
```

Verify columns were added:
```bash
php artisan tinker --execute="print_r(Schema::getColumnListing('products'));"
# meta_title, meta_description, meta_keywords should appear in the list
```

### 4.3 Install the Excel reader package

```bash
cd /home/YOUR_CPANEL_USER/dev
composer require phpoffice/phpspreadsheet
```

> **Important:** The package is `phpoffice/phpspreadsheet`, NOT `maatwebsite/excel`. Different packages entirely.

### 4.4 Upload the importer command

**Upload to:** `dev/app/Console/Commands/ImportSeoMetadata.php`

### 4.5 Upload the SEO spreadsheet

**Upload** `Suglow_SEO_3_Boxes.xlsx` **to:** `dev/storage/app/Suglow_SEO_3_Boxes.xlsx`

### 4.6 Dry run first — always

```bash
cd /home/YOUR_CPANEL_USER/dev
php artisan seo:import --dry-run
```

This prints exactly what it would do without writing anything. Check:
- Are the product names matching?
- Is the "Not matched" count low (< 10)?

If many are unmatched: the names in the spreadsheet differ from what is in the database. Open the unmatched log and compare:
```bash
cat /home/YOUR_CPANEL_USER/dev/storage/logs/seo-import-unmatched.txt
```

### 4.7 Run for real

```bash
php artisan seo:import
```

Expected output:
```
Import complete.
  Matched & updated : 438
  Blank rows skipped: 1
  Not matched       : 2
```

### 4.8 Verify data is in database

```bash
php artisan tinker --execute="echo App\Models\Product::whereNotNull('meta_title')->count();"
# Should show ~438

php artisan tinker --execute="echo App\Models\Product::first()->meta_title;"
# Should show a real SEO title like "CeraVe Foaming Cleanser Price in Bangladesh | Suglow"
```

---

## 5. Phase 3 — Sitemap

### 5.1 Install the sitemap package

```bash
cd /home/YOUR_CPANEL_USER/dev
composer require spatie/laravel-sitemap
```

### 5.2 Add web root path to .env

Open `dev/.env` and add at the bottom:

```env
PUBLIC_WEB_PATH=/home/YOUR_CPANEL_USER/public_html
APP_URL=https://suglow.com
```

> **Why PUBLIC_WEB_PATH?** Laravel's `public_path()` points to `dev/public/`. But your web root is `public_html/`. The sitemap must be written to `public_html/` or it won't be reachable from the internet. The command reads this env variable to find the correct path.

### 5.3 Upload the command

**Upload to:** `dev/app/Console/Commands/GenerateSitemap.php`

### 5.4 Generate the sitemap

```bash
cd /home/YOUR_CPANEL_USER/dev
php artisan sitemap:generate
```

Expected output:
```
Writing sitemap into: /home/YOUR_CPANEL_USER/public_html
Sitemap written.
  File:       /home/YOUR_CPANEL_USER/public_html/sitemap.xml
  Categories: 9
  Products:   440

Verify with:  curl -I https://suglow.com/sitemap.xml
Expect:       Content-Type: text/xml  (NOT text/html)
```

### 5.5 Schedule daily regeneration

Add to `dev/app/Console/Kernel.php` inside the `schedule()` method:

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('sitemap:generate')->dailyAt('03:00');
}
```

Or via cPanel Cron Jobs:
```
0 3 * * * /usr/local/bin/php /home/YOUR_CPANEL_USER/dev/artisan sitemap:generate
```

### 5.6 Submit to Google Search Console

1. Go to [search.google.com/search-console](https://search.google.com/search-console)
2. Left sidebar → Sitemaps
3. Enter `sitemap.xml` → Submit
4. Google will discover and index all 440 product pages

### 5.7 Verify

```bash
curl -I https://suglow.com/sitemap.xml
# Must show: Content-Type: application/xml  (NOT text/html)

curl -s https://suglow.com/sitemap.xml | head -3
# Must show: <?xml version="1.0" encoding="UTF-8"?>
```

---

## 6. Phase 4 — Structured Data (SeoSchema)

This enables Google Rich Snippets (price badge, availability, FAQ accordion in search results) and allows ChatGPT/Gemini/Perplexity to cite suglow.com as a source.

### 6.1 Create the Support directory

In cPanel File Manager: open `dev/app/` → New Folder → type `Support` → Create.

Or via terminal:
```bash
mkdir -p /home/YOUR_CPANEL_USER/dev/app/Support
```

### 6.2 Upload SeoSchema.php

**Upload to:** `dev/app/Support/SeoSchema.php`

This class generates two JSON-LD blocks:
- `Product` — name, price, availability, brand, seller
- `FAQPage` — 4 Q&A pairs for AEO/featured snippets

### 6.3 Edit ProductController.php

Open your existing: `dev/app/Http/Controllers/ProductController.php`

Add two `use` statements at the top:
```php
use App\Support\SeoSchema;
use Illuminate\Support\Facades\View;
```

In your `show()` method, add ONE line before `return Inertia::render(...)`:
```php
public function show(string $slug)
{
    $product = Product::where('slug', $slug)->firstOrFail();

    View::share('structuredData', SeoSchema::product($product)); // ADD THIS

    return Inertia::render('Product/Show', [
        'product' => $product,
    ]);
}
```

For the homepage, add to your home/index method:
```php
View::share('structuredData', SeoSchema::organisation()); // ADD THIS
```

### 6.4 Edit app.blade.php

Open: `dev/resources/views/app.blade.php`

Inside `<head>`, after `@inertiaHead`, add:
```blade
@isset($structuredData)
    <script type="application/ld+json">{!! $structuredData !!}</script>
@endisset
```

Full file should look like:
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
    @isset($structuredData)
        <script type="application/ld+json">{!! $structuredData !!}</script>
    @endisset
</head>
<body>
    @inertia
</body>
</html>
```

> **Why Blade and not Vue?** Vue's compiler strips `<script>` tags out of templates — it is a hard framework limitation. JSON-LD must render server-side from Blade to be visible to crawlers. `View::share()` passes the data from the controller to the Blade view for this request only.

### 6.5 Verify

```bash
# After deploying (requires SSR for full verification, or test via Rich Results tool)
curl -s https://suglow.com/product/YOUR-PRODUCT-SLUG | grep "ld+json"
```

Use Google Rich Results Test:
```
https://search.google.com/test/rich-results?url=https://suglow.com/product/your-slug
```

---

## 7. Phase 5 — Meta Tags + Vue Components

### 7.1 Upload three components

**Upload all to:** `dev/resources/js/Components/`

- `MetaHead.vue` — sets `<title>`, `<meta description>`, Open Graph (Facebook/WhatsApp previews), Twitter Card
- `ProductImage.vue` — image wrapper that always outputs `width` + `height` (fixes CLS)
- `ProductSeoBlocks.vue` — renders AEO Q&A and GEO entity block as visible page text

### 7.2 Edit your product page Vue file

Find your product page. Common locations:
- `dev/resources/js/Pages/Product/Show.vue`
- `dev/resources/js/Pages/ProductDetail.vue`
- `dev/resources/js/Pages/Products/Show.vue`

**Step A — Add imports** to the `<script setup>` block:
```js
import MetaHead         from '@/Components/MetaHead.vue'
import ProductImage     from '@/Components/ProductImage.vue'
import ProductSeoBlocks from '@/Components/ProductSeoBlocks.vue'
```

**Step B — Add MetaHead** as the first element inside `<template>`:
```html
<MetaHead
  :title="product.meta_title || `${product.name} Price in Bangladesh | Suglow`"
  :description="product.meta_description"
  :keywords="product.meta_keywords"
  :image="product.image_url"
  :url="`https://suglow.com/product/${product.slug}`"
/>
```

**Step C — Replace the main product image.** Find your existing `<img>` for the main product photo. Replace it with:
```html
<ProductImage
  :src="product.image_url"
  :alt="product.name"
  :width="800"
  :height="800"
  eager
  img-class="w-full rounded-lg object-cover"
/>
```

> **Set `:width` and `:height` to your actual image dimensions.** To find them: open any product image in browser → right-click → Inspect → in console type `$0.naturalWidth`. If images are 1000×1000, use 1000. Wrong dimensions make CLS worse.
>
> The `eager` prop is for the MAIN image only. It tells the browser to fetch it immediately (fixes LCP). For thumbnails and related products, use `<ProductImage>` WITHOUT `eager`.

**Step D — Add SEO blocks** near the bottom of the product description area:
```html
<ProductSeoBlocks :description="product.meta_description" />
```

This renders the Q&A block and product detail table as visible text. This is what earns featured snippets and AI citations — content in hidden meta tags does not count.

### 7.3 Build

```bash
cd /home/YOUR_CPANEL_USER/dev
npm run build
```

### 7.4 Verify

Open any product page → View Page Source (`Ctrl+U`) → search for `meta name="description"`. You should see your SEO description.

---

## 8. Phase 6 — CLS Fix (Core Web Vitals)

### What CLS 1.209 means

The page jumps by more than one full screen height as images load. Google's limit is 0.1. Your score is 12× over the limit. This suppresses ranking regardless of content quality.

### Root cause

Every `<img>` tag without explicit `width` and `height` attributes causes layout shift. The browser allocates zero space for the image, renders the text below it, then when the image loads it pushes everything down.

### Find all problem images

```bash
cd /home/YOUR_CPANEL_USER/dev
grep -rn "<img" resources/js/ --include="*.vue" | grep -v "width=" | grep -v ":width="
```

Each result is a CLS contributor.

### Fix 1 — Product images (use the component)

`ProductImage.vue` wraps images in an `aspect-ratio` container that pre-reserves the exact space. This is the correct fix — it works even before the image loads.

Replace all product `<img>` tags with `<ProductImage>` as described in Phase 5.

### Fix 2 — Other images (banners, category, logos)

For images outside products, add attributes directly:

```html
<!-- Before (causes CLS): -->
<img :src="category.image" class="w-full">

<!-- After (fixes CLS): -->
<img :src="category.image" class="w-full" width="600" height="400" loading="lazy">
```

### Fix 3 — Fonts

If you load Google Fonts, add `font-display: swap` to prevent invisible text during load:

```html
<!-- In app.blade.php <head>: -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=YourFont&display=swap" rel="stylesheet">
```

### Rebuild and retest

```bash
npm run build
```

Then run PageSpeed again. CLS should be under 0.1.

---

## 9. Phase 7 — JavaScript Bundle Splitting

### Current problem

Lighthouse reports 468 KB of 705 KB JavaScript is unused on every page. The browser downloads the entire application on first load even though most of it is not needed.

### Fix

Upload `vite.config.js` to replace `dev/vite.config.js`.

**Before uploading:** open your existing `vite.config.js` and note:
- The `input:` file paths (may be `app.ts` not `app.js`)
- Any extra plugins (vue-i18n, ziggy, etc.)
- Any existing aliases

The new config adds `manualChunks` which splits the bundle:

```js
manualChunks(id) {
  if (id.includes('/vue/') || id.includes('/@inertiajs/')) return 'vendor'
  if (id.includes('/@headlessui/') || id.includes('/@heroicons/')) return 'ui'
  if (id.includes('/axios/')) return 'http'
}
```

This means a product page loads only `vendor` + `app` chunks instead of everything at once.

Also adds the `@` alias explicitly:
```js
resolve: {
  alias: {
    '@': path.resolve(__dirname, 'resources/js'),
  },
},
```

### Build and verify

```bash
npm run build
ls -lh /home/YOUR_CPANEL_USER/public_html/build/assets/*.js | sort -k5 -rh | head -10
```

No single JS file should exceed 200 KB after splitting.

---

## 10. Phase 8 — Server-Side Rendering (SSR)

**This is the most important phase.** Without SSR, all meta tags, structured data, and AEO content is invisible to crawlers. Google, ChatGPT, and Gemini receive the empty HTML shell regardless of everything else done in phases 1–7.

### Check availability on your host

```bash
node -v         # Node must be available
which supervisorctl 2>/dev/null || echo "No supervisor"
```

On many shared cPanel plans, SSR is not possible. Ask your host:
> "Does my plan allow a persistent background process or Supervisor? I need Inertia SSR on port 13714."

If the answer is no, skip to section 10.8 (fallback options).

### 10.1 Install SSR dependencies

```bash
cd /home/YOUR_CPANEL_USER/dev
npm install @inertiajs/server @vue/server-renderer
```

### 10.2 Upload ssr.js

**Upload to:** `dev/resources/js/ssr.js`

### 10.3 Update vite.config.js

The `vite.config.js` from Phase 7 already includes the SSR entry:
```js
laravel({
  input: ['resources/css/app.css', 'resources/js/app.js'],
  ssr: 'resources/js/ssr.js',   // this line enables SSR build
  refresh: true,
}),
```

### 10.4 Add to .env

```env
INERTIA_SSR_ENABLED=true
INERTIA_SSR_URL=http://127.0.0.1:13714
```

### 10.5 Build

```bash
cd /home/YOUR_CPANEL_USER/dev
npm run build
```

### 10.6 Test SSR manually

```bash
# Start SSR server in foreground to test
php artisan inertia:start-ssr

# In another terminal, check that pages now return real HTML:
curl -s http://localhost/ | grep "<h1\|meta name"
# Should show actual content, not just <title>SuGlow</title>
```

Press `Ctrl+C` to stop the test server.

### 10.7 Set up Supervisor daemon

Create `/etc/supervisor/conf.d/suglow-ssr.conf`:

```ini
[program:suglow-ssr]
command=php /home/YOUR_CPANEL_USER/dev/artisan inertia:start-ssr
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=YOUR_CPANEL_USER
redirect_stderr=true
stdout_logfile=/home/YOUR_CPANEL_USER/logs/suglow-ssr.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start suglow-ssr:*
sudo supervisorctl status
```

### 10.8 Fallback if SSR is not possible

**Option A — Prerender.io proxy** (recommended for shared hosting)

Sign up at prerender.io (free tier: ~1,000 pages/month).

Add to `public_html/.htaccess` ABOVE the existing Laravel rewrite block:

```apache
RewriteEngine On

# Send crawlers to prerender.io
RewriteCond %{HTTP_USER_AGENT} (googlebot|bingbot|gptbot|claudebot|perplexitybot|facebookexternalhit|twitterbot) [NC]
RewriteCond %{REQUEST_URI} !\.(js|css|xml|less|png|jpg|jpeg|gif|pdf|txt|svg)$ [NC]
RewriteRule ^(.*)$ https://service.prerender.io/https://suglow.com/$1 [P,L]

# Existing Laravel rewrite below this line
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

**Option B — Blade product pages** (better long-term)

Keep the SPA for browsing, but serve product pages as pure Blade views. Crawlers get real HTML; users get the SPA. Ask for the Blade template files if you choose this route.

**Option C — Move to VPS**

A $6–10/month VPS (DigitalOcean, Vultr, Hetzner) runs this setup comfortably and supports full Supervisor + SSR. Recommended for long-term growth.

### 10.9 Verify SSR working

```bash
curl -s https://suglow.com/product/YOUR-PRODUCT-SLUG | grep 'meta name="description"'
# Must return the description tag — if empty, SSR is not running

curl -s https://suglow.com/ | grep "application/ld+json"
# Must return the Organisation JSON-LD block
```

---

## 11. Verification Checklist

Run all of these after completing the phases. Every check should pass before considering the work done.

```bash
# Phase 1 checks
curl -I https://suglow.com/robots.txt 2>/dev/null | grep -i content-type
# Expected: content-type: text/plain

curl -s https://suglow.com/llms.txt | head -1
# Expected: # Suglow

# Phase 3 checks
curl -I https://suglow.com/sitemap.xml 2>/dev/null | grep -i content-type
# Expected: content-type: application/xml

curl -s https://suglow.com/sitemap.xml | grep -c "<url>"
# Expected: 440+ (one per product + categories + static pages)

# Phase 4 check (requires SSR)
curl -s https://suglow.com/product/YOUR-SLUG | grep "application/ld+json"
# Expected: the JSON-LD script tag

# Phase 5 check (requires SSR)
curl -s https://suglow.com/product/YOUR-SLUG | grep 'meta name="description"'
# Expected: your SEO description

# Phase 2 database check
cd /home/YOUR_CPANEL_USER/dev
php artisan tinker --execute="echo App\Models\Product::whereNotNull('meta_title')->count();"
# Expected: 430+ products

# Phase 8 SSR check
curl -s https://suglow.com/ | wc -c
# Before SSR: ~1500 bytes (empty shell)
# After SSR:  ~25000+ bytes (full rendered page)
```

### Google tools

| Tool | URL |
|------|-----|
| Rich Results Test | https://search.google.com/test/rich-results |
| PageSpeed Insights | https://pagespeed.web.dev/ |
| Search Console | https://search.google.com/search-console |
| Mobile-Friendly Test | https://search.google.com/test/mobile-friendly |

### Target scores after all phases

| Metric | Before | Target |
|--------|--------|--------|
| Performance | 31 | 80+ |
| CLS | 1.209 | < 0.1 |
| LCP | 7.6s | < 2.5s |
| Agentic Browsing | 0/3 | 3/3 |
| Google Rich Snippets | None | Price + FAQ |

---

## 12. File Upload Reference

### Files that go in public_html (no command needed)

| File | Destination |
|------|-------------|
| `robots.txt` | `public_html/robots.txt` |
| `Suglow_llms.txt` | `public_html/llms.txt` (rename on upload) |

### Files that go in dev (commands required after)

| File | Destination | Command after |
|------|-------------|---------------|
| `2026_07_28_120000_add_seo_columns_to_products.php` | `dev/database/migrations/` | `php artisan migrate` |
| `ImportSeoMetadata.php` | `dev/app/Console/Commands/` | `php artisan seo:import` |
| `GenerateSitemap.php` | `dev/app/Console/Commands/` | `php artisan sitemap:generate` |
| `SeoSchema.php` | `dev/app/Support/` *(create folder first)* | edit Controller + blade |
| `MetaHead.vue` | `dev/resources/js/Components/` | `npm run build` |
| `ProductImage.vue` | `dev/resources/js/Components/` | `npm run build` |
| `ProductSeoBlocks.vue` | `dev/resources/js/Components/` | `npm run build` |
| `vite.config.js` | `dev/vite.config.js` *(back up old first)* | `npm run build` |
| `ssr.js` | `dev/resources/js/ssr.js` | `npm run build` |

### Files to EDIT (not upload)

| File to edit | What to add |
|---|---|
| `dev/resources/views/app.blade.php` | `@isset($structuredData)` snippet in `<head>` |
| `dev/app/Http/Controllers/ProductController.php` | `View::share(...)` line in `show()` method |
| Your product page `.vue` file | `<MetaHead>`, `<ProductImage>`, `<ProductSeoBlocks>` |
| `dev/.env` | `PUBLIC_WEB_PATH`, `INERTIA_SSR_ENABLED`, `APP_URL` |

### Composer packages to install

```bash
cd /home/YOUR_CPANEL_USER/dev
composer require spatie/laravel-sitemap
composer require phpoffice/phpspreadsheet
```

### npm packages to install

```bash
cd /home/YOUR_CPANEL_USER/dev
npm install @inertiajs/server @vue/server-renderer
```

---

## Developer Notes

- **Replace `YOUR_CPANEL_USER`** with the actual cPanel username throughout. It is visible top-left in File Manager (e.g. `/home/sug12345/`).
- **Always back up** `vite.config.js` and `app.blade.php` before editing.
- **The `--dry-run` flag** on `seo:import` is safe to run multiple times. It never writes to the database.
- **Run phases in order.** Phase 2 must complete before Phase 4 (controller needs the meta columns to exist). Phase 5 must complete before SSR is worth testing.
- **CLS image dimensions** must be the actual pixel size of your images. Open any product image in browser → right-click → Inspect → type `$0.naturalWidth` in console to see the real size.
- **SSR is the biggest single win.** Without it, crawlers see nothing regardless of all other work. If the host doesn't support it, the prerender.io fallback in section 10.8 is the next best option.
- **Rich snippets appear 1–2 weeks** after Google re-crawls. After deploying, go to Search Console → URL Inspection → Request Indexing on 5–10 product pages to speed this up.

---

*Guide prepared for suglow.com — July 2026*  
*All implementation files are in the `suglow-v2/` folder delivered alongside this guide.*
