# Suglow SEO, AEO, GEO ও Core Web Vitals — Project Todo

এই checklist তৈরি করা হয়েছে:

- `temp_folder/SUGLOW~2.MD`
- `temp_folder/UPLOAD~1.MD`
- `temp_folder/dev/`-এর generated files
- বর্তমান Laravel 12 + Vue 3 + Vue Router project structure

> **গুরুত্বপূর্ণ:** এই project Inertia ব্যবহার করে না। Frontend একটি Vue Router SPA, head management-এ `@vueuse/head` ব্যবহার করে, এবং product SEO data ইতিমধ্যে `product_seos` table-এ সংরক্ষিত হয়। তাই generated Inertia-specific files সরাসরি copy করা যাবে না।

## Status Legend

- **Priority:** `P0` = blocker/critical, `P1` = high, `P2` = improvement
- **Dependency:** কাজটি শুরু করার আগে যে Todo সম্পন্ন হতে হবে
- **Acceptance:** কাজটি সম্পন্ন হয়েছে প্রমাণ করার শর্ত

## Generated Files Compatibility Matrix

| Generated file | Status | Project-specific decision |
|---|---|---|
| `temp_folder/dev/app/Console/Commands/ImportSeoMetadata.php` | **Adapt** | `PhpSpreadsheet` ও `products.meta_*` বাদ দিয়ে installed `maatwebsite/excel` এবং বিদ্যমান `product_seos` schema ব্যবহার করতে হবে। |
| `temp_folder/dev/app/Console/Commands/GenerateSitemap.php` | **Adapt** | `Category`/`categories` assumptions বাদ দিয়ে `ProductCategory`/`product_categories`, প্রকৃত routes এবং project status rules ব্যবহার করতে হবে। |
| `temp_folder/dev/app/Support/SeoSchema.php` | **Adapt** | বর্তমান Product fields, prices, media, stock এবং route format অনুযায়ী schema mapping ঠিক করতে হবে। যাচাই ছাড়া authenticity, delivery বা availability claim প্রকাশ করা যাবে না। |
| `temp_folder/dev/resources/js/Components/ProductImage.vue` | **Adapt** | CLS/LCP pattern ব্যবহার করা যাবে; project image paths, styling এবং existing product gallery integration অনুযায়ী বদলাতে হবে। |
| `temp_folder/dev/resources/js/Components/ProductSeoBlocks.vue` | **Adapt** | Vue 3 compatible; spreadsheet description format ও বর্তমান product-details layout যাচাই করে integrate করতে হবে। |
| `temp_folder/dev/resources/js/Components/MetaHead.vue` | **Do not copy directly** | এটি `@inertiajs/vue3`-এর `Head` ব্যবহার করে। বর্তমান `@vueuse/head` implementation উন্নত করতে হবে। |
| `temp_folder/dev/ssr.js` | **Do not copy directly** | এটি Inertia SSR entry point; বর্তমান Vue Router app-এর সঙ্গে compatible নয়। |
| `temp_folder/dev/vite.config.js` | **Do not copy directly** | Inertia SSR config ও project-এ অনুপস্থিত aliases/dependencies ধরে নেয়। প্রয়োজনীয় bundle rules বর্তমান config-এ বেছে merge করতে হবে। |
| `temp_folder/dev/resources/views/app-blade-SNIPPET.txt` | **Do not copy directly** | Project shell হলো `resources/views/master.blade.php`; এখানে `@inertiaHead` নেই। |
| `temp_folder/dev/app/Http-ProductController-EXAMPLE.php` | **Do not copy directly** | Product page web controller দিয়ে render হয় না; বর্তমান web/API/controller flow অনুযায়ী server SEO design করতে হবে। |
| `temp_folder/dev/database/migrations/2026_07_28_120000_add_seo_columns_to_products.php` | **Do not use** | SEO columns ইতিমধ্যে normalized `product_seos` table-এ আছে। Duplicate columns যোগ করা যাবে না। |

---

## 1. Backup ও Baseline Audit

