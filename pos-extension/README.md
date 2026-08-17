# WBAM Repairs — POS tile

Adds a **Repairs** tile to the Shopify POS smart grid:

- **New repair booking** — staff fill name/phone/device/fault; the Hub creates the ticket and texts/emails the customer. Optional deposit drops straight into the POS cart.
- **Take deposit / balance** — search open tickets (number, name or phone), tap one, amount pre-filled from the quote, one tap adds the correctly-titled line to the cart. Staff never type ticket codes; the server composes the line title.

Payments ring through the normal POS basket, so they land in cash/card/Trade-In reports like any sale, and the webhook links them to the ticket automatically.

## Deploy (one-time, ~10 minutes, from any machine with Node 18+)

```bash
npm install -g @shopify/cli@latest
cd pos-extension
npm install
npx shopify app deploy
```

- First run opens a browser to log in — use the account that owns the WBAM store org (saif@staffasia.org).
- When asked which app to use: **connect to an existing app** → pick **WBAM Hub** (the org's dev-dashboard app; `shopify.app.toml` already carries its client_id).
- `deploy` creates a new app version including this extension → confirm release.

Then on each POS device: **≡ → Smart grid → Add tile → WBAM Repairs** (POS may need a restart to see it).

## Requirements

- The WBAM Hub app installed on the store (done at app creation).
- The Hub live at `https://system.webuyanymobile.com` — the tile calls `/wp-json/wbam/v1/pos/*`, authenticated with Shopify session tokens (verified server-side with the app's client secret; no extra keys to manage).

## Notes

- Component/prop names track `@shopify/ui-extensions-react/point-of-sale`; if a future CLI version renames a prop, `shopify app dev` will point at the exact line.
- Until the tile is deployed, the no-typing fallback is: create the deposit from the ticket screen in the Hub → it appears in POS under **Draft orders** → open → charge.
