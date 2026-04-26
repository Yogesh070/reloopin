<?php
/**
 * Plugin Name: reLoopin Loyalty
 * Plugin URI:  https://reloopin.com
 * Description: Integrates a custom loyalty points backend with WooCommerce.
 * Version:     1.0.0
 * Author:      reLoopin
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 * Text Domain: reloopin-loyalty
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RELOOPIN_LOYALTY_VERSION', '1.0.0');
define('RELOOPIN_LOYALTY_PLUGIN_DIR', plugin_dir_path(__FILE__));

/** Platform integer for WooCommerce in the loyalty backend. */
define('RELOOPIN_LOYALTY_PLATFORM', 1);

/**
 * Write a debug message to wp-content/debug.log.
 *
 * Only fires when both WP_DEBUG and WP_DEBUG_LOG are true.
 * Each line is prefixed with [reLoopin Loyalty] and a timestamp for easy grepping.
 *
 * @param string $message
 * @param mixed  $context  Optional value (array/object) to dump alongside the message.
 */
function reloopin_loyalty_debug(string $message, mixed $context = null): void
{
    if (!(defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
        return;
    }

    $timestamp = gmdate('Y-m-d H:i:s');
    $entry = "[reLoopin Loyalty] [{$timestamp}] {$message}";

    if ($context !== null) {
        $entry .= ' | ' . (is_string($context) ? $context : wp_json_encode($context));
    }

    error_log($entry);
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Check WooCommerce is active before doing anything.
 */
add_action('plugins_loaded', 'reloopin_loyalty_init', 20);

function reloopin_loyalty_init()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>reLoopin Loyalty</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    require_once RELOOPIN_LOYALTY_PLUGIN_DIR . 'includes/class-loyalty-api.php';
    require_once RELOOPIN_LOYALTY_PLUGIN_DIR . 'includes/class-loyalty-orders.php';
    require_once RELOOPIN_LOYALTY_PLUGIN_DIR . 'includes/class-loyalty-launcher.php';

    $api = new ReLoopin_Loyalty_API();

    new ReLoopin_Loyalty_Orders($api);
    new ReLoopin_Loyalty_Launcher($api);
}

// ---------------------------------------------------------------------------
// Settings: WooCommerce > Settings > Loyalty tab
// ---------------------------------------------------------------------------

add_filter('woocommerce_settings_tabs_array', 'reloopin_loyalty_add_settings_tab', 50);
function reloopin_loyalty_add_settings_tab($tabs)
{
    $tabs['reloopin_loyalty'] = __('Loyalty', 'reloopin-loyalty');
    return $tabs;
}

add_action('woocommerce_settings_tabs_reloopin_loyalty', 'reloopin_loyalty_settings_tab');
function reloopin_loyalty_settings_tab()
{
    woocommerce_admin_fields(reloopin_loyalty_get_settings());
}

add_action('woocommerce_update_options_reloopin_loyalty', 'reloopin_loyalty_update_settings');
function reloopin_loyalty_update_settings()
{
    woocommerce_update_options(reloopin_loyalty_get_settings());
}

function reloopin_loyalty_get_settings()
{
    return [
        [
            'title' => __('Loyalty System Settings', 'reloopin-loyalty'),
            'type' => 'title',
            'id' => 'reloopin_loyalty_section_title',
        ],
        [
            'title' => __('API Base URL', 'reloopin-loyalty'),
            'desc' => __('Base URL of your loyalty backend — no trailing slash. e.g. https://loyalty.yourdomain.com', 'reloopin-loyalty'),
            'id' => 'reloopin_loyalty_api_url',
            'type' => 'text',
            'default' => '',
        ],
        [
            'title' => __('API Key', 'reloopin-loyalty'),
            'desc' => __('Bearer token used to authenticate with the loyalty API.', 'reloopin-loyalty'),
            'id' => 'reloopin_loyalty_api_key',
            'type' => 'password',
            'default' => '',
        ],
        [
            'title' => __('Merchant ID', 'reloopin-loyalty'),
            'desc' => __('Your merchant UUID from the loyalty backend, e.g. 3fa85f64-5717-4562-b3fc-2c963f66afa6', 'reloopin-loyalty'),
            'id' => 'reloopin_loyalty_merchant_id',
            'type' => 'text',
            'default' => '',
        ],
        [
            'title' => __('Merchant Code', 'reloopin-loyalty'),
            'desc' => __('Sent as the merchant_code header on transaction-entry requests.', 'reloopin-loyalty'),
            'id' => 'reloopin_loyalty_merchant_code',
            'type' => 'text',
            'default' => '',
        ],
        [
            'type' => 'sectionend',
            'id' => 'reloopin_loyalty_section_end',
        ],

        // ── LAUNCHER WIDGET: Design ─────────────────────────────────────────
        [
            'title' => __('Launcher Widget — Design', 'reloopin-loyalty'),
            'type'  => 'title',
            'id'    => 'reloopin_launcher_design_title',
            'desc'  => __('Customise the look of the floating loyalty launcher.', 'reloopin-loyalty'),
        ],
        [
            'title'   => __('Logo URL', 'reloopin-loyalty'),
            'desc'    => __('Full URL of your logo image. Leave blank to show the widget icon instead.', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_logo_url',
            'type'    => 'text',
            'default' => '',
        ],
        [
            'title'   => __('Show logo', 'reloopin-loyalty'),
            'desc'    => __('Display your logo in the launcher button and panel header.', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_logo_show',
            'type'    => 'checkbox',
            'default' => 'yes',
        ],
        [
            'title'   => __('Primary colour', 'reloopin-loyalty'),
            'desc'    => __('Hex colour for the card background and buttons, e.g. #1e3a8a', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_color_primary',
            'type'    => 'text',
            'default' => '#1e3a8a',
            'css'     => 'width:120px;',
        ],
        [
            'title'   => __('Text colour on primary', 'reloopin-loyalty'),
            'desc'    => __('Hex colour for text shown on the primary colour, e.g. #ffffff', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_color_text',
            'type'    => 'text',
            'default' => '#ffffff',
            'css'     => 'width:120px;',
        ],
        [
            'title'   => __('Panel background colour', 'reloopin-loyalty'),
            'desc'    => __('Hex colour for the panel body background, e.g. #f3f4f6', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_color_bg',
            'type'    => 'text',
            'default' => '#f3f4f6',
            'css'     => 'width:120px;',
        ],
        [
            'title'   => __('Show "Powered by reLoopin"', 'reloopin-loyalty'),
            'desc'    => __('Display branding at the bottom of the launcher panel.', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_branding',
            'type'    => 'checkbox',
            'default' => 'yes',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'reloopin_launcher_design_end',
        ],

        // ── LAUNCHER WIDGET: Content ────────────────────────────────────────
        [
            'title' => __('Launcher Widget — Content', 'reloopin-loyalty'),
            'type'  => 'title',
            'id'    => 'reloopin_launcher_content_title',
            'desc'  => __('Text displayed inside the launcher panel for guests and logged-in users.', 'reloopin-loyalty'),
        ],
        [
            'title'   => __('Guest heading', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_guest_heading',
            'type'    => 'text',
            'default' => __('Join Our Loyalty Program', 'reloopin-loyalty'),
        ],
        [
            'title'   => __('Guest sub-text', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_guest_subtext',
            'type'    => 'textarea',
            'default' => __('Sign up to start earning points on every purchase.', 'reloopin-loyalty'),
            'css'     => 'height:60px;',
        ],
        [
            'title'   => __('Earn description', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_earn_desc',
            'type'    => 'textarea',
            'default' => __('Earn points by shopping, signing up, and more.', 'reloopin-loyalty'),
            'css'     => 'height:60px;',
        ],
        [
            'title'   => __('Redeem description', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_redeem_desc',
            'type'    => 'textarea',
            'default' => __('Redeem your points for discounts on future orders.', 'reloopin-loyalty'),
            'css'     => 'height:60px;',
        ],
        [
            'title'   => __('Show referral section', 'reloopin-loyalty'),
            'desc'    => __('Display the referral URL card for logged-in users.', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_referral_show',
            'type'    => 'checkbox',
            'default' => 'yes',
        ],
        [
            'title'   => __('Referral card title', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_referral_title',
            'type'    => 'text',
            'default' => __('Refer and earn', 'reloopin-loyalty'),
        ],
        [
            'title'   => __('Referral card text', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_referral_text',
            'type'    => 'textarea',
            'default' => __('Refer your friends and earn rewards. Your friend can get a reward as well!', 'reloopin-loyalty'),
            'css'     => 'height:60px;',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'reloopin_launcher_content_end',
        ],

        // ── LAUNCHER WIDGET: Launcher tab ───────────────────────────────────
        [
            'title' => __('Launcher Widget — Launcher', 'reloopin-loyalty'),
            'type'  => 'title',
            'id'    => 'reloopin_launcher_tab_title',
            'desc'  => __('Control where and how the floating launcher button appears.', 'reloopin-loyalty'),
        ],
        [
            'title'   => __('Enable launcher widget', 'reloopin-loyalty'),
            'desc'    => __('Show the floating loyalty launcher on the frontend.', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_enabled',
            'type'    => 'checkbox',
            'default' => 'yes',
        ],
        [
            'title'   => __('Button position', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_position',
            'type'    => 'select',
            'default' => 'bottom-right',
            'options' => [
                'bottom-right' => __('Bottom right', 'reloopin-loyalty'),
                'bottom-left'  => __('Bottom left', 'reloopin-loyalty'),
            ],
        ],
        [
            'title'   => __('Button text', 'reloopin-loyalty'),
            'desc'    => __('Label shown on the floating trigger button.', 'reloopin-loyalty'),
            'id'      => 'reloopin_launcher_button_text',
            'type'    => 'text',
            'default' => __('Rewards', 'reloopin-loyalty'),
        ],
        [
            'type' => 'sectionend',
            'id'   => 'reloopin_launcher_tab_end',
        ],
    ];
}
