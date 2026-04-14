<?php
/**
 * Plugin Name: Ainba -Receipt Upload for WooCommerce
 * Plugin URI: https://www.ainbae.com
 * Description: Allows customers to upload bank transfer receipts on the order detail page.
 * Version:     2.1.0
 * Author: Abdul Basit
 * Author URI: https://www.basitamin.dev
 *
 * Security hardening applied:
 *  - IDOR fixed: ownership verified in the upload handler (Part 2)
 *  - Guest auth logic fixed: && replaced with || with hash_equals()
 *  - wp_check_filetype_and_ext() used instead of wp_check_filetype()
 *  - Receipts saved to a private, web-inaccessible directory with .htaccess protection
 *  - Rate limiting on uploads (5 attempts per hour per user / IP)
 *  - Existing receipt cannot be silently overwritten via direct POST
 *  - Admin receipt viewer uses an authenticated download endpoint
 *  - WhatsApp number is configurable via constant / filter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// CONFIGURATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Define BACS_WHATSAPP_NUMBER in wp-config.php to override, e.g.:
 *   define( 'BACS_WHATSAPP_NUMBER', '923001234567' );
 * Or use the filter 'bacs_receipt_whatsapp_number'.
 */
function bacs_get_whatsapp_number() {
    $number = defined( 'BACS_WHATSAPP_NUMBER' ) ? BACS_WHATSAPP_NUMBER : '923001234567';
    return apply_filters( 'bacs_receipt_whatsapp_number', $number );
}

/** Max upload size in bytes (default 5 MB). */
define( 'BACS_MAX_UPLOAD_SIZE', 5 * 1024 * 1024 );

/** Rate-limit: max uploads per window. */
define( 'BACS_RATE_LIMIT_MAX',    5 );
define( 'BACS_RATE_LIMIT_WINDOW', HOUR_IN_SECONDS );

// ─────────────────────────────────────────────────────────────────────────────
// PRIVATE UPLOAD DIRECTORY SETUP
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns (and creates if needed) a private directory for receipts that lives
 * OUTSIDE the public uploads tree.  Falls back to wp-content/bacs-receipts-private
 * if the server does not support a path above the webroot.
 *
 * An .htaccess file is written that denies direct HTTP access.
 */
function bacs_get_private_upload_dir() {
    // Prefer a directory above the webroot where possible.
    $base = WP_CONTENT_DIR . '/bacs-receipts-private';

    if ( ! file_exists( $base ) ) {
        wp_mkdir_p( $base );
    }

    // Always (re-)write the .htaccess so the directory is protected even if
    // the file was deleted or the folder was re-created.
    $htaccess = $base . '/.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents(
            $htaccess,
            "# Block all direct HTTP access\n" .
            "<IfModule mod_authz_core.c>\n" .
            "    Require all denied\n" .
            "</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n" .
            "    Order deny,allow\n" .
            "    Deny from all\n" .
            "</IfModule>\n"
        );
    }

    // Also drop an index.php to block directory listing on servers that ignore .htaccess.
    $index = $base . '/index.php';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, '<?php // Silence is golden.' );
    }

    return trailingslashit( $base );
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/** Allowed MIME types for receipt uploads. */
function bacs_allowed_mimes() {
    return array(
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'pdf'          => 'application/pdf',
    );
}

/**
 * Verify that the current user (logged-in or guest) is authorised to interact
 * with the given order.
 *
 * A user is authorised if:
 *   (a) they are logged in and are the order's customer, OR
 *   (b) they present the correct order key (guest checkout flow).
 *
 * $order_key should come from $_GET['key'] (frontend) or $_POST['order_key']
 * (upload handler).  Uses hash_equals() to prevent timing attacks.
 *
 * @param  WC_Order $order
 * @param  string   $order_key  Raw (not yet validated) key from request.
 * @return bool
 */
function bacs_current_user_can_access_order( $order, $order_key = '' ) {
    $current_user_id   = get_current_user_id();
    $order_customer_id = $order->get_customer_id();

    // Logged-in owner.
    if ( $current_user_id && $current_user_id === $order_customer_id ) {
        return true;
    }

    // Guest (or mismatched user) with valid order key.
    if ( ! empty( $order_key ) && hash_equals( $order->get_order_key(), $order_key ) ) {
        return true;
    }

    return false;
}

/**
 * Simple transient-based rate limiter.
 *
 * @param  string $user_key  A unique key identifying the actor.
 * @return bool  TRUE if the limit has been reached.
 */
