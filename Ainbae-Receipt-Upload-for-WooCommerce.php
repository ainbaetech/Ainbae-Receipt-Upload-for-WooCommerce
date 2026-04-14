<?php
/**
 * Plugin Name: Ainbae Receipt Upload for WooCommerce
 * Plugin URI: https://www.ainbae.com
 * Description: Allows customers to upload bank transfer receipts on the order detail page.
 * Version:     2.1.7
 * Author: Ainbae
 * Author URI: https://www.ainbae.com
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────────────────────────────────────

define( 'BACS_MAX_UPLOAD_SIZE',   5 * 1024 * 1024 );
define( 'BACS_RATE_LIMIT_MAX',    5 );
define( 'BACS_RATE_LIMIT_WINDOW', HOUR_IN_SECONDS );
define( 'BACS_OPTION_KEY',        'bacs_receipt_settings' );

// ─────────────────────────────────────────────────────────────────────────────
// SETTINGS HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Default settings. Every key here is the canonical key stored in the DB.
 */
function bacs_default_settings() {
    return array(
        // WhatsApp
        'whatsapp_enabled'       => '1',
        'whatsapp_number'        => '1234567890',

        // Colours — Card
        'color_card_bg'          => '#f0f4f2',
        'color_card_border'      => '#d6e4dc',

        // Colours — Drop zone
        'color_dropzone_bg'      => '#ffffff',
        'color_dropzone_border'  => '#b0c8bc',
        'color_icon'             => '#0aa7ff',

        // Colours — Upload button
        'color_upload_btn_from'  => '#0aa7ff',
        'color_upload_btn_to'    => '#0aa7ff',
        'color_upload_btn_text'  => '#ffffff',

        // Colours — WhatsApp button
        'color_wa_btn_bg'        => '#e6f9ee',
        'color_wa_btn_border'    => '#a8dfc0',
        'color_wa_btn_text'      => '#1a7a3c',

        // Colours — Typography
        'color_heading'          => '#1a1a1a',
        'color_subtitle'         => '#555555',
        'color_hint'             => '#888888',

        // Colours — OR divider
        'color_or_line'          => '#d0ddd6',
        'color_or_text'          => '#999999',

        // Text / Labels
        'label_heading'          => 'Verify Your Payment',
        'label_subtitle'         => 'Please upload a screenshot of your transaction receipt, or send it directly via WhatsApp to process your order.',
        'label_dropzone'         => 'Click to upload, or drag and drop your receipt file',
        'label_upload_btn'       => 'Upload Receipt',
        'label_wa_btn'           => 'Send Receipt via WhatsApp',
        'label_hint'             => 'Allowed formats: JPG, PNG, PDF. Max size: 5 MB.',

        // Layout
        'card_border_radius'     => '16',
    );
}

/** Get one setting value, falling back to default. */
function bacs_setting( $key ) {
    $defaults = bacs_default_settings();
    $saved    = get_option( BACS_OPTION_KEY, array() );
    $all      = array_merge( $defaults, (array) $saved );
    return $all[ $key ] ?? ( $defaults[ $key ] ?? '' );
}

/** WhatsApp number — respects wp-config constant for backward compat. */
function bacs_get_whatsapp_number() {
    $number = defined( 'BACS_WHATSAPP_NUMBER' ) ? BACS_WHATSAPP_NUMBER : bacs_setting( 'whatsapp_number' );
    return apply_filters( 'bacs_receipt_whatsapp_number', $number );
}

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN — MENU
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'bacs_register_menu' );

