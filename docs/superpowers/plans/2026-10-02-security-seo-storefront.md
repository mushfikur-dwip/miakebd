# Security, Search Visibility & Storefront Quality — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the security holes found in the 2026-10-02 audit, fix the search-visibility gaps (Google, Bing, AI answer engines), and remove the storefront's layout shift / cropped banners / unlabeled controls.

**Architecture:** Three independent parts that ship together. A (security) is server-only: a middleware, a value binder, a validation rule + env-writer subclass, an allow-list. B (search) extends the existing server-rendered SEO layer (`RootController` → `master.blade.php`) with a path classifier, a storefront 404 renderer, new meta resolvers and an IndexNow submitter on the existing scheduler. C (frontend) is small Vue edits verified in real Chrome against live data.

**Tech Stack:** Laravel 12 / PHP 8.4 (PHPUnit 11, in-memory SQLite), Vue 3 + vue-router 4 + @vueuse/head, Tailwind 3, Vite 6, maatwebsite/excel 3.1, puppeteer-core 23 (scratchpad only).

**Spec:** `docs/superpowers/specs/2026-10-02-security-seo-storefront-audit.md`

## Global Constraints

- PHP is `C:\Users\User\.config\herd\bin\php.bat`; run tests as `cmd //c "C:\Users\User\.config\herd\bin\php.bat vendor\bin\phpunit <path>"`. Never run `config:cache`, `migrate`, `db:seed` or tinker writes locally — the local `.env` is the production database.
- No test may write the real `.env`: any test that can reach the env editor sets `config(['enveditor.pathToEnv' => <temp file>])` first (installer tests snapshot and restore `.env` as well).
- Canonical/absolute URLs come from `config('app.url')`, never from the request host.
- Do not touch the other session's uncommitted files (dashboard, employee, order-list, report components, `resources/js/languages/*.json`, `store/modules/{dashboard,employee}.js`). Reuse existing i18n keys: `label.search`, `label.cart`, `button.profile`, `button.filter`.
- No commits: the user has not asked for any, and another session's uncommitted work shares this tree.
- Baseline: 361 tests pass. Every task ends with the full suite green.

## Review Focus