- [ ] **P0 — Deployment backup ও rollback point তৈরি করুন**
  - **Dependency:** None
  - **Actions:**
    - বর্তমান code release, `.env`, database এবং uploaded media backup নিন।
    - production deploy-এর আগের commit/tag বা archive identifier লিখে রাখুন।
    - shared-hosting document root এবং Laravel root-এর প্রকৃত absolute path নিশ্চিত করুন।
  - **Acceptance:** Code, database ও media restore করার tested/ documented পথ আছে।

- [ ] **P0 — Production architecture ও environment baseline নিন**
  - **Dependency:** Backup
  - **Commands:**
    ```bash
    php -v
    node -v
    composer show maatwebsite/excel
    php artisan about
    php artisan route:list
    npm run build
    ```
  - **Record:**
    - Laravel root: `<LARAVEL_ROOT>`
    - Public web root: `<PUBLIC_WEB_ROOT>`
    - Production URL: `<APP_URL>`
    - Node process/daemon support: `<YES_OR_NO>`
  - **Acceptance:** PHP/Node versions, build status, document root এবং long-running Node process support নথিভুক্ত।

- [ ] **P0 — বর্তমান crawler ও performance baseline সংরক্ষণ করুন**
  - **Dependency:** Environment baseline
  - **Commands:**
    ```bash
    curl -sS -D - https://suglow.com/ -o /tmp/suglow-home.html
    curl -sS https://suglow.com/product/<KNOWN_PRODUCT_SLUG> -o /tmp/suglow-product.html
    grep -Ei '<title|meta name="description"|canonical|application/ld\+json|<h1' /tmp/suglow-product.html
    ```
  - PageSpeed/Lighthouse mobile ও desktop reports সংরক্ষণ করুন।
  - **Acceptance:** FCP, LCP, CLS, JS transfer/unused JS এবং raw-HTML SEO baseline recorded।

---

## 2. `robots.txt`, `llms.txt` ও Sitemap

- [x] **P0 — বিদ্যমান `public/robots.txt` audit ও replace করুন**
  - **Dependency:** Document root confirmed
  - বর্তমান file মাত্র minimal rules রাখে; production routes অনুযায়ী public pages allow এবং admin/API/cart/checkout/account routes appropriately disallow করুন।
  - প্রতিটি named bot block independent ধরে প্রয়োজনীয় rules repeat করুন।
  - Sitemap line যোগ করুন:
    ```text
    Sitemap: https://suglow.com/sitemap.xml
    ```
  - **Acceptance:**
    ```bash
    curl -sSI https://suglow.com/robots.txt
    curl -sS https://suglow.com/robots.txt
    ```
    Response `200`, plain text, Laravel HTML shell নয়।

- [x] **P1 — `public/llms.txt` তৈরি করুন**
  - **Dependency:** Brand/store claims approved
  - প্রথম line `# Suglow` রাখুন।
  - Store identity, product categories, geographic coverage, canonical URLs এবং verified policies লিখুন।
  - যাচাইহীন “100% authentic”, “authorised channels”, “same-day dispatch” বা অনুরূপ claim যোগ করবেন না।
  - **Acceptance:**
    ```bash
    curl -sSI https://suglow.com/llms.txt
    curl -sS https://suglow.com/llms.txt | head -3
    ```
    Response plain text এবং প্রথম line `# Suglow`।

- [x] **P0 — Project-compatible sitemap generator implement করুন**
  - **Dependency:** Routes/status rules audited
  - Generated `GenerateSitemap.php` adapt করে:
    - `App\Models\ProductCategory` ও প্রকৃত `product_categories` table ব্যবহার করুন।
    - Product URL `/product/{slug}` এবং category/listing-এর প্রকৃত public URL ব্যবহার করুন।
    - শুধু active, non-deleted, indexable records include করুন।
    - nonexistent static routes (`/about`, `/contact`, `/blog`, `/brands`) hard-code করবেন না; `routes`/published pages থেকে resolve করুন।
    - large datasets chunk করুন এবং output path `<PUBLIC_WEB_ROOT>/sitemap.xml` configurable রাখুন।
    - XML-এ canonical HTTPS URLs ও valid `lastmod` দিন।
  - `spatie/laravel-sitemap` install করার আগে lockfile check করুন; প্রয়োজন হলে:
    ```bash
    composer require spatie/laravel-sitemap
    ```
  - **Acceptance:**
    ```bash
    php artisan sitemap:generate --path="<PUBLIC_WEB_ROOT>"
    curl -sSI https://suglow.com/sitemap.xml
    curl -sS https://suglow.com/sitemap.xml | head
    ```
    Valid XML, correct content type, কোনো admin/API/private/deleted URL নেই।

