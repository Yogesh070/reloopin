<?php
/**
 * Loyalty Launcher Widget
 *
 * Renders a floating launcher button and slide-up panel on every frontend page.
 * Guest users see a sign-up CTA; logged-in users see their live points balance,
 * earn/redeem action cards, and a referral URL — all loaded via AJAX on first open.
 *
 * Settings are managed under WooCommerce > Settings > Loyalty (Design, Content,
 * Launcher sections added by reloopin-loyalty.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReLoopin_Loyalty_Launcher
{

    private ReLoopin_Loyalty_API $api;

    public function __construct(ReLoopin_Loyalty_API $api)
    {
        $this->api = $api;

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_launcher']);
        add_action('wp_ajax_reloopin_launcher_data', [$this, 'ajax_launcher_data']);
        add_action('wp_ajax_nopriv_reloopin_launcher_data', [$this, 'ajax_launcher_data']);
        add_action('wp_ajax_reloopin_launcher_history', [$this, 'ajax_launcher_history']);
    }

    // -----------------------------------------------------------------------

    public function enqueue_assets(): void
    {
        if (get_option('reloopin_launcher_enabled', 'yes') !== 'yes') {
            return;
        }

        $base = plugin_dir_url(RELOOPIN_LOYALTY_PLUGIN_DIR . 'reloopin-loyalty.php');

        wp_enqueue_style(
            'reloopin-launcher',
            $base . 'assets/css/launcher.css',
            [],
            RELOOPIN_LOYALTY_VERSION
        );

        wp_enqueue_script(
            'reloopin-launcher',
            $base . 'assets/js/launcher.js',
            ['jquery'],
            RELOOPIN_LOYALTY_VERSION,
            true
        );

        wp_localize_script('reloopin-launcher', 'rlLauncher', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('reloopin_launcher'),
            'is_logged_in' => is_user_logged_in(),
            'account_url'  => wc_get_account_endpoint_url('loyalty-points'),
            'register_url' => wp_registration_url(),
            'login_url'    => wp_login_url(),
        ]);
    }

    // -----------------------------------------------------------------------

    public function ajax_launcher_data(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_success(['logged_in' => false]);
        }

        $user         = wp_get_current_user();
        $customer_ref = $user->user_email;
        $balance_data = $this->api->get_balance($customer_ref);

        if (is_wp_error($balance_data)) {
            wp_send_json_error(['message' => __('Could not fetch points balance.', 'reloopin-loyalty')]);
        }

        wp_send_json_success([
            'logged_in'        => true,
            'name'             => $user->display_name ?: $user->first_name ?: $user->user_login,
            'available_points' => (int) ($balance_data['available_points'] ?? 0),
            'lifetime_points'  => (int) ($balance_data['lifetime_points'] ?? 0),
            'tier'             => $balance_data['tier'] ?? '',
            'referral_url'     => add_query_arg('ref', get_current_user_id(), home_url('/')),
        ]);
    }

    // -----------------------------------------------------------------------

    public function ajax_launcher_history(): void
    {
        check_ajax_referer('reloopin_launcher', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'not_logged_in']);
        }

        $user         = wp_get_current_user();
        $page         = max(1, (int) ($_POST['page'] ?? 1));
        $history_data = $this->api->get_history($user->user_email, $page, 10);

        if (is_wp_error($history_data)) {
            wp_send_json_error(['message' => $history_data->get_error_message()]);
        }

        $results = array_map(function (array $entry): array {
            $delta    = (int) ($entry['points'] ?? 0);
            $date_raw = $entry['created_at'] ?? $entry['timestamp'] ?? '';
            return [
                'date'   => $date_raw ? date_i18n(get_option('date_format'), strtotime($date_raw)) : '—',
                'type'   => ucfirst($entry['entry_type'] ?? $entry['type'] ?? ''),
                'points' => $delta,
                'note'   => $entry['notes'] ?? $entry['note'] ?? '',
            ];
        }, $history_data['results'] ?? []);

        wp_send_json_success([
            'results'    => $results,
            'total'      => (int) ($history_data['total'] ?? 0),
            'page_size'  => (int) ($history_data['page_size'] ?? 10),
            'page'       => $page,
        ]);
    }

    // -----------------------------------------------------------------------

    public function render_launcher(): void
    {
        if (get_option('reloopin_launcher_enabled', 'yes') !== 'yes') {
            return;
        }

        // Design settings
        $logo_url    = esc_url(get_option('reloopin_launcher_logo_url', ''));
        $logo_show   = get_option('reloopin_launcher_logo_show', 'yes') === 'yes';
        $color_primary = esc_attr(get_option('reloopin_launcher_color_primary', '#1e3a8a'));
        $color_text    = esc_attr(get_option('reloopin_launcher_color_text', '#ffffff'));
        $color_bg      = esc_attr(get_option('reloopin_launcher_color_bg', '#f3f4f6'));
        $branding    = get_option('reloopin_launcher_branding', 'yes') === 'yes';

        // Content settings
        $guest_heading   = esc_html(get_option('reloopin_launcher_guest_heading', __('Join Our Loyalty Program', 'reloopin-loyalty')));
        $guest_subtext   = esc_html(get_option('reloopin_launcher_guest_subtext', __('Sign up to start earning points on every purchase.', 'reloopin-loyalty')));
        $earn_desc       = esc_html(get_option('reloopin_launcher_earn_desc', __('Earn points by shopping, signing up, and more.', 'reloopin-loyalty')));
        $redeem_desc     = esc_html(get_option('reloopin_launcher_redeem_desc', __('Redeem your points for discounts on future orders.', 'reloopin-loyalty')));
        $referral_show   = get_option('reloopin_launcher_referral_show', 'yes') === 'yes';
        $referral_title  = esc_html(get_option('reloopin_launcher_referral_title', __('Refer and earn', 'reloopin-loyalty')));
        $referral_text   = esc_html(get_option('reloopin_launcher_referral_text', __('Refer your friends and earn rewards. Your friend can get a reward as well!', 'reloopin-loyalty')));

        // Launcher settings
        $position    = get_option('reloopin_launcher_position', 'bottom-right') === 'bottom-left' ? 'bottom-left' : 'bottom-right';
        $button_text = esc_html(get_option('reloopin_launcher_button_text', __('Rewards', 'reloopin-loyalty')));

        $css_vars = "style=\"--rl-primary:{$color_primary};--rl-text:{$color_text};--rl-bg:{$color_bg};\"";
        ?>
        <button type="button" id="rl-launcher-btn" class="rl-position-<?php echo esc_attr($position); ?>" <?php echo $css_vars; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-expanded="false" aria-controls="rl-launcher-panel">
            <?php if ($logo_show && $logo_url) : ?>
                <img src="<?php echo $logo_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" alt="" class="rl-btn-logo" width="24" height="24">
            <?php else : ?>
                <span class="rl-btn-icon" aria-hidden="true">&#127873;</span>
            <?php endif; ?>
            <span class="rl-btn-text"><?php echo $button_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
        </button>

        <div id="rl-launcher-panel" class="rl-position-<?php echo esc_attr($position); ?>" <?php echo $css_vars; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> hidden aria-hidden="true" role="dialog" aria-label="<?php esc_attr_e('Loyalty rewards', 'reloopin-loyalty'); ?>">

            <div class="rl-panel-header">
                <?php if ($logo_show && $logo_url) : ?>
                    <img src="<?php echo $logo_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" alt="" class="rl-logo" height="40">
                <?php else : ?>
                    <span class="rl-logo-text"><?php echo $button_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php endif; ?>
                <button type="button" class="rl-close" aria-label="<?php esc_attr_e('Close', 'reloopin-loyalty'); ?>">&#x2715;</button>
            </div>

            <div class="rl-panel-body">

                <?php /* --- LOADING STATE --- */ ?>
                <div class="rl-state-loading">
                    <div class="rl-spinner" aria-label="<?php esc_attr_e('Loading…', 'reloopin-loyalty'); ?>"></div>
                </div>

                <?php /* --- GUEST STATE --- */ ?>
                <div class="rl-state-guest" style="display:none">
                    <div class="rl-signup-card">
                        <h3><?php echo $guest_heading; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                        <p><?php echo $guest_subtext; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                        <a href="<?php echo esc_url(wp_registration_url()); ?>" class="rl-btn-primary"><?php esc_html_e('Join Now', 'reloopin-loyalty'); ?></a>
                        <a href="<?php echo esc_url(wp_login_url()); ?>" class="rl-link-login"><?php esc_html_e('Already a member? Sign in', 'reloopin-loyalty'); ?></a>
                    </div>
                    <div class="rl-action-row">
                        <div class="rl-action-card">
                            <span class="rl-action-icon" aria-hidden="true">&#x1FA99;</span>
                            <span class="rl-action-label"><?php esc_html_e('Earn', 'reloopin-loyalty'); ?></span>
                            <p class="rl-action-desc"><?php echo $earn_desc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                        </div>
                        <div class="rl-action-card">
                            <span class="rl-action-icon" aria-hidden="true">&#x1F381;</span>
                            <span class="rl-action-label"><?php esc_html_e('Redeem', 'reloopin-loyalty'); ?></span>
                            <p class="rl-action-desc"><?php echo $redeem_desc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                        </div>
                    </div>
                </div>

                <?php /* --- LOGGED-IN STATE --- */ ?>
                <div class="rl-state-loggedin" style="display:none">
                    <div class="rl-balance-card">
                        <p class="rl-greeting"><?php esc_html_e('Hello', 'reloopin-loyalty'); ?> <span class="rl-user-name"></span>,<br><?php esc_html_e('you have', 'reloopin-loyalty'); ?></p>
                        <p class="rl-points-display">
                            <span class="rl-points-count">&#8230;</span>
                            <span class="rl-points-label"><?php esc_html_e('points', 'reloopin-loyalty'); ?></span>
                        </p>
                        <button type="button" class="rl-view-history-btn"><?php esc_html_e('View history ›', 'reloopin-loyalty'); ?></button>
                    </div>

                    <div class="rl-action-row">
                        <div class="rl-action-card">
                            <span class="rl-action-icon" aria-hidden="true">&#x1FA99;</span>
                            <span class="rl-action-label"><?php esc_html_e('Earn', 'reloopin-loyalty'); ?></span>
                            <p class="rl-action-desc"><?php echo $earn_desc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                        </div>
                        <div class="rl-action-card">
                            <span class="rl-action-icon" aria-hidden="true">&#x1F381;</span>
                            <span class="rl-action-label"><?php esc_html_e('Redeem', 'reloopin-loyalty'); ?></span>
                            <p class="rl-action-desc"><?php echo $redeem_desc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                        </div>
                    </div>

                    <?php if ($referral_show) : ?>
                    <div class="rl-referral-card">
                        <h4><?php echo $referral_title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h4>
                        <p><?php echo $referral_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                        <div class="rl-ref-row">
                            <input type="text" class="rl-ref-url" readonly aria-label="<?php esc_attr_e('Your referral link', 'reloopin-loyalty'); ?>">
                            <button type="button" class="rl-copy-btn" title="<?php esc_attr_e('Copy link', 'reloopin-loyalty'); ?>" aria-label="<?php esc_attr_e('Copy referral link', 'reloopin-loyalty'); ?>">
                                <span class="rl-copy-icon" aria-hidden="true">&#x29c9;</span>
                            </button>
                        </div>
                        <span class="rl-copy-feedback" aria-live="polite"></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php /* --- HISTORY STATE --- */ ?>
                <div class="rl-state-history" style="display:none">
                    <div class="rl-history-header">
                        <button type="button" class="rl-back-btn" aria-label="<?php esc_attr_e('Back', 'reloopin-loyalty'); ?>">
                            &#8592; <span><?php esc_html_e('Back', 'reloopin-loyalty'); ?></span>
                        </button>
                        <h3><?php esc_html_e('Points History', 'reloopin-loyalty'); ?></h3>
                    </div>
                    <div class="rl-history-loading">
                        <div class="rl-spinner"></div>
                    </div>
                    <div class="rl-history-list" style="display:none"></div>
                    <p class="rl-history-empty" style="display:none"><?php esc_html_e('No points history yet.', 'reloopin-loyalty'); ?></p>
                    <p class="rl-history-err" style="display:none"><?php esc_html_e('Could not load history. Please try again later.', 'reloopin-loyalty'); ?></p>
                    <div class="rl-history-pagination" style="display:none">
                        <button type="button" class="rl-hist-prev" disabled>&#8592; <?php esc_html_e('Newer', 'reloopin-loyalty'); ?></button>
                        <span class="rl-hist-page-info"></span>
                        <button type="button" class="rl-hist-next"><?php esc_html_e('Older', 'reloopin-loyalty'); ?> &#8594;</button>
                    </div>
                </div>

                <?php /* --- ERROR STATE --- */ ?>
                <div class="rl-state-error" style="display:none">
                    <p><?php esc_html_e('Could not load your points right now. Please try again later.', 'reloopin-loyalty'); ?></p>
                </div>

            </div><!-- /.rl-panel-body -->

            <?php if ($branding) : ?>
            <div class="rl-branding"><?php esc_html_e('Powered by reLoopin', 'reloopin-loyalty'); ?></div>
            <?php endif; ?>

        </div><!-- /#rl-launcher-panel -->
        <?php
    }
}
