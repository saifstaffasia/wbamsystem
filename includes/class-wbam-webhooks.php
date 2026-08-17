<?php
if (!defined('ABSPATH')) exit;

/**
 * Shopify webhooks: registration + receiver.
 * Webhook-first design keeps the warehouse (and the Today report) live all day.
 */
class WBAM_Webhooks {

    const TOPICS = ['orders/create', 'orders/updated', 'refunds/create', 'orders/cancelled'];

    public static function address(): string {
        return rest_url('wbam/v1/webhook');
    }

    /** Idempotently register our topics against this site. */
    public static function register(): array {
        $client = WBAM_Shopify::i();
        [$existing] = $client->rest('GET', '/webhooks.json?limit=250');
        $have = [];
        foreach ((array) ($existing['webhooks'] ?? []) as $w) {
            if (($w['address'] ?? '') === self::address()) $have[$w['topic']] = true;
        }
        $added = [];
        foreach (self::TOPICS as $topic) {
            if (isset($have[$topic])) continue;
            $client->rest('POST', '/webhooks.json', ['webhook' => [
                'topic' => $topic, 'address' => self::address(), 'format' => 'json',
            ]]);
            $added[] = $topic;
        }
        return ['registered' => $added, 'already' => array_keys($have)];
    }

    public static function verify(WP_REST_Request $req): bool {
        $hmac = $req->get_header('x_shopify_hmac_sha256') ?: $req->get_header('X-Shopify-Hmac-Sha256');
        if (!$hmac) return false;
        $calc = base64_encode(hash_hmac('sha256', $req->get_body(), WBAM_Shopify::i()->secret(), true));
        return hash_equals($calc, $hmac);
    }

    public static function handle(WP_REST_Request $req) {
        if (!self::verify($req)) {
            return new WP_REST_Response(['ok' => false], 401);
        }
        $topic = $req->get_header('x_shopify_topic') ?: $req->get_header('X-Shopify-Topic');
        $payload = json_decode($req->get_body(), true) ?: [];

        try {
            switch ($topic) {
                case 'orders/create':
                case 'orders/updated':
                case 'orders/cancelled':
                    WBAM_Warehouse::upsert_order($payload);
                    WBAM_Units::sell_reconcile((int) ($payload['id'] ?? 0));
                    break;
                case 'refunds/create':
                    $order_id = (int) ($payload['order_id'] ?? 0);
                    if ($order_id) {
                        WBAM_Sync::queue('reupsert_order', ['order_id' => $order_id], 'refund received', 0);
                        WBAM_Sync::run_queue(); // immediate attempt
                    }
                    break;
            }
        } catch (Throwable $e) {
            // Never 500 a webhook repeatedly — queue for retry and ack.
            WBAM_Sync::queue('webhook_replay', ['topic' => $topic, 'payload' => $payload], $e->getMessage());
        }
        return new WP_REST_Response(['ok' => true], 200);
    }
}
