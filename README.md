# Sarah's Boutique

A boutique storefront for clothing and accessories made in small batches by South African makers.

## Features

- Product gallery with current prices and images
- Shopping bag with quantity controls
- Embedded Stripe Checkout for one-time card payments
- Password-protected admin for updating products
- Newsletter signup form

## Managing products

The admin page lets you change product prices and images. For an image, enter an HTTPS image URL or upload a JPEG, PNG, or WebP file up to 5 MB. Uploading a file replaces the URL. Add descriptive alt text and select **Save all changes**.

Product data is stored in `product-prices.json` and `product-images.json`. Uploaded images are saved in `uploads/`.

## Hosting and credentials

Stripe and admin credentials are read from `private-config.php`, with environment variables as a fallback for local development. Before deployment, set `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `ADMIN_PASSWORD`, and `SITE_URL` in that file. Keep the file private and upload the accompanying `.htaccess` rule that denies direct requests to it. Do not commit credentials or use keys that have been shared publicly. Set `STRIPE_WEBHOOK_SECRET` only after creating a webhook endpoint in Stripe.

On InfinityFree free hosting, inbound requests from Stripe to webhook endpoints may be blocked by the hosting security system. Do not rely on webhook-based fulfillment there unless you have verified Stripe can reach the endpoint.

## Project files

| File | Purpose |
|---|---|
| `index.html`, `styles.css`, `dynamics.js` | Storefront, cart, and embedded Stripe form |
| `create-checkout-session.php` | Validates the cart and creates the Stripe Checkout Session |
| `checkout-result.php` | Confirms payment status with Stripe after checkout |
| `admin.php` | Password-protected product editor |
| `products.php` | Reads and serves the product catalogue |
| `stripe-webhook.php` | Verifies Stripe webhook signatures and handles supported checkout events |
| `subscribe.php` | Newsletter signup |
| `app-config.php`, `private-config.php` | Reads hosting credentials from the private config file or environment |
