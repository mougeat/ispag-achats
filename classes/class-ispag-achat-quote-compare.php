<?php
defined('ABSPATH') || exit;

/**
 * Comparaison d'une offre fournisseur (PDF analysé par Mistral) avec les cuves d'une commande d'achat.
 *
 * Flux : bouton « analyser » d'un document (Achats → Documents) → Mistral lit le PDF (tâche de fond) → la page envoie le résultat
 * à `ispag_achat_quote_compare` → une fenêtre propose, pour chaque cuve de l'offre, la cuve de la commande la plus proche
 * (modifiable), compare les champs et le prix net → `ispag_achat_quote_import` enregistre ce que l'utilisateur a coché.
 */
class ISPAG_Achat_Quote_Compare {

    const NONCE = 'ispag_quote_compare';

    /** Champs techniques comparés : clé => [libellé, numérique ?, poids dans le score de proximité]. */
    const FIELDS = [
        'diameter'      => ['Diameter (mm)', true, 3],
        'volume'        => ['Volume (L)', true, 3],
        'height'        => ['Height (mm)', true, 2],
        'max_pressure'  => ['Design pressure (bar)', true, 1],
        'test_pressure' => ['Test pressure (bar)', true, 1],
        'temperature'   => ['Temperature (°C)', true, 1],
        'clearance'     => ['Ground clearance (mm)', true, 1],
        'type'          => ['Type', false, 1],
        'materiau'      => ['Material', false, 2],
        'support'       => ['Support', false, 1],
    ];

    /** Noms possibles du prix net unitaire dans la réponse de l'IA. */
    const PRICE_KEYS = ['net_price', 'price_net', 'unit_price_net', 'prix_net', 'prix_net_unitaire', 'sales_price', 'unit_price', 'price', 'total_net', 'gesamtpreis_netto', 'preis_netto'];

    public static function init() {
        add_action('wp_ajax_ispag_achat_quote_compare', [self::class, 'ajax_compare']);
        add_action('wp_ajax_ispag_achat_quote_import', [self::class, 'ajax_import']);
    }

    public static function nonce(): string {
        return wp_create_nonce(self::NONCE);
    }

