# Deploy — Branch-wise stock (`suglow-BRANCH-STOCK.zip`)

Every branch now holds its own stock. The POS shows and deducts the stock of
the branch the cashier selected. The public site keeps showing the **total
across all branches**, which is the real stock.

Zip is built and verified: **338 entries, 2.55 MB, 0 backslash entries.**

Includes the three fixes from the second round:

1. **The stock screen lists the whole catalogue.** It used to read the stock
   ledger and group whatever it found, so a product only appeared once
   something had moved for it — most of the 455 were simply absent. It now
   reads from `products`, so every product and every sellable variation is
   listed, showing a real `0` where nothing has moved.
2. **Barcode search.** A scan box sits in the Stock page header — scan, it
   searches on Enter. Barcodes in this app *are* the SKU, so the same box
   matches typed SKUs and product names' SKUs alike, on both products and
   variations. A SKU column was added to the table and the Excel export.
3. **Recalculate** adjustment type. Pick a branch, pick products, type the
   count — that number *becomes* the branch's stock for that product. The
   screen prefills each row with what the branch holds now, so you are
   correcting a real figure rather than typing blind.

4. **The adjustment product picker lists the whole catalogue.** It was reading
   the *purchasable* product list the purchase screen uses, which filters to
   `can_purchasable = YES` and `status = ACTIVE` — so most products could be
   seen on the stock screen but never picked to correct. It now reads the
   plain product list, which has no such filter.

5. **Edit stock straight from the Stock screen.** Every row has an Edit action
   opening a dialog with one line per branch — current count on the left, the
   number you want on the right. Save and each branch that changed is recorded
   as its own *Recalculate* adjustment, so it appears in the adjustment history
   and can be undone from there.
6. **The Branch Stock column shows every branch**, including the ones holding
   nothing. It used to list only branches that had stock rows, which left the
   column blank for most of the catalogue.
7. **Quantity shows the real number instead of `N/C`.** `N/C` appeared for any
   product with *Can Purchasable = No* — which is most of yours. In this app
   that setting means the storefront ignores the count and reports a fixed
   `NON_PURCHASE_QUANTITY` (100) instead. That behaviour is unchanged; the
   stock screen now simply shows the true figure, tagged **not stock
   controlled**, because you cannot correct a number you cannot see.

Also fixed along the way: the Excel export sent the screen's `paginate=1`, so
it exported ten rows instead of the catalogue. It now exports everything.

---

## Step 1 — Back up

cPanel → phpMyAdmin → your database → **Export** → Go. Save the `.sql`.

This deploy adds columns and one table. It is additive, but a stock migration
is not something to run without a database export sitting on your machine.

---

## Step 2 — Upload

Upload `suglow-BRANCH-STOCK.zip` to `public_html/` via FileZilla or cPanel File
Manager.

Do **not** extract it with the cPanel File Manager. It *replaces* whole
directories — that is what emptied `app/` and took the site down on 5 Aug.
Extract over SSH with `unzip -o`, which merges.

---

## Step 3 — Run

```bash
cd ~/domains/suglow.com/public_html
php artisan down

unzip -o suglow-BRANCH-STOCK.zip && rm suglow-BRANCH-STOCK.zip

composer dump-autoload
php artisan optimize:clear
php artisan migrate --force
php artisan route:cache
php artisan view:cache

php artisan up
```

### Why each line

**`composer dump-autoload` is not optional.** This ships **8 new PHP classes**
(`StockAdjustment`, `StockAdjustmentService`, `StockAdjustmentController`,
`StockAdjustmentRequest`, two resources, the `StockAdjustmentType` enum, and the
`HasOutletStock` trait). Production runs an optimized autoloader off a pre-built
class map and will not find new files on its own — the admin panel then dies
with `Class "App\Models\StockAdjustment" not found`.

**`migrate`, never `migrate:fresh`.** `migrate:fresh` drops every table — every
product, order and customer. One word apart, and not recoverable without Step 1.

**Never `php artisan config:cache` or `php artisan optimize`.** This app calls
`env()` in 38 places outside `config/`, including the API key in
`master.blade.php`. Caching config makes `env()` return null there and the whole
storefront goes down. `route:cache` and `view:cache` are safe.

