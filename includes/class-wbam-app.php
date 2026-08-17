<?php
if (!defined('ABSPATH')) exit;

/**
 * The staff-facing front-end app ([wbam_app], normally on the /app page).
 *
 * - /app renders STANDALONE (no theme) and doubles as the LOGIN page: logged-out
 *   visitors get a WBAM System sign-in card, never the WordPress login screen.
 * - wp-login.php and logged-out /wp-admin visits are redirected here (password
 *   reset, logout and other special flows still work).
 * - Every URL is derived from the site address at runtime, so changing domain
 *   needs no code edits.
 */
class WBAM_App {

    public static function init(): void {
        add_shortcode('wbam_app', [self::class, 'shortcode']);
        add_action('template_redirect', [self::class, 'standalone'], 0);
        add_action('login_init', [self::class, 'hide_wp_login']);
        add_action('admin_post_nopriv_wbam_login', [self::class, 'handle_login']);
        add_action('admin_post_wbam_login', [self::class, 'handle_login']);
        add_filter('login_redirect', [self::class, 'login_redirect'], 10, 3);
        add_action('admin_init', [self::class, 'block_wp_admin']);
        add_filter('show_admin_bar', [self::class, 'admin_bar'], 99);
    }

    /** The app page URL — found dynamically (page containing [wbam_app]), never hardcoded. */
    public static function url(): string {
        $pid = (int) get_option('wbam_app_page_id');
        if ($pid) {
            $p = get_post($pid);
            if ($p && $p->post_status === 'publish' && has_shortcode((string) $p->post_content, 'wbam_app')) {
                return (string) get_permalink($pid);
            }
        }
        global $wpdb;
        $pid = (int) $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE '%[wbam_app]%' ORDER BY ID ASC LIMIT 1"
        );
        if ($pid) {
            update_option('wbam_app_page_id', $pid);
            return (string) get_permalink($pid);
        }
        return home_url('/app/');
    }

    /** Staff (and managers) land on the app after logging in. */
    public static function login_redirect($redirect, $requested, $user) {
        if ($user instanceof WP_User && user_can($user, 'wbam_use') && !user_can($user, 'manage_options')) {
            return self::url();
        }
        return $redirect;
    }

    /** Shop-floor staff have no business in wp-admin; managers keep access. */
    public static function block_wp_admin(): void {
        global $pagenow;
        if (wp_doing_ajax() || $pagenow === 'admin-post.php') return;
        if (current_user_can('wbam_use') && !current_user_can('wbam_manage') && !current_user_can('manage_options')) {
            wp_safe_redirect(self::url());
            exit;
        }
    }

    /** Admin bar only for actual administrators. */
    public static function admin_bar($show) {
        return current_user_can('manage_options') ? $show : false;
    }

    /**
     * Nobody sees the WordPress login screen: GET requests to wp-login.php go to
     * the WBAM System sign-in instead. Special flows (logout, password reset,
     * interim re-auth, form POSTs) pass through untouched.
     */
    public static function hide_wp_login(): void {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        if (isset($_GET['interim-login'])) return;
        $action  = sanitize_key($_GET['action'] ?? 'login');
        $allowed = ['logout', 'lostpassword', 'retrievepassword', 'rp', 'resetpass', 'postpass', 'confirmaction', 'confirm_admin_email'];
        if (in_array($action, $allowed, true)) return;

        $to = self::url();
        if (!empty($_GET['loggedout'])) $to = add_query_arg('loggedout', '1', $to);
        if (!empty($_GET['redirect_to'])) {
            $safe = wp_validate_redirect(wp_unslash($_GET['redirect_to']), '');
            if ($safe) $to = add_query_arg('redirect_to', rawurlencode($safe), $to);
        }
        wp_safe_redirect($to);
        exit;
    }

    /** POST target of the sign-in card (works logged-out via admin-post). */
    public static function handle_login(): void {
        if (is_user_logged_in()) {
            wp_safe_redirect(self::url());
            exit;
        }
        $user = wp_signon([
            'user_login'    => sanitize_user(wp_unslash($_POST['log'] ?? '')),
            'user_password' => (string) wp_unslash($_POST['pwd'] ?? ''),
            'remember'      => !empty($_POST['rememberme']),
        ], is_ssl());
        if (is_wp_error($user)) {
            wp_safe_redirect(add_query_arg('login', 'failed', self::url()));
            exit;
        }
        $dest = self::url();
        if (!empty($_POST['redirect_to'])) {
            $safe = wp_validate_redirect(wp_unslash($_POST['redirect_to']), '');
            // Only managers can be sent into wp-admin; everyone else stays in the app.
            if ($safe && (strpos($safe, '/wp-admin') === false || user_can($user, 'wbam_manage') || user_can($user, 'manage_options'))) {
                $dest = $safe;
            }
        }
        wp_safe_redirect($dest);
        exit;
    }

    private static function config(WP_User $u): array {
        return [
            'rest'     => rest_url('wbam/v1/'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'user'     => $u->display_name,
            'reports'  => current_user_can('wbam_reports'),
            'manage'   => current_user_can('wbam_manage'),
            'logout'   => wp_logout_url(self::url()),
            'admin'    => current_user_can('wbam_manage') ? admin_url('admin.php?page=wbam') : '',
        ];
    }

    private static function head(string $title): string {
        $css = esc_url(WBAM_URL . 'assets/css/app.css?ver=' . WBAM_VER);
        return '<!doctype html><html lang="en-GB"><head><meta charset="utf-8">'
             . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
             . '<meta name="robots" content="noindex,nofollow">'
             . '<title>' . esc_html($title) . '</title>'
             . '<link rel="stylesheet" href="' . $css . '"></head>';
    }

    /** The standalone sign-in document. */
    private static function render_login(): void {
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        $post   = esc_url(admin_url('admin-post.php'));
        $lost   = esc_url(wp_lostpassword_url());
        $failed = !empty($_GET['login']);
        $out    = !empty($_GET['loggedout']);
        $redir  = '';
        if (!empty($_GET['redirect_to'])) {
            $redir = wp_validate_redirect(wp_unslash($_GET['redirect_to']), '');
        }
        echo self::head('WBAM System — Log in')
           . '<body class="wa-standalone"><div class="wa-login">'
           . '<div class="wa-login-card">'
           . '<div class="wa-login-logo">WBAM System</div>'
           . '<p class="wa-sub" style="margin-top:0">WeBuyAnyMobile staff</p>'
           . ($failed ? '<div class="wa-msg err">Wrong username or password — try again.</div>' : '')
           . ($out ? '<div class="wa-msg ok">Logged out. See you next shift.</div>' : '')
           . '<form method="post" action="' . $post . '">'
           . '<input type="hidden" name="action" value="wbam_login">'
           . ($redir ? '<input type="hidden" name="redirect_to" value="' . esc_attr($redir) . '">' : '')
           . '<label>Username or email<input type="text" name="log" autocomplete="username" autofocus required></label>'
           . '<label>Password<input type="password" name="pwd" autocomplete="current-password" required></label>'
           . '<label class="wa-remember"><input type="checkbox" name="rememberme" value="1" checked> Keep me logged in on this till</label>'
           . '<button class="wa-btn p" type="submit" style="width:100%">Log in</button>'
           . '</form>'
           . '<p class="wa-sub" style="text-align:center;margin-bottom:0"><a href="' . $lost . '">Forgot password?</a></p>'
           . '</div></div></body></html>';
        exit;
    }

    /**
     * Serve the app page as a full standalone document — no theme, full width,
     * proper viewport for the shop-floor tablets. Logged-out → sign-in card.
     */
    public static function standalone(): void {
        if (is_admin() || is_feed()) return;
        $post = get_queried_object();
        if (!($post instanceof WP_Post) || !has_shortcode((string) $post->post_content, 'wbam_app')) return;
        if ((int) get_option('wbam_app_page_id') !== (int) $post->ID) update_option('wbam_app_page_id', (int) $post->ID);

        if (!is_user_logged_in()) {
            self::render_login();
        }
        if (!current_user_can('wbam_use')) {
            wp_die('This account has no WBAM System access — ask a manager. <a href="' . esc_url(wp_logout_url(self::url())) . '">Log out</a>', 'WBAM System', ['response' => 403]);
        }
        $u   = wp_get_current_user();
        $cfg = self::config($u);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        $js = esc_url(WBAM_URL . 'assets/js/frontend.js?ver=' . WBAM_VER);
        echo self::head('WBAM System')
           . '<body class="wa-standalone">'
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
                 . esc_url(self::url()) . '">Log in</a></p></div>';
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