function bacs_register_menu() {
    add_submenu_page(
        'woocommerce',
        'Ainbae Receipt Upload Settings',
        'Upload Receipt',
        'manage_woocommerce',
        'ainbae-receipt-settings',
        'ainbae_render_settings_page'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN — SAVE SETTINGS
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_init', 'bacs_save_settings' );

function bacs_save_settings() {
    if (
        ! isset( $_POST['bacs_settings_nonce'] )
        || ! wp_verify_nonce( $_POST['bacs_settings_nonce'], 'bacs_save_settings_action' )
        || ! current_user_can( 'manage_woocommerce' )
        || ! isset( $_POST['bacs_save_settings'] )
    ) {
        return;
    }

    $defaults  = bacs_default_settings();
    $sanitized = array();

    // Colour fields
    $colour_keys = array(
        'color_card_bg', 'color_card_border',
        'color_dropzone_bg', 'color_dropzone_border', 'color_icon',
        'color_upload_btn_from', 'color_upload_btn_to', 'color_upload_btn_text',
        'color_wa_btn_bg', 'color_wa_btn_border', 'color_wa_btn_text',
        'color_heading', 'color_subtitle', 'color_hint',
        'color_or_line', 'color_or_text',
    );
    foreach ( $colour_keys as $key ) {
        $val = isset( $_POST[ $key ] ) ? sanitize_hex_color( wp_unslash( $_POST[ $key ] ) ) : '';
        $sanitized[ $key ] = $val ?: $defaults[ $key ];
    }

    // Text labels
    foreach ( array( 'label_heading', 'label_subtitle', 'label_dropzone', 'label_upload_btn', 'label_wa_btn', 'label_hint' ) as $key ) {
        $sanitized[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $defaults[ $key ];
    }

    // WhatsApp
    $sanitized['whatsapp_enabled'] = isset( $_POST['whatsapp_enabled'] ) ? '1' : '0';
    $sanitized['whatsapp_number']  = isset( $_POST['whatsapp_number'] )
        ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['whatsapp_number'] ) )
        : $defaults['whatsapp_number'];

    // Border radius
    $sanitized['card_border_radius'] = isset( $_POST['card_border_radius'] ) ? absint( $_POST['card_border_radius'] ) : $defaults['card_border_radius'];

    update_option( BACS_OPTION_KEY, $sanitized );

    wp_safe_redirect( add_query_arg( array( 'page' => 'ainbae-receipt-settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN — ENQUEUE COLOUR PICKER
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'bacs_enqueue_admin_assets' );

function bacs_enqueue_admin_assets( $hook ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'ainbae-receipt-settings' ) {
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN — SETTINGS PAGE RENDER
// ─────────────────────────────────────────────────────────────────────────────

function ainbae_render_settings_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Access denied.' );
    }

    // Load all settings into $s shorthand.
    $s = array();
    foreach ( array_keys( bacs_default_settings() ) as $key ) {
        $s[ $key ] = bacs_setting( $key );
    }
    ?>
    <div class="wrap" id="bacs-settings-wrap">
    <div style="margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid #e0e0e0;">
        <img src="<?php echo esc_url( plugins_url( 'admin/images/ainbae-logo.png', __FILE__ ) ); ?>" alt="Ainbae Logo" style="height:60px; width:auto; object-fit:contain;" onerror="this.style.display='none';">
            <p style="margin:0;color:#666;font-size:13px;">Customise the payment receipt widget shown to customers</p>
    </div>

    <?php if ( isset( $_GET['updated'] ) ) : ?>
    <div class="notice notice-success is-dismissible" style="border-left-color:#0aa7ff;margin-bottom:20px;">
        <p><strong>&#10003; Settings saved successfully.</strong></p>
    </div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'bacs_save_settings_action', 'bacs_settings_nonce' ); ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;max-width:1100px;align-items:start;">

            <div style="display:flex;flex-direction:column;gap:22px;">

                <div class="bacs-card">
                    <div class="bacs-card-header" style="background:linear-gradient(135deg,#dcfce7,#bbf7d040);">
                        <svg fill="#25d366" width="17" height="17" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M11.42 9.49c-.19-.09-1.1-.54-1.27-.61s-.29-.09-.42.1-.48.6-.59.73-.21.14-.4 0a5.13 5.13 0 0 1-1.49-.92 5.25 5.25 0 0 1-1-1.29c-.11-.18 0-.28.08-.38s.18-.21.28-.32a1.39 1.39 0 0 0 .18-.31.38.38 0 0 0 0-.33c0-.09-.42-1-.58-1.37s-.3-.32-.41-.32h-.4a.72.72 0 0 0-.5.23 2.1 2.1 0 0 0-.65 1.55A3.59 3.59 0 0 0 5 8.2 8.32 8.32 0 0 0 8.19 11c.44.19.78.3 1.05.39a2.53 2.53 0 0 0 1.17.07 1.93 1.93 0 0 0 1.26-.88 1.67 1.67 0 0 0 .11-.88c-.05-.07-.17-.12-.36-.21z"/><path d="M13.29 2.68A7.36 7.36 0 0 0 8 .5a7.44 7.44 0 0 0-6.41 11.15l-1 3.85 3.94-1a7.4 7.4 0 0 0 3.55.9H8a7.44 7.44 0 0 0 5.29-12.72zM8 14.12a6.12 6.12 0 0 1-3.15-.87l-.22-.13-2.34.61.62-2.28-.14-.23a6.18 6.18 0 0 1 9.6-7.65 6.12 6.12 0 0 1 1.81 4.37A6.19 6.19 0 0 1 8 14.12z"/></svg>
                        <span>WhatsApp</span>
                    </div>
                    <div class="bacs-card-body">
                        <div class="bacs-field bacs-field-toggle">
                            <div>
                                <label class="bacs-label">Enable WhatsApp Button</label>
                                <p class="bacs-desc">Show a "Send via WhatsApp" button below the upload form</p>
                            </div>
                            <label class="bacs-toggle">
                                <input type="checkbox" name="whatsapp_enabled" value="1" <?php checked( $s['whatsapp_enabled'], '1' ); ?>>
                                <span class="bacs-toggle-slider"></span>
                            </label>
                        </div>
                        <div class="bacs-field" id="bacs-wa-number-row" <?php echo $s['whatsapp_enabled'] !== '1' ? 'style="opacity:.4;pointer-events:none;"' : ''; ?>>
                            <label class="bacs-label" for="whatsapp_number">WhatsApp Number</label>
                            <p class="bacs-desc">Include country code, digits only (e.g. 1234567890)</p>
                            <div style="position:relative;">
                                <span style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#888;font-size:14px;">+</span>
                                <input type="text" id="whatsapp_number" name="whatsapp_number"
                                       value="<?php echo esc_attr( $s['whatsapp_number'] ); ?>"
                                       placeholder="1234567890" class="bacs-input" style="padding-left:22px;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bacs-card">
                    <div class="bacs-card-header" style="background:linear-gradient(135deg,#eff6ff,#dbeafe40);">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <span>Text &amp; Labels</span>
                    </div>
                    <div class="bacs-card-body">
                        <?php
                        $labels = array(
                            'label_heading'    => array( 'Heading',         'Main title at the top of the widget' ),
                            'label_subtitle'   => array( 'Subtitle',        'Instruction text below the heading' ),
                            'label_dropzone'   => array( 'Drop Zone Text',  'Text inside the file drop area' ),
                            'label_upload_btn' => array( 'Upload Button',   'Label on the upload button' ),
                            'label_wa_btn'     => array( 'WhatsApp Button', 'Label on the WhatsApp button' ),
                            'label_hint'       => array( 'Hint Text',       'Small text below the upload button' ),
                        );
                        foreach ( $labels as $key => list( $title, $desc ) ) :
                        ?>
                        <div class="bacs-field">
                            <label class="bacs-label" for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $title ); ?></label>
                            <p class="bacs-desc"><?php echo esc_html( $desc ); ?></p>
                            <input type="text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
                                   value="<?php echo esc_attr( $s[ $key ] ); ?>" class="bacs-input">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bacs-card">
                    <div class="bacs-card-header" style="background:linear-gradient(135deg,#fff7ed,#fed7aa40);">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                        <span>Layout</span>
                    </div>
                    <div class="bacs-card-body">
                        <div class="bacs-field-range">
                            <label class="bacs-label" for="card_border_radius">Card Corner Radius (px)</label>
                            <p class="bacs-desc">Roundness of the outer card corners (0 = square, 40 = pill)</p>
                            <div class="bacs-range-row">
                                <input type="range" id="bacs_br_range" min="0" max="40"
                                       value="<?php echo esc_attr( $s['card_border_radius'] ); ?>"
                                       class="bacs-range-slider">
                                <input type="number" id="card_border_radius" name="card_border_radius"
                                       min="0" max="40"
                                       value="<?php echo esc_attr( $s['card_border_radius'] ); ?>"
                                       class="bacs-input bacs-range-input">
                            </div>
                        </div>
                    </div>
                </div>

            </div><div style="display:flex;flex-direction:column;gap:22px;">

                <div class="bacs-card">
                    <div class="bacs-card-header" style="background:linear-gradient(135deg,#f0fdf4,#dcfce740);">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#0066ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <span>Live Preview</span>
                    </div>
                    <div class="bacs-card-body" style="padding:14px;">
                        <div id="bacs-preview-container" style="pointer-events:none;user-select:none;"></div>
                        <p style="text-align:center;color:#aaa;font-size:11px;margin:8px 0 0;">Updates automatically as you change settings above</p>
                    </div>
                </div>
                
                <div class="bacs-card">
                    <div class="bacs-card-header" style="background:linear-gradient(135deg,#fdf4ff,#f0abfc20);">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#9333ea" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="#9333ea"/><circle cx="17.5" cy="10.5" r=".5" fill="#9333ea"/><circle cx="8.5" cy="7.5" r=".5" fill="#9333ea"/><circle cx="6.5" cy="12.5" r=".5" fill="#9333ea"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
                        <span>Colours</span>
                    </div>
                    <div class="bacs-card-body">

                        <p class="bacs-section-title">Card</p>
                        <?php bacs_colour_field( 'color_card_bg',    'Background', $s ); ?>
                        <?php bacs_colour_field( 'color_card_border', 'Border',    $s ); ?>

                        <p class="bacs-section-title" style="margin-top:16px;">Drop Zone</p>
                        <?php bacs_colour_field( 'color_dropzone_bg',     'Background', $s ); ?>
                        <?php bacs_colour_field( 'color_dropzone_border', 'Border',     $s ); ?>
                        <?php bacs_colour_field( 'color_icon',            'Icon',       $s ); ?>

                        <p class="bacs-section-title" style="margin-top:16px;">Upload Button</p>
                        <?php bacs_colour_field( 'color_upload_btn_from', 'Gradient Start', $s ); ?>
                        <?php bacs_colour_field( 'color_upload_btn_to',   'Gradient End',   $s ); ?>
                        <?php bacs_colour_field( 'color_upload_btn_text', 'Text',           $s ); ?>

                        <p class="bacs-section-title" style="margin-top:16px;">WhatsApp Button</p>
                        <?php bacs_colour_field( 'color_wa_btn_bg',    'Background', $s ); ?>
                        <?php bacs_colour_field( 'color_wa_btn_border','Border',     $s ); ?>
                        <?php bacs_colour_field( 'color_wa_btn_text',  'Text',       $s ); ?>

                        <p class="bacs-section-title" style="margin-top:16px;">Typography</p>
                        <?php bacs_colour_field( 'color_heading',  'Heading',  $s ); ?>
                        <?php bacs_colour_field( 'color_subtitle', 'Subtitle', $s ); ?>
                        <?php bacs_colour_field( 'color_hint',     'Hint',     $s ); ?>

                        <p class="bacs-section-title" style="margin-top:16px;">OR Divider</p>
                        <?php bacs_colour_field( 'color_or_line', 'Line', $s ); ?>
                        <?php bacs_colour_field( 'color_or_text', 'Text', $s ); ?>

                    </div>
                </div>
            </div></div><div style="position:sticky;bottom:0;z-index:100;margin-top:24px;max-width:1100px;padding:14px 20px;background:#fff;border-top:1px solid #e5e7eb;box-shadow:0 -3px 14px rgba(0,0,0,.07);display:flex;align-items:center;justify-content:space-between;border-radius:12px 12px 0 0;">
            <span style="font-size:13px;color:#777;">Changes apply to all customers immediately after saving.</span>
            <button type="submit" name="bacs_save_settings" style="background:linear-gradient(135deg,#0aa7ff,#0066ff);color:#fff;border:none;border-radius:8px;padding:10px 30px;font-size:14px;font-weight:600;cursor:pointer;box-shadow:0 2px 8px rgba(22,163,74,.4);transition:opacity .2s;">
                Save Settings
            </button>
        </div>

    </form>
    </div><style>
    /* Safely target elements to avoid breaking WordPress native UI (like wp-color-picker) */
    #bacs-settings-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    
    /* Only apply box-sizing to our specific custom elements */
    .bacs-card, .bacs-card-header, .bacs-card-body, .bacs-field, .bacs-input, .bacs-range-row { 
        box-sizing: border-box; 
    }

    /* Card styling */
    .bacs-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:visible; box-shadow:0 1px 4px rgba(0,0,0,.05); }
    .bacs-card-header { display:flex; align-items:center; gap:9px; padding:13px 18px; font-weight:600; font-size:13px; color:#1a1a1a; border-bottom:1px solid #e5e7eb; border-top-left-radius: 11px; border-top-right-radius: 11px; }
    .bacs-card-body { padding:16px 18px; }

    .bacs-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#999; margin:0 0 8px; }

    .bacs-field { margin-bottom:14px; }
    .bacs-field:last-child { margin-bottom:0; }
    .bacs-field-toggle { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }

    .bacs-label { display:block; font-weight:600; font-size:13px; color:#1a1a1a; margin-bottom:3px; }
    .bacs-desc  { margin:0 0 6px; color:#999; font-size:12px; line-height:1.4; }

    .bacs-input { width:100%; border:1px solid #d1d5db; border-radius:6px; padding:7px 10px; font-size:13px; color:#1a1a1a; transition:border-color .15s; }
    .bacs-input:focus { outline:none; border-color:#0aa7ff; box-shadow:0 0 0 3px rgba(34,197,94,.12); }

    /* Layout rules for the color row */
    .bacs-colour-row { display:flex; align-items:center; justify-content:space-between; padding:7px 0; border-bottom:1px solid #f3f4f6; }
    .bacs-colour-row:last-child { border-bottom:none; }
    .bacs-colour-label { font-size:13px; color:#374151; }

    /* Range styles */
    .bacs-field-range { margin-bottom: 14px; }
    .bacs-range-row { display: flex; align-items: center; gap: 12px; }
    .bacs-range-slider { flex: 1; accent-color: #0aa7ff; cursor: pointer; }
    .bacs-range-input.bacs-input { width: 64px; text-align: center; font-weight: 600; color: #1a1a1a; }

    /* Toggle */
    .bacs-toggle { position:relative; display:inline-block; width:46px; height:26px; flex-shrink:0; }
    .bacs-toggle input { opacity:0; width:0; height:0; }
    .bacs-toggle-slider { position:absolute; cursor:pointer; inset:0; background:#d1d5db; border-radius:26px; transition:.25s; }
    .bacs-toggle-slider::before { content:''; position:absolute; height:20px; width:20px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.25s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
    .bacs-toggle input:checked + .bacs-toggle-slider { background:#0aa7ff; }
    .bacs-toggle input:checked + .bacs-toggle-slider::before { transform:translateX(20px); }
    </style>

    <script>
    jQuery(document).ready(function($) {

        // ── Colour pickers ──────────────────────────────────────────────────
        $('.bacs-color-picker').wpColorPicker({
            change: function(e, ui) {
                // Update the input value immediately so the preview can catch it
                $(e.target).val(ui.color.toString());
                schedulePreview();
            },
            clear: function() {
                schedulePreview();
            }
        });

        // ── WhatsApp toggle ─────────────────────────────────────────────────
        $('input[name="whatsapp_enabled"]').on('change', function(){
            var row = $('#bacs-wa-number-row');
            if (this.checked) { row.css({opacity:'1', pointerEvents:'auto'}); }
            else              { row.css({opacity:'.4', pointerEvents:'none'}); }
            schedulePreview();
        });

        // ── Border radius slider synchronization ────────────────────────────
        $('#bacs_br_range').on('input', function(){
            $('#card_border_radius').val(this.value);
            schedulePreview();
        });
        $('#card_border_radius').on('input', function(){
            $('#bacs_br_range').val(this.value);
            schedulePreview();
        });

        // ── Label fields ────────────────────────────────────────────────────
        $('[name^="label_"]').on('input', schedulePreview);

        // ── Preview ─────────────────────────────────────────────────────────
        var previewTimer;
        function schedulePreview() { 
            clearTimeout(previewTimer); 
            previewTimer = setTimeout(buildPreview, 80); 
        }

        function g(name) {
            var el = document.querySelector('[name="'+name+'"]');
            if (!el) return '';
            return el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        }

        function h(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

        function buildPreview() {
            var container = document.getElementById('bacs-preview-container');
            if (!container) return;

            var wa = g('whatsapp_enabled') === '1';
            var rawBr = g('card_border_radius');
            var br = (parseInt(rawBr, 10) || 0) + 'px';

            var html = '<div style="background:'+g('color_card_bg')+';border:1px solid '+g('color_card_border')+';border-radius:'+br+';padding:20px 16px;font-family:inherit;">' +

            '<h3 style="text-align:center;margin:0 0 6px;font-size:16px;font-weight:700;color:'+g('color_heading')+';">'+h(g('label_heading'))+'</h3>' +
            '<p style="text-align:center;color:'+g('color_subtitle')+';font-size:12px;margin:0 0 14px;">'+h(g('label_subtitle'))+'</p>' +

            '<div style="border:2px dashed '+g('color_dropzone_border')+';border-radius:8px;background:'+g('color_dropzone_bg')+';padding:18px 12px;text-align:center;margin-bottom:10px;">'+
              '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="'+g('color_icon')+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 6px;"><polyline points="16 16 12 12 8 16"></polyline><line x1="12" y1="12" x2="12" y2="21"></line><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path></svg>' +
              '<div style="font-size:12px;color:#555;">'+h(g('label_dropzone'))+'</div>' +
            '</div>' +

            '<div style="padding:10px;border-radius:6px;text-align:center;background:linear-gradient(90deg,'+g('color_upload_btn_from')+','+g('color_upload_btn_to')+');color:'+g('color_upload_btn_text')+';font-weight:700;font-size:12px;letter-spacing:.06em;text-transform:uppercase;margin-bottom:5px;">'+h(g('label_upload_btn'))+'</div>' +
            '<p style="text-align:center;font-size:11px;color:'+g('color_hint')+';margin:0 0 8px;">'+h(g('label_hint'))+'</p>';

            if (wa) {
                html +=
                '<div style="display:flex;align-items:center;gap:8px;margin:10px 0;">'+
                  '<div style="flex:1;border-bottom:1px solid '+g('color_or_line')+'"></div>'+
                  '<span style="color:'+g('color_or_text')+';font-size:11px;">OR</span>'+
                  '<div style="flex:1;border-bottom:1px solid '+g('color_or_line')+'"></div>'+
                '</div>'+
                '<div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;border-radius:6px;background:'+g('color_wa_btn_bg')+';border:1.5px solid '+g('color_wa_btn_border')+';color:'+g('color_wa_btn_text')+';font-weight:700;font-size:12px;letter-spacing:.06em;text-transform:uppercase;">'+
                  '<svg fill="currentColor" width="14" height="14" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M11.42 9.49c-.19-.09-1.1-.54-1.27-.61s-.29-.09-.42.1-.48.6-.59.73-.21.14-.4 0a5.13 5.13 0 0 1-1.49-.92 5.25 5.25 0 0 1-1-1.29c-.11-.18 0-.28.08-.38s.18-.21.28-.32a1.39 1.39 0 0 0 .18-.31.38.38 0 0 0 0-.33c0-.09-.42-1-.58-1.37s-.3-.32-.41-.32h-.4a.72.72 0 0 0-.5.23 2.1 2.1 0 0 0-.65 1.55A3.59 3.59 0 0 0 5 8.2 8.32 8.32 0 0 0 8.19 11c.44.19.78.3 1.05.39a2.53 2.53 0 0 0 1.17.07 1.93 1.93 0 0 0 1.26-.88 1.67 1.67 0 0 0 .11-.88c-.05-.07-.17-.12-.36-.21z"/><path d="M13.29 2.68A7.36 7.36 0 0 0 8 .5a7.44 7.44 0 0 0-6.41 11.15l-1 3.85 3.94-1a7.4 7.4 0 0 0 3.55.9H8a7.44 7.44 0 0 0 5.29-12.72zM8 14.12a6.12 6.12 0 0 1-3.15-.87l-.22-.13-2.34.61.62-2.28-.14-.23a6.18 6.18 0 0 1 9.6-7.65 6.12 6.12 0 0 1 1.81 4.37A6.19 6.19 0 0 1 8 14.12z"/></svg>'+
                  h(g('label_wa_btn'))+
                '</div>';
            }

            html += '</div>';
            container.innerHTML = html;
        }

        // Initial render after pickers initialise
        setTimeout(buildPreview, 300);

    });
    </script>
    <?php
}

/** Helper: render one colour picker row. */
function bacs_colour_field( $key, $label, $s ) {
    ?>
    <div class="bacs-colour-row">
        <span class="bacs-colour-label"><?php echo esc_html( $label ); ?></span>
        <div class="bacs-colour-right">
            <input type="text"
                   name="<?php echo esc_attr( $key ); ?>"
                   value="<?php echo esc_attr( $s[ $key ] ); ?>"
                   class="bacs-color-picker"
                   data-default-color="<?php echo esc_attr( $s[ $key ] ); ?>">
        </div>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// PRIVATE UPLOAD DIRECTORY
// ─────────────────────────────────────────────────────────────────────────────

function bacs_get_private_upload_dir() {
    $base = WP_CONTENT_DIR . '/bacs-receipts-private';

    if ( ! file_exists( $base ) ) {
        wp_mkdir_p( $base );
    }

    if ( ! file_exists( $base . '/.htaccess' ) ) {
        file_put_contents(
            $base . '/.htaccess',
            "# Block all direct HTTP access\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
        );
    }

    if ( ! file_exists( $base . '/index.php' ) ) {
        file_put_contents( $base . '/index.php', '<?php // Silence is golden.' );
    }

    return trailingslashit( $base );
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────

function bacs_allowed_mimes() {
    return array(
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'pdf'          => 'application/pdf',
    );
}

function bacs_current_user_can_access_order( $order, $order_key = '' ) {
    $uid = get_current_user_id();
    if ( $uid && $uid === $order->get_customer_id() ) return true;
    if ( ! empty( $order_key ) && hash_equals( $order->get_order_key(), $order_key ) ) return true;
    return false;
}

function bacs_rate_limit_exceeded( $user_key ) {
    $tk    = 'bacs_rl_' . md5( $user_key );
    $count = (int) get_transient( $tk );
    if ( $count >= BACS_RATE_LIMIT_MAX ) return true;
    set_transient( $tk, $count + 1, BACS_RATE_LIMIT_WINDOW );
    return false;
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 1 — FRONTEND FORM
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_order_details_before_order_table', 'bacs_receipt_upload_form', 10, 1 );

function bacs_receipt_upload_form( $order ) {
    if ( $order->get_payment_method() !== 'bacs' ) return;

    $status = $order->get_status();
    if ( in_array( $status, array( 'completed', 'cancelled', 'refunded', 'failed', 'delivered' ), true ) ) return;

    if ( $status === 'processing' ) {
        echo '<div class="woocommerce-message" style="margin-bottom:30px;border-top-color:#2e7d32;"><strong>Payment Verified.</strong> Your order is being processed.</div>';
        return;
    }

    $order_id  = $order->get_id();
    $order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';

    if ( ! bacs_current_user_can_access_order( $order, $order_key ) ) return;

    if ( get_post_meta( $order_id, '_bacs_receipt_path', true ) ) {
        echo '<div class="woocommerce-info" style="margin-bottom:30px;"><strong>Payment Receipt Uploaded.</strong> We will verify your payment shortly.</div>';
        return;
    }

    $wa_enabled = bacs_setting( 'whatsapp_enabled' ) === '1';
    $wa_number  = bacs_get_whatsapp_number();
    $wa_message = "Hello, I am sharing the payment receipt for my recent order.\n\n"
                . "*Order Number:* " . $order->get_order_number() . "\n"
                . "*Amount:* " . $order->get_currency() . ' ' . $order->get_total() . "\n\n"
                . "Please find the receipt attached below.";
    $wa_link = 'https://wa.me/' . esc_attr( $wa_number ) . '?text=' . rawurlencode( $wa_message );
    $br      = absint( bacs_setting( 'card_border_radius' ) ) . 'px';
    ?>
    <style>
    .bacs-upload-wrap{margin-bottom:32px;background:<?php echo esc_attr(bacs_setting('color_card_bg')); ?>;border:1px solid <?php echo esc_attr(bacs_setting('color_card_border')); ?>;border-radius:<?php echo esc_attr($br); ?>;padding:32px 28px 28px;}
    .bacs-upload-wrap h3{text-align:center;font-size:22px;font-weight:700;color:<?php echo esc_attr(bacs_setting('color_heading')); ?>;margin:0 0 8px;}
    .bacs-subtitle{text-align:center;color:<?php echo esc_attr(bacs_setting('color_subtitle')); ?>;font-size:14px;margin:0 0 20px;}
    .bacs-dropzone{border:2px dashed <?php echo esc_attr(bacs_setting('color_dropzone_border')); ?>;border-radius:10px;background:<?php echo esc_attr(bacs_setting('color_dropzone_bg')); ?>;padding:28px 20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;margin-bottom:14px;position:relative;}
    .bacs-dropzone:hover,.bacs-dropzone.bacs-drag-over{border-color:<?php echo esc_attr(bacs_setting('color_upload_btn_from')); ?>;}
    .bacs-dropzone input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
    .bacs-dropzone svg{display:block;margin:0 auto 10px;color:<?php echo esc_attr(bacs_setting('color_icon')); ?>;}
    .bacs-dropzone-label{font-size:14px;color:#444;pointer-events:none;}
    .bacs-file-chosen{font-size:13px;color:<?php echo esc_attr(bacs_setting('color_upload_btn_from')); ?>;font-weight:600;margin-top:6px;}
    .bacs-btn-upload{display:block;width:100%;padding:14px;background:linear-gradient(90deg,<?php echo esc_attr(bacs_setting('color_upload_btn_from')); ?> 0%,<?php echo esc_attr(bacs_setting('color_upload_btn_to')); ?> 100%);color:<?php echo esc_attr(bacs_setting('color_upload_btn_text')); ?> !important;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border:none;border-radius:8px;cursor:pointer;box-shadow:0 3px 10px rgba(0,0,0,.15);margin-bottom:6px;transition:opacity .2s,transform .1s;}
    .bacs-btn-upload:hover{opacity:.9;transform:translateY(-1px);}
    .bacs-upload-hint{text-align:center;font-size:12px;color:<?php echo esc_attr(bacs_setting('color_hint')); ?>;margin:0 0 16px;}
    .bacs-or{display:flex;align-items:center;gap:10px;margin:16px 0;color:<?php echo esc_attr(bacs_setting('color_or_text')); ?>;font-size:13px;}
    .bacs-or::before,.bacs-or::after{content:'';flex:1;border-bottom:1px solid <?php echo esc_attr(bacs_setting('color_or_line')); ?>;}
    .bacs-btn-wa{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:13px;background:<?php echo esc_attr(bacs_setting('color_wa_btn_bg')); ?>;color:<?php echo esc_attr(bacs_setting('color_wa_btn_text')); ?> !important;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border:1.5px solid <?php echo esc_attr(bacs_setting('color_wa_btn_border')); ?>;border-radius:8px;text-decoration:none !important;transition:background .2s;}
    </style>

    <div class="bacs-upload-wrap">
        <h3><?php echo esc_html( bacs_setting('label_heading') ); ?></h3>
        <p class="bacs-subtitle"><?php echo esc_html( bacs_setting('label_subtitle') ); ?></p>

        <form action="" method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'bacs_upload_receipt_' . $order_id, 'bacs_receipt_nonce' ); ?>
            <input type="hidden" name="bacs_order_id"  value="<?php echo esc_attr( $order_id ); ?>">
            <input type="hidden" name="bacs_order_key" value="<?php echo esc_attr( $order_key ); ?>">

            <div class="bacs-dropzone" id="bacs-dropzone">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="16 16 12 12 8 16"></polyline><line x1="12" y1="12" x2="12" y2="21"></line><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path></svg>
                <input type="file" name="bacs_receipt_file" id="bacs_receipt_file" accept=".jpg,.jpeg,.png,.pdf" required>
                <div class="bacs-dropzone-label">
                    <?php echo esc_html( bacs_setting('label_dropzone') ); ?>
                    <span id="bacs-file-name"></span>
                </div>
            </div>

            <button type="submit" name="submit_bacs_receipt" class="bacs-btn-upload">
            <?php echo esc_html( bacs_setting('label_upload_btn') ); ?>
            </button>
            <p class="bacs-upload-hint"><?php echo esc_html( bacs_setting('label_hint') ); ?></p>
        </form>

        <?php if ( $wa_enabled ) : ?>
        <div class="bacs-or">OR</div>
        <a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener noreferrer" class="bacs-btn-wa">
            <svg fill="currentColor" width="20" height="20" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M11.42 9.49c-.19-.09-1.1-.54-1.27-.61s-.29-.09-.42.1-.48.6-.59.73-.21.14-.4 0a5.13 5.13 0 0 1-1.49-.92 5.25 5.25 0 0 1-1-1.29c-.11-.18 0-.28.08-.38s.18-.21.28-.32a1.39 1.39 0 0 0 .18-.31.38.38 0 0 0 0-.33c0-.09-.42-1-.58-1.37s-.3-.32-.41-.32h-.4a.72.72 0 0 0-.5.23 2.1 2.1 0 0 0-.65 1.55A3.59 3.59 0 0 0 5 8.2 8.32 8.32 0 0 0 8.19 11c.44.19.78.3 1.05.39a2.53 2.53 0 0 0 1.17.07 1.93 1.93 0 0 0 1.26-.88 1.67 1.67 0 0 0 .11-.88c-.05-.07-.17-.12-.36-.21z"/><path d="M13.29 2.68A7.36 7.36 0 0 0 8 .5a7.44 7.44 0 0 0-6.41 11.15l-1 3.85 3.94-1a7.4 7.4 0 0 0 3.55.9H8a7.44 7.44 0 0 0 5.29-12.72zM8 14.12a6.12 6.12 0 0 1-3.15-.87l-.22-.13-2.34.61.62-2.28-.14-.23a6.18 6.18 0 0 1 9.6-7.65 6.12 6.12 0 0 1 1.81 4.37A6.19 6.19 0 0 1 8 14.12z"/></svg>
            <?php echo esc_html( bacs_setting('label_wa_btn') ); ?>
        </a>
        <?php endif; ?>
    </div>

    <script>
    (function(){
        var input=document.getElementById('bacs_receipt_file'),zone=document.getElementById('bacs-dropzone'),label=document.getElementById('bacs-file-name');
        if(!input||!zone||!label)return;
        input.addEventListener('change',function(){ if(this.files&&this.files[0]){label.textContent=this.files[0].name;label.className='bacs-file-chosen';} });
        zone.addEventListener('dragover',function(e){e.preventDefault();zone.classList.add('bacs-drag-over');});
        zone.addEventListener('dragleave',function(){zone.classList.remove('bacs-drag-over');});
        zone.addEventListener('drop',function(e){e.preventDefault();zone.classList.remove('bacs-drag-over');if(e.dataTransfer.files&&e.dataTransfer.files[0]){input.files=e.dataTransfer.files;label.textContent=e.dataTransfer.files[0].name;label.className='bacs-file-chosen';}});
    })();
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 2 — PROCESS UPLOAD
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'template_redirect', 'bacs_process_receipt_upload' );

function bacs_process_receipt_upload() {
    if ( ! isset( $_POST['submit_bacs_receipt'] ) || ! isset( $_FILES['bacs_receipt_file'] ) ) return;

    $order_id = isset( $_POST['bacs_order_id'] ) ? absint( $_POST['bacs_order_id'] ) : 0;
    if ( ! $order_id || ! isset( $_POST['bacs_receipt_nonce'] ) || ! wp_verify_nonce( $_POST['bacs_receipt_nonce'], 'bacs_upload_receipt_' . $order_id ) ) {
        wc_add_notice( 'Security check failed. Please refresh the page and try again.', 'error' ); return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_payment_method() !== 'bacs' ) { wc_add_notice( 'Invalid order.', 'error' ); return; }

    $order_key = isset( $_POST['bacs_order_key'] ) ? wc_clean( wp_unslash( $_POST['bacs_order_key'] ) ) : '';
    if ( ! bacs_current_user_can_access_order( $order, $order_key ) ) { wc_add_notice( 'You do not have permission to upload a receipt for this order.', 'error' ); return; }

    if ( in_array( $order->get_status(), array( 'completed', 'processing', 'cancelled', 'refunded', 'failed', 'delivered' ), true ) ) {
        wc_add_notice( 'A receipt cannot be uploaded for this order in its current status.', 'error' ); return;
    }

    if ( get_post_meta( $order_id, '_bacs_receipt_path', true ) ) {
        wc_add_notice( 'A receipt has already been uploaded for this order.', 'error' ); return;
    }

    $actor = get_current_user_id() ? 'user_' . get_current_user_id() : 'ip_' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
    if ( bacs_rate_limit_exceeded( $actor ) ) { wc_add_notice( 'Too many upload attempts. Please wait before trying again.', 'error' ); return; }

    $file = $_FILES['bacs_receipt_file']; // phpcs:ignore
    if ( $file['error'] !== UPLOAD_ERR_OK )   { wc_add_notice( 'Upload error (code ' . (int) $file['error'] . ').', 'error' ); return; }
    if ( $file['size'] > BACS_MAX_UPLOAD_SIZE ){ wc_add_notice( 'File is too large. Maximum size is 5 MB.', 'error' ); return; }

    $allowed_mimes = bacs_allowed_mimes();
    if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
    $file_info = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
    if ( empty( $file_info['ext'] ) || empty( $file_info['type'] ) ) { wc_add_notice( 'Invalid file type. Only JPG, PNG, and PDF are accepted.', 'error' ); return; }

    $dest = bacs_get_private_upload_dir() . wp_generate_uuid4() . '.' . $file_info['ext'];
    if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) { wc_add_notice( 'Could not save file. Please try again.', 'error' ); return; }

    update_post_meta( $order_id, '_bacs_receipt_path',     $dest );
    update_post_meta( $order_id, '_bacs_receipt_mime',     sanitize_mime_type( $file_info['type'] ) );
    update_post_meta( $order_id, '_bacs_receipt_uploaded', current_time( 'mysql' ) );
    $order->add_order_note( sprintf( 'Customer uploaded a bank transfer receipt on %s. Use the admin panel to view it securely.', current_time( 'mysql' ) ) );

    wc_add_notice( 'Receipt uploaded successfully. Thank you! We will verify your payment shortly.', 'success' );
    wp_safe_redirect( $order->get_view_order_url() );
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 3 — ADMIN ORDER PANEL & SECURE FILE VIEWER
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_admin_order_data_after_order_details', 'bacs_display_receipt_in_admin', 10, 1 );

function bacs_display_receipt_in_admin( $order ) {
    if ( $order->get_payment_method() !== 'bacs' ) return;

    $path = get_post_meta( $order->get_id(), '_bacs_receipt_path', true );
    $uploaded = get_post_meta( $order->get_id(), '_bacs_receipt_uploaded', true );

    echo '<br class="clear"><h3>Bank Transfer Receipt</h3>';

    if ( $path && file_exists( $path ) ) {
        $url = wp_nonce_url(
            add_query_arg( array( 'action' => 'bacs_view_receipt', 'order_id' => $order->get_id() ), admin_url( 'admin-post.php' ) ),
            'bacs_view_receipt_' . $order->get_id()
        );
        echo '<p style="margin-top:10px;"><a href="' . esc_url( $url ) . '" target="_blank" class="button button-primary">View Uploaded Receipt</a>';
        if ( $uploaded ) echo ' &nbsp;<small style="color:#666;">Uploaded: ' . esc_html( $uploaded ) . '</small>';
        echo '</p>';
    } else {
        echo '<p style="color:#d63638;"><strong>No receipt uploaded yet.</strong></p>';
    }
}

add_action( 'admin_post_bacs_view_receipt', 'bacs_serve_receipt_to_admin' );

function bacs_serve_receipt_to_admin() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Access denied.', 403 );

    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
    if ( ! $order_id || ! check_admin_referer( 'bacs_view_receipt_' . $order_id ) ) wp_die( 'Invalid request.', 400 );

    $path = get_post_meta( $order_id, '_bacs_receipt_path', true );
    $mime = get_post_meta( $order_id, '_bacs_receipt_mime', true );

    if ( ! $path || ! file_exists( $path ) ) wp_die( 'Receipt not found.', 404 );

    $real_path = realpath( $path );
    $real_dir  = realpath( bacs_get_private_upload_dir() );
    if ( $real_path === false || strpos( $real_path, $real_dir ) !== 0 ) wp_die( 'Access denied.', 403 );

    if ( ! in_array( $mime, bacs_allowed_mimes(), true ) ) wp_die( 'Invalid file type.', 400 );

    nocache_headers();
    header( 'Content-Type: ' . sanitize_mime_type( $mime ) );
    header( 'Content-Length: ' . filesize( $real_path ) );
    header( 'Content-Disposition: inline; filename="receipt-order-' . $order_id . '.' . pathinfo( $real_path, PATHINFO_EXTENSION ) . '"' );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $real_path ); // phpcs:ignore
    exit;
}