1. A real storefront route missing from the server's path list would be answered 404 (page still renders, but Google/WhatsApp drop it) — Task B1's test reads every `path:` in `frontendRoutes.js` / `authRoutes.js` and asserts none classifies as missing.
2. The storefront-404 renderer running for asset/probe requests (`/storage/x.png`, `/wp-login.php`) would cost a full shell render per bot hit — Task B1 asserts both get the plain 404 without `<div id="app">`.
3. `position: sticky` silently failing because an ancestor sets `overflow` — Task C1's browser check asserts the header is still at the top of the viewport after scrolling.
4. IndexNow re-submitting the whole catalogue on every request via ScheduleFallback — Task B6 asserts a second run with no changes sends nothing.
5. The env-safety rule blocking a legitimate save (e.g. mail password containing `\`) without saying why — Task A3 pins the exact validation message.

---

## Part A — Security

### Task 1 (A1): Lock the installer once installed

**Files:**
- Create: `app/Http/Middleware/EnsureNotInstalled.php`
- Modify: `bootstrap/app.php` (alias), `routes/web.php:19` (install group middleware), `app/Http/Controllers/Installer/InstallerController.php:30-32` (constructor)
- Test: `tests/Feature/InstallerLockTest.php`

**Interfaces:**
- Produces: middleware alias `not-installed` → `EnsureNotInstalled::handle()` aborts 404 when `storage_path('installed')` exists.

- [ ] **Step 1: Write the failing tests.** `setUp` snapshots `.env` bytes; `tearDown` restores them if changed. Bind a Mockery mock of `InstallerService` with `shouldNotReceive('siteSetup','databaseSetup','finalSetup','licenseCodeChecker','checkDatabaseConnection')`.

```php
public function test_installer_pages_are_gone_once_installed(): void
{
    foreach (['/install', '/install/requirement', '/install/permission', '/install/license',
              '/install/site', '/install/database', '/install/final'] as $path) {
        $this->get($path)->assertNotFound();
    }
}

public function test_installer_actions_never_run_once_installed(): void
{
    $this->post('/install/site', ['app_name' => 'X', 'app_url' => 'https://evil.example'])->assertNotFound();
    $this->post('/install/database', ['database_host' => 'evil.example', 'database_port' => 3306,
        'database_name' => 'x', 'database_username' => 'x', 'database_password' => 'x'])->assertNotFound();
    $this->post('/install/license', ['license_key' => 'abc'])->assertNotFound();
    $this->get('/install/final-store')->assertNotFound();
    $this->assertSame($this->envBefore, file_get_contents(base_path('.env')));
}
```

- [ ] **Step 2: Run** `... phpunit tests\Feature\InstallerLockTest.php` — expect FAIL (302s, mock receives calls).
- [ ] **Step 3: Implement** the middleware (404 via `abort(404)` when installed), alias it in `bootstrap/app.php`, apply `['web', 'not-installed']` to the install group, and replace the constructor's `Redirect::to(...)->send()` with `abort(404)` (defence in depth; drop the unused `Redirect` import).
- [ ] **Step 4: Run** the test file, then the full suite — expect PASS.

### Task 2 (A2): Exports never emit formulas from data

**Files:**
- Create: `app/Support/SafeExcelValueBinder.php`
- Modify: `app/Providers/AppServiceProvider.php` (`boot()`)
- Test: `tests/Feature/ExportFormulaInjectionTest.php`

**Interfaces:**
- Produces: `SafeExcelValueBinder extends Maatwebsite\Excel\DefaultValueBinder` with `bindValue(Cell $cell, $value): bool` — strings whose first character is `=` are stored with `setValueExplicit($value, DataType::TYPE_STRING)`; everything else defers to the parent. `boot()` sets `config(['excel.value_binder.default' => SafeExcelValueBinder::class])`.

- [ ] **Step 1: Write the failing test.** An anonymous `FromArray` export of `[['=HYPERLINK("https://evil.example","x")', 42, '0171']]`, rendered with `Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX)`, written to a temp file and read back with `PhpOffice\PhpSpreadsheet\IOFactory::load()`:

```php
$this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
$this->assertSame('=HYPERLINK("https://evil.example","x")', $sheet->getCell('A1')->getValue());
$this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B1')->getDataType()); // numbers stay numbers
$this->assertSame('0171', $sheet->getCell('C1')->getValue());                   // leading zero kept
```

- [ ] **Step 2: Run** — expect FAIL (A1 is TYPE_FORMULA).
- [ ] **Step 3: Implement** the binder and the `boot()` config line.
- [ ] **Step 4: Run** the test, then the full suite — expect PASS.

### Task 3 (A3): Settings that write `.env` cannot break or extend it

**Files:**
- Create: `app/Rules/EnvSafe.php`, `app/Support/SafeEnvEditor.php`
- Modify: `app/Http/Requests/CompanyRequest.php` (`company_name`), `app/Http/Requests/MailRequest.php` (every field written to `.env`: host, port, username, password, encryption, from email, from name), `app/Http/Requests/LicenseRequest.php` (`license_key` rule + `new EnvEditor()` → `app(EnvEditor::class)`), `app/Services/InstallerService.php` and `app/Http/Controllers/Installer/InstallerController.php` (`new EnvEditor()` → `app(EnvEditor::class)`), `app/Providers/AppServiceProvider.php` (`register()`: bind `EnvEditor::class` → `SafeEnvEditor::class`)
- Test: `tests/Feature/EnvWriteSafetyTest.php`

**Interfaces:**
- Produces: `App\Rules\EnvSafe` (`ValidationRule`) failing with exactly `The :attribute may not contain quotes, backslashes, line breaks or ${…}.`; `EnvSafe::isSafe(?string $value): bool` (static, shared with the editor). `SafeEnvEditor::addData($data = [])` throws `InvalidArgumentException("Refusing to write {KEY} to .env: it contains quotes, backslashes, line breaks or \${…}.")` before writing anything when any value fails `isSafe`.

- [ ] **Step 1: Write the failing tests.** `setUp`: copy `.env.example` to a temp file and `config(['enveditor.pathToEnv' => $tmp])`; sign in an administrator the way `AdminAccessTest` does.
  - `test_company_name_with_a_quote_is_refused_and_env_untouched`: `PUT /api/admin/setting/company` with `company_name = 'Suglow "BD"'` (other required fields valid) → 422, `errors.company_name.0` === `'The company name may not contain quotes, backslashes, line breaks or ${…}.'`; temp env bytes unchanged.
  - `test_plain_company_name_is_written`: `company_name = 'Suglow BD'` → 200; temp env contains `APP_NAME="Suglow BD"`.
  - `test_editor_refuses_injection_from_any_caller`: `app(EnvEditor::class)` is a `SafeEnvEditor`; `addData(['APP_NAME' => "x\nAPP_DEBUG=true"])` and `addData(['APP_NAME' => '${DB_PASSWORD}'])` each throw `InvalidArgumentException`; temp env unchanged.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement** rule, editor subclass, request rules, container binding, call-site swaps. `isSafe`: null/'' safe; unsafe if it contains `"`, `\`, `\r`, `\n`, `\0` or `${`.
