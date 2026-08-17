# WBAM Hub

In-house operations hub for WeBuyAnyMobile on top of Shopify: used-device intake with IMEI registry and per-unit costs, shelf-label printing, repair tickets with customer notifications and vendor POs, and live sales/profit reporting (webhook-fed, with an on-demand Refresh button).

Runs as a WordPress plugin on **system.webuyanymobile.com** — same stack and the same client-credentials custom-app pattern as the existing Redeem Voucher / Site Integration plugins.

## Architecture in 30 seconds

- **Shopify stays the source of truth for money and pooled stock** (products, POS, payments, checkout). The Hub owns everything Shopify can't: which exact IMEI is which, what you paid for it, repairs, parts, vendors, and cross-cutting reports.
- **Webhook-first**: `orders/create|updated`, `refunds/create` land in the Hub's warehouse tables within seconds of a sale, so the Today report is always current. The nightly cron is only a safety sweep; the **↻ Refresh from Shopify** button on the report page force-pulls today on demand (that's the store-closing button).
- Intake **adds** pooled variant stock (+1) in Shopify, prints a label whose barcode is the **variant barcode** (so native POS scanning finds the product), and keeps variant *cost per item* at the rolling average of in-stock units. Exact per-unit profit lives in the Hub via order-line ↔ unit matching (automatic when unambiguous, one-scan Reconcile screen otherwise).

## 1) Hosting

- WordPress 6.x, PHP 8.0+, MySQL. HTTPS required (Shopify webhooks refuse plain HTTP).
- Point `system.webuyanymobile.com` at the server, install WP, install this plugin folder into `wp-content/plugins/wbam-hub`, activate.
- WP-Cron is unreliable on low-traffic sites; add a real cron:
  ```
  */5 * * * * curl -s https://system.webuyanymobile.com/wp-cron.php > /dev/null
  ```

## 2) Shopify custom app (one-time)

Create the app **in the WeBuyAnyMobile store's own dev dashboard** (store admin → Settings → Apps → App development → Build apps in Dev Dashboard), exactly like the Redeem apps — client-credentials only works on the store's own org.

**Required scopes — all of these in the *Required* box (NOT Optional, or writes 403):**

```
read_products, write_products, read_inventory, write_inventory,
read_orders, write_orders, read_customers, write_customers,
read_locations, read_draft_orders, write_draft_orders, read_cash_tracking
```

Release the version, install, copy the client ID + secret.

> **Why no `read_users`:** the API's `Order.staffMember` (which PIN-staff made the sale) is restricted to Advanced/Plus stores. Orders still arrive carrying a numeric `user_id` — the Hub collects those automatically and you name them once in **Settings → Staff map**. Per-staff sales/profit reports work off that.

## 3) Configure the plugin

WBAM Hub → **Settings**:

1. Shop domain `sa-we-buy-any-mobile.myshopify.com`, client ID/secret → **Save**, then **Test connection**.
2. **Sync locations → branches** (Chapel Road now; new branches appear when you add locations in Shopify).
3. **Register webhooks** (idempotent; endpoint shown at the bottom of the page).
4. **Backfill 59 days** once, so reports start with history. (Older history: Shopify admin CSV export — the API's `read_orders` window is 60 days.)
5. Tender buckets: already maps `cash`→Cash, `shopify_payments`→Card, `Trade In`→Trade-in (verified against your live orders).
6. Optional SMS: set Twilio SID/token/from. Without it, customers get email only.

Create two WordPress pages:

- **/report** containing `[wbam_report]` — the store-closing report for POS-side tablets/phones. Log the manager in once on the device and bookmark it. Tabs: Today / Yesterday / Week / Month / Quarter / Year + branch filter + ↻ Refresh.
- **/track** containing `[wbam_track]` — customer self-service repair status (ticket # + last 4 of phone).

WordPress users: give branch managers the **WBAM Manager** role (reports + stocktake), shop-floor staff **WBAM Staff** (intake, tickets, parts).

## 4) Storefront repair form

Replace the current form on `/pages/repair` with `templates/booking-form.html` (Custom Liquid section). Bookings create tickets instantly (with confirmation email/SMS) — after which Powerful Form Builder ($19.90/mo) can be uninstalled.

## 5) Daily use

**Buying a phone (intake)** — WBAM Hub → Device intake: scan IMEI, type model, tap options, price paid, Save + print label. This: registers the unit → +1 Shopify stock at the branch → updates rolling avg cost → prints the label → records the payout (cash buy-ins show against the day's cash for reconciliation).

**Selling** — scan the label at POS like any product. The webhook attaches the exact unit automatically; when several identical units are in stock, the **Reconcile** screen asks for one scan of the unit that actually left. (Keep the printed label on the phone until sold.)

**Repairs** — bookings arrive from the web form, the Hub screen, or the **POS Repairs tile** (see `pos-extension/` — booking + deposit in one flow, no typing). Move the status forward; customers get notified on the steps that matter. Deposits/balances in store: the POS tile adds the correctly-titled line to the cart; until the tile is deployed, create a draft from the ticket screen and open it in POS under **Draft orders**. Remote payment: same draft's invoice link. All of it links back to the ticket automatically.

**Parts** — on the ticket: *Use from stock* (decrements internal stock at avg cost) or *Order from vendor* (creates a draft PO). Staff place the actual order on the **supplier's website** (link buttons on the PO), enter the price paid, hit **Mark ordered**; *Receive* books costs to the ticket or into stock. Parts are internal only — never Shopify products. Direct supplier-site ordering can be integrated later.

**Stocktake** (per branch, managers): scan every phone on the shelf → Compare → Apply. Writes off missing units and pushes exact pooled counts to Shopify. Run one at rollout to make Shopify true, then monthly.

## 6) Label printer

Any thermal label printer the browser can print to (Zebra ZD230, Munbyn, Dymo). Set the driver/paper to 40×30 mm (size configurable in Settings), print dialog → that printer, margins 0, scale 100%. The label page auto-opens print. Tip: in Chrome use "Print using system dialog" once, then it remembers.

## 7) POS tile

`pos-extension/` contains the **Repairs** smart-grid tile (booking + deposit/balance into the cart, zero typing). Deploy per `pos-extension/README.md` (Shopify CLI, one login, one command). A later version of the same extension will add "Scan device" IMEI capture at the basket.

## 8) What's intentionally NOT here (yet)

- Supplier-website ordering integration (POs record what staff order manually for now).
- "Scan device" in-basket IMEI capture (Reconcile screen covers it meanwhile).
- CheckMEND API integration (field exists on intake; automate later).
- Cash-tracking (register sessions) pull via `read_cash_tracking` — scope is in the app already; reporting join lands in a later iteration.
- VAT margin-scheme accounting — report GP is operational; your accountant's margin-scheme calc stays authoritative.

## Data model (tables)

`wbam_units` (the IMEI registry) + `wbam_unit_events`, `wbam_orders`/`wbam_order_lines`/`wbam_tenders` (warehouse), `wbam_payouts` (buy-in money out), `wbam_tickets`/`wbam_ticket_events`/`wbam_ticket_parts` (repairs), `wbam_vendors`/`wbam_parts`/`wbam_part_stock`/`wbam_purchase_orders`/`wbam_po_lines` (parts), `wbam_branches`, `wbam_staff_map`, `wbam_sync_state`, `wbam_queue` (retry).