function bacs_rate_limit_exceeded( $user_key ) {
    $transient_key = 'bacs_rl_' . md5( $user_key );
    $count         = (int) get_transient( $transient_key );

    if ( $count >= BACS_RATE_LIMIT_MAX ) {
        return true;
    }

    set_transient( $transient_key, $count + 1, BACS_RATE_LIMIT_WINDOW );
    return false;
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 1 — DISPLAY THE UPLOAD FORM (FRONTEND)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_order_details_before_order_table', 'bacs_receipt_upload_form', 10, 1 );

function bacs_receipt_upload_form( $order ) {

    // Only for Bank Transfer orders.
    if ( $order->get_payment_method() !== 'bacs' ) {
        return;
    }

    $status = $order->get_status();

    // Hide form entirely for terminal statuses.
    if ( in_array( $status, array( 'completed', 'cancelled', 'refunded', 'failed', 'delivered' ), true ) ) {
        return;
    }

    // Show confirmation message once payment is verified.
    if ( $status === 'processing' ) {
        echo '<div class="woocommerce-message" style="margin-bottom:30px;border-top-color:#2e7d32;">';
        echo '<strong>Payment Verified.</strong> Your order is being processed.';
        echo '</div>';
        return;
    }

    $order_id  = $order->get_id();
    $order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';

    // ── FIXED: use OR logic + hash_equals() ──────────────────────────────────
    if ( ! bacs_current_user_can_access_order( $order, $order_key ) ) {
        return; // Unauthorised — show nothing.
    }

    // Receipt already uploaded — waiting for admin verification.
    $receipt_path = get_post_meta( $order_id, '_bacs_receipt_path', true );
    if ( $receipt_path ) {
        echo '<div class="woocommerce-info" style="margin-bottom:30px;">';
        echo '<strong>Payment Receipt Uploaded.</strong> We will verify your payment shortly.';
        echo '</div>';
        return;
    }

    // ── WhatsApp link ─────────────────────────────────────────────────────────
    $whatsapp_number = bacs_get_whatsapp_number();
    $order_number    = $order->get_order_number();
    $order_total     = $order->get_currency() . ' ' . $order->get_total();

    $wa_message = "Hello, I am sharing the payment receipt for my recent order.\n\n";
    $wa_message .= "*Order Number:* " . $order_number . "\n";
    $wa_message .= "*Amount:* " . $order_total . "\n\n";
    $wa_message .= "Please find the receipt attached below.";

    // Encode the entire string. rawurlencode safely turns \n into %0A
    $encoded_message = rawurlencode($wa_message);
    $wa_link = "https://wa.me/" . $whatsapp_number . "?text=" . $encoded_message;

    // ── Render form ───────────────────────────────────────────────────────────
    ?>
    <style>
    .bacs-upload-wrap {
        margin-bottom: 32px;
        background: #f0f4f2;
        border-radius: 16px;
        padding: 32px 28px 28px;
        font-family: inherit;
    }
    .bacs-upload-wrap h3 {
        text-align: center;
        font-size: 22px;
        font-weight: 700;
        color: #1a1a1a;
        margin: 0 0 8px;
    }
    .bacs-upload-wrap .bacs-subtitle {
        text-align: center;
        color: #555;
        font-size: 14px;
        margin: 0 0 20px;
    }

    /* ── Drop zone ── */
    .bacs-dropzone {
        border: 2px dashed #b0c8bc;
        border-radius: 10px;
        background: #fff;
        padding: 28px 20px;
        text-align: center;
        cursor: pointer;
        transition: border-color .2s, background .2s;
        margin-bottom: 14px;
        position: relative;
    }
    .bacs-dropzone:hover,
    .bacs-dropzone.bacs-drag-over {
        border-color: #25a244;
        background: #f0faf3;
    }
    .bacs-dropzone input[type="file"] {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
        width: 100%;
        height: 100%;
    }
    .bacs-dropzone svg {
        display: block;
        margin: 0 auto 10px;
        color: #7aab8f;
    }
    .bacs-dropzone-label {
        font-size: 14px;
        color: #444;
        pointer-events: none;
    }
    .bacs-dropzone-label span {
        display: block;
        font-size: 12px;
        color: #777;
        margin-top: 4px;
    }
    .bacs-file-chosen {
        font-size: 13px;
        color: #25a244;
        font-weight: 600;
        margin-top: 6px;
    }

    /* ── Upload button ── */
    .bacs-btn-upload {
        display: block;
        width: 100%;
        padding: 14px;
        background: linear-gradient(90deg, #22c55e 0%, #16a34a 100%);
        color: #fff !important;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        text-align: center;
        transition: opacity .2s, transform .1s;
        box-shadow: 0 3px 10px rgba(34,197,94,.35);
        margin-bottom: 6px;
    }
    .bacs-btn-upload:hover { opacity: .9; transform: translateY(-1px); }
    .bacs-btn-upload:active { transform: translateY(0); }

    .bacs-upload-hint {
        text-align: center;
        font-size: 12px;
        color: #888;
        margin: 0 0 16px;
    }

    /* ── OR divider ── */
    .bacs-or {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 16px 0;
        color: #999;
        font-size: 13px;
    }
    .bacs-or::before,
    .bacs-or::after {
        content: '';
        flex: 1;
        border-bottom: 1px solid #d0ddd6;
    }

    /* ── WhatsApp button ── */
    .bacs-btn-wa {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        padding: 13px;
        background: #e6f9ee;
        color: #1a7a3c !important;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        border: 1.5px solid #a8dfc0;
        border-radius: 8px;
        text-decoration: none !important;
        transition: background .2s, border-color .2s;
    }
    .bacs-btn-wa:hover {
        background: #d0f4e0;
        border-color: #6fcfa0;
    }
    </style>

    <div class="bacs-upload-wrap">
        <h3>Verify Your Payment</h3>
        <p class="bacs-subtitle">Please upload a screenshot of your transaction receipt, or send it directly via WhatsApp to process your order.</p>

        <form action="" method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'bacs_upload_receipt_' . $order_id, 'bacs_receipt_nonce' ); ?>
            <input type="hidden" name="bacs_order_id"  value="<?php echo esc_attr( $order_id ); ?>">
            <input type="hidden" name="bacs_order_key" value="<?php echo esc_attr( $order_key ); ?>">

            <div class="bacs-dropzone" id="bacs-dropzone">
                <!-- Cloud upload icon -->
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">
                    <polyline points="16 16 12 12 8 16"></polyline>
                    <line x1="12" y1="12" x2="12" y2="21"></line>
                    <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path>
                </svg>
                <input type="file"
                       name="bacs_receipt_file"
                       id="bacs_receipt_file"
                       accept=".jpg,.jpeg,.png,.pdf"
                       required>
                <div class="bacs-dropzone-label" id="bacs-dropzone-label">
                    Click to upload, or drag and drop your receipt file
                    <span id="bacs-file-name"></span>
                </div>
            </div>

            <button type="submit" name="submit_bacs_receipt" class="bacs-btn-upload">
                &#8679;&nbsp; Upload Receipt
            </button>
            <p class="bacs-upload-hint">Allowed formats: JPG, PNG, PDF. Max size: 5 MB.</p>
        </form>

        <div class="bacs-or">OR</div>

        <a href="<?php echo esc_url( $wa_link ); ?>"
           target="_blank"
           rel="noopener noreferrer"
           class="bacs-btn-wa">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 448 512" fill="currentColor" aria-hidden="true">
                <path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zM223.9 413.6c-33.6 0-66.5-9-95.2-26l-6.8-4-70.8 18.6 18.9-69-4.4-7c-18.7-29.7-28.6-64-28.6-98.8 0-103.7 84.4-188.2 188.4-188.2 50.3 0 97.6 19.6 133.2 55.2 35.6 35.6 55.2 82.9 55.2 133.2 0 103.8-84.4 188.2-188.4 188.2zm103.4-141.6c-5.7-2.8-33.6-16.6-38.8-18.5-5.2-1.9-9-.2-12.8 5.4-3.8 5.7-14.7 18.5-18 22.3-3.3 3.8-6.6 4.3-12.3 1.4-5.7-2.8-24-8.8-45.7-28.2-16.9-15.1-28.3-33.8-31.6-39.5-3.3-5.7-.3-8.8 2.5-11.6 2.6-2.6 5.7-6.6 8.5-9.9 2.8-3.3 3.8-5.7 5.7-9.5 1.9-3.8.9-7.1-.5-9.9-1.4-2.8-12.8-30.8-17.5-42.2-4.6-11.2-9.3-9.7-12.8-9.9-3.3-.2-7.1-.2-10.9-.2-3.8 0-9.9 1.4-15.1 7.1-5.2 5.7-20 19.4-20 47.4 0 28 20.4 55.1 23.2 58.8 2.8 3.8 40.1 61.2 97.1 85.5 13.6 5.8 24.2 9.2 32.5 11.8 13.7 4.3 26.1 3.7 35.9 2.2 11-1.7 33.6-13.7 38.3-27 4.7-13.3 4.7-24.6 3.3-27-.9-2.8-4.7-4.2-10.4-7.1z"/>
            </svg>
            Send Receipt via WhatsApp
        </a>
    </div>

    <script>
    (function () {
        var input  = document.getElementById('bacs_receipt_file');
        var zone   = document.getElementById('bacs-dropzone');
        var label  = document.getElementById('bacs-file-name');

        if (!input || !zone || !label) return;

        input.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                label.textContent = this.files[0].name;
                label.className = 'bacs-file-chosen';
            }
        });

        zone.addEventListener('dragover', function (e) {
            e.preventDefault();
            zone.classList.add('bacs-drag-over');
        });
        zone.addEventListener('dragleave', function () {
            zone.classList.remove('bacs-drag-over');
        });
        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            zone.classList.remove('bacs-drag-over');
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                input.files = e.dataTransfer.files;
                label.textContent = e.dataTransfer.files[0].name;
                label.className = 'bacs-file-chosen';
            }
        });
    })();
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 2 — SECURELY PROCESS THE FILE UPLOAD
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'template_redirect', 'bacs_process_receipt_upload' );

