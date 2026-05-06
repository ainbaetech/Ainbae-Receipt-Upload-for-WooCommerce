<p align="center">
  <img src="./admin/images/ainbae-logo.png" width="400px" />
</p>

<h1 align="center">Ainbae Receipt Upload for WooCommerce</h1>

<p align="center">
  Allow customers to upload bank transfer payment receipts directly from the WooCommerce order page — with a modern UI, secure private storage, and admin verification tools.
</p>

<p align="center">
  <a href="https://github.com/ainbaetech/Ainbae-Receipt-Upload-for-WooCommerce/releases/latest">
    <img src="https://img.shields.io/badge/Download-Latest%20Version-blue?style=for-the-badge&logo=wordpress" />
  </a>
  <a href="https://wordpress.org/plugins/ainbae-receipt-upload-for-woocommerce/">
    <img src="https://img.shields.io/badge/WordPress.org-Plugin%20Page-orange?style=for-the-badge&logo=wordpress" />
  </a>
  <img src="https://img.shields.io/badge/WooCommerce-7.1%2B-96588a?style=for-the-badge&logo=woocommerce" />
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=for-the-badge&logo=php" />
  <img src="https://img.shields.io/badge/License-GPLv2-green?style=for-the-badge" />
</p>

---

## 📋 Table of Contents

