# Suglow — step-by-step deploy

> **The one rule:** upload **individual files**, never whole folders.
> Your product images live only on the server. Uploading `public/` or
> `storage/` as a folder will destroy them.

---

## ⛔ NEVER touch these — your images and config live here

| Path on server | What it holds | If you overwrite it |
|---|---|---|
| `public_html/storage/app/public/` | **every product image, category image, logo** | all product photos gone, permanently |
| `public_html/public/storage/` | the link that serves those images | every image on the site 404s |
| `public_html/.env` | DB password, API keys, SMS gateway | site down, credentials lost |
| `public_html/storage/framework/` | sessions, cache | everyone logged out |
| `public_html/storage/logs/` | error history | lose your debugging trail |
| `public_html/vendor/` | installed packages | site down until `composer install` |

These are all in `.gitignore` — that is *why* they are not in this project
folder. They are not missing; they are server-only by design.

**In FileZilla / cPanel File Manager: never drag the `public` or `storage`
folder onto the server.** Only the individual files listed in Step 4.

---

## Step 1 — Back up (do not skip)

In cPanel:

1. **Database** → phpMyAdmin → select your database → **Export** → Go.
   Save the `.sql` file.
2. **Files** → File Manager → select `public_html` → **Compress** → zip it →
   download. If that is too large, at minimum zip and download:
   - `public_html/storage/app/public/` ← **your images**
   - `public_html/.env`
   - `public_html/public/build/`
   - `public_html/public/sitemap.xml`

Do not continue until the backup is downloaded to your own computer.

---

## Step 2 — Build the frontend locally

The server has no Node, so this must happen on your machine.

```bash
cd e:/suglow-web/miakebd
npm ci
npm run build
```

This writes `public/build/`. It should finish with `✓ built in …` and no errors.

---

## Step 3 — Put the site in maintenance mode (optional but safer)

Via SSH or cPanel Terminal:

```bash
cd ~/domains/suglow.com/public_html
php artisan down
```

Customers see a maintenance page instead of a half-updated site. You will bring
it back up in Step 7.

---

## Step 4 — Upload the changed files

**38 files.** Upload each into the same path it has here. Overwrite when asked.

<details>
<summary><b>app/ — 20 files</b></summary>

```
app/Console/Commands/GenerateSitemap.php
app/Http/Controllers/Auth/ForgotPasswordController.php
app/Http/Controllers/Auth/GuestController.php          ← new
app/Http/Controllers/Auth/LoginController.php
app/Http/Controllers/Auth/SignupController.php
app/Http/Controllers/Frontend/RootController.php
app/Http/Requests/GuestStartRequest.php                ← new
app/Http/Requests/OrderRequest.php
app/Http/Requests/ProfileRequest.php
app/Http/Requests/SiteRequest.php
app/Http/Resources/SettingResource.php
app/Http/Resources/SiteResource.php
app/Http/Resources/UserResource.php
app/Libraries/AppLibrary.php
app/Models/User.php
app/Services/CouponService.php
app/Services/GuestMergeService.php                     ← new
app/Support/CategoryMetaResolver.php                   ← new
app/Support/CheckoutNotice.php                         ← new
app/Support/ProductMetaResolver.php                    ← new
app/Support/SeoSchema.php
```

`app/Support/` may not exist on the server yet — create the folder first.
</details>

<details>
<summary><b>config, database, lang, routes, views — 13 files</b></summary>

```
config/app.php
database/migrations/2026_01_15_000000_fix_transaction_user_ids.php
database/migrations/2026_01_15_000001_fix_transaction_balances.php
database/migrations/2026_06_14_000002_add_store_sales_report_permission_and_menu.php
database/migrations/2026_07_30_120000_add_checkout_notice_to_site_settings.php   ← new
database/seeders/SiteTableSeeder.php
lang/ar/all.php
lang/bn/all.php
lang/en/all.php
routes/api.php
routes/web.php
resources/views/master.blade.php
```
</details>

<details>
<summary><b>public/ — 5 individual files ONLY</b></summary>

```
public/robots.txt
public/llms.txt
public/themes/default/css/custom.css
public/themes/default/fonts/iconly/iconly.css
public/themes/default/fonts/iconly/iconly.min.css
```

⚠️ These are **five separate files**. Do not upload the `public` folder.
</details>

### Then the built frontend

Delete the server's `public/build` folder and upload your local `public/build`
in its place.

`public/build` contains only generated files — it holds no images and is safe to
replace. **`public/storage` is a different folder. Leave it alone.**

---

## Step 5 — Run the commands

SSH or cPanel Terminal:

