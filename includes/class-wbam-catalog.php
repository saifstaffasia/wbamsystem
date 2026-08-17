<?php
if (!defined('ABSPATH')) exit;

/**
 * Bridges the Hub to the Shopify product catalog:
 * find models, ensure the pooled variant exists, own the pool barcode,
 * adjust pooled inventory, and keep variant "cost per item" = rolling average.
 */
class WBAM_Catalog {

    /** Autocomplete phone models for the intake screen. */
    public static function search_models(string $term): array {
        $q = 'query($q: String!) {
            products(first: 10, query: $q) {
                nodes {
                    id title
                    options { name optionValues { name } }
                }
            }
        }';
        // No product_type filter: WBAM serializes phones, MacBooks, tablets, consoles…
        // Staff pick from titles, so accessory near-misses are harmless.
        // Split words into ANDed title clauses so "iphone 16 pro" matches
        // "iPhone 16 Pro (Max)" only — not every product containing "iphone".
        $words = preg_split('/\s+/', trim(str_replace('"', '', $term))) ?: [];
        $clauses = array_map(fn($w) => 'title:*' . $w . '*', array_filter($words));
        $search = $clauses ? implode(' AND ', $clauses) : 'title:*';
        $res = WBAM_Shopify::i()->graphql($q, ['q' => $search]);
        $out = [];
        foreach (($res['data']['products']['nodes'] ?? []) as $p) {
            $opts = [];
            foreach (($p['options'] ?? []) as $o) {
                $opts[$o['name']] = array_map(fn($v) => $v['name'], $o['optionValues'] ?? []);
            }
            $out[] = [
                'product_id' => WBAM_Shopify::gid_to_id($p['id']),
                'title'      => $p['title'],
                'options'    => $opts, // e.g. Colour => [...], Storage => [...], Condition => [...]
            ];
        }
        usort($out, fn($a, $b) => strlen($a['title']) <=> strlen($b['title'])); // most specific match first
        return $out;
    }

    /**
     * Custom device intake — a one-off product created on the fly (device not in
     * the catalog). Single variant, Condition option = grade, barcode/sku = unit
     * code, cost + qty 1 set in one call, published to the POS channel.
     */
    public static function create_custom_product(string $title, string $grade, float $sell, float $cost, string $barcode, int $location_id): array {
        $m = 'mutation($input: ProductSetInput!) {
            productSet(input: $input, synchronous: true) {
                product { id variants(first: 1) { nodes { id inventoryItem { id } } } }
                userErrors { field message }
            }
        }';
        $res = WBAM_Shopify::i()->graphql($m, ['input' => [
            'title'          => $title,
            'status'         => 'ACTIVE',
            'productType'    => 'Phone',
            'vendor'         => 'WeBuyAnyMobile',
            'tags'           => ['wbam-custom'],
            'productOptions' => [['name' => 'Condition', 'values' => [['name' => $grade]]]],
            'variants'       => [[
                'optionValues'        => [['optionName' => 'Condition', 'name' => $grade]],
                'price'               => number_format($sell, 2, '.', ''),
                'sku'                 => $barcode,
                'barcode'             => $barcode,
                'taxable'             => true,
                'inventoryItem'       => ['tracked' => true, 'cost' => number_format($cost, 2, '.', '')],
                'inventoryQuantities' => [[
                    'locationId' => WBAM_Shopify::gid('Location', $location_id),
                    'name'       => 'available',
                    'quantity'   => 1,
                ]],
            ]],
        ]]);
        $errs = $res['data']['productSet']['userErrors'] ?? [];
        if ($errs) throw new RuntimeException('Custom product failed: ' . wp_json_encode($errs));
        $p = $res['data']['productSet']['product'];
        $v = $p['variants']['nodes'][0] ?? null;
        if (!$v) throw new RuntimeException('Custom product created without a variant.');
        $product_id = WBAM_Shopify::gid_to_id($p['id']);
        try {
            self::publish_to_pos((string) $p['id']);
        } catch (Throwable $e) {
            WBAM_Sync::queue('publish_product', ['product_id' => $product_id], $e->getMessage());
        }
        return [
            'product_id'        => $product_id,
            'variant_id'        => WBAM_Shopify::gid_to_id($v['id']),
            'inventory_item_id' => WBAM_Shopify::gid_to_id($v['inventoryItem']['id']),
        ];
    }

    /** Publish a product to the Point of Sale channel (publication id cached). */
    public static function publish_to_pos(string $product_gid): void {
        $pub = WBAM_Settings::state_get('pos_publication_id');
        if (!$pub) {
            $res = WBAM_Shopify::i()->graphql('query { publications(first: 20) { nodes { id catalog { title } } } }');
            foreach (($res['data']['publications']['nodes'] ?? []) as $n) {
                if (stripos((string) ($n['catalog']['title'] ?? ''), 'point of sale') !== false) {
                    $pub = $n['id'];
                    WBAM_Settings::state_set('pos_publication_id', $pub);
                    break;
                }
            }
        }
        if (!$pub) throw new RuntimeException('POS publication not found.');
        $res = WBAM_Shopify::i()->graphql(
            'mutation($id: ID!, $input: [PublicationInput!]!) {
                publishablePublish(id: $id, input: $input) { userErrors { field message } }
            }',
            ['id' => $product_gid, 'input' => [['publicationId' => $pub]]]
        );
        $errs = $res['data']['publishablePublish']['userErrors'] ?? [];
        if ($errs) throw new RuntimeException('POS publish failed: ' . wp_json_encode($errs));
    }

    /**
     * Find the variant matching the given option values; create it if missing.
     * $selected = ['Colour' => 'Black', 'Storage' => '128GB', 'Condition' => 'Used (A - Excellent)']
     * Returns ['variant_id','inventory_item_id','sku','barcode','price','title'].
     */
    public static function ensure_variant(int $product_id, array $selected, ?float $price = null): array {
        $gid = WBAM_Shopify::gid('Product', $product_id);
        $q = 'query($id: ID!) {
            product(id: $id) {
                options { name }
                variants(first: 250) {
                    nodes {
                        id sku barcode price title
                        selectedOptions { name value }
                        inventoryItem { id }
                    }
                }
            }
        }';
        $res  = WBAM_Shopify::i()->graphql($q, ['id' => $gid]);
        $prod = $res['data']['product'] ?? null;
        if (!$prod) throw new RuntimeException("Product $product_id not found.");

        $optionNames = array_map(fn($o) => $o['name'], $prod['options'] ?? []);

        foreach (($prod['variants']['nodes'] ?? []) as $v) {
            $vals = [];
            foreach ($v['selectedOptions'] as $so) $vals[$so['name']] = $so['value'];
            $match = true;
            foreach ($selected as $name => $want) {
                if (!isset($vals[$name]) || strcasecmp(trim($vals[$name]), trim($want)) !== 0) { $match = false; break; }
            }
            if ($match) return self::variant_out($v);
        }

        // Not found — create it (creates missing option values too).
        $optionValues = [];
        foreach ($optionNames as $name) {
            if (!isset($selected[$name])) {
                throw new RuntimeException("Missing option '$name' for new variant.");
            }
            $optionValues[] = ['optionName' => $name, 'name' => $selected[$name]];
        }
        $m = 'mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
            productVariantsBulkCreate(productId: $productId, variants: $variants) {
                productVariants { id sku barcode price title selectedOptions { name value } inventoryItem { id } }
                userErrors { field message }
            }
        }';
        $variant = ['optionValues' => $optionValues];
        if ($price !== null) $variant['price'] = number_format($price, 2, '.', '');
        $res = WBAM_Shopify::i()->graphql($m, ['productId' => $gid, 'variants' => [$variant]]);
        $errs = $res['data']['productVariantsBulkCreate']['userErrors'] ?? [];
        if ($errs) throw new RuntimeException('Variant create failed: ' . wp_json_encode($errs));
        $v = $res['data']['productVariantsBulkCreate']['productVariants'][0] ?? null;
        if (!$v) throw new RuntimeException('Variant create returned nothing.');
        return self::variant_out($v);
    }

    private static function variant_out(array $v): array {
        return [
            'variant_id'        => WBAM_Shopify::gid_to_id($v['id']),
            'inventory_item_id' => WBAM_Shopify::gid_to_id($v['inventoryItem']['id']),
            'sku'               => (string) ($v['sku'] ?? ''),
            'barcode'           => (string) ($v['barcode'] ?? ''),
            'price'             => (float) ($v['price'] ?? 0),
            'title'             => (string) ($v['title'] ?? ''),
        ];
    }

    /**
     * Make sure the variant has a barcode value (what the shelf label encodes and
     * what POS scanning matches). Uses the SKU when present, else WBAM-V{variant_id}.
     */
    public static function ensure_pool_barcode(int $product_id, array $variant): string {
        if ($variant['barcode'] !== '') return $variant['barcode'];
        $code = $variant['sku'] !== '' ? $variant['sku'] : ('WBAM-V' . $variant['variant_id']);
        $m = 'mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
            productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                userErrors { field message }
            }
        }';
        $res = WBAM_Shopify::i()->graphql($m, [
            'productId' => WBAM_Shopify::gid('Product', $product_id),
            'variants'  => [[ 'id' => WBAM_Shopify::gid('ProductVariant', $variant['variant_id']), 'barcode' => $code ]],
        ]);
        $errs = $res['data']['productVariantsBulkUpdate']['userErrors'] ?? [];
        if ($errs) throw new RuntimeException('Barcode update failed: ' . wp_json_encode($errs));
        return $code;
    }

    /**
     * Adjust pooled available quantity at a location.
     * Implemented as read-current → set(current+delta) with compareQuantity as an
     * optimistic lock (inventoryAdjustQuantities on 'available' now demands
     * changeFromQuantity on this API version). Retries on concurrent changes,
     * and auto-activates the item at locations where it isn't stocked yet.
     */
    public static function adjust_inventory(int $inventory_item_id, int $location_id, int $delta, string $reason = 'correction'): void {
        if ($delta === 0) return;
        $itemGid = WBAM_Shopify::gid('InventoryItem', $inventory_item_id);
        $locGid  = WBAM_Shopify::gid('Location', $location_id);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $res = WBAM_Shopify::i()->graphql(
                'query($item: ID!, $loc: ID!) {
                    inventoryItem(id: $item) { inventoryLevel(locationId: $loc) { quantities(names: ["available"]) { quantity } } }
                }',
                ['item' => $itemGid, 'loc' => $locGid]
            );
            $level = $res['data']['inventoryItem']['inventoryLevel'] ?? null;

            if ($level === null) {
                // Not stocked at this location yet (e.g. a new branch) — activate with the target qty.
                $res2 = WBAM_Shopify::i()->graphql(
                    'mutation($item: ID!, $loc: ID!, $available: Int) {
                        inventoryActivate(inventoryItemId: $item, locationId: $loc, available: $available) { userErrors { field message } }
                    }',
                    ['item' => $itemGid, 'loc' => $locGid, 'available' => max($delta, 0)]
                );
                $errs = $res2['data']['inventoryActivate']['userErrors'] ?? [];
                if ($errs) throw new RuntimeException('Inventory activate failed: ' . wp_json_encode($errs));
                return;
            }

            $current = (int) ($level['quantities'][0]['quantity'] ?? 0);
            // 2026-07 shape: adjust with changeFromQuantity = built-in compare-and-swap.
            $res3 = WBAM_Shopify::i()->graphql(
                'mutation($input: InventoryAdjustQuantitiesInput!) {
                    inventoryAdjustQuantities(input: $input) { userErrors { field message } }
                }',
                ['input' => [
                    'name'    => 'available',
                    'reason'  => $reason,
                    'changes' => [[
                        'inventoryItemId'    => $itemGid,
                        'locationId'         => $locGid,
                        'delta'              => $delta,
                        'changeFromQuantity' => $current,
                    ]],
                ]]
            );
            $errs = $res3['data']['inventoryAdjustQuantities']['userErrors'] ?? [];
            if (!$errs) return;
            $msg = wp_json_encode($errs);
            if (stripos($msg, 'changeFromQuantity') === false && stripos($msg, 'stale') === false && stripos($msg, 'compare') === false) {
                throw new RuntimeException('Inventory adjust failed: ' . $msg);
            }
            // Quantity moved mid-flight (a sale?) — re-read and retry.
        }
        throw new RuntimeException('Inventory adjust failed after retries (concurrent changes).');
    }

    /** Set absolute available quantity (stocktake reconciliation). 2026-07 shape — no compare fields. */
    public static function set_inventory(array $quantities): void {
        // $quantities: [ ['inventory_item_id'=>..,'location_id'=>..,'quantity'=>..], ... ]
        if (!$quantities) return;
        $m = 'mutation($input: InventorySetQuantitiesInput!) {
            inventorySetQuantities(input: $input) { userErrors { field message } }
        }';
        $items = array_map(fn($r) => [
            'inventoryItemId' => WBAM_Shopify::gid('InventoryItem', $r['inventory_item_id']),
            'locationId'      => WBAM_Shopify::gid('Location', $r['location_id']),
            'quantity'        => (int) $r['quantity'],
        ], $quantities);
        $res = WBAM_Shopify::i()->graphql($m, ['input' => [
            'name' => 'available', 'reason' => 'correction',
            'quantities' => $items,
        ]]);
        $errs = $res['data']['inventorySetQuantities']['userErrors'] ?? [];
        if ($errs) throw new RuntimeException('Inventory set failed: ' . wp_json_encode($errs));
    }

    /** Keep Shopify's variant cost = rolling average purchase price of in-stock units. */
    public static function refresh_variant_cost(int $variant_id, int $inventory_item_id): void {
        global $wpdb;
        $avg = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(purchase_price) FROM {$wpdb->prefix}wbam_units WHERE variant_id=%d AND status='in_stock'",
            $variant_id
        ));
        if ($avg === null) return; // none left in stock — keep last cost for reference
        $m = 'mutation($id: ID!, $input: InventoryItemInput!) {
            inventoryItemUpdate(id: $id, input: $input) { userErrors { field message } }
        }';
        $res = WBAM_Shopify::i()->graphql($m, [
            'id'    => WBAM_Shopify::gid('InventoryItem', $inventory_item_id),
            'input' => ['cost' => number_format((float) $avg, 2, '.', '')],
        ]);
        $errs = $res['data']['inventoryItemUpdate']['userErrors'] ?? [];
        if ($errs) throw new RuntimeException('Cost update failed: ' . wp_json_encode($errs));
    }

    /** Batch-fetch unit costs for variants (accessory COGS backfill). Returns variant_id => cost|null. */
    public static function fetch_costs(array $variant_ids): array {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($variant_ids))), 50) as $chunk) {
            $ids = array_map(fn($id) => WBAM_Shopify::gid('ProductVariant', $id), $chunk);
            $q = 'query($ids: [ID!]!) {
                nodes(ids: $ids) {
                    ... on ProductVariant { id inventoryItem { unitCost { amount } } }
                }
            }';
            $res = WBAM_Shopify::i()->graphql($q, ['ids' => $ids]);
            foreach (($res['data']['nodes'] ?? []) as $n) {
                if (!$n) continue;
                $out[WBAM_Shopify::gid_to_id($n['id'])] = isset($n['inventoryItem']['unitCost']['amount'])
                    ? (float) $n['inventoryItem']['unitCost']['amount'] : null;
            }
        }
        return $out;
    }
}
