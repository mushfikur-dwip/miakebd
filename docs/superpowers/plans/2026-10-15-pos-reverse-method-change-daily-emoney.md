# POS Reverse, Method Change and Daily E-money — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Card/MFS till money is shown per day and per calendar month instead of as an ever-growing balance, the day's sale is shown big, a wrong POS sale can be reversed (everywhere) or its payment method corrected behind PINs, and no salesman can make the figures disagree with what happened.

**Architecture:** Sale figures are read from `orders` (paid, not cancelled/rejected, by `order_datetime`), the drawer stays on the append-only `cash_entries` ledger, and every POS correction goes through one new `PosSaleService` that changes the order, lets `OrderObserver` move the money and stock, and writes an immutable `pos_sale_changes` row. Every older way of undoing a till sale (delete, cancel, mark unpaid) is refused server-side.

**Tech Stack:** Laravel 11 (PHP 8.4, Herd), Vue 3 + Vuex admin SPA, PHPUnit on in-memory SQLite, Vite.

**Spec:** the owner's request of 2026-10-15 (questions skipped: "ja ja add kora dorker koro") — the decisions below are the spec; background in `docs/superpowers/specs/2026-09-26-cash-calculation-design.md`.

## Global Constraints

- E-money (POS card & MFS) is never a balance: no opening/closing, no withdraw/add/transfer/count on accounts 8 and 9, not part of the grand total.
- Card/MFS and "today's sale" figures = POS orders of the branch, `payment_status = PAID`, `status NOT IN (CANCELED, REJECTED)`, `order_datetime` inside the day / from the 1st of that month to the end of the chosen day.
- Reverse = whole sale only, reason required (max 500), the branch cash PIN (`CashPinService::DEFAULT_PIN = '51920'` until changed), sets `status = CANCELED` + `reason`, final.
- Method change = the shop-wide method PIN, default `'112233'`, stored only as a bcrypt hash in `cash_pins` with `outlet_id = 0`, changeable with the current method PIN; reason required; card needs exactly 4 digits, MFS a TrxID, Other a note.
- Both PINs share the existing lockout (5 wrong per user / 10 per branch in 15 minutes) and `cash_pin_failures` log.
- For a till order (`source = POS` or `order_type = POS`), on every endpoint: delete refused; status only CONFIRMED ↔ DELIVERED while live; cancel/reject refused; a reversed sale's status refused; payment status may not become UNPAID.
- Cancelling/rejecting any order (any path) releases its stock rows; un-cancelling a placed order (`active = YES`) takes them again. History is not rewritten automatically.
- No new permissions: POS corrections use `permission:pos-orders|pos` + PIN; the method-PIN change uses `permission:cash-calculation`.
- Never run artisan write commands locally (the local `.env` is the live DB); tests via `cmd //c "C:\Users\User\.config\herd\bin\php.bat vendor\bin\phpunit ..."` with no `bootstrap/cache/config.php`.
- New UI text goes in per-page text modules (en + bn), not `languages/*.json` (storefront bundle).

## Review Focus

- A sale reversed on a later day than it was rung up: it leaves the sale day's figures (order-based) and the drawer shows the money leaving on the reversal day — pinned in Task 2.
- Two people pressing Reverse at the same moment: one reversal, one ledger row — the order row is locked and the status re-checked inside the transaction (Task 4).
- A POS order reached through the online-order endpoints (`/online-order/change-status/{id}`) must hit the same guards — the guards live in `OrderService`, pinned in Task 4.
- A wrong method PIN followed by the right one inside the same request sequence must not leave the order half-changed — PIN is checked before anything is written (Task 4).
- Method change to the method it already has, or on a reversed sale: refused with nothing posted (Task 4).

---

### Task 1: Stock and reports follow cancelled orders

**Files:**
- Modify: `app/Observers/OrderObserver.php` (new private `syncStock(Order $order)` called from `updated()` when `status` changed)
- Modify: `app/Models/Order.php` (add `isTillOrder(): bool`), `app/Models/Product.php:341` (`productOrders()`)
- Modify: `app/Services/DashboardService.php` (`totalSales`, `salesSummary`, `branchSalesSummary` use `countedAsSale()`), `app/Services/StoreSalesReportService.php::query` (exclude CANCELED/REJECTED), `app/Services/OrderService.php::salesReportOverview` (earnings/discounts/shipping from paid live orders only; `total_orders` unchanged)
- Test: `tests/Feature/MonthlyFiguresTest.php` (add permissions `store-sales-report`, `sales-report`, `online-orders`)

