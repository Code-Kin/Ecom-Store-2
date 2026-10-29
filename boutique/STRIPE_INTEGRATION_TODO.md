# Stripe Checkout integration

## Values to Replace

There are no sample `mode` or `line_items[].price` placeholders in the checkout-session call:

- `mode` is set to `payment` for this store's one-time purchases.
- Line items are built from the current product catalogue using `price_data`; there are no `price_...` placeholders.

No source-code values in this section need replacing before test-mode checkout. Configure the Stripe keys below with your own test keys.

## Configured Parameters

These Checkout Studio parameters are set in [create-checkout-session.php](./create-checkout-session.php):

| Parameter | Value |
|-----------|-------|
| `ui_mode` | `form` |
| `return_url` | `{SITE_URL}/checkout-result.php?session_id={CHECKOUT_SESSION_ID}` |
| `billing_address_collection` | `required` |
| `phone_number_collection[enabled]` | `false` |
| `automatic_tax[enabled]` | `false` |
| `submit_type` | `auto` |
| `shipping_address_collection[allowed_countries][0]` | `ZA` |
| `integration_identifier` | `custom_embedded_web_0001` |
| `Stripe-Version` request header | `2026-03-25.dahlia` |

`mode` remains `payment`, and `line_items` continue to use prices from `product-prices.json`. `payment_method_collection` is omitted because this is a one-time payment flow. Saving a payment method is currently omitted because Stripe requires an attached Customer for that option, and the shop has no customer-account or customer-persistence flow. Guest payments remain available.

## Setup and next steps

### Local setup

1. In Stripe Dashboard, use test mode and copy the publishable (`pk_test_...`) and secret (`sk_test_...`) API keys. Never put the secret key in browser code or commit it.
2. Set these variables for Apache so PHP can read them. For XAMPP, add the following to `C:\xampp\apache\conf\httpd.conf`, substituting your own test keys:

   ```apache
   SetEnv STRIPE_SECRET_KEY "sk_test_REPLACE_WITH_YOUR_KEY"
   SetEnv STRIPE_PUBLISHABLE_KEY "pk_test_REPLACE_WITH_YOUR_KEY"
   SetEnv SITE_URL "http://localhost/boutique"
   ```

3. Restart Apache in the XAMPP Control Panel and load the site from `http://localhost/boutique/`.
4. Ensure the PHP cURL extension is enabled. No PHP Stripe SDK or new package dependency is used; the endpoint calls Stripe's API directly.

The client requests a Checkout Session from [create-checkout-session.php](./create-checkout-session.php). The server validates the cart against [product-prices.json](./product-prices.json), creates the session, and returns its client secret and publishable key. [index.html](./index.html) loads Stripe.js directly from Stripe, while [dynamics.js](./dynamics.js) mounts the embedded Checkout Form and confirms payment.

### Test payments

Keep both keys in test mode while testing. Use Stripe's test card `4242 4242 4242 4242`, any future expiry date, and any three-digit CVC. Test keys do not charge real money. See Stripe's [testing documentation](https://docs.stripe.com/testing).

### Before going live

- Replace the test keys with live-mode keys in the Apache environment and restart Apache. Do not expose or commit the secret key.
- Configure delivery pricing before launch. The current Checkout Session collects a South African shipping address, but does not add a courier fee; the cart total therefore shows the product subtotal only.
- Confirm product names and prices in `product-prices.json`.
- Complete and test order fulfillment, inventory updates, and customer/order tracking. Review [stripe-webhook.php](./stripe-webhook.php) and configure its webhook secrets and Stripe endpoint as appropriate before relying on webhook events.
- If saved payment methods are needed, add a customer-account flow that creates/reuses and persists Stripe Customer IDs before enabling `saved_payment_method_options[payment_method_save]`.
- Test successful, declined, cancelled, and interrupted payment flows in test mode before switching to live mode.

### Project files

No new runtime files or dependencies were added. [STRIPE_INTEGRATION_TODO.md](./STRIPE_INTEGRATION_TODO.md) is the setup checklist; the integration uses the existing PHP endpoint, product catalogue, page, and JavaScript.

Resources: [Stripe Support](https://support.stripe.com) · [Stripe MCP documentation](https://docs.stripe.com/mcp)
