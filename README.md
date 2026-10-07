# ifthenpay | Payments for Tutor LMS

Adds ifthenpay (Pay by Link) to Tutor LMS's native eCommerce checkout. Orders are confirmed by ifthenpay's server callback and show up in Tutor LMS → Orders.

## Setup

1. Activate the plugin (requires Tutor LMS 3.0+ with eCommerce, store currency EUR).
2. Tutor LMS → Settings → Payment Methods → expand **ifthenpay** → **Connect** with your Backoffice Key.
3. Pick the Gateway Key, enable methods, star a default, set description and expiry (saved as you go).
4. Switch the card on and click **Save Changes**.

## How it fits into Tutor

| Piece | Where |
| --- | --- |
| Gateway registration | `tutor_payment_gateways_with_class` → `Gateway\IfthenpayGateway` (Tutor `GatewayBase`) |
| Payment + callback | `Gateway\IfthenpayPayment` (PaymentHub `BasePayment`) via Tutor's `wp-json/tutor/v1/ecommerce-webhook/ifthenpay` |
| Admin card | `Admin\PaymentCard` (webhook_url field only) + `Admin\SettingsPanel` mounted by `assets/js/admin.js` |
| Settings storage | `Settings\SettingsRepository` (`iftp_tutor_backoffice_key`, `iftp_tutor_config`, not autoloaded) |
| Checkout row | `Checkout\CheckoutRow` + `assets/js/checkout.js` |
| Orders | `Orders\OrderDisplay` (labels, order details method, pending success message) |

## Development

```
composer dump-autoload -o
```

Version 1.0.0, PHP 7.4+. See `readme.txt` for the WordPress.org description and External Services, and `SECURITY.md` for reporting vulnerabilities.
