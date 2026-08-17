<?php
if (!defined('ABSPATH')) exit;

/**
 * Printable, pre-filled "Seller Declaration & Mobile Phone Purchase Agreement" —
 * mirrors the paper form. Print → seller ticks + signs → file the copy.
 */
class WBAM_Declaration {

    public static function render_from_request(): void {
        $unit_id = (int) ($_GET['unit'] ?? 0);
        if (!is_user_logged_in() || !current_user_can('wbam_use') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wbam_decl_' . $unit_id)) {
            wp_die('Not allowed.');
        }
        $u = WBAM_Units::get($unit_id);
        if (!$u) wp_die('Unknown unit.');
        self::render($u);
        exit;
    }

    private static function row(string $l1, string $v1, string $l2, string $v2): string {
        $line = '_______________________';
        return '<tr><th>' . esc_html($l1) . '</th><td>' . ($v1 !== '' ? esc_html($v1) : $line) . '</td>'
             . '<th>' . esc_html($l2) . '</th><td>' . ($v2 !== '' ? esc_html($v2) : $line) . '</td></tr>';
    }

    public static function render(array $u): void {
        global $wpdb;
        $s      = WBAM_Settings::all();
        $seller = $u['seller'] ?? [];
        $branch = WBAM_Settings::branch((int) $u['branch_id']);
        $staff  = $u['created_by'] ? (get_userdata((int) $u['created_by'])->display_name ?? '') : '';
        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wbam_payouts WHERE unit_id=%d ORDER BY id DESC LIMIT 1", (int) $u['id']
        ), ARRAY_A);
        $bank = $payout && !empty($payout['details']) ? (json_decode($payout['details'], true) ?: []) : [];
        $method_label = ['cash' => 'Cash', 'bank' => 'Bank transfer', 'store_credit' => 'Trade-in value / store credit'][$payout['method'] ?? ''] ?? ($payout['method'] ?? '');

        $declarations = [
            'I am the sole lawful owner, am aged 18 or over and have full authority to sell the Device.',
            'The Device is not stolen, lost, found, borrowed or held for another person and has not been reported lost or stolen.',
            'No insurance, warranty-replacement, chargeback or similar claim has been made or is pending, and I will not make or support a later claim inconsistent with this sale.',
            'The Device is not leased, rented or subject to finance, security, a lien or any third-party ownership or repossession right.',
            'The serial number, IMEI 1, IMEI 2 and EID recorded above are genuine, accurate and have not been altered or cloned.',
            'I have removed my SIM and personal data, signed out of Apple/Google accounts, disabled Find My, removed Activation Lock/FRP, passcodes and any MDM/Remote Management.',
            'I have disclosed all known network restrictions, blacklist issues, repairs, parts warnings, faults, liquid damage and other material limitations.',
        ];

        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html><head><meta charset="utf-8"><title>Seller Declaration — <?php echo esc_html($u['unit_code']); ?></title>
