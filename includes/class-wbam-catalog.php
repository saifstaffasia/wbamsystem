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
        // Devices only — no cases, cables or screen protectors in the pickers.
        // (Types were normalised store-wide 19 Aug 2026: phones=Phone, etc.)
        // Split words into ANDed title clauses so "iphone 16 pro" matches
        // "iPhone 16 Pro (Max)" only — not every product containing "iphone".
        $devices = '(product_type:Phone OR product_type:Laptop OR product_type:Tablet OR product_type:Smartwatch) AND status:active';
        $words = preg_split('/\s+/', trim(str_replace('"', '', $term))) ?: [];
        $clauses = array_map(fn($w) => 'title:*' . $w . '*', array_filter($words));
        $search = $devices . ($clauses ? ' AND ' . implode(' AND ', $clauses) : '');
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
     * the catalog). Single variant, Condition option = grade, sku = unit code,
     * barcode = in-store EAN-13, cost + qty 1 set in one call, published to POS.
     */
    public static function create_custom_product(string $title, string $grade, float $sell, float $cost, string $sku, string $barcode, int $location_id): array {
        $m = 'mutation($input: ProductSetInput!) {
            productSet(input: $input, synchronous: true)%IDEM% {
                product { id variants(first: 1) { nodes { id inventoryItem { id } } } }
                userErrors { field message }
            }
        }';
        $res = self::run_idem($m, ['input' => [
            'title'          => $title,
            'status'         => 'ACTIVE',
            'productType'    => 'Phone',
            'vendor'         => 'WeBuyAnyMobile',
            'tags'           => ['wbam-custom'],
            'productOptions' => [['name' => 'Condition', 'values' => [['name' => $grade]]]],
            'variants'       => [[
                'optionValues'        => [['optionName' => 'Condition', 'name' => $grade]],
                'price'               => number_format($sell, 2, '.', ''),
                'sku'                 => $sku,
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

    /**
     * Append key/value pairs to an order's custom attributes ("Additional details"
     * in admin) — e.g. "Sold U00042" → "iPhone 16 Pro … — IMEI 358…". Merges with
     * whatever is already there.
     */
    public static function append_order_attributes(int $order_id, array $kv): void {
        $gid = WBAM_Shopify::gid('Order', $order_id);
        $res = WBAM_Shopify::i()->graphql(
            'query($id: ID!) { order(id: $id) { customAttributes { key value } } }',
            ['id' => $gid]
        );
        $attrs = [];
        foreach (($res['data']['order']['customAttributes'] ?? []) as $a) {
            $attrs[(string) $a['key']] = (string) ($a['value'] ?? '');
        }
        foreach ($kv as $k => $v) $attrs[(string) $k] = (string) $v;
        $input = [];
        foreach ($attrs as $k => $v) $input[] = ['key' => $k, 'value' => $v];

        $res2 = self::run_idem(
            'mutation($input: OrderInput!) { orderUpdate(input: $input)%IDEM% { userErrors { field message } } }',
            ['input' => ['id' => $gid, 'customAttributes' => $input]]
        );
        $errs = $res2['data']['orderUpdate']['userErrors'] ?? [];
        if ($errs) throw new RuntimeException('Order attributes failed: ' . wp_json_encode($errs));
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
        $res = self::run_idem(
            'mutation($id: ID!, $input: [PublicationInput!]!) {
                publishablePublish(id: $id, input: $input)%IDEM% { userErrors { field message } }
            }',
            ['id' => $product_gid, 'input' => [['publicationId' => $pub]]]
        );
        $errs = $res['data']['publishablePublish']['userErrors'] ?? [];
        if ($errs) throw new RuntimeException('POS publish failed: ' . wp_json_encode($errs));
    }

    /**
     * Look up (never create) the variant matching the option values — used to
     * prefill the intake's Selling price with the current shelf price.
     */
    public static function peek_variant(int $product_id, array $selected): array {
        $gid = WBAM_Shopify::gid('Product', $product_id);
        $q = 'query($id: ID!) {
            product(id: $id) {
                variants(first: 250) { nodes { price title selectedOptions { name value } } }
            }
        }';
        $res  = WBAM_Shopify::i()->graphql($q, ['id' => $gid]);
        $prod = $res['data']['product'] ?? null;
        foreach ((array) ($prod['variants']['nodes'] ?? []) as $v) {
            $vals = [];
            foreach ($v['selectedOptions'] as $so) $vals[$so['name']] = $so['value'];
            $match = true;
            foreach ($selected as $name => $want) {
                if (!isset($vals[$name]) || strcasecmp(trim($vals[$name]), trim($want)) !== 0) { $match = false; break; }
            }
            if ($match) return ['exists' => true, 'price' => (float) $v['price'], 'title' => (string) $v['title']];
        }
        return ['exists' => false, 'price' => null, 'title' => ''];
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
            if ($match) {
                // Staff gave a selling price — it wins over the current shelf price.
                if ($price !== null && abs((float) $v['price'] - $price) >= 0.01) {
                    $upd = WBAM_Shopify::i()->graphql(
                        'mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                            productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                                userErrors { field message }
                            }
                        }',
                        ['productId' => $gid, 'variants' => [[
                            'id'    => $v['id'],
                            'price' => number_format($price, 2, '.', ''),
                        ]]]
                    );
                    $uerrs = $upd['data']['productVariantsBulkUpdate']['userErrors'] ?? [];
                    if (!$uerrs) $v['price'] = number_format($price, 2, '.', '');
                }
                return self::variant_out($v);
            }
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
     * Import the bundled Samsung catalog (data/samsung-catalog.json — CEX-based
     * pricing) in a ~30s budget per call; keeps a cursor, click again to
     * continue. Existing titles are skipped, so it is safe to re-run.
     * Returns ['done'=>bool,'created'=>[titles],'skipped'=>n,'at'=>i,'total'=>n].
     */
    public static function seed_samsung(int $budget_s = 30): array {
        $file = WBAM_DIR . 'data/samsung-catalog.json';
        if (!is_readable($file)) throw new RuntimeException('samsung-catalog.json missing from the plugin.');
        $rows = json_decode((string) file_get_contents($file), true);
        if (!is_array($rows) || !$rows) throw new RuntimeException('samsung-catalog.json unreadable.');

        $i = (int) WBAM_Settings::state_get('samsung_seed_at', 0);
        $t0 = time();
        $created = [];
        $skipped = 0;
        $grades = ['Used (A - Excellent)', 'Used (B - Very Good)', 'Used (C - Good)'];
        $glet   = ['Used (A - Excellent)' => 'A', 'Used (B - Very Good)' => 'B', 'Used (C - Good)' => 'C'];

        for (; $i < count($rows) && (time() - $t0) < $budget_s; $i++) {
            $row = $rows[$i];
            $title = (string) $row['title'];
            // Idempotent: skip if a product with this exact title already exists.
            $q = WBAM_Shopify::i()->graphql(
                'query($q: String!) { products(first: 1, query: $q) { nodes { title } } }',
                ['q' => 'title:"' . addslashes($title) . '"']
            );
            $hit = $q['data']['products']['nodes'][0]['title'] ?? '';
            if (strcasecmp($hit, $title) === 0) { $skipped++; WBAM_Settings::state_set('samsung_seed_at', $i + 1); continue; }

            $storages = array_keys((array) $row['prices']);
            $codes = [];
            $used  = [];
            foreach ((array) $row['colours'] as $col) {
                $base = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', str_replace(' ', '', $col)), 0, 3));
                $code = $base;
                $n = 2;
                while (in_array($code, $used, true)) $code = $base . $n++;
                $used[] = $code;
                $codes[$col] = $code;
            }
            $variants = [];
            foreach ((array) $row['colours'] as $col) {
                foreach ($storages as $st) {
                    foreach ($grades as $gr) {
                        $variants[] = [
                            'optionValues' => [
                                ['optionName' => 'Colour', 'name' => $col],
                                ['optionName' => 'Storage', 'name' => $st],
                                ['optionName' => 'Condition', 'name' => $gr],
                            ],
                            'price' => number_format((float) $row['prices'][$st][$gr], 2, '.', ''),
                            'sku'   => 'SAM-' . str_replace(' ', '', str_replace('Samsung Galaxy ', '', $title))
                                     . '-' . str_replace(['GB', 'TB'], ['', 'T'], $st) . '-' . $codes[$col] . '-' . $glet[$gr],
                            'taxable'       => true,
                            'inventoryItem' => ['tracked' => true],
                        ];
                    }
                }
            }
            $input = [
                'title'          => $title,
                'status'         => 'ACTIVE',
                'productType'    => 'Phone',
                'vendor'         => 'Samsung',
                'category'       => 'gid://shopify/TaxonomyCategory/el-4-8-5-2', // Smart Phones
                'tags'           => ['Samsung', 'Phones'],
                'productOptions' => [
                    ['name' => 'Colour', 'values' => array_map(fn($c) => ['name' => $c], (array) $row['colours'])],
                    ['name' => 'Storage', 'values' => array_map(fn($s) => ['name' => $s], $storages)],
                    ['name' => 'Condition', 'values' => array_map(fn($g) => ['name' => $g], $grades)],
                ],
                'variants' => $variants,
            ];
            $res = self::run_idem(
                'mutation($input: ProductSetInput!) { productSet(input: $input, synchronous: true)%IDEM% {
                    product { id options { id name } } userErrors { field message } } }',
                ['input' => $input]
            );
            $errs = $res['data']['productSet']['userErrors'] ?? [];
            if ($errs) throw new RuntimeException($title . ': ' . wp_json_encode($errs));
            $gid = (string) ($res['data']['productSet']['product']['id'] ?? '');
            if ($gid) {
                try { self::publish_to_pos($gid); } catch (Throwable $e) {}
                try { self::publish_to_online($gid); } catch (Throwable $e) {}
                // "New" sells at a staff-set price, so it exists as a pickable
                // condition with no pre-made variants.
                foreach (($res['data']['productSet']['product']['options'] ?? []) as $opt) {
                    if (strcasecmp((string) $opt['name'], 'Condition') !== 0) continue;
                    try {
                        self::run_idem(
                            'mutation($pid: ID!, $opt: ID!) { productOptionUpdate(productId: $pid, option: {id: $opt}, optionValuesToAdd: [{name: "New"}])%IDEM% { userErrors { field message } } }',
                            ['pid' => $gid, 'opt' => $opt['id']]
                        );
                    } catch (Throwable $e) {}
                    break;
                }
            }
            $created[] = $title;
            WBAM_Settings::state_set('samsung_seed_at', $i + 1);
        }
        $done = $i >= count($rows);
        if ($done) WBAM_Settings::state_set('samsung_seed_at', 0);
        return ['done' => $done, 'created' => $created, 'skipped' => $skipped, 'at' => $i, 'total' => count($rows)];
    }

    /** Publish a product to the Online Store channel (publication id cached). */
    public static function publish_to_online(string $product_gid): void {
        $pub = WBAM_Settings::state_get('online_publication_id');
        if (!$pub) {
            $res = WBAM_Shopify::i()->graphql('query { publications(first: 20) { nodes { id catalog { title } } } }');
            foreach (($res['data']['publications']['nodes'] ?? []) as $n) {
                if (stripos((string) ($n['catalog']['title'] ?? ''), 'online store') !== false) {
                    $pub = $n['id'];
                    WBAM_Settings::state_set('online_publication_id', $pub);
                    break;
                }
            }
        }
        if (!$pub) return; // no online store channel — POS-only shops
        $res = self::run_idem(
            'mutation($id: ID!, $input: [PublicationInput!]!) {
                publishablePublish(id: $id, input: $input)%IDEM% { userErrors { field message } }
            }',
            ['id' => $product_gid, 'input' => [['publicationId' => $pub]]]
        );
    }

    /** EAN-13 check digit for a 12-digit payload. */
    public static function ean13_check(string $d12): string {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += ((int) $d12[$i]) * ($i % 2 ? 3 : 1);
        return (string) ((10 - $sum % 10) % 10);
    }

    /** Next in-store EAN-13 (GS1 prefix "2" = internal/in-store numbers). */
    public static function next_ean(): string {
        $seq = (int) get_option('wbam_pool_seq', 0) + 1;
        update_option('wbam_pool_seq', $seq, false);
        $payload = '2' . str_pad((string) $seq, 11, '0', STR_PAD_LEFT);
        return $payload . self::ean13_check($payload);
    }

    /** Is this one of our in-store EAN-13 codes? */
    public static function is_store_ean(string $code): bool {
        return strlen($code) === 13 && ctype_digit($code) && $code[0] === '2'
            && self::ean13_check(substr($code, 0, 12)) === $code[12];
    }

    /**
     * Every pooled variant carries a scanner-friendly in-store EAN-13 as its
     * Shopify barcode — what the shelf label encodes and what a POS scan
     * matches. Older SKU-string barcodes are upgraded on sight.
     */
    public static function ensure_pool_barcode(int $product_id, array $variant): string {
        if (self::is_store_ean((string) $variant['barcode'])) return (string) $variant['barcode'];
        $code = self::next_ean();
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
     * Run a mutation; if the API demands the @idempotent directive (2026-07
     * requires it on inventory-write mutations), retry once with a fresh key.
     * $tpl contains %IDEM% right after the mutation field call site.
     */
    private static function run_idem(string $tpl, array $vars): array {
        try {
            return WBAM_Shopify::i()->graphql(str_replace('%IDEM%', '', $tpl), $vars);
        } catch (RuntimeException $e) {
            if (stripos($e->getMessage(), 'idempotent') === false) throw $e;
            $with = str_replace('%IDEM%', ' @idempotent(key: "' . wp_generate_uuid4() . '")', $tpl);
            return WBAM_Shopify::i()->graphql($with, $vars);
        }
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
                $res2 = self::run_idem(
                    'mutation($item: ID!, $loc: ID!, $available: Int) {
                        inventoryActivate(inventoryItemId: $item, locationId: $loc, available: $available)%IDEM% { userErrors { field message } }
                    }',
                    ['item' => $itemGid, 'loc' => $locGid, 'available' => max($delta, 0)]
                );
                $errs = $res2['data']['inventoryActivate']['userErrors'] ?? [];
                if ($errs) throw new RuntimeException('Inventory activate failed: ' . wp_json_encode($errs));
                return;
            }

            $current = (int) ($level['quantities'][0]['quantity'] ?? 0);
            // 2026-07 shape: adjust with changeFromQuantity (compare-and-swap) + required @idempotent key.
            $res3 = WBAM_Shopify::i()->graphql(
                'mutation($input: InventoryAdjustQuantitiesInput!) {
                    inventoryAdjustQuantities(input: $input) @idempotent(key: "' . wp_generate_uuid4() . '") { userErrors { field message } }
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
            inventorySetQuantities(input: $input)%IDEM% { userErrors { field message } }
        }';
        $items = array_map(fn($r) => [
            'inventoryItemId' => WBAM_Shopify::gid('InventoryItem', $r['inventory_item_id']),
            'locationId'      => WBAM_Shopify::gid('Location', $r['location_id']),
            'quantity'        => (int) $r['quantity'],
        ], $quantities);
        $res = self::run_idem($m, ['input' => [
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
