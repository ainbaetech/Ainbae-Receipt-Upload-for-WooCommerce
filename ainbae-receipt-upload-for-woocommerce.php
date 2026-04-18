<?php

/**
 * Plugin Name: Ainbae Receipt Upload for WooCommerce
 * Description: Allows customers to upload bank transfer receipts on the order detail page.
 * Version: 1.0.1
 * Author: Ainbae
 * Author URI: https://www.ainbae.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ainbae-receipt-upload-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.1
 * WC tested up to: 10.7.0
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
        'whatsapp_enabled'       => '1',
        'whatsapp_number'        => '1234567890',
        'color_card_bg'          => '#f0f4f2',
        'color_card_border'      => '#d6e4dc',
        'color_dropzone_bg'      => '#ffffff',
        'color_dropzone_border'  => '#b0c8bc',
        'color_icon'             => '#0aa7ff',
        'color_upload_btn_from'  => '#0aa7ff',
        'color_upload_btn_to'    => '#0aa7ff',
        'color_upload_btn_text'  => '#ffffff',
        'color_wa_btn_bg'        => '#e6f9ee',
        'color_wa_btn_border'    => '#a8dfc0',
        'color_wa_btn_text'      => '#1a7a3c',
        'color_heading'          => '#1a1a1a',
        'color_subtitle'         => '#555555',
        'color_hint'             => '#888888',
        'color_or_line'          => '#d0ddd6',
        'color_or_text'          => '#999999',
        'label_heading'          => __('Verify Your Payment', 'ainbae-receipt-upload-for-woocommerce'),
        'label_subtitle'         => __('Please upload a screenshot of your transaction receipt, or send it directly via WhatsApp to process your order.', 'ainbae-receipt-upload-for-woocommerce'),
        'label_dropzone'         => __('Click to upload, or drag and drop your receipt file', 'ainbae-receipt-upload-for-woocommerce'),
        'label_upload_btn'       => __('Upload Receipt', 'ainbae-receipt-upload-for-woocommerce'),
        'label_wa_btn'           => __('Send Receipt via WhatsApp', 'ainbae-receipt-upload-for-woocommerce'),
        'label_hint'             => __('Allowed formats: JPG, PNG, PDF. Max size: 5 MB.', 'ainbae-receipt-upload-for-woocommerce'),
        'card_border_radius'     => '16',
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
    wp_enqueue_style('ainbae-bacs-admin-css', plugins_url('admin/css/admin.css', __FILE__), array(), '2.2.0');
    wp_enqueue_script('ainbae-bacs-admin-js', plugins_url('admin/js/admin.js', __FILE__), array('jquery', 'wp-color-picker'), '2.2.0', true);
}

add_action('wp_enqueue_scripts', 'ainbae_bacs_enqueue_public_assets');
function ainbae_bacs_enqueue_public_assets()
{
    if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('view-order') || is_wc_endpoint_url('order-received'))) {
        wp_enqueue_style('ainbae-bacs-public-css', plugins_url('public/css/public.css', __FILE__), array(), '2.2.0');
        wp_enqueue_script('ainbae-bacs-public-js', plugins_url('public/js/public.js', __FILE__), array(), '2.2.0', true);
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
        <div class="ainbae-bacs-page-header">
            <img src="<?php echo esc_url(plugins_url('admin/images/ainbae-logo.png', __FILE__)); ?>" alt="<?php esc_attr_e('Ainbae Logo', 'ainbae-receipt-upload-for-woocommerce'); ?>" onerror="this.style.display='none';">
            <p><?php esc_html_e('Customise the payment receipt widget shown to customers', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
        </div>

        <?php $updated = '';
        if (
            isset($_GET['updated'], $_GET['_wpnonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ainbae_bacs_settings_updated')
        ) {
            $updated = sanitize_text_field(wp_unslash($_GET['updated']));
        }

        if ($updated) : ?>
            <div class="notice notice-success is-dismissible" style="border-left-color:#0aa7ff;margin-bottom:20px;">
                <p><strong>&#10003; <?php esc_html_e('Settings saved successfully.', 'ainbae-receipt-upload-for-woocommerce'); ?></strong></p>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('ainbae_bacs_save_settings_action', 'ainbae_bacs_settings_nonce'); ?>

            <div class="ainbae-bacs-grid">
                <div class="ainbae-bacs-col">
                    <div class="ainbae-bacs-card">
                        <div class="ainbae-bacs-card-header" style="background:linear-gradient(135deg,#dcfce7,#bbf7d040);">
                            <svg fill="#25d366" width="17" height="17" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                                <path d="M11.42 9.49c-.19-.09-1.1-.54-1.27-.61s-.29-.09-.42.1-.48.6-.59.73-.21.14-.4 0a5.13 5.13 0 0 1-1.49-.92 5.25 5.25 0 0 1-1-1.29c-.11-.18 0-.28.08-.38s.18-.21.28-.32a1.39 1.39 0 0 0 .18-.31.38.38 0 0 0 0-.33c0-.09-.42-1-.58-1.37s-.3-.32-.41-.32h-.4a.72.72 0 0 0-.5.23 2.1 2.1 0 0 0-.65 1.55A3.59 3.59 0 0 0 5 8.2 8.32 8.32 0 0 0 8.19 11c.44.19.78.3 1.05.39a2.53 2.53 0 0 0 1.17.07 1.93 1.93 0 0 0 1.26-.88 1.67 1.67 0 0 0 .11-.88c-.05-.07-.17-.12-.36-.21z" />
                                <path d="M13.29 2.68A7.36 7.36 0 0 0 8 .5a7.44 7.44 0 0 0-6.41 11.15l-1 3.85 3.94-1a7.4 7.4 0 0 0 3.55.9H8a7.44 7.44 0 0 0 5.29-12.72zM8 14.12a6.12 6.12 0 0 1-3.15-.87l-.22-.13-2.34.61.62-2.28-.14-.23a6.18 6.18 0 0 1 9.6-7.65 6.12 6.12 0 0 1 1.81 4.37A6.19 6.19 0 0 1 8 14.12z" />
                            </svg>
                            <span><?php esc_html_e('WhatsApp', 'ainbae-receipt-upload-for-woocommerce'); ?></span>
                        </div>
                        <div class="ainbae-bacs-card-body">
                            <div class="ainbae-bacs-field ainbae-bacs-field-toggle">
                                <div>
                                    <label class="ainbae-bacs-label"><?php esc_html_e('Enable WhatsApp Button', 'ainbae-receipt-upload-for-woocommerce'); ?></label>
                                    <p class="ainbae-bacs-desc"><?php esc_html_e('Show a "Send via WhatsApp" button below the upload form', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
                                </div>
                                <label class="ainbae-bacs-toggle">
                                    <input type="checkbox" name="whatsapp_enabled" value="1" <?php checked($s['whatsapp_enabled'], '1'); ?>>
                                    <span class="ainbae-bacs-toggle-slider"></span>
                                </label>
                            </div>
                            <div class="ainbae-bacs-field" id="ainbae-bacs-wa-number-row" <?php echo $s['whatsapp_enabled'] !== '1' ? 'style="opacity:.4;pointer-events:none;"' : ''; ?>>
                                <label class="ainbae-bacs-label" for="whatsapp_number"><?php esc_html_e('WhatsApp Number', 'ainbae-receipt-upload-for-woocommerce'); ?></label>
                                <p class="ainbae-bacs-desc"><?php esc_html_e('Include country code, digits only (e.g. 1234567890)', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
                                <div class="ainbae-bacs-input-prefix">
                                    <span>+</span>
                                    <input type="text" id="whatsapp_number" name="whatsapp_number" value="<?php echo esc_attr($s['whatsapp_number']); ?>" placeholder="1234567890" class="ainbae-bacs-input">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ainbae-bacs-card">
                        <div class="ainbae-bacs-card-header" style="background:linear-gradient(135deg,#eff6ff,#dbeafe40);">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                            </svg>
                            <span><?php esc_html_e('Text & Labels', 'ainbae-receipt-upload-for-woocommerce'); ?></span>
                        </div>
                        <div class="ainbae-bacs-card-body">
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
                                <div class="ainbae-bacs-field">
                                    <label class="ainbae-bacs-label" for="<?php echo esc_attr($key); ?>"><?php echo esc_html($title); ?></label>
                                    <p class="ainbae-bacs-desc"><?php echo esc_html($desc); ?></p>
                                    <input type="text" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($s[$key]); ?>" class="ainbae-bacs-input">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="ainbae-bacs-card">
                        <div class="ainbae-bacs-card-header" style="background:linear-gradient(135deg,#fff7ed,#fed7aa40);">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2" />
                                <path d="M3 9h18M9 21V9" />
                            </svg>
                            <span><?php esc_html_e('Layout', 'ainbae-receipt-upload-for-woocommerce'); ?></span>
                        </div>
                        <div class="ainbae-bacs-card-body">
                            <div class="ainbae-bacs-field">
                                <label class="ainbae-bacs-label" for="card_border_radius"><?php esc_html_e('Card Corner Radius (px)', 'ainbae-receipt-upload-for-woocommerce'); ?></label>
                                <p class="ainbae-bacs-desc"><?php esc_html_e('Roundness of the outer card corners (0 = square, 40 = pill)', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
                                <div class="ainbae-bacs-range-row">
                                    <input type="range" id="ainbae_bacs_br_range" min="0" max="40" value="<?php echo esc_attr($s['card_border_radius']); ?>" class="ainbae-bacs-range-slider">
                                    <input type="number" id="card_border_radius" name="card_border_radius" min="0" max="40" value="<?php echo esc_attr($s['card_border_radius']); ?>" class="ainbae-bacs-input ainbae-bacs-range-input">
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="ainbae-bacs-col">

                    <div class="ainbae-bacs-card">
                        <div class="ainbae-bacs-card-header" style="background:linear-gradient(135deg,#f0fdf4,#dcfce740);">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#0066ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <span><?php esc_html_e('Live Preview', 'ainbae-receipt-upload-for-woocommerce'); ?></span>
                        </div>
                        <div class="ainbae-bacs-card-body" style="padding:14px;">
                            <div id="ainbae-bacs-preview-container" style="pointer-events:none;user-select:none;"></div>
                            <p style="text-align:center;color:#aaa;font-size:11px;margin:8px 0 0;"><?php esc_html_e('Updates automatically as you change settings above', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
                        </div>
                    </div>

                    <div class="ainbae-bacs-card">
                        <div class="ainbae-bacs-card-header" style="background:linear-gradient(135deg,#fdf4ff,#f0abfc20);">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#9333ea" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="13.5" cy="6.5" r=".5" fill="#9333ea" />
                                <circle cx="17.5" cy="10.5" r=".5" fill="#9333ea" />
                                <circle cx="8.5" cy="7.5" r=".5" fill="#9333ea" />
                                <circle cx="6.5" cy="12.5" r=".5" fill="#9333ea" />
                                <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z" />
                            </svg>
                            <span><?php esc_html_e('Colours', 'ainbae-receipt-upload-for-woocommerce'); ?></span>
                        </div>
                        <div class="ainbae-bacs-card-body">
                            <p class="ainbae-bacs-section-title"><?php esc_html_e('Card', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
                            <?php ainbae_bacs_colour_field('color_card_bg', __('Background', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_card_border', __('Border', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>

                            <p class="ainbae-bacs-section-title" style="margin-top:16px;"><?php esc_html_e('Drop Zone', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
                            <?php ainbae_bacs_colour_field('color_dropzone_bg', __('Background', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_dropzone_border', __('Border', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_icon', __('Icon', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>

                            <p class="ainbae-bacs-section-title" style="margin-top:16px;"><?php esc_html_e('Upload Button', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
                            <?php ainbae_bacs_colour_field('color_upload_btn_from', __('Gradient Start', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_upload_btn_to', __('Gradient End', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_upload_btn_text', __('Text', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>

                            <p class="ainbae-bacs-section-title" style="margin-top:16px;"><?php esc_html_e('WhatsApp Button', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
                            <?php ainbae_bacs_colour_field('color_wa_btn_bg', __('Background', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_wa_btn_border', __('Border', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_wa_btn_text', __('Text', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>

                            <p class="ainbae-bacs-section-title" style="margin-top:16px;"><?php esc_html_e('Typography', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
                            <?php ainbae_bacs_colour_field('color_heading', __('Heading', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_subtitle', __('Subtitle', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_hint', __('Hint', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>

                            <p class="ainbae-bacs-section-title" style="margin-top:16px;"><?php esc_html_e('OR Divider', 'ainbae-receipt-upload-for-woocommerce'); ?></p>
                            <?php ainbae_bacs_colour_field('color_or_line', __('Line', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
                            <?php ainbae_bacs_colour_field('color_or_text', __('Text', 'ainbae-receipt-upload-for-woocommerce'), $s); ?>
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
            <input type="text" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($s[$key]); ?>" class="ainbae-bacs-color-picker" data-default-color="<?php echo esc_attr($s[$key]); ?>">
        </div>
    </div>
<?php
}

// ─────────────────────────────────────────────────────────────────────────────
// PRIVATE UPLOAD DIRECTORY
// ─────────────────────────────────────────────────────────────────────────────

function ainbae_bacs_get_private_upload_dir()
{
    $base = WP_CONTENT_DIR . '/bacs-receipts-private';
    if (! file_exists($base)) wp_mkdir_p($base);
    if (! file_exists($base . '/.htaccess')) file_put_contents($base . '/.htaccess', "# Block all direct HTTP access\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
    if (! file_exists($base . '/index.php')) file_put_contents($base . '/index.php', '<?php // Silence is golden.');
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

    /* translators: %1$s order number, %2$s currency, %3$s total */
    $wa_message = sprintf(
        __("Hello, I am sharing the payment receipt for my recent order.\n\n *Order Number:* %1\$s\n *Amount:* %2\$s %3\$s\n\n Please find the receipt attached below.", 'ainbae-receipt-upload-for-woocommerce'),
        $order->get_order_number(),
        $order->get_currency(),
        $order->get_total()
    );

    $wa_link = 'https://wa.me/' . esc_attr($wa_number) . '?text=' . rawurlencode($wa_message);

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
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="16 16 12 12 8 16"></polyline>
                    <line x1="12" y1="12" x2="12" y2="21"></line>
                    <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path>
                </svg>
                <input type="file" name="ainbae_bacs_receipt_file" id="ainbae_bacs_receipt_file" accept=".jpg,.jpeg,.png,.pdf" required>
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
                    <path d="M11.42 9.49c-.19-.09-1.1-.54-1.27-.61s-.29-.09-.42.1-.48.6-.59.73-.21.14-.4 0a5.13 5.13 0 0 1-1.49-.92 5.25 5.25 0 0 1-1-1.29c-.11-.18 0-.28.08-.38s.18-.21.28-.32a1.39 1.39 0 0 0 .18-.31.38.38 0 0 0 0-.33c0-.09-.42-1-.58-1.37s-.3-.32-.41-.32h-.4a.72.72 0 0 0-.5.23 2.1 2.1 0 0 0-.65 1.55A3.59 3.59 0 0 0 5 8.2 8.32 8.32 0 0 0 8.19 11c.44.19.78.3 1.05.39a2.53 2.53 0 0 0 1.17.07 1.93 1.93 0 0 0 1.26-.88 1.67 1.67 0 0 0 .11-.88c-.05-.07-.17-.12-.36-.21z" />
                    <path d="M13.29 2.68A7.36 7.36 0 0 0 8 .5a7.44 7.44 0 0 0-6.41 11.15l-1 3.85 3.94-1a7.4 7.4 0 0 0 3.55.9H8a7.44 7.44 0 0 0 5.29-12.72zM8 14.12a6.12 6.12 0 0 1-3.15-.87l-.22-.13-2.34.61.62-2.28-.14-.23a6.18 6.18 0 0 1 9.6-7.65 6.12 6.12 0 0 1 1.81 4.37A6.19 6.19 0 0 1 8 14.12z" />
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

    $order_id = isset($_POST['ainbae_bacs_order_id']) ? absint($_POST['ainbae_bacs_order_id']) : 0;

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