- [Features](#-features)
- [Screenshots](#️-screenshots)
- [How It Works](#-how-it-works)
- [Requirements](#-requirements)
- [Installation](#️-installation)
- [Frequently Asked Questions](#-frequently-asked-questions)
- [Translation](#-translation)
- [Developer Notes](#-developer-notes)
- [Roadmap](#-roadmap)
- [License](#-license)
- [Author](#-author)

---

## ✨ Features

- 📤 Upload receipt from the **Order Details** page and **Thank You** page
- 🧾 Supports **JPG, PNG, and PDF** formats (max 5 MB)
- 🔒 **Secure private storage** — files stored outside the public media library
- 🛡️ **Rate limiting** — max 5 upload attempts per hour per user
- 🚫 **Duplicate prevention** — one receipt per order, enforced server-side
- ⚙️ Full **admin settings dashboard** with live preview
- 🎨 **Fully customisable UI** — colours, labels, corner radius
- 📱 **WhatsApp integration** — optional deep-link button for manual sharing
- 👨‍💼 **Admin order panel** — view receipts securely with one click
- 🌍 **Translation ready** — i18n support with `.pot` file included
- ✅ **HPOS compatible** — WooCommerce High-Performance Order Storage supported

---

## 🖼️ Screenshots

### 1. Admin Settings Dashboard

![Admin Settings](./Screenshots/screenshot-1.png)

Customise the receipt upload widget appearance and functionality. Set colours, labels, and toggle features with a live preview that updates in real time.

---

### 2. Frontend — Thank You Page

![Frontend Thank You](./Screenshots/screenshot-2.png)

After placing a BACS order, customers see the receipt upload widget on the order received page, prompting them to upload their payment receipt immediately.

---

### 3. Frontend — Order Details Page

![Order Upload](./Screenshots/screenshot-3.png)

Customers can also upload their receipt from the order details page at any time before the order is processed.

---

### 4. After Upload — Confirmation Notice

![Upload Notice](./Screenshots/screenshot-4.png)

A confirmation message is displayed after a successful upload, reassuring customers that their payment verification is in progress.

---

### 5. Payment Verified — Processing Status

![Verified Status](./Screenshots/screenshot-5.png)

When the admin changes the order status to Processing, customers see a confirmation that their payment has been verified.

---

### 6. Admin Order Panel — View Receipt

![Admin Order Panel](./Screenshots/screenshot-6.png)

Admins can view uploaded receipts securely from the WooCommerce order admin panel using the View Uploaded Receipt button, which opens the file in a new authenticated tab.

---

## 💼 How It Works

1. Customer places an order and selects **Bank Transfer (BACS)** as the payment method.
2. The receipt upload widget appears on both the **Thank You page** and the **Order Details page**.
3. Customer uploads a **JPG, PNG, or PDF (max 5 MB)**. The file is stored **privately** outside the public media library, renamed to a UUID so the original filename is never exposed on disk.
4. An order note is added automatically with the upload timestamp.
5. The admin opens the order and clicks **View Uploaded Receipt** to view the file securely — the endpoint is nonce-authenticated and restricted to `manage_woocommerce` capability.
6. Optionally, a **WhatsApp button** lets customers send their receipt directly to your WhatsApp number with a pre-filled order message.

---

## 📑 Requirements

| Requirement | Minimum version                          |
| ----------- | ---------------------------------------- |
| WordPress   | 6.2 or higher                            |
| WooCommerce | 7.1 or higher                            |
| PHP         | 7.4 or higher                            |
| Web server  | Apache or Nginx (see FAQ for Nginx note) |

---

## ⚙️ Installation

### Automatic installation (recommended)

1. Go to **Plugins → Add New** in your WordPress admin.
2. Search for **Ainbae Receipt Upload for WooCommerce**.
3. Click **Install Now** then **Activate**.
4. Go to **WooCommerce → Upload Receipt** to configure settings.

### Manual installation

1. [Download the latest release](https://github.com/ainbaetech/Ainbae-Receipt-Upload-for-WooCommerce/releases/latest) zip file.
2. Go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Click **Activate Plugin**.
4. Go to **WooCommerce → Upload Receipt** to configure settings.

### After activation

- Make sure **Bank Transfer (BACS)** is enabled under **WooCommerce → Settings → Payments**.
- Enter your WhatsApp number (country code + digits only, e.g. `923001234567`) if you want the WhatsApp button.
- Save settings. The upload widget appears automatically on order pages for any pending BACS order.

---

## ❓ Frequently Asked Questions

<details>
  <summary><b>Does this work with all payment methods?</b></summary>
  <p>No — by design. The upload widget only appears for orders paid via <strong>Bank Transfer (BACS)</strong>. It will not show for card, PayPal, or any other payment method.</p>
</details>

<details>
  <summary><b>Where are the uploaded files stored?</b></summary>
  <p>Files are stored in <code>wp-content/bacs-receipts-private/</code>, which is outside the normal media library. A deny-all <code>.htaccess</code> file blocks direct browser access on Apache servers. Files are renamed to a UUID so the original filename is never exposed on disk.</p>
</details>

<details>
  <summary><b>Does it work on Nginx?</b></summary>
  <p>The <code>.htaccess</code> file is Apache-specific and has <strong>no effect on Nginx</strong>. If your server runs Nginx, add this rule to your server block:</p>
  <pre><code>location ~* /bacs-receipts-private/ { deny all; }</code></pre>
  <p>Without this rule, uploaded files on Nginx servers may be directly accessible via URL.</p>
</details>

<details>
  <summary><b>What file types are allowed?</b></summary>
  <p>JPG, JPEG, PNG, and PDF. Maximum file size is 5 MB. These limits are enforced on both the client (HTML <code>accept</code> attribute) and the server (<code>wp_handle_upload</code> MIME validation).</p>
</details>

<details>
  <summary><b>Can a customer upload more than one receipt?</b></summary>
  <p>No. Once a receipt has been uploaded for an order, the upload form is replaced with a confirmation message and any further upload attempts are blocked server-side.</p>
</details>

<details>
  <summary><b>Can customers delete their uploaded receipt?</b></summary>
  <p>No. Only an admin with the <code>manage_woocommerce</code> capability can manage uploaded files.</p>
</details>

<details>
  <summary><b>How does the admin view the receipt?</b></summary>
  <p>Open any WooCommerce order that has a receipt. A <strong>View Uploaded Receipt</strong> button appears in the order data panel. Clicking it opens the file in a new tab via a nonce-authenticated, admin-only endpoint.</p>
</details>

<details>
  <summary><b>Is the WhatsApp button required?</b></summary>
  <p>No. You can disable it entirely from the settings page under <strong>WooCommerce → Upload Receipt</strong>.</p>
</details>

<details>
  <summary><b>What happens if a customer tries to upload a receipt for someone else's order?</b></summary>
  <p>The plugin checks that the logged-in user's ID matches the order's customer ID. For guest orders, it validates the order key from the URL. If neither matches, the upload is rejected with a permission error.</p>
</details>

<details>
  <summary><b>Is this plugin GDPR-friendly?</b></summary>
  <p>Uploaded receipts are stored on your own server and are not transmitted to any third party by this plugin. You are responsible for including receipt data handling in your store's privacy policy. On plugin uninstall, you should manually remove <code>wp-content/bacs-receipts-private/</code> if you wish to purge all uploaded data.</p>
</details>

<details>
  <summary><b>I activated the plugin but the upload form is not showing. What should I check?</b></summary>
  <ol>
    <li>Make sure the order payment method is <strong>Bank Transfer (BACS)</strong>.</li>
    <li>Make sure the order status is <strong>Pending Payment</strong> or <strong>On Hold</strong> — the form does not appear for completed, cancelled, processing, or refunded orders.</li>
    <li>Make sure the customer is viewing their own order (logged in, or using a valid order-key link).</li>
    <li>Check that WooCommerce is active and up to date (7.1 or higher).</li>
  </ol>
</details>

---

## 🌍 Translation

This plugin is fully translation ready. All strings are wrapped in i18n functions with the text domain `ainbae-receipt-upload-for-woocommerce`.

**Included language files:**

```
languages/
└── ainbae-receipt-upload-for-woocommerce.pot   ← translation template
```

**To create a translation:**

1. Open the `.pot` file in [Poedit](https://poedit.net)
2. Choose your language
3. Translate each string
4. Save — Poedit generates both `.po` and `.mo` files automatically
5. Place both files in the `/languages/` folder

**To regenerate the `.pot` file after code changes:**

```bash
wp i18n make-pot . languages/ainbae-receipt-upload-for-woocommerce.pot --allow-root
```

Community translations are also accepted via [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/ainbae-receipt-upload-for-woocommerce/) once the plugin is listed.

---

## 🧑‍💻 Developer Notes

- Follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- All output escaped with `esc_html()`, `esc_attr()`, `esc_url()` — no raw echoes
- All input sanitized with `sanitize_text_field()`, `absint()`, `sanitize_hex_color()` etc.
- Nonce verification on every form submission and admin endpoint
- Capability checks (`manage_woocommerce`) on all admin actions
- File uploads handled via `wp_handle_upload()` with MIME validation
- Private storage directory with `.htaccess` deny-all and `index.php` guard
- UUID-based filenames — original filenames never stored on disk
- Rate limiting via WordPress transients (5 attempts / hour / user)
- Path traversal protection via `realpath()` comparison
- WooCommerce HPOS compatible via `FeaturesUtil::declare_compatibility()`
- Hook-based integration — no core file modifications

---

## 🚀 Roadmap

Features planned for future releases:

- [ ] Email notification to admin on receipt upload
- [ ] Admin approve / reject system with customer notification
- [ ] Automatic order status change after receipt upload
- [ ] Multiple file uploads per order
- [ ] Cloud storage support (Amazon S3, Google Drive)
- [ ] WhatsApp Business API automation
- [ ] Receipt expiry and re-upload window

---

## 📄 License

Licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).

---

## 👨‍💻 Author

**Ainbae**
🌐 [https://www.ainbae.com](https://www.ainbae.com)

---

## ⭐ Support

If this plugin helps your store, please consider:

- Giving it a ⭐ on [GitHub](https://github.com/ainbaetech/Ainbae-Receipt-Upload-for-WooCommerce)
- Leaving a review on [WordPress.org](https://wordpress.org/plugins/ainbae-receipt-upload-for-woocommerce/)
- Reporting bugs via [GitHub Issues](https://github.com/ainbaetech/Ainbae-Receipt-Upload-for-WooCommerce/issues)

---

## 📝 Changelog

### 1.0.2

- Moved private receipt storage from wp-content root into `wp-content/uploads/ainbae-receipt-upload-for-woocommerce/` using `wp_upload_dir()` per WordPress guidelines
- Replaced `WP_CONTENT_DIR` constant with `wp_upload_dir()` for correct path resolution across all WordPress configurations
- Replaced echo of binary file contents with `readfile()` to stream files without buffering or escaping concerns
- Removed broken donate link from readme.txt
- Updated readme FAQ to reflect new storage path and Nginx configuration instructions

### 1.0.1

- Security: replaced `file_put_contents()` with WP Filesystem API
- Security: added explicit `is_uploaded_file()` check before `wp_handle_upload()`
- Security: added `Content-Security-Policy` header when serving receipt files
- Fix: asset version strings now use plugin version constant instead of hardcoded value
- Fix: corrected `WC tested up to` version to reflect currently released WooCommerce
- Improvement: added `Requires PHP` and `WC requires at least` headers
- Improvement: uninstall routine now removes uploaded receipt files and order post-meta
- Improvement: added Settings link to plugin row on the Plugins screen

### 1.0.0

- Initial release