- [x] **P1 — Sitemap regeneration schedule করুন**
  - **Dependency:** Sitemap generator verified
  - Laravel scheduler-এ daily generation register করুন।
  - cPanel cron দিয়ে `php artisan schedule:run` প্রতি minute চালান, অথবা direct daily command ব্যবহার করুন।
  - failure log এবং writable output-path check রাখুন।
  - **Acceptance:** Manual ও scheduled run একই valid sitemap তৈরি করে; failure observable।

- [ ] **P1 — Sitemap search engines-এ submit করুন**
  - **Dependency:** Public sitemap verified
  - Google Search Console-এ `https://suglow.com/sitemap.xml` submit করুন।
  - discovered বনাম indexed URLs এবং parsing errors monitor করুন।
  - **Acceptance:** Sitemap status success এবং কোনো format/fetch error নেই।

---

## 3. বিদ্যমান `product_seos` Table-এ Spreadsheet Import

- [x] **P0 — Spreadsheet schema ও matching key audit করুন**
  - **Dependency:** Database backup
  - `<SEO_SPREADSHEET_PATH>` workbook-এর sheet name, headings এবং sample rows যাচাই করুন।
  - Product matching priority স্থির রাখুন:
    1. exact SKU, যদি spreadsheet-এ থাকে;
    2. exact normalized product name;
    3. কোনো ambiguous/fuzzy match হলে update নয়—report।
  - duplicate spreadsheet rows এবং duplicate product matches আলাদা report করুন।
  - **Acceptance:** Column mapping ও deterministic matching rules documented এবং sample rows দিয়ে verified।

- [x] **P0 — Import command-টি বর্তমান schema অনুযায়ী adapt করুন**
  - **Dependency:** Spreadsheet audit
  - `products.meta_title/meta_description/meta_keywords` ব্যবহার করবেন না।
  - `product_seos` mapping:
    - spreadsheet title → `title`
    - spreadsheet description/three blocks → `description`
    - spreadsheet keywords → valid JSON array in `meta_keyword`
    - matched product ID → `product_id`
  - installed `maatwebsite/excel` ব্যবহার করুন; duplicate Excel package install করবেন না।
  - `--file`, `--sheet`, `--dry-run` options রাখুন।
  - create/update উভয় path support করুন; এক product-এর একটিমাত্র SEO record enforce/check করুন।
  - writes transaction-safe করুন; unmatched, duplicate, invalid ও unchanged counts report/log করুন।
  - **Acceptance:** Command missing file/sheet/columns-এ clean failure দেয় এবং dry-run database পরিবর্তন করে না।

- [x] **P0 — Import dry-run review করুন**
  - **Dependency:** Adapted importer
  - **Command:**
    ```bash
    php artisan seo:import --file="<SEO_SPREADSHEET_PATH>" --sheet="<SHEET_NAME>" --dry-run
    ```
  - unmatched ও duplicate reports manually review করুন।
  - কোনো fallback যেন first/random/partial product update না করে।
  - **Acceptance:** Match rate acceptable, zero ambiguous writes, expected create/update/skip totals confirmed।

- [x] **P0 — SEO import execute ও verify করুন**
  - **Dependency:** Approved dry-run এবং fresh database backup
  - **Command:**
    ```bash
    php artisan seo:import --file="<SEO_SPREADSHEET_PATH>" --sheet="<SHEET_NAME>"
    ```
  - counts এবং sample product SEO records verify করুন।
  - একই import দ্বিতীয়বার চালিয়ে idempotency check করুন।
  - **Acceptance:** Expected records populated, JSON keywords valid, rerun duplicate records তৈরি করে না।

---

## 4. Product Meta, Canonical, Open Graph ও Twitter Tags

