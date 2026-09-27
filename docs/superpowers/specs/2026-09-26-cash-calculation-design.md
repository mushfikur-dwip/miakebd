# Cash Calculation — design

Date: 2026-09-26 · Status: awaiting owner review (revision 2)

## Goal

A **Cash Calculation** page in the admin panel that shows, per branch (outlet),
how much money the branch should hold. POS cash sales are recorded
automatically, money can be added by hand, and taking money out needs the
branch's PIN. Branches that offer bKash / Nagad agent service also track that
business separately. The main aim is that **an employee cannot quietly take
money**: every taka that moves leaves a permanent, named record, and the
physical count is checked against the system.

### Decided with the owner

| Topic | Decision |
|---|---|
| Existing features | **Additive only.** POS, orders, outlets and reports behave exactly as today. No existing screen changes. |
| Who can see it | Customers never. Staff only with the permission. |
| bKash / Nagad | **Agent service only** — customers doing cash in / cash out at the counter. POS sales paid by MFS are *not* part of this and stay as today. |
| MFS cash | Kept in a **separate box** per provider, not in the sales drawer. |
| Balance visibility | **Blind for cashiers.** Only holders of `cash-calculation_balance` see balances, history and count results. |
| PIN | **One PIN per branch.** Every branch starts at `51920`; a branch's PIN is changed by entering that branch's current PIN. |
| What needs the PIN | **Money out only:** withdraw, transfer, reversal, MFS on/off, PIN change. Adding money, MFS cash in/out and counting do not. |
| Recharge (2026-09-27) | **A service of its own**, next to bKash agent and Nagad agent, with its own balance (accounts 6 "Recharge balance", 7 "Recharge cash"). bKash and Nagad do In / Out only; a recharge is refused there. The one MFS switch turns all three on. |
| Entry on the page (2026-09-27) | In / Out / Recharge are typed straight into boxes on each service's card (amount, optional number, Add), not behind a dialog. Dates use the admin's own date picker, never a native date input. |

## Core idea: an append-only ledger

Balances are never stored as an editable number. Every movement is one row in
`cash_entries`; a balance is the sum of its rows. Rows cannot be edited or
deleted by anyone — the model throws on `updating` and `deleting`, and there is
no endpoint for it. A mistake is corrected by a **reversal** row that points at
the original, carries a reason and needs the PIN. The original stays visible,
struck through.

## Accounts

Each branch has up to five accounts:

| Account | Meaning | Present when |
|---|---|---|
| Drawer | Physical shop cash: opening + POS cash sales + adds − withdrawals | Always |
| bKash SIM | e-money on the branch's bKash agent number | Branch MFS on |
| bKash cash | Notes from bKash agent business | Branch MFS on |
| Nagad SIM | e-money on the branch's Nagad agent number | Branch MFS on |
| Nagad cash | Notes from Nagad agent business | Branch MFS on |

`outlets.mfs_enabled` (boolean, default false) switches the four MFS accounts on
for a branch. Turning it off hides them; their history is kept.

## Money movements

| Action | Effect | PIN | Who posts it |
|---|---|---|---|
| POS cash sale | Drawer + order total | no | Automatic |
| POS cash sale cancelled / rejected / marked unpaid / deleted | Automatic reversal of what was posted for that order | no | Automatic, flagged |
| Add money (incl. opening balance, agent commission) | Chosen account + amount, reason required | no | Staff |
| MFS Cash In (customer gives notes, we send e-money) | SIM − amount, MFS cash + amount | no | Staff |
| MFS Cash Out (customer sends e-money, we give notes) | SIM + amount, MFS cash − amount | no | Staff |
| MFS Recharge (customer gives notes, we recharge their mobile from the SIM) | SIM − amount, MFS cash + amount — always, same as Cash In | no | Staff |
| Withdraw | Chosen account − amount; reason and "given to" required; cannot exceed balance | **yes** | Staff + owner |
| Transfer | Account A − amount, account B + amount (same branch), e.g. Drawer → bKash cash; cannot exceed balance | **yes** | Staff + owner |
| Reversal | Opposite of one earlier manual entry, reason required | **yes** | Staff + owner |
| Count | Posts the difference between counted and expected as a variance row | no | Staff |

Cash In and Recharge are never refused over the SIM balance (owner's call,
2026-09-27): the SIM figure may be out of date, and a SIM that goes below zero
is put right by its next count. Cash Out still cannot hand over more notes
than the MFS cash box holds. There is no fee field; any agent commission is
entered with **Add money**.

Card, MFS and "Other" POS payments never touch the drawer; the summary lists
today's totals for them for information only, read from the existing orders.

The ledger starts on the day it goes live. There is no backfill; each account's
opening amount is entered once with **Add money → Opening balance**.

### POS cash sales and their reversals

One idempotent function, `CashLedgerService::syncOrder(Order)`, decides what a
till order should have posted to the Drawer and posts only the difference:

- target = order total when the order exists, was paid in **cash**, is not
  cancelled or rejected, and is paid; otherwise 0;
- the difference from what is already posted for that `order_id` is written as
  a `POS_SALE` (+) or `POS_SALE_REVERSAL` (−) row.