- [ ] **Step 4: Run** the test, then the full suite — expect PASS.

### Task 4 (A4): Gateway allow-list; remove dead and crashing routes

**Files:**
- Modify: `app/Services/PaymentManagerService.php` (`gateway()`), `app/Http/Controllers/Frontend/PaymentController.php` (`payment()`), `routes/api.php` (delete `checkout/{order}/{paymentGateway}/payment|success|fail|cancel`, `frontend/language/{code}`, `frontend/overview` index; keep `GET /api/checkout`)
- Test: `tests/Feature/PaymentGatewayResolutionTest.php`

**Interfaces:**
- Produces: `PaymentManagerService::gateway(...$args): static` throws `InvalidArgumentException('Unknown payment gateway.')` unless the name matches `/^[A-Za-z0-9]+$/`, the class exists and `is_subclass_of($class, PaymentAbstract::class)`. `PaymentController::payment()` turns that exception into the same "gateway disabled" redirect it already uses (no 500).

- [ ] **Step 1: Write the failing tests:** `gateway('Nonexistent')`, `gateway('..\\Models\\User')`, `gateway('')` each throw `InvalidArgumentException`; `gateway('cashondelivery')` returns the manager with a `Cashondelivery` gateway; `GET /api/checkout/1/cashondelivery/success` (as a signed-in customer) → 404; `GET /api/frontend/language/en` → 404; `GET /api/frontend/overview` → 404; `GET /api/frontend/overview/total-orders` unauthenticated → still 401 (group untouched).
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test, `tests\Feature\PaymentSecurityTest.php`, then the full suite — expect PASS.

### Task 5 (A5): Permissions-Policy header

**Files:** Modify `app/Http/Middleware/SecurityHeaders.php`; Test: `tests/Feature/SecurityHeadersTest.php`

- [ ] **Step 1: Failing test:** `GET /` and `GET /api/frontend/setting` both carry `Permissions-Policy: camera=(), microphone=(), geolocation=()` and still carry `X-Frame-Options: SAMEORIGIN`.
- [ ] **Step 2: Run** — FAIL. **Step 3:** add the header. **Step 4:** run test + suite — PASS.

---

## Part B — Search visibility

### Task 6 (B1): Real 404s and noindex for private pages

**Files:**
- Create: `app/Support/StorefrontPaths.php`
- Modify: `app/Http/Controllers/Frontend/RootController.php` (`index()` → classify; new `notFound()`; `shell()` gains `int $status = 200` and returns `Illuminate\Http\Response`), `routes/web.php` (fallback calls `RootController::spa()`), `bootstrap/app.php` (render `NotFoundHttpException` for page requests), `resources/views/master.blade.php` (omit canonical + `og:url` when `$seo['canonical']` is null; delete the hard-coded `googlebot` meta)
- Test: `tests/Feature/StorefrontNotFoundTest.php`

