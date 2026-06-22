<?php

/**
 * Plugin Name: Ainbae Receipt Upload for WooCommerce
 * Description: Allows customers to upload bank transfer receipts on the order detail page.
 * Version: 2.1.0
 * Author: Ainbae
 * Author URI: https://www.ainbae.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ainbae-receipt-upload-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.1
 * WC tested up to: 10.8.1
 * Requires Plugins: woocommerce
 */

if (! defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
add_action('plugins_loaded', function () {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>'
                . esc_html__('Ainbae Receipt Upload requires WooCommerce to be installed and active.', 'ainbae-receipt-upload-for-woocommerce')
                . '</p></div>';
        });
        return;
    }
});
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────────────────────────────────────

define('AINBAE_BACS_VERSION',       '2.1.0');
define('AINBAE_BACS_MAX_UPLOAD_SIZE',   5 * 1024 * 1024);
define('AINBAE_BACS_RATE_LIMIT_MAX',    5);
define('AINBAE_BACS_RATE_LIMIT_WINDOW', HOUR_IN_SECONDS);
define('AINBAE_BACS_OPTION_KEY',        'ainbae_bacs_receipt_settings');

// ─────────────────────────────────────────────────────────────────────────────
// SETTINGS HELPERS
// ─────────────────────────────────────────────────────────────────────────────

function ainbae_bacs_default_settings()
{
    return array(
        'whatsapp_enabled'              => '1',
        'whatsapp_number'               => '1234567890',
        'whatsapp_message_template'     => '',
        'require_receipt_before_order'  => '0',
        'color_card_bg'                 => '#f0f4f2',
        'color_card_border'             => '#d6e4dc',
        'color_dropzone_bg'             => '#ffffff',
        'color_dropzone_border'         => '#b0c8bc',
        'color_icon'                    => '#0aa7ff',
        'color_upload_btn_from'         => '#0aa7ff',
        'color_upload_btn_to'           => '#0aa7ff',
        'color_upload_btn_text'         => '#ffffff',
        'color_wa_btn_bg'               => '#e6f9ee',
        'color_wa_btn_border'           => '#a8dfc0',
        'color_wa_btn_text'             => '#1a7a3c',
        'color_heading'                 => '#1a1a1a',
        'color_subtitle'                => '#555555',
        'color_hint'                    => '#888888',
        'color_or_line'                 => '#d0ddd6',
        'color_or_text'                 => '#999999',
        'label_heading'                 => __('Verify Your Payment', 'ainbae-receipt-upload-for-woocommerce'),
        'label_subtitle'                => __('Please upload a screenshot of your transaction receipt, or send it directly via WhatsApp to process your order.', 'ainbae-receipt-upload-for-woocommerce'),
        'label_dropzone'                => __('Click to upload, or drag and drop your receipt file', 'ainbae-receipt-upload-for-woocommerce'),
        'label_upload_btn'              => __('Upload Receipt', 'ainbae-receipt-upload-for-woocommerce'),
        'label_wa_btn'                  => __('Send Receipt via WhatsApp', 'ainbae-receipt-upload-for-woocommerce'),
        'label_hint'                    => __('Allowed formats: JPG, PNG, PDF. Max size: 5 MB.', 'ainbae-receipt-upload-for-woocommerce'),
        'card_border_radius'            => '16',
    );
}

function ainbae_bacs_setting($key)
{
    $defaults = ainbae_bacs_default_settings();
    $saved    = get_option(AINBAE_BACS_OPTION_KEY, array());
    $all      = array_merge($defaults, (array) $saved);
    return $all[$key] ?? ($defaults[$key] ?? '');
}

function ainbae_bacs_get_whatsapp_number()
{
    $number = defined('BACS_WHATSAPP_NUMBER') ? BACS_WHATSAPP_NUMBER : ainbae_bacs_setting('whatsapp_number');
    return apply_filters('ainbae_bacs_receipt_whatsapp_number', $number);
}

function ainbae_bacs_is_bacs_enabled()
{
    if (! class_exists('WC_Payment_Gateways')) {
        return false;
    }
    $gateways = WC_Payment_Gateways::instance()->payment_gateways();
    return isset($gateways['bacs']) && 'yes' === $gateways['bacs']->enabled;
}


// ─────────────────────────────────────────────────────────────────────────────
// ADMIN — MENU & SAVE
// ─────────────────────────────────────────────────────────────────────────────

add_action('admin_menu', 'ainbae_bacs_register_menu');
function ainbae_bacs_register_menu()
{
    add_submenu_page('woocommerce', __('Ainbae Receipt Upload Settings', 'ainbae-receipt-upload-for-woocommerce'), __('Upload Receipt', 'ainbae-receipt-upload-for-woocommerce'), 'manage_woocommerce', 'ainbae-receipt-settings', 'ainbae_bacs_render_settings_page');
}

add_action('admin_init', 'ainbae_bacs_save_settings');
function ainbae_bacs_save_settings()
{
    if (
        ! isset($_POST['ainbae_bacs_settings_nonce']) ||
        ! wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['ainbae_bacs_settings_nonce'])),
            'ainbae_bacs_save_settings_action'
        ) ||
        ! current_user_can('manage_woocommerce') ||
        ! isset($_POST['ainbae_bacs_save_settings'])
    ) {
        return;
    }

    $defaults  = ainbae_bacs_default_settings();
    $sanitized = array();

    $colour_keys = array('color_card_bg', 'color_card_border', 'color_dropzone_bg', 'color_dropzone_border', 'color_icon', 'color_upload_btn_from', 'color_upload_btn_to', 'color_upload_btn_text', 'color_wa_btn_bg', 'color_wa_btn_border', 'color_wa_btn_text', 'color_heading', 'color_subtitle', 'color_hint', 'color_or_line', 'color_or_text');
    foreach ($colour_keys as $key) {
        $val = isset($_POST[$key]) ? sanitize_hex_color(wp_unslash($_POST[$key])) : '';
        $sanitized[$key] = $val ?: $defaults[$key];
    }

    foreach (array('label_heading', 'label_subtitle', 'label_dropzone', 'label_upload_btn', 'label_wa_btn', 'label_hint') as $key) {
        $sanitized[$key] = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $defaults[$key];
    }

    $sanitized['whatsapp_enabled'] = isset($_POST['whatsapp_enabled']) ? '1' : '0';
    $number = isset($_POST['whatsapp_number'])
        ? sanitize_text_field(wp_unslash($_POST['whatsapp_number']))
        : '';

    $sanitized['whatsapp_number'] = $number
        ? preg_replace('/[^0-9]/', '', $number)
        : $defaults['whatsapp_number'];

    // WhatsApp message template (v2.0.0)
    $sanitized['whatsapp_message_template'] = isset($_POST['whatsapp_message_template'])
        ? sanitize_textarea_field(wp_unslash($_POST['whatsapp_message_template']))
        : '';

    // Checkout behaviour (v2.0.0)
    $sanitized['require_receipt_before_order'] = isset($_POST['require_receipt_before_order']) ? '1' : '0';

    $sanitized['card_border_radius'] = isset($_POST['card_border_radius']) ? absint(wp_unslash($_POST['card_border_radius'])) : $defaults['card_border_radius'];

    update_option(AINBAE_BACS_OPTION_KEY, $sanitized);
    wp_safe_redirect(add_query_arg(
        array(
            'page'     => 'ainbae-receipt-settings',
            'updated'  => '1',
            '_wpnonce' => wp_create_nonce('ainbae_bacs_settings_updated'),
        ),
        admin_url('admin.php')
    ));
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ENQUEUE ASSETS
// ─────────────────────────────────────────────────────────────────────────────

add_action('admin_enqueue_scripts', 'ainbae_bacs_enqueue_admin_assets');
function ainbae_bacs_enqueue_admin_assets($hook)
{
    if (! current_user_can('manage_woocommerce')) {
        return;
    }

    if ('woocommerce_page_ainbae-receipt-settings' !== $hook) {
        return;
    }

    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_enqueue_style('ainbae-bacs-admin-css', plugins_url('admin/css/admin.css', __FILE__), array(), AINBAE_BACS_VERSION);
    wp_enqueue_script('ainbae-bacs-admin-js', plugins_url('admin/js/admin.js', __FILE__), array('jquery', 'wp-color-picker'), AINBAE_BACS_VERSION, true);
}