It is called from the existing `OrderObserver` on `created`, on `updated` when
`status`, `payment_status` or `pos_payment_method` changes, and on `deleted`.
The observer's `created` runs inside `posOrderStore`'s transaction, so the sale
and its cash row commit together. `posOrderStore`, `changeStatus` and `destroy`
are not edited. Online orders are ignored (the same till-order test the
observer already uses).

A reversal row records who caused it and how long after the sale it happened;
the page lists these in an alert panel. Deleting or cancelling a cash sale
after pocketing the money is the most common till theft, so it is never silent.

## Anti-cheat

- **Immutable ledger** — no edit or delete, reversals only, with PIN.
- **Server-side facts** — user, branch, IP and time are taken from the request,
  never from the form.
- **Running balance** — each row stores `balance_after` for its account,
  computed while the branch row is locked (`lockForUpdate`), so two tills
  posting at once cannot corrupt it and every row shows the balance it left.
- **Money out needs the branch PIN** and cannot exceed the balance.
- **Per-branch PIN** — stored only as a bcrypt hash. A branch with no PIN row
  yet uses the default `51920`, so new branches work with no setup and
  `OutletService` is not touched. While a branch still uses the default, balance
  viewers see a "Change this branch's PIN" warning, because the default is
  known to everyone who read this document.
- **Lockout** — five wrong tries by one user (on any branch), or ten by anyone
  on one branch, within 15 minutes lock PIN actions for 15 minutes. Counted from
  `cash_pin_failures`, keyed on user id and branch (not IP, which the CDN may
  hide), so a cache clear does not reset it. Every wrong try is shown to
  balance viewers with the person's name.
- **Blind count** — the count form never shows the expected amount. For the
  drawer and MFS cash boxes staff enter note counts (1000, 500, 200, 100, 50,
  20, 10, 5, 2, 1); for SIM accounts they type the balance shown on the agent
  phone. The variance is posted as a row under the counter's name, so shortages
  build a visible history per person. Only balance viewers see the result.
- **MFS cross-check** — SIM + MFS cash for a provider only changes through
  adds, withdrawals and transfers — Cash In, Recharge and Cash Out leave it
  unchanged. An employee who sends e-money or recharge to their own number, or
  keeps the notes from a cash in or recharge, leaves one of the two short at
  the next count.
- **Permission split** — `cash-calculation` lets staff use the page;
  `cash-calculation_balance` is needed to see any balance. Without it, a user
  sees the action buttons and only the entries they themselves made today.

## Permissions and menu

| Permission | Grants | Default roles |
|---|---|---|
| `cash-calculation` | Open the page, add money, MFS cash in / out / recharge, count, PIN actions (with PIN) | Admin, Manager |
| `cash-calculation_balance` | See balances, full history, count results, alerts | Admin, Manager |

The owner can grant `cash-calculation` to POS operators in **Settings → Roles**.
Every new route declares a `permission:` middleware, so it passes the existing
`test_every_admin_route_declares_a_permission` check; the admin group's
`EnsureStaff` keeps customers and guest tokens out entirely.

A top-level **Cash Calculation** menu item is added by a data migration (for the
live site) and by the permission and menu seeders (for fresh installs), using
the same pattern as `2026_08_05_100001_add_campaign_permission_and_menu.php`.

## Data model

New tables:

- `cash_entries` — `id`, `outlet_id`, `account` (tinyint), `type` (tinyint),
  `amount` (decimal 19,6, signed), `balance_after` (decimal 19,6), `order_id`
  (nullable, no cascade — the row outlives a deleted order), `reverses_id`
  (nullable), `group_ref` (nullable uuid shared by the two rows of a transfer or
  cash in / out / recharge), `reference` (nullable, e.g. MFS transaction id), `party`
  (nullable, "given to" / customer number), `note`, `created_by`, `ip`,
  `created_at`. Indexed on `(outlet_id, account, id)` and `order_id`.
- `cash_counts` — `id`, `outlet_id`, `account`, `expected`, `counted`,
  `variance`, `denominations` (json, nullable), `entry_id` (the variance row),
  `created_by`, `created_at`.
- `cash_pins` — `outlet_id` (unique), `pin_hash`, `updated_by`, timestamps. No
  row means the default `51920`.
- `cash_pin_failures` — `user_id`, `outlet_id`, `action`, `ip`, `created_at`.

New column: `outlets.mfs_enabled` boolean default false (existing rows get
false, so nothing changes until the owner turns it on).

New enums: `CashAccount`, `CashEntryType`.

## API (all under `/api/admin/cash-calculation`)

