<?php
if (!defined('ABSPATH')) exit;

/**
 * Shopify Admin API client (single store), client-credentials grant.
 * Same token pattern as the proven RCB_Shopify_Client, plus GraphQL support.
 */
class WBAM_Shopify {

    private static ?WBAM_Shopify $inst = null;

    private string $domain;
    private string $client_id;
    private string $client_secret;
    private string $version;

    public static function i(): WBAM_Shopify {
        if (!self::$inst) self::$inst = new self();
        return self::$inst;
    }

    public function __construct() {
        $s = WBAM_Settings::all();
        $this->domain        = self::normalize_domain($s['shop_domain']);
        $this->client_id     = trim($s['client_id']);
        $this->client_secret = trim($s['client_secret']);
        $this->version       = trim($s['api_version']) ?: '2026-07';
    }

    public static function normalize_domain(string $d): string {
        $d = strtolower(trim($d));
        $d = preg_replace('#^https?://#', '', $d);
        return rtrim($d, '/');
    }

    public function is_configured(): bool {
        return $this->domain !== '' && $this->client_id !== '' && $this->client_secret !== '';
    }

    public function secret(): string {
        return $this->client_secret;
    }

    private function base(): string {
        return "https://{$this->domain}/admin/api/{$this->version}";
    }

    private function token_cache_key(): string {
        return 'wbam_tok_' . md5($this->domain . '|' . $this->client_id);
    }

    private function get_access_token(bool $force = false): string {
        $key = $this->token_cache_key();
        if (!$force) {
            $cached = get_transient($key);
            if (is_string($cached) && $cached !== '') return $cached;
        }
        $res = wp_remote_post("https://{$this->domain}/admin/oauth/access_token", [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
            'body'    => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
            ],
        ]);
        if (is_wp_error($res)) throw new RuntimeException('Token request failed: ' . $res->get_error_message());
        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300 || empty($data['access_token'])) {
            $msg = $data['error_description'] ?? $data['error'] ?? ('HTTP ' . $code);
            throw new RuntimeException("Could not mint Shopify token ($code): $msg");
        }
        $ttl = max(60, (int) ($data['expires_in'] ?? 86399) - 3600);
        set_transient($key, (string) $data['access_token'], $ttl);
        return (string) $data['access_token'];
    }

    /** REST request. $path like '/orders.json?limit=250'. Returns [decoded, headers]. */
    public function rest(string $method, string $path, ?array $body = null, bool $retry_on_auth = true): array {
        $args = [
            'method'  => $method,
            'timeout' => 40,
            'headers' => [
                'X-Shopify-Access-Token' => $this->get_access_token(),
                'Content-Type'           => 'application/json',
                'Accept'                 => 'application/json',
            ],
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body);

        $res = wp_remote_request($this->base() . $path, $args);
        if (is_wp_error($res)) throw new RuntimeException('Shopify request failed: ' . $res->get_error_message());

        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);

        if ($code === 401 && $retry_on_auth) {
            delete_transient($this->token_cache_key());
            $this->get_access_token(true);
            return $this->rest($method, $path, $body, false);
        }
        if ($code === 429) { // throttled — one polite retry
            sleep(2);
            return $this->rest($method, $path, $body, false);
        }
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) && isset($data['errors'])
                ? (is_string($data['errors']) ? $data['errors'] : wp_json_encode($data['errors']))
                : ('HTTP ' . $code);
            throw new RuntimeException("Shopify API error ($code): $msg");
        }
        return [is_array($data) ? $data : [], wp_remote_retrieve_headers($res)];
    }

    /** GraphQL request. Throws on transport/HTTP/GraphQL errors (userErrors are the caller's job). */
    public function graphql(string $query, array $variables = []): array {
        [$data] = $this->rest('POST', '/graphql.json', [
            'query'     => $query,
            'variables' => $variables ?: new stdClass(),
        ]);
        if (!empty($data['errors'])) {
            throw new RuntimeException('GraphQL error: ' . wp_json_encode($data['errors']));
        }
        return $data;
    }

    /** Parse the REST Link header for cursor pagination; returns next page_info or null. */
    public static function next_page_info($headers): ?string {
        $link = is_object($headers) ? ($headers['link'] ?? '') : '';
        if (!$link) return null;
        if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>;\s*rel="next"/', (string) $link, $m)) {
            return $m[1];
        }
        return null;
    }

    public function test_connection(): array {
        try {
            $this->get_access_token(true);
            $res = $this->graphql('query { shop { name currencyCode } }');
            $n = $res['data']['shop']['name'] ?? '?';
            return [true, "Connected to {$n}."];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /** Numeric id from a gid string. */
    public static function gid_to_id($gid): int {
        return (int) preg_replace('/\D/', '', (string) $gid);
    }

    public static function gid(string $type, $id): string {
        return "gid://shopify/{$type}/{$id}";
    }
}
