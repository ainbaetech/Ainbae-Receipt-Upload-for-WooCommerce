<p align="center" style="margin-bottom: 20px; background: #f9f9f9; padding: 5px;">
  <img src="./admin/images/ainbae-logo.png" width="400px" />
</p>

<h1 align="center">Ainbae Receipt Upload for WooCommerce</h1>

<p align="center">Allow customers to upload bank transfer payment receipts directly from the WooCommerce order page — with a modern UI, secure storage, and admin verification tools.
</p>

---

<p align="center" style="margin-top: 20px;">
  <a href="https://github.com/ainbaetech/Ainbae-Receipt-Upload-for-WooCommerce/releases/latest">
    <img src="https://img.shields.io/badge/Download-Latest%20Version-blue?style=for-the-badge&logo=wordpress" />
  </a>
  <!-- <a href="https://wordpress.org/plugins/ainbae-receipt-upload-for-woocommerce">
    <img src="https://img.shields.io/badge/WordPress-Plugin-orange?style=for-the-badge&logo=wordpress" />
  </a> -->
</p>

## ✨ Features

- 📤 Upload receipt from **Order Details page**
- 🧾 Supports **JPG, PNG, PDF**
- 🔒 Secure private file storage (protected directory)
- ⚙️ Full **admin settings dashboard**
- 🎨 Customizable UI (colors, labels, layout)
- 📱 WhatsApp integration for manual sharing
- 🚫 Rate limiting for security
- 👨‍💼 Admin panel to view uploaded receipts
- 🌍 Translation-ready (i18n support)

---

## 🖼️ Screenshots

### 🔧 Admin Settings Dashboard

![Admin Settings](./Screenshots/01_admin-dashboard.png)

---

### 👁️ Live Preview Panel

![Live Preview](./Screenshots/02_live-preview.png)

---

### 💳 Frontend – Thank You Page

![Frontend Thank You](./Screenshots/03_frontend-thankyou.png)

---

### 📂 Order Details – Upload Section

![Order Upload](./Screenshots/04_order-details-upload-section.png)

---

### 📩 After Upload Notice

![Upload Notice](./Screenshots/05_after-upload-notice.png)

---

### ✅ Payment Verified Status

![Verified Status](./Screenshots/06_payment-verified-status.png)

---

### 🛠️ Admin Order Panel

![Admin Order Panel](./Screenshots/07_admin-order-panel.png)

---

## ⚙️ Installation

1. Upload the plugin folder to:
   ```
   /wp-content/plugins/
   ```
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to:
   ```
   WooCommerce > Receipt Upload
   ```
4. Configure settings as needed

---

## 🔐 Security Features

- Nonce verification for uploads
- File type validation (MIME + extension)
- Private directory with `.htaccess` protection
- Rate limiting to prevent abuse
- Permission checks for order access

---

## 🧠 Use Cases

Perfect for stores using:

- Bank Transfer (BACS)
- Manual payments
- Cash deposits
- WhatsApp-based order confirmations

---

## 🌍 Translation

This plugin is fully translation-ready.

Language files:

- `ainbae-receipt-upload.pot` (template)

You can generate `.po` / `.mo` files for any language.

---

## 🧑‍💻 Developer Notes

- Built following WordPress coding standards
- Uses `esc_*` functions for safe output
- Modular structure for easy extension
- WooCommerce hooks-based integration

---

## 💼 Roadmap (Pro Version Ideas)

- Email notification on receipt upload
- Admin approve/reject system
- Auto order status change
- Multiple file uploads
- Cloud storage (S3 / Drive)
- WhatsApp API automation

---

## 📄 License

Licensed under GPL v2 or later.

---

## 👨‍💻 Author

**Ainbae**  
🌐 https://www.ainbae.com

---

## ⭐ Support

If you like this plugin, consider giving it a ⭐ on GitHub!