| Method | Path | Permission | PIN |
|---|---|---|---|
| GET | `/summary?outlet_id=&date=` | cash-calculation (balances only with `_balance`) | — |
| GET | `/entries?outlet_id=&from=&to=&type=&account=` | cash-calculation (full list only with `_balance`) | — |
| GET | `/alerts?outlet_id=` | cash-calculation_balance | — |
| POST | `/add` | cash-calculation | — |
| POST | `/mfs` (`provider`, `kind`: cash_in / cash_out / recharge, `amount`, optional `reference`, `party`) | cash-calculation | — |
| POST | `/count` | cash-calculation | — |
| POST | `/withdraw` | cash-calculation | branch PIN |
| POST | `/transfer` | cash-calculation | branch PIN |
| POST | `/reverse/{entry}` | cash-calculation | branch PIN |
| POST | `/mfs-toggle` | cash-calculation | branch PIN |
| POST | `/pin` (`outlet_id`, `current_pin`, `new_pin`, 5–8 digits) | cash-calculation | branch's current PIN |

No existing request, resource or endpoint changes.

## Screens

**Cash Calculation page** (`/admin/cash-calculation`):

1. Branch tabs and a date picker (default today) at the top — switching either
   changes every number below. Each calculation is a daily statement for the
   chosen date: **opening** (the real balance at the start of that day, i.e.
   the previous day's closing — never typed in) → that day's movements →
   **closing** (or "available now" for today).
2. **Shop cash** — its own calculation, kept apart from MFS:

   | Line | Amount |
   |---|---|
   | Opening balance | balance at 00:00 |
   | + POS cash sales | |
   | + Money added | |
   | − Withdrawn | |
   | ± Transfers, count differences, reversals | |
   | **= Available cash** | |

   Also: last count (when, by whom, short/over) and, as information only, that
   day's card / MFS / other POS sales. Buttons: Add money, Withdraw 🔒,
   Transfer 🔒, Count.
3. **bKash agent** — its own calculation (MFS on only), two columns, SIM and
   cash in hand:

   | Line | SIM | Cash |
   |---|---|---|
   | Opening balance | | |
   | Cash In (customers) | − | + |
   | Cash Out (customers) | + | − |
   | Recharge (customers) | − | + |
   | + Added / − Withdrawn / ± Transfers, count differences | | |
   | **= Closing** | | |
   | **bKash total (SIM + cash)** | | |

   Buttons: Cash In, Cash Out, Recharge, Add, Withdraw 🔒, Count.
4. **Nagad agent** — the same calculation, separately.
5. **Grand total** — the only place the three are added together: shop cash +
   bKash total + Nagad total = **total branch money**, with a split into
   physical notes (drawer + both MFS cash boxes) and e-money (both SIMs).
6. **Alerts:** POS sale reversals, wrong PIN tries, count shortages (last 7
   days), and "this branch still uses the default PIN".
7. **History table:** date, account, type, amount (green +, red −), balance
   after, by, note / reference; filters for date range, account and type;
   reversed rows struck through with a link to their reversal; a Reverse 🔒
   action on manual rows.
8. Settings (gear): MFS agent service on/off for this branch 🔒, change this
   branch's PIN.

Cashiers without `_balance` see the branch tabs, the action buttons and "My
entries today" — no statements, grand total, alerts or count results.

The POS is not changed.

## Error handling

- Validation errors return 422 with a field message, as the rest of the admin
  API does.
- Wrong PIN: 422 "Wrong PIN" plus the tries left; locked: 429 with the minutes
  remaining.
- Withdraw, transfer or MFS movement above the balance: 422 showing the
  available amount only to balance viewers ("Not enough balance" to others).
- Posting runs in a DB transaction with the branch row locked; any failure
  rolls back the whole movement (both rows of a transfer, cash in / out or
  recharge).

## Testing

Feature tests (in-memory SQLite, `phpunit` via Herd PHP — never against the
live DB):

- A POS cash sale posts one Drawer row equal to the total; card, MFS and other
  POS sales post nothing.
- Cancel, reject, mark unpaid and delete each post exactly one reversal;
  un-cancelling re-posts; repeated status saves post nothing extra.
- An existing POS order payload still creates the order exactly as before.
- Entries cannot be updated or deleted through the model.
- Per-branch PIN: a branch with no PIN row accepts `51920`; changing branch A's
  PIN needs A's current PIN and leaves branch B on its own PIN; the old PIN then
  fails on A.
- Withdraw: wrong PIN rejected and logged; fifth failure by one user locks;
  tenth failure on one branch locks; right PIN works; amount above balance
  rejected.
- Cash In, Recharge and Cash Out move SIM and MFS cash in opposite directions
  with one shared `group_ref` (Recharge: SIM −, cash + — the same as Cash In,
  under its own type); a cash in or recharge larger than the SIM balance is
  rejected; a cash out larger than the MFS cash box is rejected; the request
  has no fee field; MFS endpoints refuse a branch with MFS off.
- Blind count: response hides expected / variance without `_balance`; variance
  row posted with the correct sign.
- Daily statement: a date's opening equals the previous date's closing; shop
  cash, bKash and Nagad each add up on their own; the grand total equals their
  sum; day boundaries follow the timezone set in the admin settings.
- A customer token and a guest token get 403 on every endpoint; staff without
  the permission get 403; the route-coverage test passes.

## Out of scope

POS sales paid by MFS (they stay as today), hash-chained tamper evidence at
database level, automatic bKash / Nagad balance reading, bank account tracking,
a dashboard widget, and backfilling past POS sales.
