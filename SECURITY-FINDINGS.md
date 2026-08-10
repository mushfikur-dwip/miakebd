# Security review — 10 Aug 2026

Full-codebase scan of the Laravel app: injection, authorisation, file upload,
path traversal, XSS, command execution, deserialisation, price and wallet
handling. Findings below are the ones I confirmed by reading the code path end
to end. All of them are fixed in `suglow-BRANCH-STOCK.zip`.

---

## The root cause behind five of these

`routes/api.php` line 207:

```php
Route::prefix('admin')->middleware(['auth:sanctum', 'active'])->group(...)
```

The whole admin API asks only for **a valid sanctum token and an active
account**. Every customer who logs in to the storefront has exactly that. The
only thing separating a shopper from an admin endpoint is the `permission:`
middleware each controller declares for itself — and ten controllers declared
none.

`AdminController::__construct()` is empty, so nothing catches the omission.

---

## 1. Any logged-in customer could set the cashback rate — CRITICAL

`app/Http/Controllers/Admin/WalletSettingController.php`

`PATCH /api/admin/setting/wallet-setting` had no permission check.

```
PATCH /api/admin/setting/wallet-setting
{ "wallet_status": 1, "cashback_status": 1,
  "cashback_rule": "cart_wise", "cashback_type": "percentage",
  "cashback_amount": 100, "process_cashback": "delivered" }
```

A customer sends that with their own token, places an order, and
`OrderObserver::handleCashback` credits their wallet with 100% of the order
value. Wallet balance is spendable at checkout. That is money out of the till,
repeatable, and it leaves the cashback settings wrong for every other customer.

**Fixed:** `permission:settings` on `show` and `update`, matching the router's
`permissionUrl: "settings"` on Settings → Wallet.

## 2. Read any customer's order — HIGH

`app/Http/Controllers/Admin/MyOrderDetailsController.php`

`GET /api/admin/order/show/{user}/{order}` had no permission check, and the
ownership test in `OrderService::orderDetails` compares the order against **the
user id in the URL**, not the caller:

```php
if ($order->user_id == $user->id) { return $order; }
```

So it only confirms the two path segments agree with each other. Walk the id
pairs and you get every order on the site: customer name, phone, delivery
address, and everything they bought.

**Fixed:** `permission:administrators`, matching the screen that uses it.

## 3. Rewrite the storefront's mobile navigation — HIGH

`app/Http/Controllers/Admin/MobileSectionController.php`

`storeButton`, `updateButton`, `deleteButton` and `updateBackground` had no
permission check. A customer could point the mobile nav buttons at any URL —
a phishing page, say — for every visitor on the site.

`updateBackground` also accepted **SVG**. An SVG is an XML document that can
carry `<script>`, served back from your own origin: uploading one is stored XSS
against every storefront visitor. Laravel's `image` rule allows svg, so it has
to be excluded by name.

**Fixed:** `permission:settings` on the four write methods, `svg` dropped from
the accepted types (`webp` added in its place). `index` is deliberately left
open — the storefront calls the same method through
`GET /api/frontend/mobile-section`.

## 4. Create, rewrite and delete menu templates — HIGH

`app/Http/Controllers/Admin/MenuTemplateController.php`
`app/Http/Controllers/Admin/MenuSectionController.php`

Menu templates drive the storefront navigation. `store`, `update` and `destroy`
were open to any logged-in customer.

**Fixed:** `permission:settings` on both controllers.

## 5. Stored XSS against admins, via product reviews — HIGH

`resources/js/components/admin/reviews/ReviewListComponent.vue` line 77

```html
<span v-html="textShortener(review.review)"></span>
```

A review is free text a customer types. `ProductReviewRequest` validates it as
`string|max:5000` and stores it raw. Rendering it with `v-html` means a review
of

```html
<img src=x onerror="/* anything, as the admin */">
```

runs in the admin's session the moment anyone opens Reviews — and that session
can reach every endpoint in section 1 through 4 legitimately.

**Fixed:** rendered as text via interpolation. `ReviewShowComponent` was already
using interpolation and needed no change.

## 6. Customer roster readable by any customer — MEDIUM

`app/Http/Controllers/Admin/SimpleUserController.php`

`GET /api/admin/simple-user` listed every account (id and name) to anyone with a
token. Nothing in the frontend calls it.

**Fixed:** `permission:customers`.

## 7. Language editor could read and write any file on the server — MEDIUM

`app/Services/LanguageService.php`

```php
public function fileText(...)      { include($request->path); }
public function fileTextStore(...) { file_put_contents($request->x_language_file_path, ...); }
```

Both took an absolute path straight off the request. `include()` on an arbitrary
path is remote code execution given any file with PHP in it; the write side puts
attacker-influenced content into any file the web user can write.

This one *was* behind `permission:settings`, so it is not customer-reachable —
it is a route from "staff member with settings access" to "shell on the server",
which is still a boundary worth keeping.

**Fixed:** both paths now go through `assertLanguageFilePath()`, which
`realpath()`s the input and requires it to sit inside `lang/` or
`resources/js/languages/`. `realpath` resolves `../` before the check, so
traversal cannot walk out.

## 8. The cashback base was whatever the customer said it was — CRITICAL

`app/Services/FrontendOrderService.php`, `app/Observers/OrderObserver.php`

`orders.total_amount_for_cashback` is the number `OrderObserver` multiplies by
the cashback rate when an order is marked delivered. It arrived straight from
the browser: `OrderRequest` validated it as `nullable|numeric|min:0` — no upper
bound — `$request->validated()` carried it into `Order::create`, and
`OrderPriceGuard` never looked at it. `max_cashback_amount` is nullable, so with
no cap configured nothing downstream bounded it either.