add_action('woocommerce_admin_order_data_after_order_details', 'ainbae_bacs_display_receipt_in_admin', 10, 1);

function ainbae_bacs_display_receipt_in_admin($order)
{
    if ($order->get_payment_method() !== 'bacs') return;

    $path = get_post_meta($order->get_id(), '_ainbae_bacs_receipt_path', true);
    $uploaded = get_post_meta($order->get_id(), '_ainbae_bacs_receipt_uploaded', true);

    echo '<br class="clear"><h3>' . esc_html__('Bank Transfer Receipt', 'ainbae-receipt-upload-for-woocommerce') . '</h3>';

    if ($path && file_exists($path)) {
        $url = wp_nonce_url(add_query_arg(array('action' => 'ainbae_bacs_view_receipt', 'order_id' => $order->get_id()), admin_url('admin-post.php')), 'ainbae_bacs_view_receipt_' . $order->get_id());
        echo '<p style="margin-top:10px;"><a href="' . esc_url($url) . '" target="_blank" class="button button-primary">' . esc_html__('View Uploaded Receipt', 'ainbae-receipt-upload-for-woocommerce') . '</a>';
        if ($uploaded) echo ' &nbsp;<small style="color:#666;">' . esc_html__('Uploaded:', 'ainbae-receipt-upload-for-woocommerce') . ' ' . esc_html($uploaded) . '</small>';
        echo '</p>';
    } else {
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

    // ── Bootstrap WP_Filesystem ───────────────────────────────────────────
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    // ── Validate path exists ──────────────────────────────────────────────
    if (! $path || ! $wp_filesystem->exists($path) || ! $wp_filesystem->is_file($path)) {
        wp_die(esc_html__('Receipt not found.', 'ainbae-receipt-upload-for-woocommerce'), 404);
    }

    // ── Path traversal protection ─────────────────────────────────────────
    $real_path = realpath($path);
    $real_dir  = realpath(ainbae_bacs_get_private_upload_dir());

    if (false === $real_path || false === $real_dir || strpos($real_path, $real_dir) !== 0) {
        wp_die(esc_html__('Access denied.', 'ainbae-receipt-upload-for-woocommerce'), 403);
    }

    // ── MIME type validation ──────────────────────────────────────────────
    if (! $mime || ! in_array($mime, ainbae_bacs_allowed_mimes(), true)) {
        wp_die(esc_html__('Invalid file type.', 'ainbae-receipt-upload-for-woocommerce'), 400);
    }

    // ── Read file via WP_Filesystem ───────────────────────────────────────
    $file_contents = $wp_filesystem->get_contents($real_path);

    if (false === $file_contents) {
        wp_die(esc_html__('Could not read the receipt file.', 'ainbae-receipt-upload-for-woocommerce'), 500);
    }

    // ── Serve file ────────────────────────────────────────────────────────
    $ext = pathinfo($real_path, PATHINFO_EXTENSION);

    nocache_headers();
    header('Content-Type: '        . sanitize_mime_type($mime));
    header('Content-Length: '      . strlen($file_contents));
    header('Content-Disposition: inline; filename="receipt-order-' . $order_id . '.' . esc_attr($ext) . '"');
    header('X-Content-Type-Options: nosniff');

    echo $file_contents; // phpcs:ignore WordPress.Security.EscapeOutput -- Binary file output (image/PDF), escaping would corrupt content.
    exit;
}