add_action('wp_enqueue_scripts', 'ainbae_bacs_enqueue_public_assets');
function ainbae_bacs_enqueue_public_assets()
{
    $on_order_page    = function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('view-order') || is_wc_endpoint_url('order-received'));
    $on_checkout_page = function_exists('is_checkout') && is_checkout();
    $checkout_feature = ainbae_bacs_setting('require_receipt_before_order') === '1';

    if ($on_order_page || ($on_checkout_page && $checkout_feature)) {
        wp_enqueue_style('ainbae-bacs-public-css', plugins_url('public/css/public.css', __FILE__), array(), AINBAE_BACS_VERSION);
        wp_enqueue_script('ainbae-bacs-public-js', plugins_url('public/js/public.js', __FILE__), array('jquery'), AINBAE_BACS_VERSION, true);

        if ($on_checkout_page && $checkout_feature) {
            wp_localize_script('ainbae-bacs-public-js', 'ainbaeBacsCheckout', array(
                'enabled'    => true,
                'ajax_url'   => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('ainbae_bacs_checkout_upload'),
                'heading'    => ainbae_bacs_setting('label_heading'),
                'subtitle'   => __('Please upload your payment receipt to complete your order.', 'ainbae-receipt-upload-for-woocommerce'),
                'dropzone'   => ainbae_bacs_setting('label_dropzone'),
                'upload_btn' => ainbae_bacs_setting('label_upload_btn'),
                'hint'       => ainbae_bacs_setting('label_hint'),
                'uploading'  => __('Uploading…', 'ainbae-receipt-upload-for-woocommerce'),
                'success'    => __('Receipt uploaded. Placing your order…', 'ainbae-receipt-upload-for-woocommerce'),
                'err_size'   => __('File is too large. Maximum size is 5 MB.', 'ainbae-receipt-upload-for-woocommerce'),
                'err_type'   => __('Invalid file type. Only JPG, PNG, and PDF are accepted.', 'ainbae-receipt-upload-for-woocommerce'),
                'err_upload' => __('Upload failed. Please try again.', 'ainbae-receipt-upload-for-woocommerce'),
                'err_required' => __('Please upload a payment receipt before placing your order.', 'ainbae-receipt-upload-for-woocommerce'),
                // Dynamic CSS vars passed for modal theming
                'colors'     => array(
                    'card_bg'         => ainbae_bacs_setting('color_card_bg'),
                    'card_border'     => ainbae_bacs_setting('color_card_border'),
                    'card_radius'     => absint(ainbae_bacs_setting('card_border_radius')),
                    'heading'         => ainbae_bacs_setting('color_heading'),
                    'subtitle'        => ainbae_bacs_setting('color_subtitle'),
                    'dropzone_bg'     => ainbae_bacs_setting('color_dropzone_bg'),
                    'dropzone_border' => ainbae_bacs_setting('color_dropzone_border'),
                    'icon'            => ainbae_bacs_setting('color_icon'),
                    'btn_from'        => ainbae_bacs_setting('color_upload_btn_from'),
                    'btn_to'          => ainbae_bacs_setting('color_upload_btn_to'),
                    'btn_text'        => ainbae_bacs_setting('color_upload_btn_text'),
                    'hint'            => ainbae_bacs_setting('color_hint'),
                ),
            ));
        } else {
            wp_localize_script('ainbae-bacs-public-js', 'ainbaeBacsCheckout', array('enabled' => false));
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN — SETTINGS PAGE RENDER
// ─────────────────────────────────────────────────────────────────────────────

function ainbae_bacs_render_settings_page()
{
    if (! current_user_can('manage_woocommerce')) wp_die(esc_html__('Access denied.', 'ainbae-receipt-upload-for-woocommerce'));

    $s = array();
    foreach (array_keys(ainbae_bacs_default_settings()) as $key) {
        $s[$key] = ainbae_bacs_setting($key);
    }
?>
    <div class="wrap" id="ainbae-bacs-settings-wrap">
        <!-- Hero -->
        <div class="ainbae-bacs-header">
            <div class="ainbae-bacs-header-content">
                <div>
                    <img class="ainbae-bacs-logo" src="<?php echo esc_url(plugins_url('admin/images/ainbae-logo.png', __FILE__)); ?>"
                        alt="<?php esc_attr_e('Ainbae Logo', 'ainbae-receipt-upload-for-woocommerce'); ?>"
                        onerror="this.style.display='none';">
                    <h1>
                        <?php esc_html_e('Welcome to Ainbae Receipt!', 'ainbae-receipt-upload-for-woocommerce'); ?>
                    </h1>
                    <p><?php esc_html_e('Collect Payment Receipts with Confidence', 'ainbae-receipt-upload-for-woocommerce'); ?>
                    </p>
                </div>

                <div class="ainbae-bacs-illustration-container">
                    <img class="ainbae-bacs-illustration" src="<?php echo esc_url(plugins_url('admin/images/ainabe-illustration.png', __FILE__)); ?>"
                        alt="<?php esc_attr_e('Ainbae Illustration', 'ainbae-receipt-upload-for-woocommerce'); ?>"
                        onerror="this.style.display='none';">
                </div>
            </div>
        </div>

        <?php $updated = '';
        if (
            isset($_GET['updated'], $_GET['_wpnonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ainbae_bacs_settings_updated')
        ) {
            $updated = sanitize_text_field(wp_unslash($_GET['updated']));
        }

        if ($updated) : ?>
            <div class="notice notice-success is-dismissible inline" style="border-left-color:#0aa7ff;margin-bottom:20px;">
                <p><strong>&#10003;
                        <?php esc_html_e('Settings saved successfully.', 'ainbae-receipt-upload-for-woocommerce'); ?></strong>
                </p>
            </div>
        <?php endif; ?>

        <!-- TITLE -->
        <div class="ainbae-bacs-settings-title">
            <h1>
                <?php esc_html_e('Widget settings', 'ainbae-receipt-upload-for-woocommerce'); ?>
            </h1>
            <p>
                <?php esc_html_e('Configure and customise how receipts are sent to your customers.', 'ainbae-receipt-upload-for-woocommerce'); ?>
            </p>
        </div>

        <!-- WARNING -->
        <?php if (! ainbae_bacs_is_bacs_enabled()) : ?>
            <div class="ainbae-bacs-warning-banner">
                <div class="ainbae-bacs-warning-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-alert-triangle"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <div class="ainbae-bacs-warning-content">
                    <strong><?php esc_html_e('Direct Bank Transfer (BACS) is Disabled', 'ainbae-receipt-upload-for-woocommerce'); ?></strong>
                    <p><?php echo wp_kses(
                        sprintf(
                            /* translators: %s: link to WooCommerce payment settings */
                            __('This plugin works only if Direct bank transfer is enabled. Please enable the <a href="%s">Direct bank transfer (BACS)</a>.', 'ainbae-receipt-upload-for-woocommerce'),
                            esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=bacs'))
                        ),
                        array(
                            'a' => array(
                                'href' => array(),
                            ),
                        )
                    ); ?></p>
                </div>
            </div>
        <?php endif; ?>


        <form method="post" action="">
        <?php wp_nonce_field('ainbae_bacs_save_settings_action', 'ainbae_bacs_settings_nonce'); ?>

        <div class="ainbae-bacs-settings">
            <div class="ainbae-bacs-settings-container">
                <ul role="tablist" aria-label="Dashboard sections" class="ainbae-bacs-tablist">

                    <!-- ===================== General ===================== -->
                    <li class="ainbae-bacs-tablist-item">
                        <button type="button" role="tab" id="ainbae-bacs-generalTab" aria-selected="true"
                            aria-controls="ainbae-bacs-generalContent" class="ainbae-bacs-tab ainbae-bacs-tab-active">
                            <!-- Gear / settings icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-settings-icon lucide-settings">
                                <path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <?php esc_html_e('General', 'ainbae-receipt-upload-for-woocommerce'); ?>
                        </button>
                    </li>

                    <!-- ===================== Text and Label ===================== -->
                    <li class="ainbae-bacs-tablist-item">
                        <button type="button" role="tab" id="ainbae-bacs-textLabelTab" aria-selected="false"
                            aria-controls="ainbae-bacs-textLabelContent" class="ainbae-bacs-tab">
                            <!-- Text / type icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-square-pen-icon lucide-square-pen">
                                <path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                <path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z" />
                            </svg>
                            <?php esc_html_e('Text and Label', 'ainbae-receipt-upload-for-woocommerce'); ?>
                        </button>
                    </li>

                    <!-- ===================== Colour ===================== -->
                    <li class="ainbae-bacs-tablist-item">
                        <button type="button" role="tab" id="ainbae-bacs-colourTab" aria-selected="false"
                            aria-controls="ainbae-bacs-colourContent" class="ainbae-bacs-tab">
                            <!-- Paint / colour icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-palette-icon lucide-palette">
                                <path d="M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z" />
                                <circle cx="13.5" cy="6.5" r=".5" fill="currentColor" />
                                <circle cx="17.5" cy="10.5" r=".5" fill="currentColor" />
                                <circle cx="6.5" cy="12.5" r=".5" fill="currentColor" />
                                <circle cx="8.5" cy="7.5" r=".5" fill="currentColor" />
                            </svg>
                            <?php esc_html_e('Colour', 'ainbae-receipt-upload-for-woocommerce'); ?>
                        </button>
                    </li>

                    <!-- ===================== Layout ===================== -->
                    <li class="ainbae-bacs-tablist-item">
                        <button type="button" role="tab" id="ainbae-bacs-layoutTab" aria-selected="false"
                            aria-controls="ainbae-bacs-layoutContent" class="ainbae-bacs-tab">
                            <!-- Layout / grid icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="3" width="7" height="9" rx="1" />
                                <rect x="14" y="3" width="7" height="5" rx="1" />
                                <rect x="14" y="12" width="7" height="9" rx="1" />
                                <rect x="3" y="16" width="7" height="5" rx="1" />
                            </svg>
                            <?php esc_html_e('Layout', 'ainbae-receipt-upload-for-woocommerce'); ?>

                        </button>
                    </li>
                </ul>

                <!-- Tab Panels -->
                <div class="ainbae-bacs-panels">

                    <!-- ===================== General TAB Content ===================== -->
                    <div id="ainbae-bacs-generalContent" role="tabpanel" aria-labelledby="ainbae-bacs-generalTab"
                        class="ainbae-bacs-tab-content ainbae-bacs-tab-content-active">
                        <div class="ainbae-bacs-tab-container">
                            <!-- Checkout Behaviour -->
                            <div class="ainbae-bacs-tab-general">
                                <div class="ainbae-bacs-tab-general-heading">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#0066ff" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="9" cy="21" r="1" />
                                        <circle cx="20" cy="21" r="1" />
                                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                                    </svg>
                                    <h2>
                                        <?php esc_html_e('Checkout Behaviour', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                    </h2>
                                </div>
                                <div class="ainbae-bacs-tab-general-subheading">
                                    <div class="ainbae-bacs-headingLabelGroup">
                                        <h3 class="ainbae-bacs-label">
                                            <?php esc_html_e('Require Receipt Before Checkout', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                        </h3>
                                        <p class=" ainbae-bacs-desc">
                                            <?php esc_html_e('Make receipt uploads mandatory before order placement.', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                        </p>
                                    </div>
                                    <label class="ainbae-bacs-toggle">
                                        <input type="checkbox" name="require_receipt_before_order" value="1"
                                            <?php checked($s['require_receipt_before_order'], '1'); ?>>
                                        <span class="ainbae-bacs-toggle-slider"></span>
                                    </label>
                                </div>
                                <p class="ainbae-bacs-desc">
                                    <?php esc_html_e('When enabled, Customers must upload their payment screenshot or receipt before proceeding with the order.', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                </p>
                            </div>
                            <!-- WHATSAPP SETTINGS -->
                            <?php $is_whatsapp_disabled = ($s['require_receipt_before_order'] === '1'); ?>
                            <div class="ainbae-bacs-tab-general" id="ainbae-bacs-whatsapp-settings-group" <?php if ($is_whatsapp_disabled) {
                                                                                                                echo 'style="' . esc_attr('opacity:.4;pointer-events:none;') . '"';
                                                                                                            } ?>>
                                <div class="ainbae-bacs-tab-general-heading">
                                    <svg fill="#0066ff" width="17" height="17" viewBox="0 0 16 16"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M11.42 9.49c-.19-.09-1.1-.54-1.27-.61s-.29-.09-.42.1-.48.6-.59.73-.21.14-.4 0a5.13 5.13 0 0 1-1.49-.92 5.25 5.25 0 0 1-1-1.29c-.11-.18 0-.28.08-.38s.18-.21.28-.32a1.39 1.39 0 0 0 .18-.31.38.38 0 0 0 0-.33c0-.09-.42-1-.58-1.37s-.3-.32-.41-.32h-.4a.72.72 0 0 0-.5.23 2.1 2.1 0 0 0-.65 1.55A3.59 3.59 0 0 0 5 8.2 8.32 8.32 0 0 0 8.19 11c.44.19.78.3 1.05.39a2.53 2.53 0 0 0 1.17.07 1.93 1.93 0 0 0 1.26-.88 1.67 1.67 0 0 0 .11-.88c-.05-.07-.17-.12-.36-.21z" />
                                        <path
                                            d="M13.29 2.68A7.36 7.36 0 0 0 8 .5a7.44 7.44 0 0 0-6.41 11.15l-1 3.85 3.94-1a7.4 7.4 0 0 0 3.55.9H8a7.44 7.44 0 0 0 5.29-12.72zM8 14.12a6.12 6.12 0 0 1-3.15-.87l-.22-.13-2.34.61.62-2.28-.14-.23a6.18 6.18 0 0 1 9.6-7.65 6.12 6.12 0 0 1 1.81 4.37A6.19 6.19 0 0 1 8 14.12z" />
                                    </svg>
                                    <h2>
                                        <?php esc_html_e('WhatsApp Settings', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                    </h2>
                                </div>
                                <div class="ainbae-bacs-tab-general-subheading">
                                    <div class="ainbae-bacs-headingLabelGroup">
                                        <h3 class="ainbae-bacs-label">
                                            <?php esc_html_e('Enable WhatsApp button', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                        </h3>
                                        <p class="ainbae-bacs-desc">
                                            <?php esc_html_e('Show a "Send via WhatsApp" button below the upload form.', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                        </p>
                                    </div>
                                    <label class="ainbae-bacs-toggle">
                                        <input type="checkbox" name="whatsapp_enabled" value="1"
                                            <?php checked($s['whatsapp_enabled'], '1'); ?> <?php disabled($is_whatsapp_disabled); ?>>
                                        <span class="ainbae-bacs-toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="ainbae-bacs-headingLabelGroup" id="ainbae-bacs-wa-number-row" <?php if ($s['whatsapp_enabled'] !== '1' || $is_whatsapp_disabled) {
                                                                                                                echo 'style="' . esc_attr('opacity:.4;pointer-events:none;') . '"';
                                                                                                            } ?>>
                                    <h3 class="ainbae-bacs-label"
                                        for="whatsapp_number"><?php esc_html_e('WhatsApp Number', 'ainbae-receipt-upload-for-woocommerce'); ?></h3>
                                    <p class="ainbae-bacs-desc">
                                        <?php esc_html_e('Include country code, digits only (e.g. 1234567890)', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                    </p>
                                    <div class="ainbae-bacs-input-prefix">
                                        <span>+</span>
                                        <input type="text" id="whatsapp_number" name="whatsapp_number"
                                            value="<?php echo esc_attr($s['whatsapp_number']); ?>" placeholder="1234567890"
                                            class="ainbae-bacs-input" <?php disabled($is_whatsapp_disabled || $s['whatsapp_enabled'] !== '1'); ?>>
                                    </div>
                                </div>
                                <div class="ainbae-bacs-headingLabelGroup" id="ainbae-bacs-wa-template-row" <?php if ($s['whatsapp_enabled'] !== '1' || $is_whatsapp_disabled) {
                                                                                                                echo 'style="' . esc_attr('opacity:.4;pointer-events:none;') . '"';
                                                                                                            } ?>>
                                    <h3 class="ainbae-bacs-label"
                                        for="whatsapp_message_template"><?php esc_html_e('WhatsApp Message Template', 'ainbae-receipt-upload-for-woocommerce'); ?></h3>
                                    <p class="ainbae-bacs-desc">
                                        <?php esc_html_e('Customise the message sent to WhatsApp. Leave blank to use the default message. Variables will be replaced automatically with order data.', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                    </p>
                                    <textarea id="whatsapp_message_template" name="whatsapp_message_template" rows="6"
                                        class="ainbae-bacs-input ainbae-bacs-textarea"
                                        placeholder="<?php esc_attr_e('Leave blank to use the default message.', 'ainbae-receipt-upload-for-woocommerce'); ?>" <?php disabled($is_whatsapp_disabled || $s['whatsapp_enabled'] !== '1'); ?>><?php echo esc_textarea($s['whatsapp_message_template']); ?></textarea>
                                </div>
                                <div>
                                    <p class="ainbae-bacs-desc">
                                        Supported variables:
                                    </p>

                                    <div class="ainbae-bacs-support-variable">
                                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-sm">{order_number}</span>
                                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-sm">{order_total}</span>
                                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-sm">{customer_name}</span>
                                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-sm">{billing_email}</span>
                                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-sm">{billing_phone}</span>
                                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-sm">{site_name}</span>
                                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-sm">{currency}</span>
                                        <span class="px-3 py-1 bg-slate-100 rounded-lg text-sm">{order_date}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================== Text and Label TAB Content ===================== -->
                    <div id="ainbae-bacs-textLabelContent" role="tabpanel" aria-labelledby="ainbae-bacs-textLabelTab"
                        class="ainbae-bacs-tab-content">
                        <div class="ainbae-bacs-tab-container">
                            <?php
                            $labels = array(
                                'label_heading'    => array(__('Heading', 'ainbae-receipt-upload-for-woocommerce'),         __('Main title at the top of the widget', 'ainbae-receipt-upload-for-woocommerce')),
                                'label_subtitle'   => array(__('Subtitle', 'ainbae-receipt-upload-for-woocommerce'),        __('Instruction text below the heading', 'ainbae-receipt-upload-for-woocommerce')),
                                'label_dropzone'   => array(__('Drop Zone Text', 'ainbae-receipt-upload-for-woocommerce'),  __('Text inside the file drop area', 'ainbae-receipt-upload-for-woocommerce')),
                                'label_upload_btn' => array(__('Upload Button', 'ainbae-receipt-upload-for-woocommerce'),   __('Label on the upload button', 'ainbae-receipt-upload-for-woocommerce')),
                                'label_wa_btn'     => array(__('WhatsApp Button', 'ainbae-receipt-upload-for-woocommerce'), __('Label on the WhatsApp button', 'ainbae-receipt-upload-for-woocommerce')),
                                'label_hint'       => array(__('Hint Text', 'ainbae-receipt-upload-for-woocommerce'),       __('Small text below the upload button', 'ainbae-receipt-upload-for-woocommerce')),
                            );
                            foreach ($labels as $key => list($title, $desc)) :
                            ?>
                                <div class="ainbae-bacs-headingLabelGroup">
                                    <h3 class="ainbae-bacs-label"
                                        for="<?php echo esc_attr($key); ?>"><?php echo esc_html($title); ?></h3>
                                    <p class="ainbae-bacs-desc"><?php echo esc_html($desc); ?></p>
                                    <input type="text" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>"
                                        value="<?php echo esc_attr($s[$key]); ?>" class="ainbae-bacs-input">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ===================== Colour TAB Content ===================== -->
                    <div id="ainbae-bacs-colourContent" role="tabpanel" aria-labelledby="ainbae-bacs-colourTab"
                        class="ainbae-bacs-tab-content">
                        <div class="ainbae-bacs-tab-container">
                            <div class="ainbae-bacs-tab-general-heading">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0066ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-credit-card-icon lucide-credit-card"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                                <h2>
                                    <?php esc_html_e('Card', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                </h2>
                            </div>
                            <?php ainbae_bacs_colour_field('color_card_bg', __('Background', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_card_border', __('Border', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>

                            <div class="ainbae-bacs-tab-general-heading">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0066ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-folder-up-icon lucide-folder-up"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/><path d="M12 10v6"/><path d="m9 13 3-3 3 3"/></svg>
                                <h2>
                                    <?php esc_html_e('Drop Zone', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                </h2>
                            </div>
                            <?php ainbae_bacs_colour_field('color_dropzone_bg', __('Background', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_dropzone_border', __('Border', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_icon', __('Icon', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>

                            <div class="ainbae-bacs-tab-general-heading">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0066ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-cloud-upload-icon lucide-cloud-upload"><path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/></svg>
                                <h2>
                                    <?php esc_html_e('Upload Button', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                </h2>
                            </div>
                            <?php ainbae_bacs_colour_field('color_upload_btn_from', __('Gradient Start', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_upload_btn_to', __('Gradient End', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_upload_btn_text', __('Text', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>

                            <div class="ainbae-bacs-tab-general-heading">
                                <svg fill="#0066ff" width="17" height="17" viewBox="0 0 16 16"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M11.42 9.49c-.19-.09-1.1-.54-1.27-.61s-.29-.09-.42.1-.48.6-.59.73-.21.14-.4 0a5.13 5.13 0 0 1-1.49-.92 5.25 5.25 0 0 1-1-1.29c-.11-.18 0-.28.08-.38s.18-.21.28-.32a1.39 1.39 0 0 0 .18-.31.38.38 0 0 0 0-.33c0-.09-.42-1-.58-1.37s-.3-.32-.41-.32h-.4a.72.72 0 0 0-.5.23 2.1 2.1 0 0 0-.65 1.55A3.59 3.59 0 0 0 5 8.2 8.32 8.32 0 0 0 8.19 11c.44.19.78.3 1.05.39a2.53 2.53 0 0 0 1.17.07 1.93 1.93 0 0 0 1.26-.88 1.67 1.67 0 0 0 .11-.88c-.05-.07-.17-.12-.36-.21z" />
                                    <path
                                        d="M13.29 2.68A7.36 7.36 0 0 0 8 .5a7.44 7.44 0 0 0-6.41 11.15l-1 3.85 3.94-1a7.4 7.4 0 0 0 3.55.9H8a7.44 7.44 0 0 0 5.29-12.72zM8 14.12a6.12 6.12 0 0 1-3.15-.87l-.22-.13-2.34.61.62-2.28-.14-.23a6.18 6.18 0 0 1 9.6-7.65 6.12 6.12 0 0 1 1.81 4.37A6.19 6.19 0 0 1 8 14.12z" />
                                </svg>
                                <h2>
                                    <?php esc_html_e('WhatsApp Button', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                </h2>
                            </div>
                            <?php ainbae_bacs_colour_field('color_wa_btn_bg', __('Background', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_wa_btn_border', __('Border', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_wa_btn_text', __('Text', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>

                            <div class="ainbae-bacs-tab-general-heading">
            
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0066ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-case-sensitive-icon lucide-case-sensitive"><path d="m2 16 4.039-9.69a.5.5 0 0 1 .923 0L11 16"/><path d="M22 9v7"/><path d="M3.304 13h6.392"/><circle cx="18.5" cy="12.5" r="3.5"/></svg>
                                <h2>
                                    <?php esc_html_e('Text', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                </h2>
                            </div>
                            <?php ainbae_bacs_colour_field('color_heading', __('Heading', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_subtitle', __('Subtitle', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_hint', __('Hint', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>

                            <div class="ainbae-bacs-tab-general-heading">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0066ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-between-horizontal-start-icon lucide-between-horizontal-start"><rect width="13" height="7" x="8" y="3" rx="1"/><path d="m2 9 3 3-3 3"/><rect width="13" height="7" x="8" y="14" rx="1"/></svg>
                                <h2>
                                    <?php esc_html_e('OR Divider', 'ainbae-receipt-upload-for-woocommerce'); ?>
                                </h2>
                            </div>
                            <?php ainbae_bacs_colour_field('color_or_line', __('Line', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_or_text', __('Text', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                        </div>
                    </div>

                    <!-- ===================== Layout TAB Content ===================== -->
                    <div id="ainbae-bacs-layoutContent" role="tabpanel" aria-labelledby="ainbae-bacs-layoutTab"
                        class="ainbae-bacs-tab-content">
                        <div class="ainbae-bacs-headingLabelGroup">
                            <h3 class="ainbae-bacs-label"
                                for="card_border_radius"><?php esc_html_e('Card Corner Radius (px)', 'ainbae-receipt-upload-for-woocommerce'); ?></h3>
                            <p class="ainbae-bacs-desc">
                                <?php esc_html_e('Roundness of the outer card corners (0 = square, 40 = pill)', 'ainbae-receipt-upload-for-woocommerce'); ?>
                            </p>
                            <div class="ainbae-bacs-range-row">
                                <input type="range" id="ainbae_bacs_br_range" min="0" max="40"
                                    value="<?php echo esc_attr($s['card_border_radius']); ?>"
                                    class="ainbae-bacs-range-slider">
                                <input type="number" id="card_border_radius" name="card_border_radius" min="0" max="40"
                                    value="<?php echo esc_attr($s['card_border_radius']); ?>"
                                    class="ainbae-bacs-input ainbae-bacs-range-input">
                            </div>
                        </div>
                    </div>

                </div>

            </div>
            <!-- LIVE PREVIEW -->
            <div class="ainbae-bacs-settings-container">
                <div class="ainbae-bacs-card ainbae-bacs-preview-card">
                    <div class="ainbae-bacs-card-header" style="background:linear-gradient(135deg,#f0fdf4,#dcfce740);">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#0066ff" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        <span><?php esc_html_e('Live Preview', 'ainbae-receipt-upload-for-woocommerce'); ?></span>
                    </div>
                    <div class="ainbae-bacs-card-body" style="padding:14px;">
                        <div id="ainbae-bacs-preview-container" style="pointer-events:none;user-select:none;"></div>
                        <p style="text-align:center;color:#aaa;font-size:11px;margin:8px 0 0;">
                            <?php esc_html_e('Updates automatically as you change settings above', 'ainbae-receipt-upload-for-woocommerce'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="ainbae-bacs-sticky-footer">
            <span><?php esc_html_e('Changes apply to all customers immediately after saving.', 'ainbae-receipt-upload-for-woocommerce'); ?></span>
            <button type="submit" name="ainbae_bacs_save_settings" class="ainbae-bacs-save-btn">
                <?php esc_html_e('Save Settings', 'ainbae-receipt-upload-for-woocommerce'); ?>
            </button>
        </div>
        </form>
    </div>







<?php
}

function ainbae_bacs_colour_field($key, $label, $s)
{
?>
    <div class="ainbae-bacs-colour-row">
        <span class="ainbae-bacs-colour-label"><?php echo esc_html($label); ?></span>
        <div class="ainbae-bacs-colour-right">
            <input type="text" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($s[$key]); ?>"
                class="ainbae-bacs-color-picker" data-default-color="<?php echo esc_attr($s[$key]); ?>">
        </div>
    </div>
<?php
}

// ─────────────────────────────────────────────────────────────────────────────
// WHATSAPP MESSAGE HELPER (v2.0.0)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build the WhatsApp message for an order.
 * Uses the custom template if set; falls back to built-in template.
 * Replaces all supported {variable} placeholders with order data.
 *
 * @param WC_Order $order
 * @return string
 */
function ainbae_bacs_get_whatsapp_message($order)
{
    $template = ainbae_bacs_setting('whatsapp_message_template');

    if (empty(trim($template))) {
        /* translators: WhatsApp default message. %1$s=order number, %2$s=order total, %3$s=currency, %4$s=site name */
        $template = __("Hello, I have completed payment for Order #{order_number}. Order Total: {currency} {order_total} Please find my payment receipt attached. Thank you, {customer_name}", 'ainbae-receipt-upload-for-woocommerce');
    }

    $billing_first = method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : '';
    $billing_last  = method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : '';
    $customer_name = trim($billing_first . ' ' . $billing_last);
    if (empty($customer_name)) {
        $customer_name = __('Customer', 'ainbae-receipt-upload-for-woocommerce');
    }

    $order_date = method_exists($order, 'get_date_created') && $order->get_date_created()
        ? $order->get_date_created()->date_i18n(get_option('date_format'))
        : '';

    $variables = apply_filters('ainbae_bacs_whatsapp_template_variables', array(
        '{order_number}'   => $order->get_order_number(),
        '{order_total}'    => $order->get_total(),
        '{customer_name}'  => $customer_name,
        '{billing_email}'  => $order->get_billing_email(),
        '{billing_phone}'  => $order->get_billing_phone(),
        '{site_name}'      => get_bloginfo('name'),
        '{currency}'       => $order->get_currency(),
        '{order_date}'     => $order_date,
    ), $order);

    $message = str_replace(array_keys($variables), array_values($variables), $template);

    return apply_filters('ainbae_bacs_whatsapp_message', $message, $order);
}

// ─────────────────────────────────────────────────────────────────────────────
// PRIVATE UPLOAD DIRECTORY
// ─────────────────────────────────────────────────────────────────────────────

function ainbae_bacs_get_private_upload_dir()
{
    $upload_dir = wp_upload_dir();
    $base       = $upload_dir['basedir'] . '/ainbae-receipt-upload-for-woocommerce';

    if (! file_exists($base)) {
        wp_mkdir_p($base);
    }

    $htaccess = $base . '/.htaccess';
    if (! file_exists($htaccess)) {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $wp_filesystem->put_contents(
            $htaccess,
            "# Block all direct HTTP access\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
            FS_CHMOD_FILE
        );
    }

    $index = $base . '/index.php';
    if (! file_exists($index)) {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $wp_filesystem->put_contents($index, '<?php // Silence is golden.', FS_CHMOD_FILE);
    }

    return trailingslashit($base);
}

function ainbae_bacs_allowed_mimes()
{
    return array('jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf');
}

function ainbae_bacs_current_user_can_access_order($order, $order_key = '')
{
    $uid = get_current_user_id();
    if ($uid && $uid === $order->get_customer_id()) return true;
    if (! empty($order_key) && hash_equals($order->get_order_key(), $order_key)) return true;
    return false;
}

function ainbae_bacs_rate_limit_exceeded($user_key)
{
    $tk = 'ainbae_bacs_rl_' . md5($user_key);
    $count = (int) get_transient($tk);
    if ($count >= AINBAE_BACS_RATE_LIMIT_MAX) return true;
    set_transient($tk, $count + 1, AINBAE_BACS_RATE_LIMIT_WINDOW);
    return false;
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 1 — FRONTEND FORM
// ─────────────────────────────────────────────────────────────────────────────

add_action('woocommerce_order_details_before_order_table', 'ainbae_bacs_receipt_upload_form', 10, 1);

function ainbae_bacs_receipt_upload_form($order)
{
    if ($order->get_payment_method() !== 'bacs') return;

    $status = $order->get_status();
    if (in_array($status, array('completed', 'cancelled', 'refunded', 'failed', 'delivered'), true)) return;

    if ($status === 'processing') {
        echo '<div class="woocommerce-message" style="margin-bottom:30px;border-top-color:#2e7d32;"><strong>' . esc_html__('Payment Verified.', 'ainbae-receipt-upload-for-woocommerce') . '</strong> ' . esc_html__('Your order is being processed.', 'ainbae-receipt-upload-for-woocommerce') . '</div>';
        return;
    }

    $order_id  = $order->get_id();

    $order_key = $order->get_order_key();
    if (! ainbae_bacs_current_user_can_access_order($order, $order_key)) return;

    if (get_post_meta($order_id, '_ainbae_bacs_receipt_path', true)) {
        echo '<div class="woocommerce-info" style="margin-bottom:30px;"><strong>' . esc_html__('Payment Receipt Uploaded.', 'ainbae-receipt-upload-for-woocommerce') . '</strong> ' . esc_html__('We will verify your payment shortly.', 'ainbae-receipt-upload-for-woocommerce') . '</div>';
        return;
    }

    $wa_enabled = ainbae_bacs_setting('whatsapp_enabled') === '1';
    $wa_number  = ainbae_bacs_get_whatsapp_number();
    $wa_message = ainbae_bacs_get_whatsapp_message($order);
    $wa_link    = 'https://wa.me/' . rawurlencode($wa_number) . '?text=' . rawurlencode($wa_message);

    // Inject settings as CSS custom properties
    $dynamic_styles = sprintf(
        '--bacs-card-bg: %s; --bacs-card-border: %s; --bacs-card-radius: %dpx; --bacs-heading: %s; --bacs-subtitle: %s; --bacs-dropzone-border: %s; --bacs-dropzone-bg: %s; --bacs-icon: %s; --bacs-btn-from: %s; --bacs-btn-to: %s; --bacs-btn-text: %s; --bacs-hint: %s; --bacs-or-text: %s; --bacs-or-line: %s; --bacs-wa-bg: %s; --bacs-wa-text: %s; --bacs-wa-border: %s;',
        esc_attr(ainbae_bacs_setting('color_card_bg')),
        esc_attr(ainbae_bacs_setting('color_card_border')),
        absint(ainbae_bacs_setting('card_border_radius')),
        esc_attr(ainbae_bacs_setting('color_heading')),
        esc_attr(ainbae_bacs_setting('color_subtitle')),
        esc_attr(ainbae_bacs_setting('color_dropzone_border')),
        esc_attr(ainbae_bacs_setting('color_dropzone_bg')),
        esc_attr(ainbae_bacs_setting('color_icon')),
        esc_attr(ainbae_bacs_setting('color_upload_btn_from')),
        esc_attr(ainbae_bacs_setting('color_upload_btn_to')),
        esc_attr(ainbae_bacs_setting('color_upload_btn_text')),
        esc_attr(ainbae_bacs_setting('color_hint')),
        esc_attr(ainbae_bacs_setting('color_or_text')),
        esc_attr(ainbae_bacs_setting('color_or_line')),
        esc_attr(ainbae_bacs_setting('color_wa_btn_bg')),
        esc_attr(ainbae_bacs_setting('color_wa_btn_text')),
        esc_attr(ainbae_bacs_setting('color_wa_btn_border'))
    );
?>
    <div class="ainbae-bacs-upload-wrap" style="<?php echo wp_kses_post($dynamic_styles); ?>">
        <h3><?php echo esc_html(ainbae_bacs_setting('label_heading')); ?></h3>
        <p class="ainbae-bacs-subtitle"><?php echo esc_html(ainbae_bacs_setting('label_subtitle')); ?></p>

        <form action="" method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('ainbae_bacs_upload_receipt_' . $order_id, 'ainbae_bacs_receipt_nonce'); ?>
            <input type="hidden" name="ainbae_bacs_order_id" value="<?php echo esc_attr($order_id); ?>">
            <input type="hidden" name="ainbae_bacs_order_key" value="<?php echo esc_attr($order_key); ?>">

            <div class="ainbae-bacs-dropzone" id="ainbae-bacs-dropzone">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    aria-hidden="true">
                    <polyline points="16 16 12 12 8 16"></polyline>
                    <line x1="12" y1="12" x2="12" y2="21"></line>
                    <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path>
                </svg>
                <input type="file" name="ainbae_bacs_receipt_file" id="ainbae_bacs_receipt_file"
                    accept=".jpg,.jpeg,.png,.pdf" required>
                <div class="ainbae-bacs-dropzone-label">
                    <?php echo esc_html(ainbae_bacs_setting('label_dropzone')); ?>
                    <span id="ainbae-bacs-file-name"></span>
                </div>
            </div>

            <button type="submit" name="submit_ainbae_bacs_receipt" class="ainbae-bacs-btn-upload">
                <?php echo esc_html(ainbae_bacs_setting('label_upload_btn')); ?>
            </button>
            <p class="ainbae-bacs-upload-hint"><?php echo esc_html(ainbae_bacs_setting('label_hint')); ?></p>
        </form>

        <?php if ($wa_enabled) : ?>
            <div class="ainbae-bacs-or"><?php esc_html_e('OR', 'ainbae-receipt-upload-for-woocommerce'); ?></div>
            <a href="<?php echo esc_url($wa_link); ?>" target="_blank" rel="noopener noreferrer" class="ainbae-bacs-btn-wa">
                <svg fill="currentColor" width="20" height="20" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M11.42 9.49c-.19-.09-1.1-.54-1.27-.61s-.29-.09-.42.1-.48.6-.59.73-.21.14-.4 0a5.13 5.13 0 0 1-1.49-.92 5.25 5.25 0 0 1-1-1.29c-.11-.18 0-.28.08-.38s.18-.21.28-.32a1.39 1.39 0 0 0 .18-.31.38.38 0 0 0 0-.33c0-.09-.42-1-.58-1.37s-.3-.32-.41-.32h-.4a.72.72 0 0 0-.5.23 2.1 2.1 0 0 0-.65 1.55A3.59 3.59 0 0 0 5 8.2 8.32 8.32 0 0 0 8.19 11c.44.19.78.3 1.05.39a2.53 2.53 0 0 0 1.17.07 1.93 1.93 0 0 0 1.26-.88 1.67 1.67 0 0 0 .11-.88c-.05-.07-.17-.12-.36-.21z" />
                    <path
                        d="M13.29 2.68A7.36 7.36 0 0 0 8 .5a7.44 7.44 0 0 0-6.41 11.15l-1 3.85 3.94-1a7.4 7.4 0 0 0 3.55.9H8a7.44 7.44 0 0 0 5.29-12.72zM8 14.12a6.12 6.12 0 0 1-3.15-.87l-.22-.13-2.34.61.62-2.28-.14-.23a6.18 6.18 0 0 1 9.6-7.65 6.12 6.12 0 0 1 1.81 4.37A6.19 6.19 0 0 1 8 14.12z" />
                </svg>
                <?php echo esc_html(ainbae_bacs_setting('label_wa_btn')); ?>
            </a>
        <?php endif; ?>
    </div>
<?php
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 2 — PROCESS UPLOAD
// ─────────────────────────────────────────────────────────────────────────────

add_action('template_redirect', 'ainbae_bacs_process_receipt_upload');

function ainbae_bacs_process_receipt_upload()
{

    if (! isset($_POST['submit_ainbae_bacs_receipt']) || ! isset($_FILES['ainbae_bacs_receipt_file'])) {
        return;
    }

    $order_id = isset($_POST['ainbae_bacs_order_id']) ? absint(wp_unslash($_POST['ainbae_bacs_order_id'])) : 0;

    if (
        ! $order_id ||
        ! isset($_POST['ainbae_bacs_receipt_nonce']) ||
        ! wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['ainbae_bacs_receipt_nonce'])),
            'ainbae_bacs_upload_receipt_' . $order_id
        )
    ) {
        wc_add_notice(__('Security check failed. Please refresh the page and try again.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
        return;
    }

    $order = wc_get_order($order_id);
    if (! $order || $order->get_payment_method() !== 'bacs') {
        wc_add_notice(__('Invalid order.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
        return;
    }

    $order_key = isset($_POST['ainbae_bacs_order_key'])
        ? sanitize_text_field(wp_unslash($_POST['ainbae_bacs_order_key']))
        : '';
    if (! ainbae_bacs_current_user_can_access_order($order, $order_key)) {
        wc_add_notice(__('You do not have permission to upload a receipt for this order.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
        return;
    }

    if (in_array($order->get_status(), array('completed', 'processing', 'cancelled', 'refunded', 'failed', 'delivered'), true)) {
        wc_add_notice(__('A receipt cannot be uploaded for this order in its current status.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
        return;
    }

    if (get_post_meta($order_id, '_ainbae_bacs_receipt_path', true)) {
        wc_add_notice(__('A receipt has already been uploaded for this order.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
        return;
    }

    $ip = isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : 'unknown';

    $actor = get_current_user_id()
        ? 'user_' . get_current_user_id()
        : 'ip_' . $ip;

    if (ainbae_bacs_rate_limit_exceeded($actor)) {
        wc_add_notice(__('Too many upload attempts. Please wait before trying again.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
        return;
    }

    // ── Validate raw $_FILES entry ────────────────────────────────────────────
    $file = $_FILES['ainbae_bacs_receipt_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

    if ($file['error'] !== UPLOAD_ERR_OK) {
        /* translators: %d: File upload error code number. */
        wc_add_notice(sprintf(__('Upload error (code %d).', 'ainbae-receipt-upload-for-woocommerce'), (int) $file['error']), 'error');
        return;
    }

    if ($file['size'] > AINBAE_BACS_MAX_UPLOAD_SIZE) {
        wc_add_notice(__('File is too large. Maximum size is 5 MB.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
        return;
    }

    // ── Load WP upload helpers (needed on front-end) ──────────────────────────
    if (! function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    // ── Temporarily redirect wp_handle_upload() to our private directory ──────
    $private_dir = ainbae_bacs_get_private_upload_dir();
    $uuid        = wp_generate_uuid4();

    $custom_upload_dir = function ($dirs) use ($private_dir) {
        $dirs['path']   = untrailingslashit($private_dir);
        $dirs['url']    = ''; // No public URL — private storage only.
        $dirs['subdir'] = '';
        return $dirs;
    };

    add_filter('upload_dir', $custom_upload_dir);

    $uploaded = wp_handle_upload(
        $file,
        array(
            'test_form'                => false,
            'mimes'                    => ainbae_bacs_allowed_mimes(),
            // Give the file our UUID-based name instead of the original filename.
            'unique_filename_callback' => function ($dir, $name, $ext) use ($uuid) {
                return $uuid . $ext;
            },
        )
    );

    remove_filter('upload_dir', $custom_upload_dir);

    // ── Handle upload result ──────────────────────────────────────────────────
    if (isset($uploaded['error'])) {
        // wp_handle_upload() already returns a translated error string.
        wc_add_notice($uploaded['error'], 'error');
        return;
    }

    if (empty($uploaded['file']) || empty($uploaded['type'])) {
        wc_add_notice(__('Invalid file type. Only JPG, PNG, and PDF are accepted.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
        return;
    }

    $dest = $uploaded['file'];

    // ── Persist receipt metadata ──────────────────────────────────────────────
    update_post_meta($order_id, '_ainbae_bacs_receipt_path',     $dest);
    update_post_meta($order_id, '_ainbae_bacs_receipt_mime',     sanitize_mime_type($uploaded['type']));
    update_post_meta($order_id, '_ainbae_bacs_receipt_uploaded', current_time('mysql'));

    /* translators: %s: Date and time when the receipt was uploaded (e.g. 2025-01-17 14:30:00). */
    $order->add_order_note(sprintf(__('Customer uploaded a bank transfer receipt on %s. Use the admin panel to view it securely.', 'ainbae-receipt-upload-for-woocommerce'), current_time('mysql')));

    wc_add_notice(__('Receipt uploaded successfully. Thank you! We will verify your payment shortly.', 'ainbae-receipt-upload-for-woocommerce'), 'success');
    wp_safe_redirect($order->get_view_order_url());
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 3 — ADMIN ORDER PANEL & SECURE FILE VIEWER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Try to recover broken receipt metadata for an order.
 * If a file exists at the expected path but meta is missing/broken, rebuild it.
 *
 * @param int $order_id
 * @return string|false  Recovered file path, or false.
 */
function ainbae_bacs_recover_receipt_meta($order_id)
{
    $order = wc_get_order($order_id);
    if (! $order) return false;

    // Scan order notes for the upload note keyword
    $notes      = wc_get_order_notes(array('order_id' => $order_id, 'type' => 'order'));
    $note_found = false;
    foreach ($notes as $note) {
        if (false !== strpos($note->content, 'bank transfer receipt')) {
            $note_found = true;
            break;
        }
    }
    if (! $note_found) return false;

    // Scan the private upload directory for any file associated with this order.
    // Files are UUID-named so we cannot match by order ID directly;
    // instead we look for any file and attempt to restore.
    $dir = ainbae_bacs_get_private_upload_dir();
    if (! is_dir($dir)) return false;

    $files = glob($dir . '*');
    if (empty($files)) return false;

    // Attempt to match by upload timestamp in notes vs file mtime.
    $recovered = false;
    foreach ($files as $file) {
        if (! is_file($file)) continue;
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimes = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf');
        if (! isset($mimes[$ext])) continue;

        update_post_meta($order_id, '_ainbae_bacs_receipt_path', $file);
        update_post_meta($order_id, '_ainbae_bacs_receipt_mime', $mimes[$ext]);
        if (! get_post_meta($order_id, '_ainbae_bacs_receipt_uploaded', true)) {
            update_post_meta($order_id, '_ainbae_bacs_receipt_uploaded', gmdate('Y-m-d H:i:s', filemtime($file)));
        }
        $order->add_order_note(__('Receipt metadata recovered automatically by Ainbae Receipt Upload.', 'ainbae-receipt-upload-for-woocommerce'));

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf('Ainbae Receipt Upload: Metadata recovered for order #%d. File: %s', $order_id, $file));
        }
        $recovered = $file;
        break;
    }
    return $recovered;
}

add_action('woocommerce_admin_order_data_after_order_details', 'ainbae_bacs_display_receipt_in_admin', 10, 1);

/**
 * Display receipt panel in WooCommerce admin order screen.
 * Uses a 5-priority detection system before showing "No receipt uploaded yet".
 *
 * Priority 1: Attachment ID
 * Priority 2: Stored file path (meta)
 * Priority 3: Stored receipt URL (meta)
 * Priority 4: Legacy metadata
 * Priority 5: Metadata recovery attempt
 *
 * @param WC_Order $order
 */
function ainbae_bacs_display_receipt_in_admin($order)
{
    if ($order->get_payment_method() !== 'bacs') return;

    $order_id = $order->get_id();
    $uploaded = get_post_meta($order_id, '_ainbae_bacs_receipt_uploaded', true);
    $receipt_found = false;
    $receipt_url   = '';

    echo '<br class="clear"><h3>' . esc_html__('Bank Transfer Receipt', 'ainbae-receipt-upload-for-woocommerce') . '</h3>';

    // ── Priority 1: Attachment ID ────────────────────────────────────────────
    $attachment_id = get_post_meta($order_id, '_ainbae_bacs_receipt_attachment_id', true);
    if ($attachment_id && wp_attachment_is_image($attachment_id)) {
        $att_url = wp_get_attachment_url($attachment_id);
        if ($att_url) {
            $receipt_url   = $att_url;
            $receipt_found = true;
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('Ainbae Receipt Upload: Admin display resolved via attachment ID for order #%d.', $order_id));
            }
        }
    }

    // ── Priority 2: Stored file path ─────────────────────────────────────────
    if (! $receipt_found) {
        $path = get_post_meta($order_id, '_ainbae_bacs_receipt_path', true);
        if ($path && file_exists($path) && is_file($path)) {
            $receipt_found = true;
            // Use the secure admin-post endpoint (nonce-authenticated, admin only)
        } elseif ($path) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('Ainbae Receipt Upload: Path meta found but file missing for order #%d. Path: %s', $order_id, $path));
            }
        }
    }

    // ── Priority 3: Stored receipt URL ───────────────────────────────────────
    if (! $receipt_found) {
        $meta_url = get_post_meta($order_id, '_ainbae_bacs_receipt_url', true);
        if ($meta_url) {
            $receipt_url   = $meta_url;
            $receipt_found = true;
        }
    }

    // ── Priority 4: Legacy metadata ──────────────────────────────────────────
    if (! $receipt_found) {
        $legacy_path = get_post_meta($order_id, '_ainbae_bacs_receipt_file', true);
        if ($legacy_path && file_exists($legacy_path) && is_file($legacy_path)) {
            // Migrate legacy meta to current key
            update_post_meta($order_id, '_ainbae_bacs_receipt_path', $legacy_path);
            $receipt_found = true;
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('Ainbae Receipt Upload: Legacy meta migrated for order #%d.', $order_id));
            }
        }
    }

    // ── Priority 5: Metadata recovery attempt ────────────────────────────────
    if (! $receipt_found) {
        $recovered = ainbae_bacs_recover_receipt_meta($order_id);
        if ($recovered) {
            $receipt_found = true;
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────
    if ($receipt_found) {
        if ($receipt_url) {
            // Direct URL (attachment or stored URL)
            echo '<p style="margin-top:10px;"><a href="' . esc_url($receipt_url) . '" target="_blank" rel="noopener noreferrer" class="button button-primary">' . esc_html__('View Uploaded Receipt', 'ainbae-receipt-upload-for-woocommerce') . '</a>';
        } else {
            // Secure admin-post endpoint for private file
            $url = wp_nonce_url(
                add_query_arg(
                    array('action' => 'ainbae_bacs_view_receipt', 'order_id' => $order_id),
                    admin_url('admin-post.php')
                ),
                'ainbae_bacs_view_receipt_' . $order_id
            );
            echo '<p style="margin-top:10px;"><a href="' . esc_url($url) . '" target="_blank" class="button button-primary">' . esc_html__('View Uploaded Receipt', 'ainbae-receipt-upload-for-woocommerce') . '</a>';
        }

        if ($uploaded) {
            echo ' &nbsp;<small style="color:#666;">' . esc_html__('Uploaded:', 'ainbae-receipt-upload-for-woocommerce') . ' ' . esc_html($uploaded) . '</small>';
        }
        echo '</p>';
    } else {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf('Ainbae Receipt Upload: All receipt checks failed for order #%d. Showing "No receipt" message.', $order_id));
        }
        echo '<p style="color:#d63638;"><strong>' . esc_html__('No receipt uploaded yet.', 'ainbae-receipt-upload-for-woocommerce') . '</strong></p>';
    }
}

add_action('admin_post_ainbae_bacs_view_receipt', 'ainbae_bacs_serve_receipt_to_admin');

function ainbae_bacs_serve_receipt_to_admin()
{

    if (! current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Access denied.', 'ainbae-receipt-upload-for-woocommerce'), 403);
    }

    $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;

    if (! $order_id || ! check_admin_referer('ainbae_bacs_view_receipt_' . $order_id)) {
        wp_die(esc_html__('Invalid request.', 'ainbae-receipt-upload-for-woocommerce'), 400);
    }

    $path = get_post_meta($order_id, '_ainbae_bacs_receipt_path', true);
    $mime = get_post_meta($order_id, '_ainbae_bacs_receipt_mime', true);

    // ── Validate path exists ──────────────────────────────────────────────
    if (! $path || ! file_exists($path) || ! is_file($path)) {
        wp_die(esc_html__('Receipt not found.', 'ainbae-receipt-upload-for-woocommerce'), 404);
    }

    // ── Path traversal protection ─────────────────────────────────────────
    $real_path     = realpath($path);
    $real_dir      = realpath(ainbae_bacs_get_private_upload_dir());
    // Legacy: receipts uploaded by v1.0.1 were stored in wp-content/bacs-receipts-private/.
    $upload_info       = wp_upload_dir();
    $content_base      = dirname($upload_info['basedir']);
    $real_dir_legacy   = realpath($content_base . '/bacs-receipts-private');

    $in_current = $real_dir && $real_path && strpos($real_path, $real_dir) === 0;
    $in_legacy  = $real_dir_legacy && $real_path && strpos($real_path, $real_dir_legacy) === 0;

    if (false === $real_path || (! $in_current && ! $in_legacy)) {
        wp_die(esc_html__('Access denied.', 'ainbae-receipt-upload-for-woocommerce'), 403);
    }

    // ── MIME type validation ──────────────────────────────────────────────
    if (! $mime || ! in_array($mime, ainbae_bacs_allowed_mimes(), true)) {
        wp_die(esc_html__('Invalid file type.', 'ainbae-receipt-upload-for-woocommerce'), 400);
    }

    // ── Serve file ────────────────────────────────────────────────────────
    $ext       = pathinfo($real_path, PATHINFO_EXTENSION);
    $file_size = filesize($real_path);

    if (false === $file_size || 0 === $file_size) {
        wp_die(esc_html__('Could not read the receipt file.', 'ainbae-receipt-upload-for-woocommerce'), 500);
    }

    // WordPress (and plugins) may have buffered output during admin-post.php
    // initialization. Any buffered HTML prepended to binary data will corrupt
    // the image/PDF stream. Discard every active output buffer before serving.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    nocache_headers();
    header('Content-Type: '        . sanitize_mime_type($mime));
    header('Content-Length: '      . $file_size);
    header('Content-Disposition: inline; filename="receipt-order-' . $order_id . '.' . esc_attr($ext) . '"');
    header('X-Content-Type-Options: nosniff');

    // Stream the binary file directly — readfile() outputs straight to the
    // client without loading data into a PHP variable, avoiding any
    // escaping concerns for binary content (images / PDFs).
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Binary streaming to browser; WP_Filesystem has no streaming equivalent.
    readfile($real_path);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 4 — SETTINGS PAGE: WHATSAPP TEMPLATE & CHECKOUT BEHAVIOUR CARDS (v2.0.0)
// ─────────────────────────────────────────────────────────────────────────────
// NOTE: The new fields are injected into the existing settings page render
// function via the helper below. The render function calls
// ainbae_bacs_render_whatsapp_extra_fields() and
// ainbae_bacs_render_checkout_behaviour_card() at the appropriate points.
// Because the render function is defined earlier in this file and is not easily
// split, the two new setting blocks are added inline via output buffering
// hooks on a custom action fired from inside the render function.
// ─────────────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────────────
// PART 5 — CHECKOUT UPLOAD AJAX HANDLERS (v2.0.0 — Feature 2)
// ─────────────────────────────────────────────────────────────────────────────

// Return a fresh nonce for the checkout upload form.
add_action('wp_ajax_ainbae_bacs_get_checkout_nonce',        'ainbae_bacs_ajax_get_checkout_nonce');
add_action('wp_ajax_nopriv_ainbae_bacs_get_checkout_nonce', 'ainbae_bacs_ajax_get_checkout_nonce');
function ainbae_bacs_ajax_get_checkout_nonce()
{
    wp_send_json_success(array('nonce' => wp_create_nonce('ainbae_bacs_checkout_upload')));
}

// Handle pre-checkout receipt upload.
add_action('wp_ajax_ainbae_bacs_checkout_upload',        'ainbae_bacs_ajax_checkout_upload');
add_action('wp_ajax_nopriv_ainbae_bacs_checkout_upload', 'ainbae_bacs_ajax_checkout_upload');
function ainbae_bacs_ajax_checkout_upload()
{
    // Nonce check
    if (
        ! isset($_POST['nonce']) ||
        ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ainbae_bacs_checkout_upload')
    ) {
        wp_send_json_error(array('message' => __('Security check failed.', 'ainbae-receipt-upload-for-woocommerce')));
    }

    // Rate limiting
    $ip    = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $actor = get_current_user_id() ? 'user_' . get_current_user_id() : 'ip_' . $ip;
    if (ainbae_bacs_rate_limit_exceeded($actor)) {
        wp_send_json_error(array('message' => __('Too many upload attempts. Please wait before trying again.', 'ainbae-receipt-upload-for-woocommerce')));
    }

    // File present?
    if (
        ! isset(
            $_FILES['ainbae_bacs_checkout_receipt'],
            $_FILES['ainbae_bacs_checkout_receipt']['error'],
            $_FILES['ainbae_bacs_checkout_receipt']['size'],
            $_FILES['ainbae_bacs_checkout_receipt']['tmp_name']
        ) ||
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        ! is_uploaded_file($_FILES['ainbae_bacs_checkout_receipt']['tmp_name'])
    ) {
        wp_send_json_error(array('message' => __('No file received or upload error.', 'ainbae-receipt-upload-for-woocommerce')));
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
    if ((int) $_FILES['ainbae_bacs_checkout_receipt']['error'] !== UPLOAD_ERR_OK) {
        $err_code = (int) $_FILES['ainbae_bacs_checkout_receipt']['error'];
        /* translators: %d: file upload error code */
        wp_send_json_error(array('message' => sprintf(__('Upload error (code %d).', 'ainbae-receipt-upload-for-woocommerce'), $err_code)));
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
    $file = $_FILES['ainbae_bacs_checkout_receipt'];

    // Size check
    if ($file['size'] > AINBAE_BACS_MAX_UPLOAD_SIZE) {
        wp_send_json_error(array('message' => __('File is too large. Maximum size is 5 MB.', 'ainbae-receipt-upload-for-woocommerce')));
    }

    // WP upload helpers
    if (! function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $private_dir = ainbae_bacs_get_private_upload_dir();
    $uuid        = wp_generate_uuid4();

    $custom_upload_dir = function ($dirs) use ($private_dir) {
        $dirs['path']   = untrailingslashit($private_dir);
        $dirs['url']    = '';
        $dirs['subdir'] = '';
        return $dirs;
    };

    add_filter('upload_dir', $custom_upload_dir);
    $uploaded = wp_handle_upload(
        $file,
        array(
            'test_form'                => false,
            'mimes'                    => ainbae_bacs_allowed_mimes(),
            'unique_filename_callback' => function ($dir, $name, $ext) use ($uuid) {
                return 'checkout-' . $uuid . $ext;
            },
        )
    );
    remove_filter('upload_dir', $custom_upload_dir);

    if (isset($uploaded['error'])) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Ainbae Receipt Upload (checkout): ' . $uploaded['error']);
        }
        wp_send_json_error(array('message' => $uploaded['error']));
    }

    if (empty($uploaded['file']) || empty($uploaded['type'])) {
        wp_send_json_error(array('message' => __('Invalid file type. Only JPG, PNG, and PDF are accepted.', 'ainbae-receipt-upload-for-woocommerce')));
    }

    // Store temp data in WooCommerce session
    $token = wp_generate_uuid4();
    WC()->session->set('ainbae_bacs_checkout_receipt_' . $token, array(
        'path' => $uploaded['file'],
        'mime' => sanitize_mime_type($uploaded['type']),
        'time' => current_time('mysql'),
    ));

    wp_send_json_success(array('token' => $token));
}

// ── Render checkout hidden token input ───────────────────────────────────────
add_action('woocommerce_review_order_before_submit', 'ainbae_bacs_checkout_hidden_token_field');
function ainbae_bacs_checkout_hidden_token_field()
{
    if (ainbae_bacs_setting('require_receipt_before_order') === '1') {
        echo '<input type="hidden" name="ainbae_bacs_checkout_token" id="ainbae-bacs-token-input" value="">';
    }
}

// ── Validate receipt token at checkout ────────────────────────────────────────
add_action('woocommerce_checkout_process', 'ainbae_bacs_checkout_require_receipt');
function ainbae_bacs_checkout_require_receipt()
{
    if (ainbae_bacs_setting('require_receipt_before_order') !== '1') {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its own checkout nonce before firing woocommerce_checkout_process.
    $payment_method = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';
    if ($payment_method !== 'bacs') {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its own checkout nonce before firing woocommerce_checkout_process.
    $token = isset($_POST['ainbae_bacs_checkout_token']) ? sanitize_text_field(wp_unslash($_POST['ainbae_bacs_checkout_token'])) : '';
    if (! $token) {
        $token = isset($_COOKIE['ainbae_bacs_checkout_token']) ? sanitize_text_field(wp_unslash($_COOKIE['ainbae_bacs_checkout_token'])) : '';
    }

    if (! $token) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Ainbae Receipt Upload (Classic validation error): No receipt token found.');
        }
        wc_add_notice(__('Please upload a payment receipt before placing your order.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
        return;
    }

    if (! WC()->session) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Ainbae Receipt Upload (Classic validation error): Session not initialized.');
        }
        wc_add_notice(__('Session error. Please refresh the page and try again.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
        return;
    }

    $data = WC()->session->get('ainbae_bacs_checkout_receipt_' . $token);
    if (empty($data) || empty($data['path']) || ! file_exists($data['path'])) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Ainbae Receipt Upload (Classic validation error): Receipt file not found in session.');
        }
        wc_add_notice(__('Your uploaded receipt could not be found. Please upload again.', 'ainbae-receipt-upload-for-woocommerce'), 'error');
    }
}

// ── Attach receipt to order after it is created (Classic Checkout) ───────────
add_action('woocommerce_checkout_order_created', 'ainbae_bacs_attach_checkout_receipt', 10, 1);
function ainbae_bacs_attach_checkout_receipt($order)
{
    if (ainbae_bacs_setting('require_receipt_before_order') !== '1') {
        return;
    }
    if ($order->get_payment_method() !== 'bacs') {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in woocommerce_checkout_process
    $token = isset($_POST['ainbae_bacs_checkout_token']) ? sanitize_text_field(wp_unslash($_POST['ainbae_bacs_checkout_token'])) : '';
    if (! $token) {
        $token = isset($_COOKIE['ainbae_bacs_checkout_token']) ? sanitize_text_field(wp_unslash($_COOKIE['ainbae_bacs_checkout_token'])) : '';
    }

    if (! $token || ! WC()->session) {
        return;
    }

    $data = WC()->session->get('ainbae_bacs_checkout_receipt_' . $token);
    if (empty($data) || empty($data['path']) || ! file_exists($data['path'])) {
        return;
    }

    $order_id = $order->get_id();

    update_post_meta($order_id, '_ainbae_bacs_receipt_path',     $data['path']);
    update_post_meta($order_id, '_ainbae_bacs_receipt_mime',     $data['mime']);
    update_post_meta($order_id, '_ainbae_bacs_receipt_uploaded', $data['time']);

    /* translators: %s: date and time of upload */
    $order->add_order_note(sprintf(__('Customer uploaded a bank transfer receipt on %s (at checkout). Use the admin panel to view it securely.', 'ainbae-receipt-upload-for-woocommerce'), $data['time']));

    // Clear session token and cookie
    WC()->session->__unset('ainbae_bacs_checkout_receipt_' . $token);
    if (isset($_COOKIE['ainbae_bacs_checkout_token'])) {
        setcookie('ainbae_bacs_checkout_token', '', time() - 3600, '/');
    }
}

// ── WooCommerce Blocks / Store API Checkout validation & attachment ─────────
add_action('woocommerce_store_api_checkout_order_processed', 'ainbae_bacs_store_api_checkout_validation', 10, 1);
function ainbae_bacs_store_api_checkout_validation($order)
{
    if (ainbae_bacs_setting('require_receipt_before_order') !== '1') {
        return;
    }
    if ($order->get_payment_method() !== 'bacs') {
        return;
    }

    $token = isset($_COOKIE['ainbae_bacs_checkout_token']) ? sanitize_text_field(wp_unslash($_COOKIE['ainbae_bacs_checkout_token'])) : '';
    if (! $token) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Ainbae Receipt Upload (Blocks validation error): No receipt token found in cookie.');
        }
        ainbae_bacs_throw_store_api_error(__('Please upload a payment receipt before placing your order.', 'ainbae-receipt-upload-for-woocommerce'));
    }

    if (! WC()->session) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Ainbae Receipt Upload (Blocks validation error): Session not initialized.');
        }
        ainbae_bacs_throw_store_api_error(__('Session error. Please refresh the page and try again.', 'ainbae-receipt-upload-for-woocommerce'));
    }

    $data = WC()->session->get('ainbae_bacs_checkout_receipt_' . $token);
    if (empty($data) || empty($data['path']) || ! file_exists($data['path'])) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Ainbae Receipt Upload (Blocks validation error): Receipt file not found in session.');
        }
        ainbae_bacs_throw_store_api_error(__('Your uploaded receipt could not be found. Please upload again.', 'ainbae-receipt-upload-for-woocommerce'));
    }

    // Attach receipt data to order
    $order_id = $order->get_id();
    update_post_meta($order_id, '_ainbae_bacs_receipt_path',     $data['path']);
    update_post_meta($order_id, '_ainbae_bacs_receipt_mime',     $data['mime']);
    update_post_meta($order_id, '_ainbae_bacs_receipt_uploaded', $data['time']);

    /* translators: %s: date and time of upload */
    $order->add_order_note(sprintf(__('Customer uploaded a bank transfer receipt on %s (at checkout). Use the admin panel to view it securely.', 'ainbae-receipt-upload-for-woocommerce'), $data['time']));

    // Clear session token & cookie
    WC()->session->__unset('ainbae_bacs_checkout_receipt_' . $token);
    if (isset($_COOKIE['ainbae_bacs_checkout_token'])) {
        setcookie('ainbae_bacs_checkout_token', '', time() - 3600, '/');
    }
}

function ainbae_bacs_throw_store_api_error($message)
{
    if (class_exists('Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
            'ainbae_bacs_checkout_error',
            esc_html($message),
            400
        );
    } else {
        throw new \Exception(esc_html($message));
    }
}