**Interfaces:**
- Produces: `Order::isTillOrder(): bool` — `source == Source::POS || order_type == OrderType::POS`; `Product::productOrders()` now = stock rows of orders, `status = ACTIVE`, order not CANCELED/REJECTED.

- [ ] **Step 1: Write the failing tests** in `MonthlyFiguresTest`:
  - `test_cancelled_and_rejected_sales_count_nowhere`: orders 200 (confirmed, paid), 999 (CANCELED, paid), 555 (REJECTED, paid), all `2026-10-10 10:00:00`, outlet "RMC" for the branch card. Assert `dashboard/total-sales` = `currencyAmountFormat(200)`, `dashboard/sales-summary?first_date=2026-10-01&last_date=2026-10-31` `data.total_sales` = `currencyAmountFormat(200)`, `store-sales-report/overview` `data.total_earnings` = `currencyAmountFormat(200)`, `sales-report/overview` `data.total_earnings` = `currencyAmountFormat(200)`.
  - `test_cancelled_sales_do_not_count_as_units_sold`: `sell()` 3 Soap (live) and 4 Soap on an order then set CANCELED; products report row Soap = 3, overview `total_sold_quantity` = 3.
  - `test_an_admin_cancelling_an_order_gives_its_stock_back` (new helper `stockOf(Product)` = sum of ACTIVE stock rows): a delivery order with a −2 ACTIVE stock row, `active = YES`; `POST /api/admin/online-order/change-status/{id}` `{status: 15, reason: 'Fake'}` → stockOf = 0; status back to 5 → stockOf = −2.
- [ ] **Step 2: Run** `cmd //c "C:\Users\User\.config\herd\bin\php.bat vendor\bin\phpunit tests\Feature\MonthlyFiguresTest.php"` — the three new tests fail.
- [ ] **Step 3: Implement.** `syncStock`: new status in [CANCELED, REJECTED] → `Stock::where(model_type Order, model_id)->where('status', ACTIVE)->update(INACTIVE)`; old status (`getOriginal` via `wasChanged` + `getPrevious()`) in that set and new not, and `active == Ask::YES` → INACTIVE rows back to ACTIVE.
- [ ] **Step 4: Run** MonthlyFiguresTest, CashCalculationTest — all pass.
- [ ] **Step 5: Commit** `fix(reports): cancelled and rejected orders release stock and leave every sales figure`.

### Task 2: Ledger — e-money per day/month, method moves, grand total

**Files:**
- Modify: `app/Enums/CashEntryType.php` (`POS_METHOD_OUT = 13`, `POS_METHOD_IN = 14`), `app/Services/CashLedgerService.php`, `app/Http/Requests/CashQueryRequest.php` (`type` `between:1,14`)
- Test: `tests/Feature/CashCalculationTest.php`

**Interfaces:**
- Produces: `CashLedgerService::sales(Outlet $outlet, Carbon $date): array` =
  `['day' => ['total','count','discount','cost','profit','missing_cost','methods' => ['cash','card','mfs','other']], 'month' => [same + 'from','to' (Y-m-d)], 'reversed' => ['count','total']]` — profit = Σ(order total − order tax) − Σ(units sold × product buying_price); missing_cost = distinct products sold in the period whose buying_price is 0. (floats rounded 2, counts int; `reversed` filled in Task 4, zeros until then).
- `statement()` returns `sales` instead of `emoney`; each account row gains `pos_method`; `grand_total.emoney` = SIM closings only.
- `accounts(Outlet)` = DRAWER (+ the six MFS pots when on) — POS_CARD/POS_MFS no longer manual.

