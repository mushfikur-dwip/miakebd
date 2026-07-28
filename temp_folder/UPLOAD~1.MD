# Suglow.com Fix Upload Checklist
**Do these IN ORDER — each step depends on the previous.**

---

## STEP 0 — Detect your stack (30 seconds)
Run these two commands on your server:
```bash
cat composer.json | grep -i inertia
cat resources/js/app.js | head -20
```
If you see `@inertiajs/vue3` → you are on **Inertia + Vue** (use all files in these folders).
If you see `@inertiajs/react` → you are on **Inertia + React** (contact me for React versions).
If you see neither → you are on **Blade/Livewire** (use only the .blade.php files).

---

## STEP 1 — Add SEO columns to your database
```bash
# Upload: database/migrations/2026_01_01_000001_add_seo_columns_to_products.php
php artisan migrate
```

---

## STEP 2 — Import SEO data from the Excel file
```bash
# Upload: app/Console/Commands/ImportSeoMetadata.php
# You also need the Excel package:
composer require maatwebsite/excel

# Then import (run this from your project root):
php artisan seo:import --file=/path/to/Suglow_SEO_3_Boxes.xlsx
```

---

## STEP 3 — Add meta tag component to your product page
```bash
# Upload: resources/js/Components/MetaHead.vue
# Then open your product page (likely resources/js/Pages/Product/Show.vue)
# and follow the instructions in: 3-meta-tags/ProductPage-example.vue
```

---

## STEP 4 — Fix your sitemap
```bash
# Install:
composer require spatie/laravel-sitemap

# Upload: app/Console/Commands/GenerateSitemap.php

# Generate the sitemap:
php artisan sitemap:generate

# Verify it was created correctly:
curl https://suglow.com/sitemap.xml | head -20
# Should show XML, NOT HTML
```

---

## STEP 5 — Upload robots.txt and llms.txt
```bash
# Upload: public/robots.txt        (already created in 2-sitemap/)
# Upload: public/llms.txt          (from previous session — Suglow_llms.txt)
# These go in public/ — the web server serves them directly, bypassing Laravel
```

---

## STEP 6 — Fix CLS (Cumulative Layout Shift — score 1.209 → target 0.1)
```bash
# Run the finder script to see which images need fixing:
bash 4-cls-fix/fix-cls-images.sh

# Upload: resources/js/Components/ProductImage.vue
# Replace your product image <img> tags with <ProductImage> component
# This automatically adds width="800" height="800" which stops the page from jumping
```

---

## STEP 7 — Enable SSR (most important — makes your site visible to AI crawlers)
```bash
# Install SSR package:
npm install @inertiajs/server @vue/server-renderer

# Upload: resources/js/ssr.js
# Upload: vite.config.js (replace existing)

# Add to .env:
INERTIA_SSR_ENABLED=true
INERTIA_SSR_URL=http://127.0.0.1:13714

# Build:
npm run build

# Test SSR works before setting up daemon:
php artisan inertia:start-ssr
# In another terminal:
curl http://suglow.com/ | grep "<h1"
# If you see actual content (not just <title>SuGlow</title>) — SSR is working!

# Set up as permanent daemon:
# Upload: supervisor-suglow.conf to /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start suglow-ssr:*
```

---

## STEP 8 — Reduce JavaScript bundle size
```bash
# The new vite.config.js (uploaded in Step 7) already includes code splitting.
# After uploading it, run:
npm run build
# Check bundle sizes:
ls -lh public/build/assets/*.js
# Target: no single file > 150 KiB
```

---

## VERIFY everything is working
```bash
# 1. Check SSR:
curl https://suglow.com/ | grep "meta name"
# Should show your meta tags

# 2. Check sitemap:
curl https://suglow.com/sitemap.xml | head -5
# Should show <?xml version="1.0"...

# 3. Check robots:
curl https://suglow.com/robots.txt
# Should show the robots.txt content, not HTML

# 4. Check llms.txt:
curl https://suglow.com/llms.txt | head -3
# Should show "# Suglow"

# 5. Run Google PageSpeed again:
# https://pagespeed.web.dev/report?url=https://suglow.com/
# Target: Performance > 70, CLS < 0.1
```

---

## Files in this package
```
suglow-fixes/
├── UPLOAD-CHECKLIST.md          ← you are here
├── 1-SSR/
│   ├── ssr.js                   → resources/js/ssr.js
│   ├── vite.config.js           → vite.config.js (replace)
│   ├── supervisor-suglow.conf   → /etc/supervisor/conf.d/suglow-ssr.conf
│   └── env-additions.txt        → append to .env
├── 2-sitemap/
│   ├── GenerateSitemap.php      → app/Console/Commands/GenerateSitemap.php
│   └── robots.txt               → public/robots.txt
├── 3-meta-tags/
│   ├── MetaHead.vue             → resources/js/Components/MetaHead.vue
│   └── ProductPage-example.vue  → shows how to add MetaHead to your product page
├── 4-cls-fix/
│   ├── fix-cls-images.sh        → run on server to find CLS problems
│   └── ProductImage-component.vue → resources/js/Components/ProductImage.vue
├── 6-structured-data/
│   └── schema-product.blade.php → resources/views/partials/schema-product.blade.php
└── 7-importer/
    ├── ImportSeoMetadata.php    → app/Console/Commands/ImportSeoMetadata.php
    └── migration-add-seo-columns.php → database/migrations/...
```