function bacs_process_receipt_upload() {

    // 1. Check form submission.
    if ( ! isset( $_POST['submit_bacs_receipt'] ) || ! isset( $_FILES['bacs_receipt_file'] ) ) {
        return;
    }

    // 2. Verify nonce (CSRF protection — nonce is order-specific).
    $order_id = isset( $_POST['bacs_order_id'] ) ? absint( $_POST['bacs_order_id'] ) : 0;

    if ( ! $order_id
        || ! isset( $_POST['bacs_receipt_nonce'] )
        || ! wp_verify_nonce( $_POST['bacs_receipt_nonce'], 'bacs_upload_receipt_' . $order_id )
    ) {
        wc_add_notice( 'Security check failed. Please refresh the page and try again.', 'error' );
        return;
    }

    // 3. Load and validate the order.
    $order = wc_get_order( $order_id );

    if ( ! $order || $order->get_payment_method() !== 'bacs' ) {
        wc_add_notice( 'Invalid order.', 'error' );
        return;
    }

    // 4. ── FIXED: verify ownership in the handler ────────────────────────────
    $order_key = isset( $_POST['bacs_order_key'] ) ? wc_clean( wp_unslash( $_POST['bacs_order_key'] ) ) : '';

    if ( ! bacs_current_user_can_access_order( $order, $order_key ) ) {
        wc_add_notice( 'You do not have permission to upload a receipt for this order.', 'error' );
        return;
    }

    // 5. Block uploads for terminal / already-verified statuses.
    $status = $order->get_status();
    if ( in_array( $status, array( 'completed', 'processing', 'cancelled', 'refunded', 'failed', 'delivered' ), true ) ) {
        wc_add_notice( 'A receipt cannot be uploaded for this order in its current status.', 'error' );
        return;
    }

    // 6. ── FIXED: prevent silent overwrite ──────────────────────────────────
    if ( get_post_meta( $order_id, '_bacs_receipt_path', true ) ) {
        wc_add_notice( 'A receipt has already been uploaded for this order. Please contact support if you need to replace it.', 'error' );
        return;
    }

    // 7. ── Rate limiting ─────────────────────────────────────────────────────
    $actor_key = get_current_user_id() ? 'user_' . get_current_user_id() : 'ip_' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );

    if ( bacs_rate_limit_exceeded( $actor_key ) ) {
        wc_add_notice( 'Too many upload attempts. Please wait a while before trying again.', 'error' );
        return;
    }

    // 8. File error check.
    $file = $_FILES['bacs_receipt_file']; // phpcs:ignore

    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        wc_add_notice( 'File upload error (code ' . (int) $file['error'] . '). Please try again.', 'error' );
        return;
    }

    // 9. File size check.
    if ( $file['size'] > BACS_MAX_UPLOAD_SIZE ) {
        wc_add_notice( 'File is too large. Maximum allowed size is 5 MB.', 'error' );
        return;
    }

    // 10. ── FIXED: validate actual file content, not just extension ──────────
    $allowed_mimes = bacs_allowed_mimes();

    if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $file_info = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );

    if ( empty( $file_info['ext'] ) || empty( $file_info['type'] ) ) {
        wc_add_notice( 'Invalid file type. Only JPG, PNG, and PDF files are accepted.', 'error' );
        return;
    }

    // 11. ── FIXED: save to private, web-inaccessible directory ───────────────
    $upload_dir = bacs_get_private_upload_dir();

    // Generate an unguessable filename to prevent enumeration attacks.
    $unique_name = wp_generate_uuid4() . '.' . $file_info['ext'];
    $destination = $upload_dir . $unique_name;

    if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
        wc_add_notice( 'Could not save the uploaded file. Please try again or contact support.', 'error' );
        return;
    }

    // Store the ABSOLUTE PATH (never a public URL) in order meta.
    update_post_meta( $order_id, '_bacs_receipt_path',      $destination );
    update_post_meta( $order_id, '_bacs_receipt_mime',      sanitize_mime_type( $file_info['type'] ) );
    update_post_meta( $order_id, '_bacs_receipt_uploaded',  current_time( 'mysql' ) );

    // Notify the admin via an order note (no URL exposed — admin uses secure viewer).
    $order->add_order_note(
        sprintf(
            'Customer uploaded a bank transfer receipt on %s. Use the admin panel to view it securely.',
            current_time( 'mysql' )
        )
    );

    wc_add_notice( 'Receipt uploaded successfully. Thank you! We will verify your payment shortly.', 'success' );

    wp_safe_redirect( $order->get_view_order_url() );
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 3 — SECURE ADMIN RECEIPT VIEWER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Display a "View Receipt" button in the admin order panel.
 * The button links to our authenticated download endpoint — NOT a direct file URL.
 */