- [ ] **Step 1: Rewrite/replace the e-money tests:**
  - replace `test_the_grand_total_is_the_only_place_the_three_are_added_up` assertions: `sales.day.methods.card` = 120, `sales.day.methods.mfs` = 80, `grand_total.emoney` = 2550, `grand_total.total` = 3900.
  - replace `test_every_section_opens_on_yesterdays_closing` loop with drawer only; add `test_card_and_mfs_show_the_day_and_the_month_never_a_running_total`: 300 card + 90 MFS on 2026-09-30, 200 card on 2026-10-01 09:00, 50 card + 70 cash on 2026-10-02; summary for 2026-10-02 → day card 50, mfs 0, cash 70, total 120, count 2; month card 250, mfs 0, cash 70, total 320, from `2026-10-01`, to `2026-10-02`.
  - `test_profit_is_the_sale_without_tax_less_what_the_units_cost`: 2 Soap (buying 100) sold for 300 incl. tax 20 → day cost 200, profit 80; a product with buying_price 0 sold → missing_cost 1; month profit sums both days.
  - `test_a_sale_reversed_next_day_leaves_its_sale_day`: card 300 on 2026-10-01, `Order::update(status CANCELED)` on 2026-10-02 → day 2026-10-01 card = 0.
  - replace `test_card_emoney_can_be_settled_out_with_the_pin` and `test_card_emoney_is_counted_as_one_typed_figure` with `test_card_and_mfs_take_no_manual_entries`: add/withdraw/count on account 8 and 9 → 422.
  - change `test_correcting_a_sales_payment_method_moves_its_money`: drawer +300 row typed `POS_METHOD_IN`, card −300 row `POS_METHOD_OUT`, both with the same non-null `group_ref`; summary `drawer.pos_method` = 300; no `POS_SALE_REVERSAL` row.
  - `test_the_whole_page_comes_in_one_request`: `summary.sales.day.methods.card` = 300 instead of `emoney.card.closing`.
- [ ] **Step 2: Run** CashCalculationTest — the changed tests fail.
- [ ] **Step 3: Implement** `sales()` (one grouped query per period: `SUM(total), COUNT(*), SUM(discount)` by `pos_payment_method`, till orders of the outlet via `countedAsSale()`), the statement changes, and the move detection in `syncOrder` (any positive and any negative difference in one pass ⇒ METHOD types, all lines in one `post()` call).
- [ ] **Step 4: Run** CashCalculationTest — pass.
- [ ] **Step 5: Commit** `feat(cash): card and MFS shown per day and month, method changes recorded as moves`.

### Task 3: The method-change PIN

**Files:**
- Modify: `app/Services/CashPinService.php`, `app/Http/Controllers/Admin/CashCalculationController.php` (`methodPin`), `app/Http/Requests/CashMovementRequest.php` (`methodPin` rules = `changePin` rules), `routes/api.php` (`POST cash-calculation/method-pin`), `lang/en/all.php`
- Test: `tests/Feature/CashCalculationTest.php`

**Interfaces:**
- Produces: `CashPinService::METHOD_PIN_DEFAULT = '112233'`, `CashPinService::METHOD_PIN_ROW = 0`, `verifyMethodPin(int $outletId, ?string $pin): void` (throws `CashException` 422/429, failure action `method_change`), `changeMethodPin(int $outletId, string $currentPin, string $newPin): void`. `verify()` and `verifyMethodPin()` share one private guard.

- [ ] **Step 1: Write failing tests:** `test_the_method_pin_starts_at_112233_and_is_not_the_branch_pin` (verifyMethodPin accepts `112233`, refuses `51920` with a `cash_pin_failures` row action `method_change`); `test_the_method_pin_change_needs_the_current_method_pin` (POST `method-pin` with current `51920` → 422; with `112233` → 200; then `112233` refused, new accepted; branch PIN still `51920`).
- [ ] **Step 2: Run** — fail.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** CashCalculationTest + `tests/Feature/AdminAccessTest.php` — pass.
- [ ] **Step 5: Commit** `feat(cash): shop-wide PIN for payment-method changes`.

### Task 4: Reverse and method change on POS sales

**Files:**
- Create: `database/migrations/2026_10_15_000001_create_pos_sale_changes_table.php`, `app/Models/PosSaleChange.php` (append-only like `CashEntry`; consts `REVERSE = 'reverse'`, `METHOD = 'method'`), `app/Services/PosSaleService.php`, `app/Http/Requests/PosSaleChangeRequest.php`, `tests/Feature/PosSaleChangeTest.php`
- Modify: `app/Http/Controllers/Admin/PosOrderController.php` (`reverse`, `changeMethod`; middleware lists), `routes/api.php` (both under `idempotent`), `app/Services/OrderService.php` (guards in `changeStatus`, `changePaymentStatus`, `destroy`), `app/Http/Resources/OrderDetailsResource.php` (`is_reversed`, `pos_changes`), `app/Services/CashLedgerService.php` (`sales().reversed`, `alerts()` reasons + `method_changes`), `lang/en/all.php`, `tests/Feature/CashCalculationTest.php` (old cancel/delete/unpaid tests now assert refusal; reversal via the new endpoint)