- [x] **P0 — Product API SEO payload normalize করুন**
  - **Dependency:** SEO schema confirmed
  - `ProductSeoResource`-এ `meta_keyword` সবসময় predictable array হিসেবে return করুন।
  - canonical URL, absolute social image এবং safe fallback values frontend-এ derive করার contract নির্ধারণ করুন।
  - meta description-এর জন্য three-block content থেকে শুধু প্রথম concise block ব্যবহার করুন; full description data visible AEO/GEO section-এর জন্য preserve করুন।
  - **Acceptance:** SEO record থাকা/না থাকা দুই অবস্থাতেই stable response shape পাওয়া যায়।

- [x] **P0 — বর্তমান `useHead()` logic ঠিক করুন**
  - **Dependency:** Normalized SEO payload
  - `resources/js/components/frontend/product/ProductDetailsComponent.vue`-এ:
    - unique document title;
    - `description` ও `keywords`;
    - canonical `<link>`;
    - `robots`;
    - Open Graph-এর correct `property` attributes;
    - Twitter-এর correct `name` attributes;
    - absolute product image এবং canonical URL;
    - stable keys/identifiers দিয়ে route navigation-এ replacement;
    - missing SEO data-এর sensible product fallback যোগ করুন।
  - বর্তমান unnamed image meta entries এবং `name="title"` meta বাদ দিন।
  - **Acceptance:** Product-to-product client navigation-এ duplicate/stale tags নেই এবং current product-এর সব tags সঠিক।

- [ ] **P1 — Sitewide default head metadata যোগ করুন**
  - **Dependency:** Product head implementation
  - homepage, listing, page, search এবং error views-এর জন্য unique/default title, description, canonical ও robots policy যোগ করুন।
  - query/filter pages-এর canonical/noindex policy explicit করুন।
  - **Acceptance:** কোনো public route blank title/description দেয় না; private/duplicate routes intended robots policy পায়।

---

## 5. Server-rendered Product SEO ও JSON-LD Strategy

- [x] **P0 — বর্তমান SPA-compatible rendering approach নির্বাচন ও proof-of-concept করুন**
  - **Dependency:** Hosting capability baseline
  - Generated Inertia SSR files ব্যবহার করবেন না।
  - Preferred order:
    1. Laravel web route থেকে product-specific server-rendered head + JSON-LD এবং Vue app hydration/mount;
    2. Vue Router-compatible SSR যদি hosting persistent Node process নির্ভরযোগ্যভাবে চালাতে পারে;
    3. controlled prerender service/build only if dynamic inventory freshness ও deployment workflow acceptable।
  - একটি known product URL-এ proof-of-concept করে raw response HTML যাচাই করুন।
  - **Acceptance:** JavaScript execute না করেও raw HTML-এ product-specific title, description, canonical এবং JSON-LD পাওয়া যায়।

- [x] **P0 — Product web route ও server SEO data flow implement করুন**
  - **Dependency:** Rendering proof-of-concept approved
  - `/product/{product:slug}` request catch-all-এর আগে resolve করুন।
  - Active/indexable product এবং তার SEO/media/pricing data load করুন।
  - `master.blade.php`-তে escaped metadata এবং safely encoded JSON-LD pass করুন।
  - Vue Router যেন একই URL client-side navigation-এ চালাতে পারে।
  - missing/inactive products proper HTTP 404/robots behavior দেবে।
  - **Acceptance:** Direct product request product-specific raw HTML দেয়; SPA interaction ও client navigation অক্ষত থাকে।

- [x] **P0 — Product structured data adapt করুন**
  - **Dependency:** Server SEO data flow
  - Generated `SeoSchema.php`-কে প্রকৃত fields অনুযায়ী map করুন:
    - `Product`: name, description, image, SKU, brand/category যেখানে data আছে;
    - `Offer`: numeric current price, `BDT`, canonical URL এবং প্রকৃত stock status;
    - aggregate rating শুধু valid reviews/count থাকলে;
    - FAQ শুধু page-এ দৃশ্যমান এবং verified Q&A থাকলে।
  - fabricated `priceValidUntil`, seller claims, shipping promise বা authenticity claim দেবেন না।
  - JSON `json_encode` দিয়ে safely render করুন।
  - **Acceptance:** Google Rich Results Test এবং Schema.org validator-এ blocking error নেই; schema visible content/data-এর সঙ্গে মেলে।

