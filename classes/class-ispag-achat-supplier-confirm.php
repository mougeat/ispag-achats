<?php
defined('ABSPATH') || exit;

/**
 * Confirmation d'une commande par le fournisseur : le bon de commande PDF porte un QR code (et un lien cliquable) vers une page publique
 * où le fournisseur confirme les dates de livraison de chaque ligne et indique son numéro de confirmation de commande.
 *
 * Le lien est signé (HMAC du numéro de commande et de la date d'expiration) : aucune table ni jeton à stocker, un lien modifié ou expiré est refusé.
 * La page est affichée dans la langue du fournisseur. Les prix et les marges ne sont jamais montrés.
 */
class ISPAG_Achat_Supplier_Confirm {

    const ACTION_PAGE = 'ispag_supplier_confirm';
    const ACTION_SAVE = 'ispag_supplier_confirm_save';
    const VALID_DAYS  = 120;

    public static function init() {
        foreach ([self::ACTION_PAGE => 'render_page', self::ACTION_SAVE => 'ajax_save'] as $action => $method) {
            add_action('wp_ajax_' . $action, [self::class, $method]);
            add_action('wp_ajax_nopriv_' . $action, [self::class, $method]);
        }
    }

    // ------------------------------------------------------------------ lien signé

    private static function signature(int $order_id, int $expires): string {
        return substr(hash_hmac('sha256', 'supplier-confirm|' . $order_id . '|' . $expires, wp_salt('auth')), 0, 32);
    }

    /** Adresse publique (QR code et lien du bon de commande) de la confirmation d'une commande. */
    public static function url(int $order_id): string {
        $expires = time() + self::VALID_DAYS * DAY_IN_SECONDS;
        return add_query_arg([
            'action' => self::ACTION_PAGE,
            'o'      => $order_id,
            'e'      => $expires,
            's'      => self::signature($order_id, $expires),
        ], admin_url('admin-ajax.php'));
    }

    /** @return array [numéro de commande, état] état : ok | expired | invalid */
    private static function check(array $src): array {
        $order = absint($src['o'] ?? 0);
        $exp   = absint($src['e'] ?? 0);
        $sig   = (string) ($src['s'] ?? '');
        if (!$order || !$exp || !hash_equals(self::signature($order, $exp), $sig)) return [0, 'invalid'];
        if ($exp < time()) return [$order, 'expired'];
        return [$order, 'ok'];
    }

    // ------------------------------------------------------------------ données