**Interfaces:**
- Produces: `StorefrontPaths::classify(string $path): string` returning `StorefrontPaths::INDEX` (`''`, `flash-sale`, `promotion/*`, `product-section/*`, `campaign/*`, `page/*` — one slug segment), `NOINDEX` (`home`, `wishlist`, `account`, `account/**`, `checkout`, `checkout/**`, `signup`, `signup/verify`, `forgot-password`, `forgot-password/verify`, `forgot-password/reset-password`, `exception`, `admin`, `admin/**`) or `MISSING`; `StorefrontPaths::looksLikeFile(string $path): bool` (last segment ends in `.php|.asp|.aspx|.jsp|.cgi|.env|.git|.sql|.bak|.zip|.log|.ini|.yml|.yaml|.xml|.txt|.json|.js|.css|.map|.png|.jpe?g|.gif|.webp|.svg|.ico`). `RootController::notFound(): Response` — shell, status 404, title `Page Not Found | Suglow`, robots `noindex, follow`, canonical null. `RootController::spa(Request $request)`.
- Exception rendering (bootstrap/app.php): for `NotFoundHttpException` when `!$request->expectsJson()`, method GET/HEAD, path not `api/*` or `storage/*`, and `!looksLikeFile` → `app(RootController::class)->notFound()`; any throwable while rendering falls back to Laravel's default.

- [ ] **Step 1: Write the failing tests** (use the `noscript`/`title` helper style of `BrandPageTest`):
  - unknown `/this-page-does-not-exist` → 404, body has `<div id="app">`, `<meta name="robots" content="noindex, follow">`, no `rel="canonical"`, title `Page Not Found | Suglow`.
  - `/product/no-such-product`, `/product-category/no-such`, `/brand/no-such`, `/blog/no-such` → 404 with `<div id="app">` and noindex.
  - `/wp-login.php` and `/storage/nope.png` → 404 **without** `<div id="app">`.
  - `/api/frontend/nope` → 404 JSON (unchanged).
  - `/account/order-history`, `/checkout/cart-list`, `/signup`, `/wishlist`, `/admin/dashboard` → 200, `<div id="app">`, robots `noindex, follow`.
  - `/` → 200 with robots `index, follow, …` (unchanged); no page contains `name="googlebot"`.
  - `test_every_spa_route_is_known_to_the_server`: regex every `path: "..."` / `path: '...'` in `resources/js/router/modules/frontendRoutes.js` and `authRoutes.js`; join children to their parent (`/account` + `overview`), substitute `:param` with `x`. For each path: if `Route::getRoutes()->match(Request::create($path))` resolves to a route other than the fallback, it is owned by an explicit web route — pass; otherwise assert `StorefrontPaths::classify()` ≠ `MISSING`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.** `index()` keeps homepage behaviour for `/`.
- [ ] **Step 4: Run** the test, `BrandPageTest`, `CategorySlugTest`, `ProductSeoTest`, then the full suite — expect PASS.

### Task 7 (B2): Home lives at `/`

**Files:**
- Modify: `routes/web.php` (`GET /home` → 301 to `/`), `resources/js/router/modules/frontendRoutes.js` (home record `path: "/"`; add `{ path: "/home", redirect: "/" }`), `resources/js/router/index.js` (delete the `/` → `frontend.home` redirect record), `FrontendNavBarComponent.vue:39` and `FrontendMobileNavBarComponent.vue:3` (`checkIsPathAndRoutePathSame('/home')` → `('/')`)
- Test: add `test_home_alias_redirects_permanently` to `StorefrontNotFoundTest` (`GET /home` → 301, `Location` ends with `/`, query string kept); browser check in Task C1.

- [ ] Steps: failing test → run (FAIL) → implement → run test + suite (PASS).