**Interfaces:**
- Consumes: `Order::isTillOrder()`, `CashPinService::verify`/`verifyMethodPin`, observer stock + ledger sync.
- Produces: `PosSaleService::reverse(Order $order, string $reason, ?string $pin): Order`, `PosSaleService::changeMethod(Order $order, int $method, ?string $paymentNote, string $reason, ?string $pin): Order` (both throw `CashException`); `POST /api/admin/pos-order/reverse/{order}` `{reason, pin}` and `POST /api/admin/pos-order/change-method/{order}` `{pos_payment_method, pos_payment_note, reason, pin}` → `OrderDetailsResource`; `pos_changes[]` = `{action, amount, from_method, from_method_name, to_method, to_method_name, from_note, to_note, reason, by, at}`; alerts `method_changes[]` = `{id, order_id, reference, amount, from, to, reason, by, at}`, `pos_reversals[].reason`.
- `pos_sale_changes`: `id, order_id, outlet_id, action(10), amount decimal(19,6), from_method/to_method tinyint null, from_note/to_note string(190) null, reason string(500), created_by, ip, created_at`; index `(outlet_id, created_at)`, `order_id`.

- [ ] **Step 1: Write failing tests** (`PosSaleChangeTest`, helpers copied from CashCalculationTest):
  - `test_reversing_a_cash_sale_takes_it_back_everywhere`: ring 150 cash; reverse with `51920` → 200; order CANCELED with reason; drawer 0; stock of Soap back to 0 at the branch; one `pos_sale_changes` row (action reverse, amount 150, created_by owner); cash summary `sales.day.total` = 0, `sales.reversed.count` = 1.
  - `test_a_reversal_needs_the_branch_pin_and_a_reason` (wrong PIN → 422, order untouched, nothing posted); `test_a_reversal_needs_a_reason` (missing reason → 422).
  - `test_a_reversed_sale_cannot_be_reversed_again_or_changed` (second reverse → 422; change-method → 422; change-status 5 → 422).
  - `test_changing_the_method_moves_the_money_with_the_method_pin`: cash 300 → card `pos_payment_note 4321`, PIN `112233` → drawer 0, `sales.day.methods.card` = 300, change row from 5 to 10 with notes; branch PIN `51920` refused.
  - `test_a_card_method_needs_four_digits_and_mfs_a_trx_id` (card `12` → 422; MFS empty → 422).
  - `test_the_same_method_is_refused`.
  - `test_pos_sales_can_no_longer_be_deleted_cancelled_or_marked_unpaid` — three tests, one request each (see memory: one failing request per test): delete → 422 and order exists; change-status 15 → 422; change-payment-status 10 → 422; each leaves drawer 150.
  - `test_the_online_order_endpoint_cannot_cancel_a_pos_sale` (`online-order/change-status` 15 on a POS order → 422).
  - `test_the_watch_list_shows_reversals_with_reasons_and_method_changes`.
  - `test_the_order_page_lists_its_changes` (`pos-order/show/{id}` `data.pos_changes` has both rows in order, `data.is_reversed` true after reverse).
- [ ] **Step 2: Run** `...phpunit tests\Feature\PosSaleChangeTest.php` — fail.
- [ ] **Step 3: Implement.** Service: till-order check → status check → method validity → PIN → `DB::transaction` { `Order::whereKey()->lockForUpdate()` re-read, re-check status, update order (`pos_received_amount = total` when becoming cash, else null), `PosSaleChange::create` } → after commit `app()->terminating()` Telegram message (`TelegramNotifier::send`, "POS sale reversed" / "POS payment method changed", serial, branch, amount, from→to, by, reason).
- [ ] **Step 4: Run** PosSaleChangeTest, CashCalculationTest, MonthlyFiguresTest, AdminAccessTest — pass.
- [ ] **Step 5: Commit** `feat(pos): reverse a sale or correct its payment method behind PINs`.

### Task 5: Cash Calculation page

**Files:**
- Modify: `resources/js/components/admin/cashCalculation/CashCalculationComponent.vue`, `CashActionModalComponent.vue`, `cashCalculationText.js`