- [ ] **P1 — Homepage Organization/WebSite schema যোগ করুন**
  - **Dependency:** Server-rendered head pipeline
  - বাস্তব logo, company URL ও working search URL ব্যবহার করুন।
  - `SearchAction` কেবল public search route সত্যিই একই parameter support করলে include করুন।
  - **Acceptance:** Homepage raw HTML-এ একটিমাত্র valid Organization/WebSite graph আছে।

---

## 6. Visible AEO/GEO Content

- [ ] **P1 — Spreadsheet three-block description parser verify করুন**
  - **Dependency:** Imported data
  - Block 1 = meta summary, block 2 = visible Q&A, block 3 = visible structured product facts—এই format সব rows-এ consistent কি না audit করুন।
  - malformed বা missing blocks gracefulভাবে skip করুন।
  - **Acceptance:** Representative English/Bangla/multiline samples সঠিকভাবে parse হয়।

- [x] **P1 — `ProductSeoBlocks.vue` adapt ও integrate করুন**
  - **Dependency:** Parser verified
  - Product description area-তে accessible headings, answer paragraph এবং `<dl>` facts render করুন।
  - raw HTML ব্যবহার না করে Vue escaping বজায় রাখুন।
  - visible FAQ content এবং JSON-LD FAQ একই source থেকে তৈরি করুন।
  - শুধু SEO-এর জন্য user থেকে hidden text যোগ করবেন না।
  - **Acceptance:** Content mobile/desktop-এ readable, source data না থাকলে section render হয় না, schema/content mismatch নেই।

---

## 7. Image CLS ও LCP Fix

- [ ] **P1 — Layout-shifting images inventory করুন**
  - **Dependency:** Performance baseline
  - product gallery, cards, category images, banners, logos এবং lazy sections audit করুন।
  - প্রতিটি image-এর intrinsic dimensions/aspect ratio এবং LCP candidate record করুন।
  - **Acceptance:** Missing dimension/aspect-ratio images ও current LCP element-এর তালিকা আছে।

- [x] **P1 — `ProductImage.vue` pattern project-এ adapt করুন**
  - **Dependency:** Image inventory
  - wrapper দিয়ে space reserve, correct `width`/`height`, `alt`, `decoding` ও loading policy দিন।
  - main product image-এ eager/high priority; below-the-fold images-এ lazy loading ব্যবহার করুন।
  - actual project placeholder path ব্যবহার করুন এবং fallback একবারের বেশি retry না করে।
  - gallery/card styling ও responsive images নষ্ট না হয় নিশ্চিত করুন।
  - **Acceptance:** Broken image infinite request loop নেই; before-load ও after-load layout dimensions stable।

- [ ] **P1 — Fonts ও dynamic sections-এর CLS কমান**
  - **Dependency:** Image fixes
  - font loading strategy, fallback metrics, banners/carousels এবং async product sections-এর reserved height audit করুন।
  - unnecessary render-blocking font variants সরান/preload only critical fonts।
  - **Acceptance:** Mobile Lighthouse CLS `< 0.1` target-এর কাছাকাছি/নিচে এবং visual regression নেই।

---

## 8. JavaScript Bundle Splitting

- [ ] **P1 — Bundle composition analyze করুন**
  - **Dependency:** Clean production build
  - **Commands:**
    ```bash
    npm run build
    find public/build/assets -name '*.js' -maxdepth 1 -exec ls -lh {} \;
    ```
  - chart, editor, Firebase, Swiper, admin এবং storefront dependencies কোন chunks-এ যাচ্ছে identify করুন।
  - **Acceptance:** Largest initial chunks এবং frontend route-এ unused heavy dependencies documented।

- [ ] **P1 — Route/component lazy loading সম্পূর্ণ করুন**
  - **Dependency:** Bundle analysis
  - eager-loaded frontend/admin components audit করুন; initial shell-এর জন্য অপরিহার্য নয় এমন components dynamic import করুন।
  - admin-only libraries storefront initial bundle-এ না আসে নিশ্চিত করুন।
  - **Acceptance:** Home/product direct load functional এবং initial JS transfer baseline থেকে কম।