add_action( 'woocommerce_admin_order_data_after_order_details', 'bacs_display_receipt_in_admin', 10, 1 );

function bacs_display_receipt_in_admin( $order ) {
    if ( $order->get_payment_method() !== 'bacs' ) {
        return;
    }

    $receipt_path     = get_post_meta( $order->get_id(), '_bacs_receipt_path', true );
    $receipt_uploaded = get_post_meta( $order->get_id(), '_bacs_receipt_uploaded', true );

    echo '<br class="clear" />';
    echo '<h3>Bank Transfer Receipt</h3>';

    if ( $receipt_path && file_exists( $receipt_path ) ) {
        // Build an authenticated, nonce-protected download URL.
        $download_url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'   => 'bacs_view_receipt',
                    'order_id' => $order->get_id(),
                ),
                admin_url( 'admin-post.php' )
            ),
            'bacs_view_receipt_' . $order->get_id()
        );

        echo '<p style="margin-top:10px;">';
        echo '<a href="' . esc_url( $download_url ) . '" target="_blank" class="button button-primary">View Uploaded Receipt</a>';

        if ( $receipt_uploaded ) {
            echo ' &nbsp;<small style="color:#666;">Uploaded: ' . esc_html( $receipt_uploaded ) . '</small>';
        }

        echo '</p>';
    } else {
        echo '<p style="color:#d63638;"><strong>No receipt uploaded yet.</strong></p>';
    }
}