    private static function guard() {
        if (!current_user_can('edit_supplier_order')) wp_send_json_error(['message' => 'Not authorized'], 403);
        if (!wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE)) wp_send_json_error(['message' => 'Invalid nonce — reload the page'], 403);
    }

    /** Nombre depuis un texte de l'IA (« 2'500 L », « 1250 mm », « 3,5 bar »). */
    public static function to_number($v) {
        if (is_numeric($v)) return (float) $v;
        if (!is_string($v)) return null;
        $s = str_replace(["'", '’', ' ', "\xc2\xa0"], '', $v);
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $s, $m)) return (float) str_replace(',', '.', $m[0]);
        return null;
    }

    /** Clé de comparaison d'un diamètre écrit en texte (« DN50 », « 2" »…) : le nombre, ou '' si aucun (même logique que la liste des piquages du configurateur). */
    public static function dn_key(string $text): string {
        $t = trim($text);
        if (preg_match('/DN\s*([0-9]+)/i', $t, $m)) return $m[1];
        if (preg_match('/([0-9]+(?:\s+[0-9]+\/[0-9]+|[.,\/][0-9]+)?)\s*(?:"|″|”|\'\'|zoll|pouces?|inch)/iu', $t, $m)) return str_replace([' ', ','], ['_', '.'], $m[1]);
        return '';
    }

    /** Piquages de l'offre : liste de textes ou d'objets {text|texte|description, qty} → [['text', 'qty', 'dn_key'], …]. */
    public static function normalize_fittings($raw): array {
        $out = [];
        if (!is_array($raw)) return $out;
        foreach ($raw as $f) {
            $qty = 1;
            if (is_array($f)) {
                $qty = max(1, (int) ($f['qty'] ?? $f['quantity'] ?? $f['quantite'] ?? 1));
                $f = $f['text'] ?? $f['texte'] ?? $f['description'] ?? $f['label'] ?? '';
            }
            $text = trim(wp_strip_all_tags((string) $f));
            if ($text === '') continue;
            $out[] = ['text' => mb_substr($text, 0, 160), 'qty' => $qty, 'dn_key' => self::dn_key($text)];
        }
        return $out;
    }

    /** Cuves de la commande : ligne d'achat + article du projet + données techniques + prix net actuel. */
    public static function purchase_tanks(int $purchase_id): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.Id AS line_id, c.IdCommandeClient AS article_id, c.RefSurMesure AS title, c.Qty, c.UnitPrice, c.discount
             FROM {$p}achats_articles_cmd_fournisseurs c
             JOIN {$p}achats_details_commande d ON d.Id = c.IdCommandeClient
             WHERE c.IdCommande = %d AND d.Type = 1 ORDER BY c.Id", $purchase_id));
        $out = [];
        foreach ((array) $rows as $r) {
            $t = apply_filters('ispag_get_tank_datas', [], (int) $r->article_id);
            $c = is_array($t) ? ($t['conception'] ?? null) : null;
            $d = is_array($t) ? ($t['dimensions'] ?? null) : null;
            $unit = (float) $r->UnitPrice;
            $net  = round($unit * (1 - (float) $r->discount / 100), 2);
            $out[] = [
                'line_id'    => (int) $r->line_id,
                'article_id' => (int) $r->article_id,
                'title'      => wp_strip_all_tags(mb_substr((string) $r->title, 0, 90)),
                'qty'        => (int) $r->Qty,
                'net_price'  => $net,
                'fittings'   => apply_filters('ispag_get_fittings_texts', [], (int) $r->article_id),
                'values'     => [
                    'type'          => $c->TankType ?? '',
                    'materiau'      => $c->Material ?? '',
                    'support'       => $c->Support ?? '',
                    'volume'        => $d->Volume ?? '',
                    'diameter'      => $d->Diameter ?? '',
                    'height'        => $d->Height ?? '',
                    'max_pressure'  => $d->MaxPressure ?? '',
                    'test_pressure' => $d->TestPressure ?? '',
                    'temperature'   => $d->usingTemperature ?? '',
                    'clearance'     => $d->GroundClearance ?? '',
                ],
            ];
        }
        return $out;
    }

    /** Proximité (0–100) entre une cuve de l'offre et une cuve de la commande, sur les champs renseignés des deux côtés. */
    public static function score(array $quote, array $tank): int {
        $got = 0; $max = 0;
        foreach (self::FIELDS as $key => [$label, $numeric, $w]) {
            $a = $quote[$key] ?? null; $b = $tank['values'][$key] ?? null;
            if ($a === null || $a === '' || $b === null || $b === '') continue;
            $max += $w;
            if ($numeric) {
                $x = self::to_number($a); $y = self::to_number($b);
                if ($x === null || $y === null) continue;
                $diff = abs($x - $y) / max(abs($x), abs($y), 1);
                $got += $w * max(0, 1 - 5 * $diff);          // 0 % d'écart = 1 ; 20 % d'écart = 0
            } else {
                $got += $w * (mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b)) ? 1 : 0);
            }
        }
        return $max ? (int) round(100 * $got / $max) : 0;
    }

    /** Réponse de l'IA → liste de cuves (champs normalisés + prix net). */
    public static function normalize($raw): array {
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) return [];
        if (isset($raw['result']) && is_array($raw['result'])) $raw = $raw['result'];
        $list = isset($raw['tanks']) && is_array($raw['tanks']) ? $raw['tanks'] : $raw;
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item) || !(isset($item['type']) || isset($item['diameter']) || isset($item['volume']))) continue;
            $price = null;
            foreach (self::PRICE_KEYS as $k) {
                if (isset($item[$k]) && self::to_number($item[$k]) !== null) { $price = self::to_number($item[$k]); break; }
            }
            $t = ['title' => (string) ($item['titre'] ?? $item['title'] ?? ''), 'net_price' => $price, 'qty' => isset($item['qty']) ? (int) $item['qty'] : null];
            foreach (self::FIELDS as $key => $_) $t[$key] = $item[$key] ?? ($key === 'max_pressure' ? ($item['pressure'] ?? null) : null);
            $t['fittings'] = self::normalize_fittings($item['fittings'] ?? $item['piquages'] ?? $item['connections'] ?? []);
            $out[] = $t;
        }
        return $out;
    }

    public static function ajax_compare() {
        self::guard();
        $purchase_id = absint($_POST['purchase_id'] ?? 0);
        if (!$purchase_id) wp_send_json_error(['message' => 'Missing purchase'], 400);
        $quotes = self::normalize(wp_unslash($_POST['result'] ?? ''));
        $tanks  = self::purchase_tanks($purchase_id);

        // Meilleure cuve de la commande pour chaque cuve de l'offre (chaque cuve de la commande une seule fois si possible)
        $taken = [];
        foreach ($quotes as $qi => &$q) {
            $scores = [];
            foreach ($tanks as $ti => $t) $scores[$ti] = self::score($q, $t);
            arsort($scores);
            $q['scores'] = $scores;
            $best = null;
            foreach ($scores as $ti => $sc) { if (!in_array($ti, $taken, true)) { $best = $ti; break; } }
            if ($best === null && $scores) $best = array_key_first($scores);
            $q['suggested'] = $best;
            if ($best !== null) $taken[] = $best;
        }
        unset($q);

        $labels = [
            'diameter'      => __('Diameter (mm)', 'creation-reservoir'),
            'volume'        => __('Volume (L)', 'creation-reservoir'),
            'height'        => __('Height (mm)', 'creation-reservoir'),
            'max_pressure'  => __('Design pressure (bar)', 'creation-reservoir'),
            'test_pressure' => __('Test pressure (bar)', 'creation-reservoir'),
            'temperature'   => __('Temperature (°C)', 'creation-reservoir'),
            'clearance'     => __('Ground clearance (mm)', 'creation-reservoir'),
            'type'          => __('Type', 'creation-reservoir'),
            'materiau'      => __('Material', 'creation-reservoir'),
            'support'       => __('Support', 'creation-reservoir'),
        ];
        wp_send_json_success(['quotes' => $quotes, 'tanks' => $tanks, 'fields' => $labels, 'fitting_options' => apply_filters('ispag_get_fitting_options', ['diameters' => [], 'accessories' => []]), 'currency' => get_option('wpcb_currency', 'CHF')]);
    }

    /** Enregistre les champs techniques cochés (même circuit que l'ancien import) et le prix net de la ligne d'achat. */
    public static function ajax_import() {
        self::guard();
        global $wpdb;
        $purchase_id = absint($_POST['purchase_id'] ?? 0);
        $line_id     = absint($_POST['line_id'] ?? 0);
        $fields      = (array) wp_unslash($_POST['fields'] ?? []);
        $net_price   = isset($_POST['net_price']) && $_POST['net_price'] !== '' ? self::to_number(wp_unslash($_POST['net_price'])) : null;

        $table = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $line  = $wpdb->get_row($wpdb->prepare("SELECT Id, IdCommandeClient FROM {$table} WHERE Id = %d AND IdCommande = %d", $line_id, $purchase_id));
        if (!$line) wp_send_json_error(['message' => 'Line not found in this order'], 404);

        $messages = [];
        $clean = [];
        foreach ($fields as $k => $v) {
            $k = sanitize_key($k);
            if (!isset(self::FIELDS[$k])) continue;
            $clean[$k] = self::FIELDS[$k][1] ? self::to_number($v) : sanitize_text_field((string) $v);
            if ($clean[$k] === null) unset($clean[$k]);
        }
        if ($clean && $line->IdCommandeClient) {
            $res = apply_filters('ispag_auto_saver_tank_data', null, ['deal_id' => absint($_POST['deal_id'] ?? 0), 'achat_id' => $purchase_id, 'article_id' => (int) $line->IdCommandeClient, 'tank' => $clean], true);
            if (!$res || empty($res['success'])) {
                wp_send_json_error(['message' => $res['message'] ?? 'Error during the technical update.']);
            }
            $messages[] = sprintf('%d field(s)', count($clean));
        }
        $fit_rows = json_decode((string) wp_unslash($_POST['fittings'] ?? '[]'), true);
        if (is_array($fit_rows) && $fit_rows && $line->IdCommandeClient) {
            $rows = [];
            foreach ($fit_rows as $r) {
                if (!is_array($r)) continue;
                $rows[] = ['diameter' => absint($r['diameter'] ?? 0), 'accessory' => absint($r['accessory'] ?? 0), 'usage' => sanitize_text_field((string) ($r['usage'] ?? '')), 'qty' => max(1, absint($r['qty'] ?? 1))];
            }
            $added = (int) apply_filters('ispag_add_fittings', 0, (int) $line->IdCommandeClient, $rows);
            if (!$added) wp_send_json_error(['message' => 'No fitting could be added (check the diameter).']);
            $messages[] = sprintf('%d fitting(s)', $added);
        }
        if ($net_price !== null && $net_price >= 0) {
            // prix net = prix unitaire sans remise (la remise est déjà dans le prix net de l'offre)
            $ok = $wpdb->update($table, ['UnitPrice' => $net_price, 'discount' => 0], ['Id' => $line_id]);
            if ($ok === false) wp_send_json_error(['message' => 'Price update failed']);
            $messages[] = 'net price';
        }
        wp_send_json_success(['message' => implode(' + ', $messages) ?: 'Nothing to import']);
    }
}
