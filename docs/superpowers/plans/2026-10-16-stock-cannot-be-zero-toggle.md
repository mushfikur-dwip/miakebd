# Stock Cannot Be Zero — a switch, and an honest remark when it is off

**Goal:** one switch on the Stock page. **On:** nothing can be sold past what a
branch holds — not at the till, not on the website. **Off:** everything sells as
before, but the stock page never shows a negative number: it shows `0` and a
remark saying how many units were billed beyond stock.

**Spec:** the owner's request of 2026-10-16, after seeing `-1` on the RAMC
branch in the stock edit dialog.

## What I found while planning (changes the shape of this)

- **The till is already guarded.** `OrderService::posOrderStore` calls
  `StockGuard::settle($changes)` with `$refuse = true`, so a POS sale beyond
  stock is refused today. The switch has to make that *conditional*.
- **The website is not guarded at all.** `FrontendOrderService::myOrderStore`
  writes its stock rows `INACTIVE` and never checks them against stock.
  When payment settles, `PaymentService::payment` / `Cashondelivery::success`
  flip them `ACTIVE` with `$refuse = false` — announce-only. So **the website
  can oversell any product right now, without limit.** That is the hole the
  switch's "on" position closes, and it is worth fixing on its own.
- `can_purchasable = NO` products are already exempt from stock entirely
  (`StockGuard` skips them). That stays true in both positions.

## Decisions

- **The setting lives in a new `stock_setup` settings group**, key
  `stock_cannot_be_zero`, default **on** (`Ask::YES`) — the safe position, and
  the one matching how the till behaves today. Read through one
  `Settings::group('stock_setup')` handle per request (per
  [[settings-group-handle-is-single-use]]: a second `->get()` on the same
  handle reads null, and values come back as **strings**).
- **Off does not mean "stop counting".** The stock rows stay truthful and go
  negative, because that is what makes the next delivery absorb correctly: a
  branch at `-3` that receives 10 must end at 7, not 10. What changes is the
  **display** — the stock page clamps at `0` and carries the overdraft as a
  remark. This is the same reasoning as `stock:zero-negatives`, and it is why I
  am not writing a fake "+1 arrived" row.
- **On blocks at the point of sale, not in the cart.** The storefront already
  hides sold-out products; the guard is the last line, for a stale cart or a
  hand-made request, and it answers 422 with the existing
  `stock_not_enough` message naming the item, the branch and what is left.
- The switch is **shop-wide**, not per branch — the owner asked for one switch.
- No new permission: the switch is part of the Stock page (`permission:stock`).

## Tasks

### Task 1: The setting

- Create `app/Services/StockSetupService.php` (`list()` / `update()`, shaped
  like `ShippingSetupService`), `app/Http/Requests/StockSetupRequest.php`
  (`stock_cannot_be_zero` required boolean), `app/Http/Resources/StockSetupResource.php`.
- Add `app/Support/StockPolicy.php`: `cannotBeZero(): bool` — reads the setting
  once per request and memoises it, defaulting to **true** when unset or
  unreadable. One place that answers the question, so the guard, the storefront
  and the stock page cannot disagree.
- Routes under the existing stock prefix: `GET /api/admin/stock/setup`,
  `PUT|PATCH /api/admin/stock/setup` on `StockController` (`permission:stock`).
- Tests: default is on; the switch round-trips; a non-boolean is 422.

### Task 2: The till and the website obey the switch

- `StockGuard::settle()` — the refusal becomes
  `if ($refuse && app(StockPolicy::class)->cannotBeZero() && $moved < 0 && $left < 0)`.
  The low-stock Telegram alert is **unchanged**: it fires in both positions, and
  reports `out of stock` once a branch is at or below zero.
- New `app/Support/StorefrontStockGuard.php`, called from
  `FrontendOrderService::myOrderStore` beside the existing
  `StorefrontOrderGuard::assertNoPosOnlyProducts` call: when the switch is on,
  sum each line against what the order's branch holds (delivery orders read the
  shop-wide total, since they draw on the unassigned pool) and refuse with
  `stock_not_enough`. Variation lines check the variation, like `StockGuard`.
- Tests: with the switch **on** — a POS sale beyond stock is refused (the
  existing test), an online order beyond stock is refused, an untracked product
  still sells. With it **off** — both go through, and the stock row really is
  negative afterwards.

### Task 3: The stock page shows 0 and a remark

- `StockService::row()` gains, for every row and every branch in
  `outletBreakdown()`:
  - `quantity` — clamped at `0` when the switch is off and the real figure is
    negative (unchanged, real, when the switch is on: with it on a negative is
    historical damage the owner must still see to correct).
  - `billed_beyond` — `max(0, -real)`, the overdraft, so the UI can say it.
  - `real_quantity` — the true figure, for the edit dialog's arithmetic.
- `StockEditModalComponent.vue` — "Current stock" shows the clamped figure, with
  the overdraft as a small amber note ("1 unit billed beyond stock"), and
  `NEW TOTAL` computes off `real_quantity` so adding 1 to `-1` still reads `0`
  rather than claiming `1`. This is the exact dialog in the owner's screenshot.
- `StockListComponent.vue` — the branch chips and the Quantity column show the
  clamped number plus the same note; a red chip when a branch is overdrawn.
- New text keys in `lang/en/all.php` and the stock page's text module (en + bn).

### Task 4: The switch on the page

- A labelled toggle in `StockComponent.vue`'s header, posting to
  `admin/stock/setup`, with a one-line explanation of each position ("On:
  nothing sells past stock. Off: sales go through; the page shows 0 and notes
  what was billed beyond stock."). Reuses the Cash page's toggle styling.

### Task 5: Verify and ship

- Full suite. `npm run build`. `make-zip.ps1 -Name STOCK-ZERO-SWITCH`, verified
  (entries, backslashes 0, no `.env`/`storage/`, manifest assets present).
- Hand-off in Bengali with the SSH block and the post-deploy checks.

## Review focus

- The switch is read **once per request** — a list of 2,000 stock rows must not
  read the setting 2,000 times.
- A delivery (no-branch) online order draws on the unassigned pool: the guard
  must compare against the shop-wide total, or every delivery order is refused.
- Turning the switch off and on again must not change any stored number — only
  what is shown and what is refused.
- The overdraft note must never appear for `can_purchasable = NO` products:
  they are not stock-controlled at all.