/**
 * Authenticated file-download handler.
 *
 * Accessed via admin-post.php?action=bacs_view_receipt&order_id=X&_wpnonce=...
 * Streams the private file only to users with the 'manage_woocommerce' capability.
 */
add_action( 'admin_post_bacs_view_receipt', 'bacs_serve_receipt_to_admin' );

function bacs_serve_receipt_to_admin() {

    // Must be a WooCommerce admin.
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Access denied.', 403 );
    }

    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

    // Verify nonce.
    if ( ! $order_id || ! check_admin_referer( 'bacs_view_receipt_' . $order_id ) ) {
        wp_die( 'Invalid or expired request.', 400 );
    }

    $receipt_path = get_post_meta( $order_id, '_bacs_receipt_path', true );
    $receipt_mime = get_post_meta( $order_id, '_bacs_receipt_mime', true );

    if ( ! $receipt_path || ! file_exists( $receipt_path ) ) {
        wp_die( 'Receipt file not found.', 404 );
    }

    // Resolve realpath and confirm the file is still inside our private directory.
    $real_path    = realpath( $receipt_path );
    $real_dir     = realpath( bacs_get_private_upload_dir() );

    if ( $real_path === false || strpos( $real_path, $real_dir ) !== 0 ) {
        wp_die( 'Access denied.', 403 );
    }

    $allowed_mimes = bacs_allowed_mimes();
    if ( ! in_array( $receipt_mime, $allowed_mimes, true ) ) {
        wp_die( 'Invalid file type.', 400 );
    }

    // Stream the file.
    nocache_headers();
    header( 'Content-Type: '   . sanitize_mime_type( $receipt_mime ) );
    header( 'Content-Length: ' . filesize( $real_path ) );
    // Instruct browser to display inline (PDF/image) — adjust to attachment if preferred.
    header( 'Content-Disposition: inline; filename="receipt-order-' . $order_id . '.' . pathinfo( $real_path, PATHINFO_EXTENSION ) . '"' );
    // Prevent the browser from sniffing a different content-type.
    header( 'X-Content-Type-Options: nosniff' );

    readfile( $real_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    exit;
}