- [x] **P2 — Safe manual chunking current Vite config-এ merge করুন**
  - **Dependency:** Lazy loading
  - Generated Vite config replace করবেন না।
  - Current `optimizeDeps`, input ও Vue plugin settings preserve করে measured dependencies-এর জন্য সীমিত manual chunks যোগ করুন।
  - circular chunk warning বা oversized shared vendor chunk হলে rules simplify করুন।
  - **Acceptance:** `npm run build` warning/error ছাড়া pass; কোনো single initial chunk target `150 KiB`-এর বেশি হলে reason documented।

---

## 9. Deployment ও Final Verification

- [ ] **P0 — Staging/production-like environment-এ full regression চালান**
  - **Dependency:** All selected implementation Todos complete
  - **Commands:**
    ```bash
    composer install --no-dev --optimize-autoloader
    php artisan optimize:clear
    php artisan test
    npm ci
    npm run build
    ```
  - Homepage, product listing/details, cart, checkout, account এবং admin product SEO edit smoke-test করুন।
  - **Acceptance:** Build/tests pass এবং critical commerce flow regression নেই।

- [ ] **P0 — Production deploy করুন**
  - **Dependency:** Regression pass এবং rollback point
  - migrations/package changes deploy order অনুযায়ী চালান।
  - writable paths, cache, scheduler এবং sitemap output permission verify করুন।
  - deploy শেষে relevant Laravel caches rebuild করুন।
  - **Acceptance:** Application healthy; asset, API, product এবং admin requests expected status দেয়।

- [ ] **P0 — Crawler-visible output verify করুন**
  - **Dependency:** Production deploy
  - **Commands:**
    ```bash
    curl -sSI https://suglow.com/robots.txt
    curl -sSI https://suglow.com/llms.txt
    curl -sSI https://suglow.com/sitemap.xml
    curl -sS https://suglow.com/product/<KNOWN_PRODUCT_SLUG> \
      | grep -Ei '<title|meta name="description"|canonical|application/ld\+json|<h1'
    ```
  - **Acceptance:** Correct status/content types এবং product-specific raw HTML পাওয়া যায়।

- [ ] **P0 — SEO correctness verify করুন**
  - **Dependency:** Crawler verification
  - Product direct load এবং client-side product-to-product navigation inspect করুন।
  - canonical/OG/Twitter absolute URLs, single head tag instances এবং JSON-LD validate করুন।
  - Google Rich Results Test ও URL Inspection চালান।
  - **Acceptance:** Duplicate/stale tags নেই; structured data blocking error নেই; rendered HTML indexable।

- [ ] **P1 — Performance পুনরায় মাপুন**
  - **Dependency:** Production caches warm
  - একই baseline URLs ও device profiles দিয়ে PageSpeed/Lighthouse rerun করুন।
  - লক্ষ্য:
    - CLS `< 0.1`
    - LCP `< 2.5s`
    - FCP `< 1.8s`
    - Performance `70+`, পরবর্তী iteration-এ `80+`
    - Initial unused JS উল্লেখযোগ্যভাবে কম
  - **Acceptance:** Before/after report সংরক্ষিত; unmet target-এর bottleneck ও next action documented।

- [ ] **P1 — Post-deploy monitoring করুন**
  - **Dependency:** Production verification
  - Laravel logs, sitemap job, Search Console indexing/coverage, 404s এবং Core Web Vitals অন্তত 2–4 সপ্তাহ monitor করুন।
  - SEO importer বা schema-related exception alert করুন।
  - **Acceptance:** কোনো sustained crawler/server error নেই এবং index coverage trend documented।

---

## Definition of Done

- [ ] `robots.txt`, `llms.txt` ও sitemap public এবং correct content type-এ served।
- [x] SEO spreadsheet নিরাপদে বিদ্যমান `product_seos` table-এ imported।
- [ ] Direct product URL-এর raw HTML-এ unique metadata, canonical এবং valid JSON-LD আছে।
- [ ] Client navigation duplicate/stale head tags তৈরি করে না।
- [ ] AEO/GEO content visible, accurate এবং structured data-এর সঙ্গে consistent।
- [ ] Product/gallery images layout space reserve করে এবং broken fallback loop নেই।
- [ ] Production build ও critical regression tests pass।
- [ ] PageSpeed/CLS/LCP before-after measurements এবং remaining follow-up documented।