    private static function order(int $order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT Id, hubspot_deal_id, RefCommande, IdFournisseur, ConfCmdFournisseur, created_by FROM {$wpdb->prefix}achats_commande_liste_fournisseurs WHERE Id = %d",
            $order_id
        ));
    }

    /** Langue du fournisseur (code de locale), français à défaut. */
    private static function supplier_locale(int $supplier_id): string {
        global $wpdb;
        $lang = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}ispag_companies_meta WHERE company_id = %d AND meta_key = 'ispag_supplier_lang' ORDER BY meta_id DESC LIMIT 1",
            $supplier_id
        ));
        $lang = class_exists('ISPAG_Achat_Mail_Templates') ? ISPAG_Achat_Mail_Templates::normalize_lang($lang) : $lang;
        return $lang !== '' ? $lang : 'fr_FR';
    }

    /** Lignes de la commande (sans prix) : [Id, référence, description, quantité, date confirmée Y-m-d]. */
    private static function lines(int $order_id, string $locale): array {
        $lines = [];
        $articles = (new ISPAG_Achat_Article_Repository())->get_articles_by_order(null, $order_id, $locale);
        foreach ($articles as $a) {
            if (!empty($a->archive)) continue;
            $desc = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags(str_replace(['<br>', '<br />', '<br/>'], "\n", (string) ($a->DescSurMesure ?? ''))), ENT_QUOTES, 'UTF-8')));
            $lines[] = [
                'id'   => (int) $a->Id,
                'ref'  => trim(html_entity_decode(wp_strip_all_tags((string) ($a->RefSurMesure ?? '')), ENT_QUOTES, 'UTF-8')),
                'desc' => mb_strlen($desc) > 600 ? mb_substr($desc, 0, 600) . '…' : $desc,
                'qty'  => (int) $a->Qty,
                'date' => !empty($a->TimestampDateLivraisonConfirme) ? wp_date('Y-m-d', (int) $a->TimestampDateLivraisonConfirme) : '',
            ];
        }
        return $lines;
    }

    // ------------------------------------------------------------------ page

    public static function render_page() {
        [$order_id, $state] = self::check($_GET);
        $order = $state === 'ok' ? self::order($order_id) : null;
        if ($state === 'ok' && !$order) $state = 'invalid';

        // Page dans la langue du fournisseur
        $switched = false;
        if ($order) $switched = (bool) switch_to_locale(self::supplier_locale((int) $order->IdFournisseur));

        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        header('X-Robots-Tag: noindex, nofollow');
        if ($state !== 'ok') status_header($state === 'expired' ? 410 : 404);

        $company = (string) get_option('wpcb_companyName', get_bloginfo('name'));
        $logo_id = (int) get_theme_mod('custom_logo');
        $logo    = $logo_id ? (string) wp_get_attachment_image_url($logo_id, 'medium') : '';
        $lines   = $order ? self::lines($order_id, determine_locale()) : [];
        $cfg = [
            'url'    => admin_url('admin-ajax.php'),
            'action' => self::ACTION_SAVE,
            'auth'   => ['o' => $order_id, 'e' => absint($_GET['e'] ?? 0), 's' => (string) ($_GET['s'] ?? '')],
            'i18n'   => ['saved' => __('Thank you, your confirmation has been recorded.', 'creation-reservoir'), 'error' => __('Something went wrong, please try again.', 'creation-reservoir')],
        ];
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html(__('Order confirmation', 'creation-reservoir') . ' · ' . $company); ?></title>
<style>
:root{--bg:#f3f4f6;--card:#fff;--ink:#111827;--muted:#6b7280;--line:#d1d5db;--brand:#d32f2f;--ok:#15803d;--okbg:#dcfce7}
@media (prefers-color-scheme:dark){:root{--bg:#0f172a;--card:#1e293b;--ink:#f1f5f9;--muted:#94a3b8;--line:#475569;--okbg:#14532d}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.top{background:var(--brand);color:#fff;padding:12px 16px}.top .in{max-width:720px;margin:0 auto;display:flex;align-items:center;gap:12px;justify-content:space-between}
.logo{display:inline-flex;background:#fff;border-radius:10px;padding:6px 12px}.logo img{display:block;max-height:32px;width:auto;max-width:170px}
.wrap{max-width:720px;margin:0 auto;padding:0 12px 110px}
h1{font-size:1.35rem;margin:18px 4px 4px}.sub{color:var(--muted);margin:0 4px 14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px;margin:0 0 12px}
.line .ref{font-weight:700}.line .desc{color:var(--muted);font-size:.9rem;margin:2px 0 8px;white-space:pre-line}.line .qty{font-size:.9rem;color:var(--muted)}
label{display:block;font-weight:600;font-size:.92rem;margin:0 0 4px}
input,textarea{width:100%;min-height:46px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font:inherit}
textarea{min-height:80px}
.bar{position:fixed;left:0;right:0;bottom:0;padding:10px 12px calc(10px + env(safe-area-inset-bottom));background:var(--card);border-top:1px solid var(--line)}.bar .in{max-width:720px;margin:0 auto}
button{width:100%;min-height:50px;border:0;border-radius:12px;background:var(--brand);color:#fff;font:inherit;font-weight:700;cursor:pointer}button[disabled]{opacity:.6}
.msg{margin:10px 4px;padding:10px 12px;border-radius:10px;display:none}.msg.ok{display:block;background:var(--okbg);color:var(--ok);font-weight:600}.msg.err{display:block;background:#fee2e2;color:#b91c1c}
.err-page{text-align:center;padding:48px 16px}
</style>
</head>
<body>
<div class="top"><div class="in"><?php if ($logo): ?><span class="logo"><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($company); ?>"></span><?php else: ?><strong><?php echo esc_html($company); ?></strong><?php endif; ?><span><?php esc_html_e('Order confirmation', 'creation-reservoir'); ?></span></div></div>
<div class="wrap">
<?php if ($state !== 'ok'): ?>
    <div class="err-page"><h1><?php echo esc_html($state === 'expired' ? __('This link has expired.', 'creation-reservoir') : __('This link is not valid.', 'creation-reservoir')); ?></h1>
    <p class="sub"><?php esc_html_e('Please ask your contact for a new purchase order.', 'creation-reservoir'); ?></p></div>
<?php else: ?>
    <h1><?php echo esc_html(sprintf(__('Purchase order %s', 'creation-reservoir'), stripslashes((string) $order->RefCommande))); ?></h1>
    <p class="sub"><?php esc_html_e('Please confirm the delivery date of each line and your order confirmation number.', 'creation-reservoir'); ?></p>
    <form id="confirm-form" novalidate>
        <div class="card"><label for="conf-number"><?php esc_html_e('Your order confirmation number', 'creation-reservoir'); ?></label>
            <input id="conf-number" name="conf_number" type="text" value="<?php echo esc_attr((string) $order->ConfCmdFournisseur); ?>" autocomplete="off"></div>
        <?php foreach ($lines as $l): ?>
            <div class="card line">
                <div class="ref"><?php echo esc_html($l['ref']); ?></div>
                <?php if ($l['desc'] !== ''): ?><div class="desc"><?php echo esc_html($l['desc']); ?></div><?php endif; ?>
                <div class="qty"><?php echo esc_html(sprintf(__('Quantity: %d', 'creation-reservoir'), $l['qty'])); ?></div>
                <label for="d-<?php echo (int) $l['id']; ?>" style="margin-top:8px"><?php esc_html_e('Confirmed delivery date', 'creation-reservoir'); ?></label>
                <input id="d-<?php echo (int) $l['id']; ?>" name="date[<?php echo (int) $l['id']; ?>]" type="date" value="<?php echo esc_attr($l['date']); ?>">
            </div>
        <?php endforeach; ?>
        <div class="card"><label for="conf-comment"><?php esc_html_e('Comment (optional)', 'creation-reservoir'); ?></label><textarea id="conf-comment" name="comment"></textarea></div>
        <div id="msg" class="msg" role="status"></div>
        <div class="bar"><div class="in"><button type="submit" id="save"><?php esc_html_e('Confirm', 'creation-reservoir'); ?></button></div></div>
    </form>
    <script>
    (function () {
        var cfg = <?php echo wp_json_encode($cfg); ?>;
        var form = document.getElementById('confirm-form'), msg = document.getElementById('msg'), btn = document.getElementById('save');
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = new URLSearchParams(new FormData(form));
            body.set('action', cfg.action);
            Object.keys(cfg.auth).forEach(function (k) { body.set(k, cfg.auth[k]); });
            btn.disabled = true; msg.className = 'msg'; msg.textContent = '';
            fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) throw new Error((res.data && res.data.message) || cfg.i18n.error);
                    msg.className = 'msg ok'; msg.textContent = cfg.i18n.saved; window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
                })
                .catch(function (err) { msg.className = 'msg err'; msg.textContent = err.message || cfg.i18n.error; })
                .then(function () { btn.disabled = false; });
        });
    })();
    </script>
<?php endif; ?>
</div>
</body>
</html>
        <?php
        if ($switched) restore_previous_locale();
        exit;
    }

    // ------------------------------------------------------------------ enregistrement

    public static function ajax_save() {
        global $wpdb;
        [$order_id, $state] = self::check($_POST);
        if ($state === 'expired') wp_send_json_error(['message' => __('This link has expired.', 'creation-reservoir')], 410);
        if ($state !== 'ok') wp_send_json_error(['message' => __('This link is not valid.', 'creation-reservoir')], 403);
        $order = self::order($order_id);
        if (!$order) wp_send_json_error(['message' => __('This link is not valid.', 'creation-reservoir')], 403);

        $t       = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $dates   = (array) ($_POST['date'] ?? []);
        $updated = 0;
        $latest  = 0;
        foreach ($dates as $line_id => $raw) {
            $line_id = absint($line_id);
            $raw     = sanitize_text_field(wp_unslash($raw));
            if (!$line_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) continue;
            $ts = strtotime($raw . ' 12:00:00');
            if (!$ts || $ts < strtotime('-1 year') || $ts > strtotime('+5 years')) continue;
            // Uniquement les lignes de cette commande
            $n = $wpdb->query($wpdb->prepare(
                "UPDATE {$t} SET TimestampDateLivraisonConfirme = %d WHERE Id = %d AND IdCommande = %d AND (archive IS NULL OR archive = 0)",
                $ts, $line_id, $order_id
            ));
            if ($n !== false) { $updated += (int) $n; $latest = max($latest, $ts); }
        }

        $conf = sanitize_text_field(wp_unslash($_POST['conf_number'] ?? ''));
        $set  = [];
        if ($conf !== '') $set['ConfCmdFournisseur'] = $conf;
        if ($latest > 0) $set['TimestampDateReceptionConfirmee'] = $latest;
        if ($set) $wpdb->update($wpdb->prefix . 'achats_commande_liste_fournisseurs', $set, ['Id' => $order_id]);

        // Informe la personne qui a créé la commande
        $comment = sanitize_textarea_field(wp_unslash($_POST['comment'] ?? ''));
        if (class_exists('ISPAG_Notifications_Manager') && (int) $order->created_by > 0) {
            try {
                ISPAG_Notifications_Manager::send(
                    [(int) $order->created_by],
                    'product_manager',
                    sprintf(esc_html__('✅ Order confirmed by the supplier: %s', 'ispag-crm'), esc_html(stripslashes((string) $order->RefCommande))),
                    esc_html(sprintf(__('%d line(s) with a confirmed delivery date.', 'creation-reservoir'), $updated)) . ($conf !== '' ? ' ' . esc_html(sprintf(__('Confirmation No. %s.', 'creation-reservoir'), $conf)) : '') . ($comment !== '' ? ' ' . esc_html($comment) : ''),
                    'liste-des-achats/?search=' . (int) $order->hubspot_deal_id,
                    (int) $order->hubspot_deal_id
                );
            } catch (Throwable $e) {
                error_log('[ISPAG supplier confirm] ' . $e->getMessage());
            }
        }

        wp_send_json_success(['updated' => $updated]);
    }
}