---

## Step 4 — Confirm the migration ran

```bash
cd ~/domains/suglow.com/public_html
php artisan migrate:status | grep 2026_08_10
```

Expect three `Ran` rows:

```
2026_08_10_000001_add_outlet_id_to_stocks_table ......... Ran
2026_08_10_000002_add_outlet_id_to_purchases_table ...... Ran
2026_08_10_000003_create_stock_adjustments_table ........ Ran
```

Then confirm the old stock was left alone — every existing row must still be
unassigned, which is what keeps the website total exactly where it was:

```bash
php artisan tinker --execute="echo App\Models\Stock::whereNotNull('outlet_id')->count();"
```

Must print **0** right after deploy. Anything else means stock moved during the
migration, which it should not.

---

## Step 5 — Check the images survived

```bash
ls ~/domains/suglow.com/public_html/storage/app/public | head
```

Numbered folders (`1`, `2`, `3`…) = your product images are fine. Empty = stop
and restore from backup.

The zip carries no `storage/` and no `public/storage`, so this should be
untouched. **Do not run `php artisan storage:link`** — on this host the symlink's
presence blocks the route that actually serves the images.

---

## Step 6 — Verify in the browser

**Admin → Stock**
- [ ] The list now shows **every product**, not only ones with stock. Set the
      page size to 100 and page through — products never purchased appear with
      `0`
- [ ] New **SKU** column
- [ ] New **Branch Stock** column shows chips like `Unassigned: 120`
- [ ] Branch filter dropdown lists your outlets plus **Unassigned**
- [ ] **Stock Adjustment** button opens the new screen

**Admin → Stock — edit a product's branch stock**
- [ ] Every row has an **Edit** icon
- [ ] Branch Stock column lists **all** your branches, showing `0` where empty
- [ ] Click Edit → dialog lists one line per branch with its current count
- [ ] Set one branch to `25`, another to `10`, save
- [ ] The row updates: Branch Stock shows `25` and `10`, Quantity shows `35`
- [ ] **Stock Adjustment** list now has two *Recalculate* entries, one per
      branch. Deleting one puts that branch back
- [ ] Open the same product on the public site — its stock is the `35` total

**Admin → Stock — barcode**
- [ ] Scan box is visible in the header without opening the filter
- [ ] Scan a product barcode → that product's row appears
- [ ] Type a partial SKU and press Enter → matching rows appear
- [ ] The ✕ clears it and returns the full list

**Admin → Stock → Stock Adjustment → Add — product picker**
- [ ] Open **Add Products** and scroll — the whole catalogue is there, not a
      short list
- [ ] A product marked inactive, or with *Can Purchasable* set to No, can still
      be picked

**Admin → Stock → Stock Adjustment → Add → Recalculate**
- [ ] Type = **Recalculate** — the *From Branch* field disappears, one
      **Branch** field remains
- [ ] Pick a branch, add a product → a **Current Stock** column appears and
      prefills the quantity with what that branch holds
- [ ] Change the number to `50`, save
- [ ] Stock page: that product at that branch is now exactly `50`, whatever it
      was before
- [ ] Do it again with `0` → branch goes to `0` (allowed for recalculate only)

**Admin → Stock → Stock Adjustment → Add**
- [ ] Type = **Transfer**, From = *(blank = Unassigned)*, To = a branch
- [ ] Add a product, quantity 5, save
- [ ] Back on Stock: that product now shows `Unassigned: 115, <branch>: 5`
- [ ] Grand total unchanged — a transfer moves stock, it does not create it

**Admin → POS**
- [ ] A branch is preselected in the branch dropdown
- [ ] Each product tile shows a stock badge
- [ ] Switch branch → badges change to that branch's numbers
- [ ] Sell 1 item → after the receipt, the badge drops by 1
- [ ] Selling more than the branch holds is **allowed** (no block, by design)

**Admin → Purchase → Add**
- [ ] New **Branch** dropdown next to Supplier
- [ ] Save a purchase against a branch → Stock page shows it under that branch