### Task 8 (B3): CMS and promo pages render their own metadata

**Files:**
- Create: `app/Support/PageMetaResolver.php`
- Modify: `RootController` (`page()`, `flashSale()`, `promotion()`, `productSection()`, `campaign()`), `routes/web.php` (routes before the fallback, `installed` middleware, slug `[A-Za-z0-9\-_.]+`), `master.blade.php` (noscript branch `@elseif (!empty($cmsPage))`: `<h1>` + raw body, same trust level as blog bodies)
- Test: `tests/Feature/ContentPageSeoTest.php`

**Interfaces:**
- Produces: `PageMetaResolver::forSlug(string $slug): ?array` → `['name', 'title' => "{name} | Suglow", 'description' (≤155 chars, tags stripped, whitespace collapsed, cut at a word boundary; fallback "{name} — Suglow, authentic cosmetics and skincare in Bangladesh."), 'url', 'type' (`AboutPage` if slug contains "about", `ContactPage` if it contains "contact" or "support", else `WebPage`), 'body' (raw HTML)]` for an ACTIVE page, else null. `PageMetaResolver::structuredData(array $meta): array` → `@graph` of the typed page (`isPartOf` `#website`, `publisher` `#organization`) + BreadcrumbList (Home › name).
- Promo pages: title `"{name} — Offers on Authentic Cosmetics | Suglow"`, description `"Shop {name} at Suglow: authentic skincare and cosmetics with cash on delivery across Bangladesh."`; Promotion needs `status == Status::ACTIVE`, ProductSection `active()`, Campaign `running()`; otherwise `notFound()`. Flash sale: title `Flash Sale — Limited-Time Deals on Authentic Cosmetics | Suglow`.

- [ ] **Step 1: Failing tests:** active page "About Us" (slug `about-us`, body `<p>Suglow imports from Malaysia.</p>`) → 200, title `About Us | Suglow`, description `Suglow imports from Malaysia.`, canonical `https://suglow.com/page/about-us` (set `config(['app.url' => 'https://suglow.com'])`), JSON-LD graph contains `@type` `AboutPage`, noscript contains `Suglow imports from Malaysia.`; slug `support` → `ContactPage`; inactive page → 404 storefront shell; unknown slug → 404; active promotion → title contains its name, inactive → 404; active product section → 200, inactive → 404; running campaign → 200, ended campaign → 404; `/flash-sale` → 200 with the flash-sale title.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4:** run test + suite — PASS.

### Task 9 (B4): Entity and facts consistency

**Files:**
- Modify: `app/Support/SiteSchema.php` (Organization `sameAs`; FAQ product count), `RootController::product()` (`'brand' => SeoSchema::brandName($product)`), `public/llms.txt`
- Test: `tests/Feature/SiteSchemaTest.php`

**Interfaces:**
- Produces: `SiteSchema::sameAs(): array` — `social_media_facebook|instagram|twitter|youtube` from `Settings::group('social_media')` (one `get` per key), absolute `http(s)` URLs only; key omitted from the Organization when empty. `SiteSchema::productCountPhrase(): string` — n = active storefront products (`status` ACTIVE + `storefront()` scope); `n < 100` → `"{n} products"`, else `"over " . number_format(floor(n/100)*100) . " products"`; cached 6 h under `seo:product-count`; on any failure `"hundreds of products"`.

- [ ] **Step 1: Failing tests:** with facebook + youtube set and instagram/twitter null, the homepage Organization `sameAs` equals exactly those two; with none set, no `sameAs` key; FAQ "What products does Suglow sell?" answer contains `stocks 3 products` given 3 active storefront products + 1 inactive + 1 POS-only (flush `seo:product-count` first); a product whose brand is the default/placeholder brand renders no `product:brand` meta while a real brand does.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement**; update llms.txt (add "Company and policy pages": About, Support, Legal; Blog; Most popular; keep "1,100+"). **Step 4:** run test + `ProductSeoTest` + suite — PASS.