<style>
  @page { size: A4; margin: 12mm; }
  body { font: 9.5pt/1.45 -apple-system, Arial, sans-serif; color: #111; margin: 0; }
  h1 { font-size: 15pt; color: #1a2e5a; margin: 0 0 2mm; }
  h2 { font-size: 10.5pt; color: #1a2e5a; margin: 4.5mm 0 1.5mm; }
  .co { text-align: right; font-size: 7.5pt; color: #1a2e5a; font-weight: 700; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 1mm; }
  th, td { border: 0.3mm solid #999; padding: 1.6mm 2mm; text-align: left; vertical-align: top; }
  th { background: #eef1f7; font-weight: 600; width: 16%; }
  td { width: 34%; }
  .bar { background: #1a2e5a; color: #fff; font-weight: 700; font-size: 8.5pt; padding: 1.4mm 2mm; }
  .decl td.tick { width: 8mm; text-align: center; font-size: 12pt; }
  .terms p { margin: 0 0 1.6mm; font-size: 8pt; }
  .terms b { color: #1a2e5a; }
  .foot { text-align: center; font-size: 7.5pt; color: #555; margin-top: 3mm; }
  .sig td { height: 11mm; }
  @media screen { body { max-width: 210mm; margin: 10mm auto; } }
</style></head>
<body onload="window.print()">
  <div class="co"><?php echo esc_html(strtoupper($s['business_name'])); ?> · SELLER DECLARATION &amp; MOBILE PHONE PURCHASE AGREEMENT</div>
  <h1>Seller Declaration &amp; Mobile Phone Purchase Agreement</h1>

  <div class="bar">TRANSACTION DETAILS — SHOP TO COMPLETE</div>
  <table>
    <?php echo self::row('Transaction ref.', $u['unit_code'], 'Date / time', mysql2date('j M Y H:i', $u['created_at'])); ?>
    <?php echo self::row('Staff member', $staff, 'Branch', $branch['name'] ?? ''); ?>
  </table>

  <div class="bar">SELLER DETAILS — SELLER TO COMPLETE</div>
  <table>
    <?php echo self::row('Full legal name', $u['seller_name'] ?: ($seller['name'] ?? ''), 'Date of birth', ($seller['dob'] ?? '') . '   [ ] 18+'); ?>
    <?php echo self::row('Address line 1', $seller['address1'] ?? '', 'Postcode', $seller['postcode'] ?? ''); ?>
    <?php echo self::row('Address line 2', $seller['address2'] ?? '', 'Mobile', $seller['mobile'] ?? ''); ?>
    <?php echo self::row('Email', $seller['email'] ?? '', 'Time at address', $seller['time_at_address'] ?? ''); ?>
  </table>

  <div class="bar">DEVICE RECORD — STAFF TO COMPLETE FROM THE DEVICE</div>
  <table>
    <?php echo self::row('Make / model', trim($u['model_title'] . ' ' . $u['variant_title']), 'Condition / grade', $u['grade']); ?>
    <?php echo self::row('IMEI 1', $u['imei'], 'IMEI 2', $seller['imei2'] ?? ''); ?>
    <?php echo self::row('Serial number', $seller['serial'] ?? '', 'EID', $seller['eid'] ?? ''); ?>
    <?php echo self::row('Network lock', $seller['network_lock'] ?? '', 'Battery health', $u['battery_health'] !== null && $u['battery_health'] !== '' ? $u['battery_health'] . '%' : ''); ?>
    <?php echo self::row('Accessories', $seller['accessories'] ?? '', 'Known faults / damage', $seller['known_faults'] ?? ''); ?>
  </table>

  <div class="bar">IDENTITY, ADDRESS AND OWNERSHIP CHECKS — VERIFICATION RECORD</div>
  <table>
    <?php echo self::row('Photo ID type', $seller['id_type'] ?? '', 'ID ref. / expiry', $seller['id_ref'] ?? ''); ?>
    <?php echo self::row('Proof of address', $seller['proof_of_address'] ?? '', 'Document date', $seller['document_date'] ?? ''); ?>
    <?php echo self::row('Ownership evidence', $seller['ownership_evidence'] ?? '', 'Evidence ref.', $seller['evidence_ref'] ?? ''); ?>
    <?php echo self::row('Name/address match', '[ ] Yes  [ ] No', 'Copy retained', '[ ] Yes  [ ] No'); ?>
    <?php echo self::row('Status-check provider / ref.', $u['checkmend_ref'], 'Staff initials', ''); ?>
  </table>

  <div class="bar">PRICE AND PAYMENT — AGREED PAYMENT</div>
  <table>
    <?php echo self::row('Purchase price', '£' . number_format((float) $u['purchase_price'], 2), 'Payment method', $method_label); ?>
    <?php echo self::row('Bank account name', $bank['account_name'] ?? '', 'Payment reference', $bank['reference'] ?? ($payout['reference'] ?? '')); ?>
    <?php echo self::row('Sort code', $bank['sort_code'] ?? '', 'Account number', $bank['account_number'] ?? ''); ?>
  </table>

  <h2>Seller declarations and agreement</h2>
  <table class="decl">
    <?php foreach ($declarations as $d): ?>
      <tr><td class="tick">[&nbsp;&nbsp;]</td><td colspan="3"><?php echo esc_html($d); ?></td></tr>
    <?php endforeach; ?>
  </table>

  <div class="terms">
    <h2>Terms</h2>
    <p><b>Sale and title.</b> The Seller sells the Device to Cellentric Ltd for the price above. Title and risk pass when both parties sign and payment is made in cleared funds, unless a written deferred-payment arrangement states otherwise. The Shop receives exclusive, peaceful possession free from undisclosed third-party rights.</p>
    <p><b>Inspection and rejection.</b> Before acceptance the Shop may carry out proportionate identity, ownership, identifier, blacklist, finance, lock and functional checks and may reject the Device for a reasonable device- or verification-related reason. A clear check does not waive rights if a declaration later proves false.</p>
    <p><b>Later report or restriction.</b> If the Device is later blocked, blacklisted or claimed because of the Seller or a prior source, the Seller must respond promptly, provide truthful reasonable assistance and, where the Seller made or controls the report, withdraw or correct it. Nothing requires interference with a genuine police, insurer or network investigation.</p>
    <p><b>Direct losses.</b> If a Seller breach causes loss, the Seller is responsible for all direct, reasonable and evidenced loss, which may include the price paid, the non-duplicated net cost of a customer refund or replacement, and reasonable recovery, postage, inspection or external legal costs lawfully recoverable.</p>
    <p><b>Data and enquiries.</b> The Shop may use personal data to verify identity/ownership, administer the purchase, prevent and detect fraud, protect customers, resolve disputes and comply with law. Where lawful, necessary and proportionate it may share relevant information with police, networks, insurers, manufacturers, device registries, fraud-prevention services, payment providers, advisers and an affected customer. This acknowledgement is not blanket GDPR consent or the Shop's sole lawful basis.</p>
    <p><b>Privacy and statutory rights.</b> The Seller confirms receipt of, or access to, the Shop's privacy notice. Nothing excludes a right, remedy or liability that cannot lawfully be excluded. Any unfair or unlawful term is limited or removed only as necessary; the remaining terms continue.</p>
    <p><b>Law and changes.</b> English and Welsh law applies and its courts have non-exclusive jurisdiction, subject to mandatory consumer rights. No handwritten change is effective unless clear and initialled by both parties. The Shop may not alter the signed agreement unilaterally.</p>
    <p>Privacy policy, GDPR policy and Terms of Service: https://webuyanymobile.com. By signing below, the seller agrees to all the terms &amp; policies mentioned above.</p>
  </div>

  <div class="bar">SIGNATURES — BOTH PARTIES CONFIRM THE DETAILS ARE COMPLETE, THE TERMS WERE READ, AND THE SELLER RECEIVED A COPY</div>
  <table class="sig">
    <?php echo self::row('Seller name', $u['seller_name'], 'Seller signature', ''); ?>
    <?php echo self::row('Staff name', $staff, 'Staff signature', ''); ?>
    <?php echo self::row('Date / time', mysql2date('j M Y H:i', $u['created_at']), 'Optional witness', ''); ?>
  </table>

  <div class="foot">Seller Declaration &amp; Mobile Phone Purchase Agreement · Cellentric Ltd (Trading as We Buy Any Mobile) · 78 Ilford Lane, IG1 2LA · <?php echo esc_html($u['unit_code']); ?></div>
</body></html><?php
    }
}
