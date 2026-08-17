<?php
if (!defined('ABSPATH')) exit;

/**
 * Shelf-label printing. Renders a print-ready page sized for the thermal
 * label printer (default 40×30 mm) and auto-opens the print dialog.
 * The big barcode encodes the POOL barcode (variant barcode) so a native
 * POS scan finds the right product/price. Unit code + IMEI tail are printed
 * for exact-unit identification.
 */
class WBAM_Labels {

    public static function url(int $unit_id): string {
        return wp_nonce_url(admin_url('admin-post.php?action=wbam_label&unit=' . $unit_id), 'wbam_label_' . $unit_id);
    }

    public static function render_from_request(): void {
        $unit_id = (int) ($_GET['unit'] ?? 0);
        if (!current_user_can('wbam_use') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wbam_label_' . $unit_id)) {
            wp_die('Not allowed.');
        }
        $u = WBAM_Units::get($unit_id);
        if (!$u) wp_die('Unknown unit.');
        self::render($u);
        exit;
    }

    public static function render(array $u): void {
        $s = WBAM_Settings::all();
        $w = (int) $s['label_w_mm'];
        $h = (int) $s['label_h_mm'];
        $barcode = $u['pool_barcode'] ?: $u['sku'];
        $imei_tail = substr($u['imei'], -6);
        $price = null;
        // Show the pooled variant's current price if we can get it quickly; not fatal if not.
        try {
            $res = WBAM_Shopify::i()->graphql(
                'query($id: ID!) { productVariant(id: $id) { price } }',
                ['id' => WBAM_Shopify::gid('ProductVariant', (int) $u['variant_id'])]
            );
            $price = $res['data']['productVariant']['price'] ?? null;
        } catch (Throwable $e) {}
        $js = WBAM_URL . 'assets/js/vendor/JsBarcode.all.min.js?v=' . WBAM_VER;
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Label <?php echo esc_html($u['unit_code']); ?></title>
<script src="<?php echo esc_url($js); ?>"></script>
<style>
  @page { size: <?php echo $w; ?>mm <?php echo $h; ?>mm; margin: 0; }
  html, body { margin: 0; padding: 0; }
  .label {
    width: <?php echo $w; ?>mm; height: <?php echo $h; ?>mm;
    box-sizing: border-box; padding: 1mm 1.5mm;
    font-family: -apple-system, Arial, sans-serif; overflow: hidden;
    display: flex; flex-direction: column; justify-content: space-between;
  }
  .top { display: flex; justify-content: space-between; align-items: baseline; }
  .model { font-size: 7pt; font-weight: 700; white-space: nowrap; overflow: hidden; max-width: 70%; }
  .grade { font-size: 8pt; font-weight: 800; border: 0.4mm solid #000; padding: 0 1mm; }
  svg { width: 100%; height: 9mm; }
  .meta { display: flex; justify-content: space-between; font-size: 6pt; }
  .price { font-size: 9pt; font-weight: 800; text-align: right; }
  @media screen { body { background: #eee; } .label { background: #fff; margin: 10mm auto; outline: 1px dashed #999; } }
</style>
</head>
<body>
  <div class="label">
    <div class="top">
      <div class="model"><?php echo esc_html($u['model_title'] ?: $u['variant_title']); ?></div>
      <div class="grade"><?php echo esc_html(self::short_grade($u['grade'])); ?></div>
    </div>
    <div style="font-size:6pt"><?php echo esc_html($u['variant_title']); ?></div>
    <svg id="bc"></svg>
    <div class="meta">
      <span><?php echo esc_html($u['unit_code']); ?> · …<?php echo esc_html($imei_tail); ?></span>
      <span class="price"><?php echo $price !== null ? '£' . esc_html(number_format((float) $price, 2)) : ''; ?></span>
    </div>
  </div>
<script>
  JsBarcode('#bc', <?php echo wp_json_encode($barcode); ?>, {
    format: 'CODE128', displayValue: false, margin: 0, height: 34
  });
  window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });
</script>
</body>
</html><?php
    }

    private static function short_grade(string $grade): string {
        if (preg_match('/\(([A-C])\s*-/', $grade, $m)) return $m[1];
        if (stripos($grade, 'new') !== false) return 'NEW';
        return $grade !== '' ? strtoupper(substr($grade, 0, 3)) : '?';
    }
}