- [ ] **Step 1:** Hero card (balance viewers, above the statements): `summary.sales.day.total` in `text-4xl` with "Today's sale" (or "Sale on dd-mm-yyyy"), sale count, profit (green/red) with cost, four method tiles, discount and reversed line, a note when `missing_cost > 0`; right column "This month (from–to)" total, profit + four methods.
- [ ] **Step 2:** E-money card: two columns Card / MFS, rows "Today" (bold) and "This month", total line; no opening/closing/withdraw.
- [ ] **Step 3:** Drawer line `pos_method` "Payment method changed"; TYPES add 13, 14 + labels; grand-total e-money label "SIM balances (bKash / Nagad / Recharge)"; add/withdraw/transfer account lists without 8 and 9.
- [ ] **Step 4:** Watch list: reversal reason, new "Payment method changed" column; Settings modal form "Payment-method change PIN" → `admin/cash-calculation/method-pin`.
- [ ] **Step 5:** `npm run build` succeeds; harness check (Task 8) later.
- [ ] **Step 6: Commit** `feat(cash-page): today's sale in big, card/MFS per day and month`.

### Task 6: POS order pages

**Files:**
- Create: `resources/js/components/admin/posOrders/PosSaleActionModalComponent.vue`, `resources/js/components/admin/posOrders/posSaleText.js`
- Modify: `PosOrderShowComponent.vue`, `PosOrderListComponent.vue`

- [ ] **Step 1:** Show page: buttons "🔒 Change payment method" and "🔒 Reverse sale" (hidden when `is_reversed`); reversed banner with reason; payment-status select only while unpaid; status select hidden when reversed; "Changes" card from `pos_changes`.
- [ ] **Step 2:** Modal: reverse (reason + branch PIN) / method (new method select without the current one, card last 4 / TrxID / note input, reason, method PIN); posts through `cashHttp.save` (idempotent); shows server errors per field; on success re-dispatches `posOrder/show`.
- [ ] **Step 3:** List: no delete icon; CANCELED/REJECTED shown as "Reversed"; status filter gains "Reversed" (15).
- [ ] **Step 4:** `npm run build` succeeds.
- [ ] **Step 5: Commit** `feat(pos-orders): reverse and payment-method change from the order page`.

### Task 7: Stock still held by old cancelled orders

**Files:**
- Create: `app/Console/Commands/ReleaseCancelledOrderStock.php` (`orders:cancelled-stock {--fix}`), `tests/Feature/ReleaseCancelledOrderStockTest.php`

- [ ] **Step 1: Failing tests:** dry run lists 1 order / 2 units and changes nothing; `--fix` sets those rows INACTIVE; a live order's rows untouched.
- [ ] **Step 2–4:** run, implement (orders CANCELED/REJECTED with ACTIVE `Order` stock rows; table output: order serial, date, branch, units), run.
- [ ] **Step 5: Commit** `feat(stock): report and release stock held by cancelled orders`.

### Task 8: Online-order invoice instead of the till receipt (user request mid-run)

The owner: online orders print a POS-style 80 mm receipt; they should get the A4 invoice other shops send; the POS receipt stays exactly as it is.

**Files:**
- Create: `resources/js/components/shared/OrderInvoiceComponent.vue` (the printable A4 sheet; props `printId`, `order`, `orderProducts`, `orderUser`, `orderAddress`, `outletAddress`), `resources/js/components/shared/invoiceText.js` (en + bn)
- Modify (markup only, parents untouched): `resources/js/components/admin/onlineOrders/OnlineOrderReceiptComponent.vue`, `resources/js/components/frontend/account/orderDetails/OrderReceiptComponent.vue`, `resources/js/components/frontend/components/OrderReceiptComponent.vue`
- Not touched: `admin/pos/ReceiptComponent.vue`, `admin/posOrders/PosOrderReceiptComponent.vue`

- [ ] **Step 1:** Sheet: logo + company name/address/phone/email; "INVOICE", number `#order_serial_no`, date, order type, payment method, PAID/UNPAID stamp; Bill to (name, phone, email) and Ship to (shipping address) — or Pick-up branch; items table SL / Product (+variation) / Unit price / Qty / Amount; totals Subtotal, Discount, Wallet, Tax, Shipping, Grand total, Paid, Due (COD: amount to pay on delivery); footer thank-you, domain, support phone, "computer-generated".
- [ ] **Step 2:** Print with `@page{size:A4;margin:12mm}` (no commas in `extraHead`), black-on-white, table header repeated across pages.
- [ ] **Step 3:** `npm run build`; harness print-preview check in Task 9.

