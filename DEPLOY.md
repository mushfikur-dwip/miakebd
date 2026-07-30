# Suglow — deployment & operations guide

Everything below was built and verified locally on PHP 8.3 with a seeded
database. Nothing has been deployed. This is the handover.

---

## 1. Deploy, in order

The server has **no Node**, so the frontend must be built locally and uploaded.

```bash
# 1. Locally — build the frontend
npm ci
npm run build

# 2. Back up the live site first
#    - public/build          (the current bundle)
#    - public/sitemap.xml
#    - .env
#    - the database

# 3. Upload
#    - every changed PHP / Blade file
#    - public/build            (replace the folder)
#    - public/robots.txt, public/llms.txt
#    - public/themes/default/css/custom.css
#    - public/themes/default/fonts/iconly/*.css

# 4. On the server
php artisan optimize:clear
php artisan migrate --force
php artisan sitemap:generate
```

**Change one thing, upload, check, then the next.** A bad `.htaccess` took this
site down once already.

### Rollback

Restore the backed-up `public/build` and the PHP files. The migrations only
insert two settings rows and are safe to leave in place.

---

## 2. What you must do yourself

| # | Task | Why |
|---|---|---|
| 1 | **Re-upload the logo at ~256×144** via the admin panel | It is 1280×720 and renders 112px wide — ~130× more pixels than needed, on every page load. CSS cannot fix file size; only a smaller file can. This is the single easiest speed win left. |
| 2 | **Test the guest flow on a real phone** | Order as a guest → order success → create account → receive the SMS → verify. The OTP path cannot be tested locally; there is no SMS gateway here. |
| 3 | **Have a native speaker read the Bangla** | I wrote the ~14 guest-checkout strings myself. They are in `resources/js/languages/bn.json`. |
| 4 | **Measure PageSpeed after deploying** | Run it three times and take the median. See §5 — I reduced JavaScript but never measured a score, so I cannot claim a number. |
| 5 | **Check `Settings → Site`** | Confirm phone verification is enabled and the checkout notice reads how you want. |

---

## 3. Security — what changed and why it matters

Three live account-takeover paths were closed. All are verified by an automated
test that tries the attack and asserts it fails.

| Hole | Before | Now |
|---|---|---|
| `signup/login-verify` | returned a valid token for **any** phone number in the database, no OTP | requires a verified, unexpired code, consumed on use |
| `forgot-password/reset-password` | set a new password on any phone with **no verification at all** | same gate, and the account reset is bound to the identifier that was verified |
| `guest/claim` | — | the code must belong to the exact phone **and** country code |

Also: OTP endpoints are now rate limited (they had none — a 4-digit code with
unlimited attempts is ~10,000 requests from any account), guest rows can no
longer shadow a real account at login, and tokens are revoked when a guest
becomes a real customer.

**If you remember one thing:** phone verification must stay **enabled** in
`Settings → Site`. The password-reset gate depends on it.

---

## 4. Guest checkout — how it actually works

```
Customer fills name + mobile
   └─ POST /api/auth/guest/start   → creates a user with is_guest = YES, returns a token
        └─ normal address + order flow runs unchanged
             └─ order success page shows "Save your order history"
                  └─ POST /api/auth/guest/send-otp  → SMS
                       └─ POST /api/auth/guest/claim → verifies, upgrades, merges past orders
```

**Deliberate design decisions — do not "fix" these:**

- **A new guest user per checkout.** Reusing one keyed on phone number would
  mean anyone typing a customer's number into checkout receives a token for
  their account and can read their order history. Phone numbers are not secret.
  Accounts merge only after an OTP proves ownership.
- **No email on a guest row.** Two users sharing an email makes every login
  lookup ambiguous.
- **Coupons require an account.** `limit_per_user` counts against a user row,
  and guest checkout creates a new one each time — so a once-per-customer coupon
  would be unlimited. Enforced in three places: the UI, the coupon-check
  endpoint, and `OrderRequest` (so the API cannot be called directly).

Order creation itself was never touched.

---

## 5. Performance — measured, and what is left

Eager JavaScript, the bundle every visitor downloads before first paint:

| | before | after |
|---|---|---|
| raw | 2,305 kB | **1,188 kB** |
| **gzip** | **628 kB** | **336 kB (−47%)** |