```
POST /api/frontend/order
{ ...a genuine 100 taka order..., "total_amount_for_cashback": 10000000 }
```

Every line price survives the price guard, because they are all real. Then the
order is delivered and the customer is credited the cashback percentage of ten
million taka, as spendable wallet balance.

This is a **separate hole from finding 1**. Fixing the wallet settings stopped a
customer changing the *rate*; this one is the *base*, and it was still open.

**Fixed:** the value is now derived server-side as `total - wallet_discount`,
the same definition the checkout screen intends — what the customer actually
pays out of pocket. Wallet-funded money is excluded on purpose: earning cashback
on wallet credit is a loop that mints balance out of itself.

## 9. Cashback paid again on every re-delivery — HIGH

`app/Observers/OrderObserver.php`

`updated()` fires whenever `status` changes to DELIVERED, and
`OrderService::changeStatus` has no state machine — nothing stops an order being
set to delivered, moved off, and set back. There was no check for a cashback
already paid on that order, so each round trip credited the wallet afresh.

The credit also had two smaller defects: `$user->balance += ...; save();` is a
read-modify-write with no row lock, so a cashback landing at the same moment as
a wallet redemption loses one of the two; and the balance write and the
`Transaction` row were not in a transaction, so a failure between them left
money in a wallet with no ledger entry explaining it.

**Fixed:** one `DB::transaction`, an existing-cashback check under
`lockForUpdate`, and the user row re-read under `lockForUpdate` before the
credit — matching what the wallet redemption path already does.

---

## Hardening added

None of these were present at all.

- **Security headers** — new `app/Http/Middleware/SecurityHeaders.php`, appended
  globally: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy: strict-origin-when-cross-origin`,
  `X-Permitted-Cross-Domain-Policies: none`, and HSTS for one year on requests
  that are already HTTPS.

  **No Content-Security-Policy.** A useful one has to enumerate every script,
  style and frame source the storefront and the payment gateways actually use,
  and a wrong guess takes checkout down rather than making it safer. It is worth
  building — from observed traffic, not from guesswork during a stock release.

- **Rate limit on the admin API** — `throttle:300,1`. There was none. Every
  controller is now permission-guarded, but a valid token could still be used to
  walk ids at full speed hunting for an endpoint that forgot one, and several
  had. 300/minute per user is well above what the POS generates and well below
  what enumeration needs.

- **Token expiry made configurable** — `config/sanctum.php` now reads
  `SANCTUM_TOKEN_EXPIRATION`. **Left at null on purpose**, so this release
  changes nothing: setting a lifetime invalidates every existing token at once
  and signs out the whole customer base. See the checklist below.

---

## Checked and clean

- **SQL injection** — every `whereRaw` / `selectRaw` / `DB::raw` in the codebase
  is either a constant or properly bound (`["%{$request}%"]` as a binding, not
  interpolated into the SQL).
- **Command execution / deserialisation** — no `exec`, `shell_exec`, `system`,
  `passthru`, `eval` or `unserialize` anywhere in `app/`. The `curl_exec` hits
  are HTTP clients in the SMS and payment gateways.
- **Checkout price manipulation** — the live order path
  (`POST /api/frontend/order`) recomputes every line price from the database
  through `OrderPriceGuard::assertPricesAreGenuine` before writing, and locks
  the user row before touching wallet balance. Already hardened.
- **`{!! !!}` in Blade** — the unescaped output in `master.blade.php` is
  `json_encode` with the HEX flags for structured data, plus admin-authored CMS
  sections and blog bodies. Appropriate.
- **`v-html` elsewhere** — the remaining uses render admin-authored content
  (product description, page body, blog post, purchase/damage notes). Only the
  review list rendered customer input.

## Left alone deliberately

- **`app/Services/CheckoutService.php`** is dead code. Its route was removed in
  an earlier session precisely because it took `subtotal`/`total`/
  `wallet_discount` from the request with no verification. Nothing routes to it
  today, so it is not exploitable — but it is a loaded gun if anyone re-adds the
  route. It also decrements stock by mutating an existing `stocks` row in place,
  which would now corrupt branch quantities. **Recommend deleting the file.**
- **`BarcodeController`, `CountryCodeController`, `TimezoneController`** still
  have no permission check. They return reference data — barcode types, country
  calling codes, timezone names. The exposure is negligible, and these lists are
  loaded by admin screens with many different permissions, so guessing a single
  permission name risks locking working forms. Left as-is on purpose.

## Your `.env` on the server — I cannot change this, you must

These are the remaining gaps, and all four live in the production `.env`.

1. **`APP_DEBUG=false`.** With it on, any error page shows visitors your file
   paths, environment and database credentials. Check this first.
2. **`SESSION_SECURE_COOKIE=true`.** Currently unset, which defaults to false —
   the session cookie travels on plain HTTP too. Added to `.env.example` with a
   note.
3. **`SANCTUM_TOKEN_EXPIRATION=43200`** (thirty days) when you are ready.
   Currently tokens never expire, so one leaked token is valid forever. Turning
   this on signs out every customer at once — do it at a quiet hour, not
   alongside this deploy.
4. **Rotate the keys** — `VITE_API_KEY` and the payment and SMS gateway
   credentials — if any developer machine has ever held a copy of the production
   `.env`. Worth assuming yes.

## Two more things worth deciding

- **Who has the `settings` permission?** It is now the gate on wallet settings,
  the menus and the language editor. Anyone holding it can reach the language
  file editor, which writes files on the server. Fewer people should have it
  than probably do.
- **A Content-Security-Policy** is the largest remaining piece of hardening, and
  the one most likely to break checkout if rushed. Build it from real traffic:
  run it in report-only mode with a report endpoint first, see what legitimately
  loads, then enforce.