### Task 9: "All" rows on admin lists (user request mid-run)

The owner: lists show at most 1000 rows at a time; there are more products, so an "All" choice is wanted.

**Files:**
- Modify: `app/Http/Requests/PaginateRequest.php` (`per_page` max 1000 stays for the storefront; `api/admin/*` allows up to `PaginateRequest::ADMIN_ALL = 100000`), `resources/js/components/admin/components/TableLimitComponent.vue` (option `100000` labelled `label.all`), `resources/js/languages/{en,bn,ar}.json` (`label.all`)
- Test: `tests/Feature/AdminListAllRowsTest.php`

- [ ] **Step 1: Failing tests:** `test_an_admin_list_can_show_every_row` (`GET /api/admin/product?paginate=1&per_page=100000` → 200, `meta.per_page` 100000); `test_the_storefront_still_caps_a_page_at_1000` (`GET /api/frontend/product?paginate=1&per_page=100000` → 422).
- [ ] **Step 2–4:** run (first fails 422), implement, run.

### Task 11: Stock only goes up by hand, never below zero, Telegram at one left (user request mid-run)

The owner: on the stock page new stock is added to what is there; stock is never reduced by hand (only sales take it out, purchases bring it in); stock is never negative; when a product is down to one, tell me on Telegram.

**Decisions (my calls):** the stock edit dialog sends `stocks[].add` (≥ 0, an old `quantity` payload is refused) and writes ADD adjustments; new REMOVE and RECALCULATE adjustments are refused; TRANSFER stays (moves between branches) but cannot take a branch below zero; deleting an adjustment that would lower an item's stock is refused. Damage, purchase edit/delete and supplier documents keep working but can never leave a branch negative. A POS sale of a tracked product (`can_purchasable` ≠ NO) beyond the branch's stock is refused. The alert fires per branch when a sale takes an item from more than 1 to 1 or less ("1 left" / "out of stock"), with the all-branch total.

**Files:**
- Create: `app/Services/StockGuard.php` (`settle(array $changes): void` — asserts every touched item/branch ≥ 0 and queues low-stock alerts after commit; a change = `product_id, item_type, item_id, outlet_id, quantity`), `app/Events/SendLowStockTelegram.php`, `app/Listeners/SendLowStockTelegramNotification.php`, `app/Services/LowStockTelegramNotificationService.php`, `tests/Feature/StockRulesTest.php`
- Modify: `OrderService::posOrderStore`, `StockAdjustmentService` (`store`, `updateItemStock`, `destroy`), `StockAdjustmentRequest`, `StockItemUpdateRequest`, `DamageService`, `PurchaseService` (update, destroy), `PaymentService` + `Cashondelivery` (alerts on online sales), `EventServiceProvider`, `lang/en/all.php`, `StockEditModalComponent.vue`, `StockAdjustmentCreateComponent.vue`, POS-selling tests' setUp (stock to sell from), `BranchStockTest` (add semantics)

- [ ] Failing tests, run, implement, run; full suite.

### Task 10: Verify and ship

- [ ] **Step 1:** Full suite `...phpunit` — all pass (report counts).
- [ ] **Step 2:** Browser harness (memory: storefront-browser-harness, admin variant): mount CashCalculationComponent and PosOrderShowComponent with fixtures from a PHPUnit probe; check hero numbers, e-money rows, reverse/method modals, refusal messages, phone width 390px.
- [ ] **Step 3:** `git status` + `find -newer suglow-TELEGRAM-ONLINE.zip` for other sessions' work; `npm run build`; `make-zip.ps1 -Name POS-REVERSE`; verify the zip (entries, backslashes 0, no `.env`/`storage/`, manifest assets present, changed files identical).
- [ ] **Step 4:** Hand-off in Bengali: SSH block with `php artisan migrate --force` (after a phpMyAdmin export), curl checks for `POST /api/admin/pos-order/reverse/1` and `/api/admin/cash-calculation/method-pin` with `Accept: application/json` (401 = deployed), `php artisan orders:cancelled-stock` dry run.
