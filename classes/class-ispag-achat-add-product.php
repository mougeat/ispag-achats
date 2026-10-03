<?php
defined('ABSPATH') || exit;

/**
 * « Add product » d'une commande fournisseur : article standard de CE fournisseur, ou article manuel (formulaire habituel).
 */
class ISPAG_Achat_Add_Product {

    const NONCE = 'ispag_achat_add_product';

    public static function init() {
        add_action('wp_ajax_ispag_achat_supplier_std_articles', [self::class, 'ajax_search']);
        add_action('wp_ajax_ispag_achat_add_standard_article', [self::class, 'ajax_add']);
    }

    public static function nonce() {
        return wp_create_nonce(self::NONCE);
    }

    private static function guard($achat_id) {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('edit_supplier_order') && !current_user_can('manage_order')) {
            wp_send_json_error(['message' => __('Access denied.', 'creation-reservoir')], 403);
        }
        global $wpdb;
        $supplier_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT IdFournisseur FROM {$wpdb->prefix}achats_commande_liste_fournisseurs WHERE Id = %d", $achat_id
        ));
        if (!$achat_id || !$supplier_id) {
            wp_send_json_error(['message' => __('Select a supplier first.', 'creation-reservoir')], 400);
        }
        return $supplier_id;
    }

    /** Articles standard ayant un prix d'achat chez le fournisseur de la commande. */
    public static function ajax_search() {
        $achat_id    = absint($_POST['achat_id'] ?? 0);
        $supplier_id = self::guard($achat_id);
        $q           = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));

        $res = ISPAG_Standard_Article_Service::search(['supplier' => $supplier_id, 'search' => $q, 'page' => 1]);
        global $wpdb;
        $p  = $wpdb->prefix . 'achats_articles_purchase';
        $ph = $wpdb->prefix . 'achats_articles_purchase_price_history';

        $items = [];
        foreach ($res['rows'] as $r) {
            $pur = $wpdb->get_row($wpdb->prepare(
                "SELECT po.supplier_reference, ph.purchase_price, ph.discount, ph.currency
                 FROM {$p} po LEFT JOIN {$ph} ph ON ph.purchase_id = po.Id AND ph.valid_to IS NULL
                 WHERE po.article_id = %d AND po.supplier_id = %d ORDER BY po.Id DESC LIMIT 1",
                $r->Id, $supplier_id
            ));
            $items[] = [
                'id'       => (int) $r->Id,
                'title'    => (string) $r->TitreArticle,
                'ref'      => (string) ($pur->supplier_reference ?? '') ?: (string) $r->ref_article_ispag,
                'price'    => $pur && $pur->purchase_price !== null ? number_format((float) $pur->purchase_price, 2, '.', ' ') : '',
                'discount' => $pur && $pur->discount !== null ? (float) $pur->discount : 0,
                'currency' => (string) ($pur->currency ?? ''),
            ];
        }
        wp_send_json_success(['items' => $items, 'total' => (int) $res['total']]);
    }

    /** Ajoute une ligne à la commande à partir d'un article standard du fournisseur. */
    public static function ajax_add() {
        $achat_id    = absint($_POST['achat_id'] ?? 0);
        $supplier_id = self::guard($achat_id);
        $article_id  = absint($_POST['article_id'] ?? 0);
        $qty         = max(1, absint($_POST['qty'] ?? 1));

        global $wpdb;
        $article = ISPAG_Standard_Article_Service::get($article_id);
        $pur = $article ? $wpdb->get_row($wpdb->prepare(
            "SELECT supplier_reference, supplier_description FROM {$wpdb->prefix}achats_articles_purchase WHERE article_id = %d AND supplier_id = %d ORDER BY Id DESC LIMIT 1",
            $article_id, $supplier_id
        )) : null;
        if (!$article || !$pur) {
            wp_send_json_error(['message' => __('This article is not sold by the supplier of the order.', 'creation-reservoir')], 400);
        }

        $ok = $wpdb->insert($wpdb->prefix . 'achats_articles_cmd_fournisseurs', [
            'IdCommande'        => $achat_id,
            'IdArticleStandard' => $article_id,
            'RefSurMesure'      => $pur->supplier_reference !== '' ? $pur->supplier_reference : $article->TitreArticle,
            'DescSurMesure'     => $pur->supplier_description !== '' ? $pur->supplier_description : (string) $article->description_ispag,
            'Qty'               => $qty,
            'UnitPrice'         => 0, // 0 = prix et remise de l'historique du fournisseur
            'discount'          => 0,
        ]);
        if ($ok === false) {
            wp_send_json_error(['message' => __('Database error', 'creation-reservoir')], 500);
        }
        wp_send_json_success(['article_line_id' => (int) $wpdb->insert_id]);
    }
}
