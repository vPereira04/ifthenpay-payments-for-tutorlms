=== ifthenpay | Payments for Tutor LMS ===
Contributors: ifthenpay
Tags: tutor lms, payments, multibanco, mb way, ifthenpay
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: tutor
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds ifthenpay to Tutor LMS's native checkout: cards, Apple Pay, Google Pay, Multibanco, MB WAY and more, through a secure pay-by-link page.

== Description ==

This plugin adds ifthenpay as a payment method in Tutor LMS's own eCommerce checkout. When a student buys a course, they're sent to ifthenpay's secure hosted payment page to pay with any method enabled on your Gateway Key. ifthenpay then calls your site back, and Tutor LMS marks the order as paid and enrols the student. No card or bank details ever touch your site.

In plain terms you get:

* ifthenpay next to PayPal in Tutor LMS → Settings → Payment Methods
* A Connect button for your Backoffice Key, your Gateway Keys and a live payment methods table, all inside the ifthenpay card
* Automatic, callback-confirmed order status in Tutor LMS → Orders, with the method used and ifthenpay's request ID
* Offline methods (Multibanco, Payshop) supported: the order is confirmed when the reference is paid

== Key Features ==

1. Native Tutor LMS payment gateway (one-time course and bundle purchases)
2. Payment methods synced live from your ifthenpay account, with "Request Activation" for methods not yet enabled
3. Default payment method pre-selected on the payment page
4. Configurable payment description and payment link expiry
5. Callback URL registered with ifthenpay automatically, with an anti-phishing key and amount check
6. Payment status, method and request ID shown in Tutor LMS's own order details and timeline
7. Multi-language payment page (EN, ES, FR, PT)

== Requirements ==

* An active ifthenpay merchant account — [subscribe here](https://ifthenpay.com/aderir/) to obtain your credentials.
* A Tutor LMS Gateway Key (request it from ifthenpay support).
* Tutor LMS 3.0 or newer, with eCommerce (Monetization by Tutor) and the store currency set to EUR.
* WordPress 6.4+ and PHP 7.4+.
* HTTPS (SSL) enabled on your site.

== Installation ==

1. Install and activate the plugin.
2. Go to `Tutor LMS → Settings → Payment Methods` and expand the **ifthenpay** card.
3. Click **Connect** and enter your Backoffice Key.
4. Pick your Gateway Key, enable the methods you want, star a default method if you like, and set the description and expiry days. These save as you go.
5. Switch the ifthenpay card on and click **Save Changes**.

ifthenpay only shows at checkout once a Gateway Key and at least one payment method are enabled, and the store currency is EUR.

== Frequently Asked Questions ==

= Does it support subscriptions or memberships? =

No. This version supports one-time purchases only, so ifthenpay isn't offered on subscription plan checkouts.

= Which currencies are supported? =

Euro (EUR) only. With another store currency, ifthenpay is hidden at checkout.

= Which payment methods are supported? =

Any ifthenpay method attached to your Gateway Key (e.g. Multibanco, MB WAY, Payshop, Credit Card, Apple Pay, Google Pay, Pix).

= How is a payment confirmed? =

ifthenpay calls your site back when the payment is made. The plugin checks the anti-phishing key and the amount, then Tutor LMS completes the order and enrols the student. Returning from the payment page never marks an order paid by itself.

= What happens with a Multibanco or Payshop reference? =

The order stays unpaid until the reference is paid; the student sees a "waiting for confirmation" message. Once ifthenpay confirms, the order completes automatically.

= Are payment details stored? =

No card or bank details are stored. The order keeps the payment method and ifthenpay's request ID.

== External Services ==

This plugin connects to the ifthenpay payment platform (https://ifthenpay.com), a third-party service, to create payment links and receive payment confirmations.

**ifthenpay API (api.ifthenpay.com)**

* What it is and what it is used for: ifthenpay's API, used to list your Gateway Keys and payment methods, create payment links, and register this site's callback URL.
* What data is sent and when:
    * When you connect or open the settings: your Backoffice Key (to list your Tutor LMS Gateway Keys) and a request for the public payment methods catalog.
    * When you choose a Gateway Key: the Gateway Key, a generated anti-phishing key and this site's callback URL, so ifthenpay can confirm payments.
    * At checkout: the Tutor LMS order number, amount, payment description, enabled payment method accounts, the default method, the link expiry date, the page language and the success/cancel return URLs. No customer name, email or address is sent.
    * During callbacks (ifthenpay → your site): order number, anti-phishing key, amount, payment method and request ID.
* Terms of Service / EULA: [https://ifthenpay.com/eula/](https://ifthenpay.com/eula/)
* Privacy Policy: [https://ifthenpay.com/politica-de-privacidade/](https://ifthenpay.com/politica-de-privacidade/)

**Payment method activation requests (email)**

* What it is and what it is used for: when you click "Request Activation" for a method that isn't enabled yet, an email is sent to ifthenpay support so they can enable it.
* What data is sent and when: only when you click the button — your Backoffice Key, Gateway Key, the requested method, the site admin email, site URL and name, and your WordPress, Tutor LMS and plugin versions.

All requests are made server-side over HTTPS. The Backoffice Key is stored in your database and never shown again in the admin screens.

== Screenshots ==

1. The ifthenpay card in Tutor LMS → Settings → Payment Methods
2. Connect modal
3. Gateway Key and payment methods table
4. The ifthenpay row at checkout
5. ifthenpay's secure payment page
6. A confirmed order in Tutor LMS → Orders

== Changelog ==

Releases that fix a security issue say so in their entry, prefixed with **Security:**.

= 1.0.0 =
* Initial release: ifthenpay Pay by Link gateway for Tutor LMS's native checkout, callback-confirmed orders, live payment methods table with activation requests.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Security ==

Please report security issues privately to suporte@ifthenpay.com with "Security" in the subject line, not in the public support forum. See SECURITY.md in the plugin folder for what to include and what happens next.

== Support ==

Commercial helpdesk: https://helpdesk.ifthenpay.com/

* ifthenpay support: suporte@ifthenpay.com
* Tutor LMS docs: https://docs.themeum.com/tutor-lms/
