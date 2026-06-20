=== Ainbae Receipt Upload for WooCommerce ===
Contributors: ainbae
Tags: woocommerce, payment verification, receipt, bank transfer, payment receipt
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Allow customers to upload bank transfer receipts directly from the WooCommerce order details page.

== Description ==
**Ainbae Receipt Upload for WooCommerce** adds a secure upload widget to the customer-facing order detail page. When a customer pays via bank transfer (BACS), they can immediately upload a screenshot or PDF of their payment receipt — no emails, no support tickets, no guesswork.
 
You get a clean, nonce-protected upload flow. Your customer gets instant confirmation. Your admin team gets a one-click "View Uploaded Receipt" button inside the WooCommerce order panel.

= How it works =
1. Customer places an order and selects Bank Transfer (BACS) as payment method.
2. After placing the order, the receipt upload widget appears on the order detail page.
3. Customer uploads a JPG, PNG, or PDF (max 5 MB). The file is stored privately — not in the public media library.
4. An order note is added automatically, and the admin can view the receipt securely from the order panel.
5. Optionally, a WhatsApp button lets customers send their receipt directly to your WhatsApp number.


== Features ==
- Upload receipt from order page
- Supports JPG, PNG, PDF
- Admin can view receipts securely
- WhatsApp integration with customisable message templates
- Require receipt before order placement (optional)
- Improved admin receipt detection with automatic metadata recovery
- Customizable UI

= Who is this for? =
Any WooCommerce store that accepts manual bank transfers and needs a structured, traceable way to collect payment proof from customers. Common use cases include wholesale stores, local businesses, and stores in regions where card payments are less common.

== Installation ==
**Automatic installation (recommended)**
1. In your WordPress admin, go to **Plugins → Add New**.
2. Search for *Ainbae Receipt Upload for WooCommerce*.
3. Click **Install Now**, then **Activate**.
4. Go to **WooCommerce → Upload Receipt** to configure settings.

**Manual installation**
1. Download the plugin zip file.
2. Go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate the plugin.
4. Go to **WooCommerce → Upload Receipt** to configure settings.

**After activation**
* Ensure your store has **Bank Transfer (BACS)** enabled as a payment method under **WooCommerce → Settings → Payments**.
* Enter your WhatsApp number (with country code, digits only) if you want to enable the WhatsApp button.
* Save settings. The upload widget will appear automatically on the order detail page for any pending BACS order.

== Frequently Asked Questions ==
= Does this work with all payment methods? =

No — by design. The upload widget only appears for orders paid via **Bank Transfer (BACS)**. It will not show for card, PayPal, or other payment methods.


= Where are the uploaded files stored? =

Files are stored in `wp-content/uploads/ainbae-receipt-upload-for-woocommerce/`, which is outside the normal media library. A deny-all `.htaccess` file blocks direct browser access on Apache servers.

= Does it work on Nginx? =

The `.htaccess` protection file is Apache-specific and has **no effect on Nginx**. If your server runs Nginx, you must add a location rule to deny access to the `ainbae-receipt-upload-for-woocommerce` directory inside uploads. Add this to your Nginx server block:

`location ~* /uploads/ainbae-receipt-upload-for-woocommerce/ { deny all; }`

Without this rule, uploaded files on Nginx servers may be directly accessible via URL.


= What file types are allowed? =
 
JPG, JPEG, PNG, and PDF. Maximum file size is 5 MB. These limits are enforced on both the client (HTML `accept` attribute) and the server (`wp_handle_upload` MIME validation).

= Can a customer upload more than one receipt? =

No. Once a receipt has been uploaded for an order, the upload form is replaced with a confirmation message and any further upload attempts are blocked.

= Can customers delete their uploaded receipt? =

No. Only an admin with `manage_woocommerce` capability can manage uploaded files.

= How does the admin view the receipt? =

Open any WooCommerce order that has a receipt. A **View Uploaded Receipt** button appears in the order data panel. Clicking it opens the file in a new tab via a nonce-authenticated, admin-only endpoint.

= Is the WhatsApp button required? =

No. You can disable it entirely from the settings page under **WooCommerce → Upload Receipt**.

= What happens if a customer tries to upload a receipt for someone else's order? =

The plugin checks that the logged-in user's ID matches the order's customer ID. For guest orders, it validates the order key from the URL. If neither matches, the upload is rejected with a permission error.

= I activated the plugin but the upload form is not showing. What should I check? =

1. Make sure the order payment method is **Bank Transfer (BACS)**.
2. Make sure the order status is **Pending Payment** or **On Hold** — the form does not appear for completed, cancelled, processing, or refunded orders.
3. Make sure the customer is viewing their own order (logged in, or using a valid order-key link).
4. Check that WooCommerce is active and up to date.


== Screenshots ==
1. The Admin Setting Page with live widget preview and customisation options.
2. The receipt upload widget as seen by the customer on the order received page.
3. The receipt upload widget as seen by the customer on the order detail page.
4. The confirmation message displayed after a successful upload.
5. The confirmation message displayed when order status is changed to processing by admin.
6. The "View Uploaded Receipt" button in the WooCommerce order admin panel.

== Changelog ==

= 2.0.0 =
- New: Custom WhatsApp message template with dynamic variable support ({order_number}, {order_total}, {customer_name}, {billing_email}, {billing_phone}, {site_name}, {currency}, {order_date})
- New: "Require Receipt Before Order Placement" checkout feature — BACS customers must upload receipt before order is created (optional, disabled by default)
- New: Checkout upload modal with drag-and-drop, progress bar, and accessibility support
- Fix: Admin panel now correctly detects receipts using a 5-priority system — prevents false "No receipt uploaded yet" messages
- New: Automatic metadata recovery system — rebuilds broken receipt meta if the physical file still exists
- New: Centralized AINBAE_BACS_VERSION constant for all asset enqueue calls
- New: Debug logging for upload failures, recovery events, and missing metadata (WP_DEBUG_LOG gated)
- Backward compatible — all existing uploads, settings, and workflows continue to work unchanged

= 1.2.0 =
- Fixed Setting Page WhatsApp number field prefix input styling
- Added languages directory and translation files
- Updated tested up to version to 10.8.1 (WooCommerce)
- Updated stable tag to 1.2.0 for latest release

= 1.0.3 =
- Updated tested up to version to 7.0
- Updated stable tag to 1.0.3 for latest release

= 1.0.2 =
- Moved private receipt storage from wp-content root into wp-content/uploads/ainbae-receipt-upload-for-woocommerce/ using wp_upload_dir() per WordPress guidelines
- Replaced WP_CONTENT_DIR constant with wp_upload_dir() for correct path resolution across all WordPress configurations
- Replaced echo of binary file contents with readfile() to stream files without buffering or escaping concerns
- Removed broken donate link from readme.txt
- Updated readme FAQ to reflect new storage path and Nginx configuration instructions

= 1.0.1 =
- Fixed security and sanitization issues
- Improved nonce verification
- Minor code improvements for WordPress standards

= 1.0.0 =
- Initial release


== Upgrade Notice ==

= 2.0.0 =
Major feature release. Adds custom WhatsApp message templates, optional require-receipt-before-order checkout flow (BACS only), and fixes a receipt detection bug in WooCommerce admin. All existing settings, uploads, and orders remain fully compatible.