What did it:

1. **The real cause was not the admin dashboard.** Rollup's CommonJS helpers and
   the Vue SFC normalizer live outside `node_modules`, so `manualChunks` never
   assigned them. Rollup parked them in whichever manual chunk it built first —
   `charts` — which made a 704 kB admin-only bundle a static import of *every*
   component. Pinning those helpers to `vendor` is what actually removed it.
2. ApexCharts was globally registered in `app.js`, so it shipped to every
   visitor regardless of routing. Now an async component.
3. All three locale files were bundled (151 kB of JSON) though only one is ever
   active. English inlines; the rest load on switch.
4. Firebase was eagerly imported by both navbars for push notifications that are
   gated on login and deferred 3s anyway.

**Still open:** the logo (§2.1) and skeleton loaders. Sections render only when
their API response lands, so the page jumps — `min-height` guesses cannot fix
that reliably. The two worst are Product Categories and Most Popular.

**Honest limit:** 90+ on mobile is not achievable for a client-rendered SPA on
shared hosting without server-side rendering. 70–75 is a good target. SEO is
already 100.

---

## 6. SEO

- Product and category pages are server-rendered for crawlers — WhatsApp,
  Facebook and GPTBot never run JavaScript, so without this they all saw the
  same generic HTML.
- Category URLs are now `/product-category/{slug}`; the old
  `/product?category=x` 301s across, preserving other filters.
- **The canonical bug is fixed.** `url()->current()` strips the query string, so
  all nine category pages were declaring `/product` as canonical — removing
  themselves from Google's index.
- Canonicals and image URLs are built from `APP_URL`, not the requesting host,
  so a health check on the bare IP cannot poison them.

`ASSET_URL` now defaults to `APP_URL`, so there is nothing to add to `.env`.

**After deploying:** resubmit the sitemap in Google Search Console, and check
`Search Console → Core Web Vitals` — that is field data from real Chrome users
and is what actually affects ranking. It needs ~28 days of traffic to appear.
Lighthouse is a lab number and swings widely on an SPA.

---

## 7. The checkout notice

`Settings → Site → Checkout notice` — a textarea plus Show / Hide.

Write it in Bangla or English. Clearing the box hides the bar on its own; you do
not also have to flip the toggle. Verified end to end: saved from the admin
panel, served to the storefront.

It works even before `php artisan migrate` runs — both settings resources fall
back to the default text — but run the migration anyway so the value becomes
editable.

---

## 8. Known gaps

1. **Nothing is deployed.** 67 files changed locally (10 new, 57 modified).
2. **The guest flow has never run with a real SMS.** Everything else is covered
   by 16 automated functional tests; the OTP delivery path is not.
3. **`tests/Feature/ExampleTest.php` fails** — pre-existing. `RefreshDatabase`
   is commented out, so it hits an empty database. It has never passed on a
   clean checkout. Either wire it up properly or delete it.
4. **`SeoSchema.php`, `GenerateSitemap.php` and `ImportSeoMetadata.php` were
   deliberately not replaced** with the versions supplied alongside the spec.
   The repo versions are correct for this schema; the supplied ones reference
   columns and models that do not exist here, and one would have fatally errored
   on every product page. Details are in the conversation.
5. **Two coupon-related trade-offs left as-is:** `guest/start` returns an
   explicit "account exists" flag (a clean enumeration oracle, but good UX), and
   the SMS throttle is keyed by IP rather than phone number.

---

## 9. Quick reference

```bash
# after any deploy
php artisan optimize:clear

# regenerate the sitemap (writes public/sitemap.xml — production only)
php artisan sitemap:generate

# inspect what the resolvers produce for a given slug
php artisan tinker
>>> App\Support\ProductMetaResolver::debug('some-product-slug')
>>> App\Support\CategoryMetaResolver::debug('sunscreen')

# confirm the real category slugs — some carry a numeric suffix
>>> App\Models\ProductCategory::pluck('slug')
```

**Verify the security fix is live** (must return 422, not a token):

```bash
curl -s -X POST https://suglow.com/api/auth/signup/login-verify \
  -H "Content-Type: application/json" \
  -H "x-api-key: <VITE_API_KEY from .env>" \
  -d '{"phone":"<any customer phone>","country_code":"+880"}'
```
