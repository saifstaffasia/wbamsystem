<?php
/**
 * Public sell-your-phone quotes, backed by data/buy-prices.json.
 *
 * The JSON is harvested from CEX's public search index:
 *   cash    = CEX "Trade-in for cash"      (our main advertised price)
 *   voucher = CEX "Trade-in for voucher"   (our trade-in / store-credit price)
 * both medians per model+storage+grade, rounded UP to the next £5.
 *
 * Grades: A = like new, B = good/light wear, C = working/heavy wear.
 */

if (!defined('ABSPATH')) exit;

class WBAM_Quotes {

    private static ?array $data = null;

    private static function data(): array {
        if (self::$data !== null) return self::$data;
        $file = WBAM_DIR . 'data/buy-prices.json';
        $j = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        return self::$data = (is_array($j) && !empty($j['devices'])) ? $j : ['devices' => []];
    }

    /**
     * Squash a device name to bare lowercase alphanumerics with brand words removed:
     * "Apple iPhone 15 Pro Max" → "iphone15promax", "galaxy s 24 ultra" → "s24ultra".
     * Comparing squashed strings makes matching immune to spacing ("15promax"),
     * word order artefacts and punctuation; levenshtein on top absorbs typos.
     */
    private static function squash(string $s): string {
        $s = strtolower($s);
        $s = str_replace(['+', '&'], [' plus ', ' '], $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $s = preg_replace('/\b(apple|samsung|galaxy)\b/', ' ', $s);
        $s = preg_replace('/\b(1st|first)\s*(gen|generation)?\b/', '2016', $s); // SE naming
        $s = preg_replace('/\b(2nd|second)\s*(gen|generation)?\b/', '2020', $s);
        $s = preg_replace('/\b(3rd|third)\s*(gen|generation)?\b/', '2022', $s);
        return preg_replace('/[^a-z0-9]/', '', $s);
    }

    /**
     * Fuzzy-match a free-typed device to a model in the price book.
     * Ranking: exact squash → query inside model (prefix beats infix, fewest
     * leftover chars) → model inside query (extra words typed, e.g. colours)
     * → levenshtein ≤1 (≥5 chars) / ≤2 (≥9 chars) for spelling mistakes.
     */
    public static function match(string $device): ?string {
        $q = self::squash($device);
        if ($q === '' || strlen($q) < 2) return null;
        $best = null; $bestScore = PHP_INT_MAX;
        foreach (array_keys(self::data()['devices']) as $model) {
            $m = self::squash($model);
            if ($m === $q) return $model;
            $score = null;
            $pos = strpos($m, $q);
            if ($pos !== false) {
                $score = ($pos === 0 ? 10 : 25) + (strlen($m) - strlen($q));
            } elseif (str_ends_with($q, $m) || (strlen($m) >= 4 && str_contains($q, $m))) {
                // extra words typed around the model ("galxy s24" → ...s24, "s24 ultra 512gb")
                $score = 50 + (strlen($q) - strlen($m));
            } else {
                $len = max(strlen($q), strlen($m));
                $tol = $len >= 9 ? 2 : ($len >= 5 ? 1 : 0);
                if ($tol > 0 && abs(strlen($q) - strlen($m)) <= $tol) {
                    $d = levenshtein($q, $m);
                    if ($d <= $tol) $score = 70 + $d;
                }
            }
            if ($score !== null && $score < $bestScore) { $bestScore = $score; $best = $model; }
        }
        return $best;
    }

    /** All priced models with their top cash price — feeds the storefront typeahead. */
    public static function device_list(): array {
        $out = [];
        foreach (self::data()['devices'] as $model => $tiers) {
            $cash = 0;
            foreach ($tiers as $grades) {
                foreach ($grades as $cv) $cash = max($cash, (int) $cv[0]);
            }
            $out[] = ['name' => $model, 'upto' => $cash];
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    /** Full quote payload for one device, or null when we don't price it. */
    public static function quote(string $device): ?array {
        $model = self::match($device);
        if (!$model) return null;
        $d = self::data();
        $tiers = $d['devices'][$model];
        $cash = $vou = 0;
        foreach ($tiers as $grades) {
            foreach ($grades as $cv) {
                $cash = max($cash, (int) $cv[0]);
                $vou  = max($vou, (int) $cv[1]);
            }
        }
        return [
            'model'        => $model,
            'tiers'        => $tiers,
            'upto'         => ['cash' => $cash, 'voucher' => $vou],
            'grades'       => $d['grades'] ?? [],
            'harvested_at' => $d['harvested_at'] ?? '',
        ];
    }

    /** Highest cash price in the whole book (hero "up to £X" strapline). */
    public static function upto(): array {
        $cash = $vou = 0;
        foreach (self::data()['devices'] as $tiers) {
            foreach ($tiers as $grades) {
                foreach ($grades as $cv) {
                    $cash = max($cash, (int) $cv[0]);
                    $vou  = max($vou, (int) $cv[1]);
                }
            }
        }
        return ['cash' => $cash, 'voucher' => $vou];
    }
}
