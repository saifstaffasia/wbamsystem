<?php
if (!defined('ABSPATH')) exit;

/**
 * The staff-facing front-end app ([wbam_app], normally on the /app page).
 * Staff log in through the normal WP login and land here — never in wp-admin.
 * The /app page renders STANDALONE (no theme) so it fills the screen like an app.
 */
class WBAM_App {

    public static function init(): void {
        add_shortcode('wbam_app', [self::class, 'shortcode']);
        add_action('template_redirect', [self::class, 'standalone'], 0);
        add_filter('login_redirect', [self::class, 'login_redirect'], 10, 3);
        add_action('admin_init', [self::class, 'block_wp_admin']);
        add_filter('show_admin_bar', [self::class, 'admin_bar']);
    }

    /** Staff (and managers) land on /app after logging in. */
    public static function login_redirect($redirect, $requested, $user) {
        if ($user instanceof WP_User && user_can($user, 'wbam_use') && !user_can($user, 'manage_options')) {
            return home_url('/app/');
        }
        return $redirect;
    }

    /** Shop-floor staff have no business in wp-admin; managers keep access. */
    public static function block_wp_admin(): void {
        global $pagenow;
        if (wp_doing_ajax() || $pagenow === 'admin-post.php') return;
        if (current_user_can('wbam_use') && !current_user_can('wbam_manage') && !current_user_can('manage_options')) {
            wp_safe_redirect(home_url('/app/'));
            exit;
        }
    }

    public static function admin_bar($show) {
        if (current_user_can('wbam_use') && !current_user_can('manage_options')) return false;
        return $show;
    }

    private static function config(WP_User $u): array {
        return [
            'rest'     => rest_url('wbam/v1/'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'user'     => $u->display_name,
            'reports'  => current_user_can('wbam_reports'),
            'manage'   => current_user_can('wbam_manage'),
            'logout'   => wp_logout_url(home_url('/app/')),
            'admin'    => current_user_can('wbam_manage') ? admin_url('admin.php?page=wbam') : '',
        ];
    }

    /**
     * Serve the app page as a full standalone document — no theme, full width,
     * proper viewport for the shop-floor tablets.
     */
    public static function standalone(): void {
        if (is_admin() || is_feed()) return;
        $post = get_queried_object();
        if (!($post instanceof WP_Post) || !has_shortcode((string) $post->post_content, 'wbam_app')) return;

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(get_permalink($post)));
            exit;
        }
        if (!current_user_can('wbam_use')) {
            wp_die('This account has no WBAM System access — ask a manager.', 'WBAM System', ['response' => 403]);
        }
        $u   = wp_get_current_user();
        $cfg = self::config($u);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        $css = esc_url(WBAM_URL . 'assets/css/app.css?ver=' . WBAM_VER);
        $js  = esc_url(WBAM_URL . 'assets/js/frontend.js?ver=' . WBAM_VER);
        echo '<!doctype html><html lang="en-GB"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
           . '<meta name="robots" content="noindex,nofollow">'
           . '<title>WBAM System</title>'
           . '<link rel="stylesheet" href="' . $css . '">'
           . '</head><body class="wa-standalone">'
           . '<div id="wbam-app"><div class="wa-loading">Loading…</div></div>'
           . '<script>var WBAMAPP=' . wp_json_encode($cfg) . ';</script>'
           . '<script src="' . $js . '"></script>'
           . '</body></html>';
        exit;
    }

    /** Fallback rendering when the shortcode is used inside a themed page. */
    public static function shortcode(): string {
        if (!is_user_logged_in()) {
            return '<div style="max-width:360px;margin:15vh auto;text-align:center;font-family:sans-serif">'
                 . '<h2>WBAM System</h2><p><a class="button" style="display:inline-block;padding:12px 28px;background:#111827;color:#fff;border-radius:8px;text-decoration:none" href="'
                 . esc_url(wp_login_url(home_url('/app/'))) . '">Log in</a></p></div>';
        }
        if (!current_user_can('wbam_use')) {
            return '<p style="text-align:center;margin-top:15vh">This account has no WBAM System access — ask a manager.</p>';
        }
        $u = wp_get_current_user();
        wp_enqueue_style('wbam-appcss', WBAM_URL . 'assets/css/app.css', [], WBAM_VER);
        wp_enqueue_script('wbam-appjs', WBAM_URL . 'assets/js/frontend.js', [], WBAM_VER, true);
        wp_localize_script('wbam-appjs', 'WBAMAPP', self::config($u));
        return '<div id="wbam-app"><div class="wa-loading">Loading…</div></div>';
    }
}