**Public site** — the important one
- [ ] Open the product you transferred stock for
- [ ] The stock shown is the **same as before the deploy** (all branches summed)
- [ ] Add to cart and reach checkout normally

---

## Step 7 — Move your old stock into branches

Nothing does this for you, and nothing needs to happen today — the site works
fine with everything unassigned.

When you are ready: **Stock → Stock Adjustment → Add**, type **Transfer**, leave
**From Branch** empty (that is the unassigned pool), pick the destination
branch, add the products and quantities, save. Repeat per branch.

You can also open an old purchase, set its Branch and save — that moves the
whole receipt to that branch, because editing a purchase rewrites its stock rows.

---

## Rollback

```bash
cd ~/domains/suglow.com/public_html
php artisan down
```

Restore the files from your Step 1 backup, then:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan up
```

The database does **not** need restoring. The three migrations are additive —
they add a nullable column to `stocks`, a nullable column to `purchases`, and a
new `stock_adjustments` table. Old code ignores all three.

If you also want the schema gone:

```bash
php artisan migrate:rollback --step=3
```

Only do this if no adjustments have been recorded yet — rolling back drops
`stock_adjustments` and with it any branch assignment you have made.

---

## Getting a 500?

```bash
cd ~/domains/suglow.com/public_html
tail -50 storage/logs/laravel.log

# Almost always this:
composer dump-autoload
php artisan optimize:clear

# Did the new classes arrive? Expect all of these:
ls -la app/Models/StockAdjustment.php app/Models/Concerns/HasOutletStock.php
ls -la app/Services/StockAdjustmentService.php
ls -la app/Http/Controllers/Admin/StockAdjustmentController.php
```

`app/Models/Concerns/` is a **new directory**. If it is missing, the zip was
extracted with the cPanel File Manager instead of `unzip -o` — re-upload and
extract over SSH. A missing `HasOutletStock.php` breaks every product query,
so the storefront goes down with it.

---

## Quick answers

**Will the website stock change the moment I deploy?**
No. Every existing stock row stays unassigned and the site sums all of them,
exactly as it does today. The number your customers see does not move.

**Do I need to upload `resources/js/`?**
No. `npm run build` already compiled it into `public/build`, which is in the zip.

**Do I need `composer install`?**
No. No packages were added. (`larastan` in your local `composer.json` is a dev
tool and is deliberately **not** in this zip.)

**Will I lose product images?**
No. The zip contains no `storage/` and no `public/storage`.

**Can a cashier sell more than the branch has?**
Yes — you asked for no stock check. The branch count goes negative and you
correct it later from Stock Adjustment → Remove/Add.

---

## Not in this zip

Your working tree has changes that are **not** part of branch-wise stock, so
they were deliberately left out rather than shipped under cover of this deploy:

- `app/Observers/OrderObserver.php` — a **cashback logic rewrite** (fixed vs
  percentage type, `max_cashback_amount` cap, direct `Transaction::create`).
  Real money logic, unreviewed here, possibly unfinished.
- `app/Http/Controllers/Api/CheckoutController.php`,
  `app/Listeners/SendSmsCodeNotification.php`, `app/Models/User.php`,
  `app/Services/ReturnAndRefundService.php` and several other models/services —
  PHP 8.4 deprecation tidying (`?Type $x = null`).
- `composer.json` / `composer.lock` / `phpstan.neon` — the larastan dev tool.
  Not needed in production.

Three files carry both my feature changes and that tidying, so they *are* in the
zip and bring it along: `app/Models/Product.php`, `app/Services/ProductService.php`,
`app/Services/ProductVariationService.php`. Both extra edits there are safe — a
nullable parameter, and a null-guard around barcode generation.

Tell me if you want the cashback rewrite in a deploy and I will package it
separately, after reviewing it.

---

## Known gap

**Damage and Return Order still record stock without a branch** — those rows land
in the unassigned pool. They were outside what you asked for, and Stock
Adjustment → **Remove** covers a branch-level damage in the meantime. Say the
word and I will add a Branch dropdown to both, same pattern as Purchase.