### Task 10 (B5): Sitemap completeness and product images

**Files:** Modify `app/Console/Commands/GenerateSitemap.php`; Test: `tests/Feature/SitemapTest.php`

**Interfaces:** `writeUrl()` gains `array $images = []`; the urlset declares `xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"`; products are chunked `->with('media')`; image URLs are the product's photos as the JSON-LD lists them (`previews`, else `cover`), skipping `/images/default/` placeholders, max 10 per product.

- [ ] **Step 1: Failing test:** run `sitemap:generate --path=<temp dir>`; XML contains `<loc>…/most-popular</loc>`, declares the image namespace, a product with an uploaded photo (media set up as in `ProductSeoTest`) has `<image:loc>`, a product without photos has none.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4:** run test + suite — PASS.

### Task 11 (B6): IndexNow

**Files:**
- Create: `app/Support/IndexNow.php`, `app/Console/Commands/IndexNowSubmit.php`
- Modify: `routes/web.php` (key file route, before the fallback), `routes/console.php` (`Schedule::command('indexnow:submit')->hourly()->withoutOverlapping();`), `app/Support/ScheduleFallback.php` (`'indexnow' => [3600, fn () => Artisan::call('indexnow:submit')]`), `config/services.php` (`'indexnow' => ['enabled' => env('INDEXNOW_ENABLED'), 'endpoint' => 'https://api.indexnow.org/indexnow']`)
- Test: `tests/Feature/IndexNowTest.php`

**Interfaces:**
- `IndexNow::key(): string` — `substr(hash_hmac('sha256', 'indexnow', (string) config('app.key')), 0, 32)`.
- `IndexNow::enabled(): bool` — `config('services.indexnow.enabled')` when not null, else `app()->environment('production')`.
- `IndexNow::urls(?Carbon $since): array` — absolute URLs of active storefront products, active categories, `BrandMetaResolver::all()` brands, active pages, published blog posts updated after `$since` (all when null) plus soft-deleted products deleted after `$since`; unique.
- `IndexNow::submit(array $urls): bool` — POST JSON `{host, key, keyLocation, urlList}` in chunks of 10,000 to the endpoint; true when every response is 200 or 202.
- Command `indexnow:submit {--all}` — `--all` or no `indexnow:last-run` in cache → all URLs; otherwise since last run. Stores `now()` as last run only after a successful submit; prints `IndexNow disabled (APP_ENV=…)` and exits 0 when disabled; prints `Nothing changed.` and sends nothing when the list is empty.
- Route: `GET /{indexNowKey}.txt` where `[a-f0-9]{32}` → `text/plain` key when it equals `IndexNow::key()`, else 404.

- [ ] **Step 1: Failing tests** (`Http::fake()`; `config(['services.indexnow.enabled' => true, 'app.url' => 'https://suglow.com'])`): key route serves the key as `text/plain`, wrong key 404; `--all` sends one request whose `urlList` contains the active storefront product URL and not the inactive or POS-only one, with `host` `suglow.com` and `keyLocation` `https://suglow.com/{key}.txt`; an immediate second run sends nothing; after touching one product (`travel(2)->minutes()`), the next run sends exactly that URL; with enabled=false nothing is sent and the command exits 0; a 500 from the endpoint leaves `indexnow:last-run` unchanged.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4:** run test, `ScheduleFallbackTest`, suite — PASS.

---

## Part C — Storefront (verified in Chrome)

Verification harness (scratchpad, not shipped): puppeteer-core loads live suglow.com pages but intercepts the HTML to point its `/build/assets/*` links at the freshly built local `public/build` files (served from disk by the interceptor). API traffic stays live and read-only (GET only; the interceptor aborts any non-GET API request). Measure session-windowed CLS during load and during a scripted scroll, at 390×844 (mobile) and 1366×900.

### Task 12 (C1): Header and footer stop shifting the page

**Files:** Modify `resources/js/components/layouts/frontend/FrontendNavBarComponent.vue` (header always `sticky top-0 z-30 …`; delete `isSticky` and the scroll listener), `resources/js/components/DefaultComponent.vue` (`<main id="main" class="sm:min-h-screen">` around `<router-view>`)

