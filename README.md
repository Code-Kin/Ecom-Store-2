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