```bash
cd ~/domains/suglow.com/public_html

composer dump-autoload      # REQUIRED — see below
php artisan optimize:clear
php artisan migrate --force
php artisan sitemap:generate
```

**`composer dump-autoload` is not optional.** This deploy adds six new PHP
classes. Production installs normally run an optimized autoloader, which uses a
pre-built class map and will not discover new files on its own — every page then
dies with `Class "App\Support\CheckoutNotice" not found`, a 500 on the whole
site.

If `composer` is not on the path, use `php composer.phar dump-autoload`.

**`migrate`, never `migrate:fresh`.** `migrate:fresh` deletes every table —
every product, order and customer. It is one word away and it is not
recoverable without your backup.

The migration only inserts two settings rows. It touches nothing else.

---

## Step 6 — Check the images survived

Before anything else:

```bash
ls ~/domains/suglow.com/public_html/storage/app/public | head
```

You should see numbered folders (`1`, `2`, `3`…) — those are your product
images. If that folder is empty, **stop and restore from your backup.**

Then open the site and confirm product photos load. If the files are there but
images 404, the symlink needs recreating:

```bash
php artisan storage:link
```

---

## Step 7 — Bring the site back

```bash
php artisan up
```

---

## Step 8 — Verify

```bash
# 1. Security fix is live — must return 422, NOT a token
curl -s -X POST https://suglow.com/api/auth/signup/login-verify \
  -H "Content-Type: application/json" \
  -H "x-api-key: <VITE_API_KEY from your .env>" \
  -d '{"phone":"<any customer phone>","country_code":"+880"}'

# 2. Category URLs
curl -sI "https://suglow.com/product?category=sunscreen" | head -3   # expect 301
curl -s  "https://suglow.com/product-category/sunscreen" | grep -i "<title"

# 3. Sitemap has category URLs
curl -s https://suglow.com/sitemap.xml | grep -c product-category
```

In the browser:

- [ ] Homepage loads, images visible
- [ ] A product page loads, photo visible
- [ ] **Admin → Settings → Site** shows the checkout notice text
- [ ] Change the notice, save, reload checkout — text updated
- [ ] Log in as an existing customer — still works
- [ ] Guest checkout: name + mobile → place an order → SMS arrives → account created
- [ ] Guest sees the coupon lock; a logged-in customer can still apply a coupon

---

## Getting a 500 after deploying?

Work through these in order.

```bash
cd ~/domains/suglow.com/public_html

# 1. What actually failed
tail -50 storage/logs/laravel.log

# 2. The usual cause — new classes not in the autoloader
composer dump-autoload
php artisan optimize:clear

# 3. Did every new file arrive? Expect four here
ls -la app/Support/
#   CategoryMetaResolver.php  CheckoutNotice.php
#   ProductMetaResolver.php   SeoSchema.php

ls -la app/Http/Controllers/Auth/GuestController.php
ls -la app/Http/Requests/GuestStartRequest.php
ls -la app/Services/GuestMergeService.php
```

A missing `app/Support/CheckoutNotice.php` breaks **every** page, because
`SettingResource` references it on every request.

To see the real error in the browser, set `APP_DEBUG=true` in `.env`, run
`php artisan optimize:clear`, reload — then **set it straight back to `false`**.
Left on, it shows visitors your file paths and database details.

---

## If something breaks

```bash
php artisan down
```

Then restore the files you backed up in Step 1 and run:

```bash
php artisan optimize:clear
php artisan up
```

The database migration is additive (two settings rows), so a file rollback alone
is enough — you do not need to restore the database.

---

## After deploying

1. **Re-upload the logo at ~256×144** through the admin panel. It is currently
   1280×720 shown at 112px wide. Biggest remaining speed win.
2. **Google Search Console** → resubmit `https://suglow.com/sitemap.xml`
3. **Run PageSpeed three times**, take the median. See `DEPLOY.md` §5 for what
   to realistically expect.
4. **Watch `storage/logs/laravel.log`** for the first day.

---

## Quick answers

**Do I need to upload `resources/js/`?**
No. Those are the source files; `npm run build` compiles them into
`public/build`. Only `public/build` goes to the server.

**Do I need `composer install`?**
No. No packages were added.

**Will I lose customer data?**
No. Nothing here touches the `users`, `orders` or `products` tables. The one new
migration inserts two rows into `settings`.

**Will I lose product images?**
Not if you follow Step 4 and upload individual files. The images are in
`storage/app/public/`, which is not in this project and which you never touch.

**What if I already uploaded the whole `public` folder by mistake?**
Your images are probably still safe in `storage/app/public/` — only the serving
link broke. Run `php artisan storage:link` and check the site again.