- [ ] **Step 1: Baseline** with the harness against the current build: record CLS (home, category, product; mobile + desktop; load + scroll). Expected today: ≥ 0.13 mobile scroll, ≥ 0.4 desktop home.
- [ ] **Step 2: Implement**, `npm run build`.
- [ ] **Step 3: Verify:** every page's session CLS < 0.1 (mobile and desktop, scroll included); after scrolling 1,500 px `document.elementFromPoint(195, 20)` is inside `header`; `/` stays `/` in the address bar (Task B2) and the Home tab is highlighted; `/home` lands on `/`.

### Task 13 (C2): Hero banners shown whole, space reserved

**Files:** Modify `resources/js/components/frontend/home/SliderComponent.vue`

- [ ] **Step 1: Implement:** slide box `aspect-[1689/600]` at every breakpoint (replaces `aspect-[4/3] sm:aspect-[3/1]`); while `loading.isActive` and no sliders yet, render a same-ratio `bg-gray-100 animate-pulse` placeholder inside the container. `npm run build`.
- [ ] **Step 2: Verify:** first slide's rendered box width/height ≈ 2.815 (±0.02) at 390 px and 1366 px; image `naturalWidth/naturalHeight` equals the box ratio (nothing cropped); home first-load CLS < 0.05 on both viewports.

### Task 14 (C3): Accessible names and alts

**Files:** Modify `FrontendNavBarComponent.vue:138,215,318,346`, `FrontendMobileNavBarComponent.vue:13`, `resources/js/components/frontend/product/ProductComponent.vue:30`, `ProductDetailsComponent.vue:38` (`<inner-image-zoom :alt="index === 0 ? product.name : product.name + ' image ' + (index + 1)">`), `resources/js/components/frontend/page/PageComponent.vue` (image `:alt="page.title"`)

- [ ] **Step 1: Implement** `:aria-label="$t('label.search')"` (both search buttons), `$t('button.profile')` (account), `$t('label.cart')` (both cart buttons), `$t('button.filter')` (filter). `npm run build`.
- [ ] **Step 2: Verify** with the audit script: no unlabeled controls and no visible `<img>` without `alt` on home, category, brand and product pages, mobile and desktop.

### Task 15 (C4): CMS pages set their own head

**Files:** Modify `resources/js/components/frontend/page/PageComponent.vue`

- [ ] **Step 1: Implement** `setup()` with `const seoHead = ref({}); useHead(seoHead);` (the BlogDetailsComponent pattern); when the page loads set `title: \`${page.title} | Suglow\`` and the description meta from the stripped body (≤155 chars). `npm run build`.
- [ ] **Step 2: Verify:** client-side navigation from `/` to `/page/about-us` gives `document.title === 'About Us | Suglow'` (or the live page's title + ` | Suglow`).

---

## Part D — Ship

### Task 16 (D1): Verify everything and package the release

- [ ] Full suite: `cmd //c "...php.bat vendor\bin\phpunit"` — all pass (361 + new). `npm test` — pass. `npm run build` — succeeds.
- [ ] Re-run the Chrome audit (Tasks C1–C4 checks) on the final build.
- [ ] `git status` + `find … -newer suglow-MONTHLY-FIGURES.zip` to list other sessions' changes that would ride along; report them.
- [ ] `powershell -NoProfile -ExecutionPolicy Bypass -File make-zip.ps1 -Name SECURITY-SEO-UX`; verify with a node central-directory reader: entry count, size, 0 backslashes, 0 `storage/`, 0 `.env`, `public/build/.htaccess` without BOM, every manifest asset present, changed files byte-identical.
- [ ] Hand-off (deploy block per the standing format) plus post-deploy checks: `/install` → 404; `/this-page-does-not-exist` → 404; `/page/about-us` title; `/home` → 301; key file 200; `php artisan indexnow:submit --all` output.
