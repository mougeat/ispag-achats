<?php
defined('ABSPATH') or die();

class ISPAG_Achat_Article_Repository {
    protected $wpdb;
    protected $table;
    protected $table_projet;
    protected $table_purchase;
    protected $table_price_history;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb                = $wpdb;
        $this->table               = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_projet        = $wpdb->prefix . 'achats_details_commande';
        $this->table_purchase      = $wpdb->prefix . 'achats_articles_purchase';
        $this->table_price_history = $wpdb->prefix . 'achats_articles_purchase_price_history';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_filter('ispag_get_achat_article_by_project_article_id', [self::$instance, 'get_achat_article_by_project_article_id'], 10, 2);
        add_filter('ispag_get_articles_by_order',                   [self::$instance, 'get_articles_by_order'], 10, 2);
        add_filter('ispag_get_purchse_article_by_id',               [self::$instance, 'get_article_by_id'], 10, 2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FRAGMENTS SQL RÉUTILISABLES
    //
    // - ap : catalogue (achats_articles_purchase)
    //        joiné UNIQUEMENT si c.IdArticleStandard est renseigné (> 0)
    // - ph : historique des prix valide À UNE DATE DONNÉE
    //        → valid_from <= $date AND (valid_to IS NULL OR valid_to >= $date)
    //        → permet de préparer des tarifs futurs sans casser l'affichage actuel
    //
    // Les deux %s dans price_joins() doivent être passés AVANT les autres
    // paramètres dans chaque wpdb->prepare().
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fragment JOIN — les deux %s correspondent à $date, $date (valid_from / valid_to).
     */
    private function price_joins(): string {
        return "LEFT JOIN {$this->table_purchase} ap
                    ON  ap.article_id  = c.IdArticleStandard
                    AND ap.supplier_id = cf.IdFournisseur
                    AND c.IdArticleStandard IS NOT NULL
                    AND c.IdArticleStandard != 0
                LEFT JOIN {$this->table_price_history} ph
                    ON  ph.purchase_id = ap.Id
                    AND ph.valid_from <= %s
                    AND (ph.valid_to IS NULL OR ph.valid_to >= %s)";
    }

    /**
     * Colonnes de prix réutilisables dans les SELECT.
     *
     * Priorité :
     *   1. Prix / remise saisis sur la commande (c.UnitPrice, c.discount)
     *   2. Historique valide à la date demandée (ph.purchase_price, ph.discount)
     *   3. 0 en fallback
     */
    private function price_columns(): string {
        return "
                ap.supplier_reference   AS RefSurMesureSupplier,
                ap.supplier_description AS DescSurMesureSupplier,
                ap.delivery_days,
                ph.currency,
                ph.valid_from           AS price_valid_from,

                -- Prix catalogue à la date demandée (pour affichage / comparaison)
                ph.purchase_price       AS UnitPriceSupplier,
                ph.discount             AS discountSupplier,

                -- Prix brut : priorité commande > historique > 0
                COALESCE(NULLIF(c.UnitPrice, 0), ph.purchase_price, 0) AS UnitPrice,

                -- Remise : priorité commande > historique > 0
                IFNULL(COALESCE(NULLIF(c.discount, 0), ph.discount), 0) AS discount,

                -- Prix net unitaire (arrondi à l'entier supérieur)
                CEIL(
                    COALESCE(NULLIF(c.UnitPrice, 0), ph.purchase_price, 0)
                    * (1 - (IFNULL(COALESCE(NULLIF(c.discount, 0), ph.discount), 0) / 100))
                ) AS UnitPriceNet,

                -- Total net (arrondi à l'entier supérieur)
                CEIL(
                    COALESCE(NULLIF(c.UnitPrice, 0), ph.purchase_price, 0)
                    * (1 - (IFNULL(COALESCE(NULLIF(c.discount, 0), ph.discount), 0) / 100))
                    * c.Qty
                ) AS TotalPriceNet
        ";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENRICHISSEMENT PHP COMMUN
    // ─────────────────────────────────────────────────────────────────────────

    private function enrich_article(object &$article, $lang = null): void {
        $switched = false;
        if ($lang) {
            if (function_exists('pll_set_language')) pll_set_language($lang);
            switch_to_locale($lang);
            $switched = true;
        }

        try {
            if (empty($article->image)) {
                $article->image = plugin_dir_url(__FILE__) . '../../../assets/img/placeholder.webp';
            } else {
                $article->image = wp_get_attachment_url($article->image);
            }

            $article->Groupe = apply_filters('ispag_get_groupe_by_article_id', null, $article->IdCommandeClient);

            switch ((int)$article->Type) {

                case 1: // Cuve sur mesure — prix hors historique catalogue

                    // error_log('[DEBUG] Locale avant data tank: ' . (function_exists('pll_current_language') ? pll_current_language() : get_locale()));


                    // $tank_repo = new ISPAG_Tank_Repository();
                    $tank_data = ISPAG_Tank_Repository::get_tank_details($article->IdCommandeClient);

                    $article->RefSurMesure              = apply_filters('ispag_get_tank_title',               $article->RefSurMesure, $article->IdCommandeClient);
                    $article->DescSurMesure             = apply_filters('ispag_get_tank_description',         $article->DescSurMesure, $article->IdCommandeClient, true, $lang);
                    $article->last_drawing_url          = apply_filters('ispag_get_last_drawing_url',         '', $article->IdCommandeClient);
                    $article->DrawingApproved           = apply_filters('ispag_get_drawing_approval',         '', $article->IdCommandeClient);
                    $article->last_doc_type             = apply_filters('ispag_get_if_last_drawing_or_modif', '', $article->IdCommandeClient);
                    $article->welding_text_informations = apply_filters('ispag_get_welding_text',             null, $article->Article ?? null, $article->IdCommandeClient);
                    $article->tank_on_site_welded       = apply_filters('ispag_get_tank_on_site_welded',      $article->Article ?? null, $article->IdCommandeClient);
                    $article->image                     = apply_filters('ispag_get_tank_svg',                 null, $article->IdCommandeClient, false);

                    if ($tank_data) {
                        $article->tank_details     = $tank_data;
                        $article->technical_volume = floatval($tank_data['dimensions_principales']['Volume_L'] ?? 0);
                    }
                    break;

                case 5: // Échangeur à plaques sur mesure — prix hors historique catalogue
                    $article->RefSurMesure  = apply_filters('ispag_get_plate_exchanger_title',       $article->RefSurMesure,  $article->IdCommandeClient);
                    $article->DescSurMesure = apply_filters('ispag_get_plate_exchanger_description', $article->DescSurMesure, $article->IdCommandeClient, true);
                    $article->image         = wp_get_attachment_url(12289);
                    break;

                default: // Article standard catalogue — prix issu de l'historique (ph)
                    $article->RefSurMesure  = !empty($article->RefSurMesureSupplier)  ? $article->RefSurMesureSupplier  : $article->RefSurMesure;
                    $article->DescSurMesure = !empty($article->DescSurMesureSupplier) ? $article->DescSurMesureSupplier : $article->DescSurMesure;
                    $article->UnitPrice     = !empty($article->UnitPriceSupplier)     ? $article->UnitPriceSupplier     : $article->UnitPrice;
                    break;
            }

            $article->total_price         = floatval($article->UnitPriceNet) * intval($article->Qty);
            $article->date_livraison      = !empty($article->TimestampDateLivraison)         ? date('d/m/Y', $article->TimestampDateLivraison)         : '';
            $article->date_livraison_conf = !empty($article->TimestampDateLivraisonConfirme) ? date('d/m/Y', $article->TimestampDateLivraisonConfirme) : '';
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REQUÊTES PUBLIQUES
    // ─────────────────────────────────────────────────────────────────────────

    public function get_articles_by_order($html, $order_id, $lang = null) {
        if (empty($order_id) || !is_numeric($order_id)) return [];

        $today = current_time('Y-m-d');

        // Les deux %s (date) doivent précéder le %d (order_id)
        // car price_joins() place ses placeholders avant le WHERE
        $sql = $this->wpdb->prepare(
            "SELECT
                c.*,
                dp.Type,
                tp.image,
                dp.serial_no,
                {$this->price_columns()}
            FROM {$this->table} c
            LEFT JOIN {$this->wpdb->prefix}achats_commande_liste_fournisseurs cf ON cf.Id = c.IdCommande
            LEFT JOIN {$this->table_projet} dp ON dp.Id = c.IdCommandeClient
            {$this->price_joins()}
            LEFT JOIN {$this->wpdb->prefix}achats_type_prestations tp ON tp.Id = dp.Type
            WHERE c.IdCommande = %d
            ORDER BY tri ASC",
            $today, $today, $order_id   // ← $today x2 pour les %s de price_joins()
        );

        $results = $this->wpdb->get_results($sql);
        if (!is_array($results)) return [];

        // error_log('[DEBUG] Locale avant enrich_article: ' . (function_exists('pll_current_language') ? pll_current_language() : get_locale()));

        foreach ($results as &$article) {
            $this->enrich_article($article, $lang);
        }

        return $results;
    }

    public function get_achat_article_by_project_article_id($html, $id) {
        $id_result = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT Id FROM {$this->table} WHERE IdCommandeClient = %d LIMIT 1", $id)
        );
        return $this->get_article_by_id(null, $id_result);
    }

    public function get_article_by_id($html, $id) {
        $today = current_time('Y-m-d');

        $sql = $this->wpdb->prepare(
            "SELECT
                c.*,
                dp.Type,
                tp.image,
                dp.serial_no,
                {$this->price_columns()}
            FROM {$this->table} c
            LEFT JOIN {$this->wpdb->prefix}achats_commande_liste_fournisseurs cf ON cf.Id = c.IdCommande
            LEFT JOIN {$this->table_projet} dp ON dp.Id = c.IdCommandeClient
            {$this->price_joins()}
            LEFT JOIN {$this->wpdb->prefix}achats_type_prestations tp ON tp.Id = dp.Type
            WHERE c.Id = %d",
            $today, $today, $id         // ← $today x2 pour les %s de price_joins()
        );

        $row = $this->wpdb->get_row($sql);
        if (!$row) return null;

        $this->enrich_article($row);

        return $row;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GESTION DE L'HISTORIQUE DES PRIX (articles standards uniquement)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Archive l'ancien prix et insère le nouveau.
     * Accepte une $valid_from optionnelle pour planifier un tarif futur.
     */
    public function update_purchase_price(
        int    $purchase_id,
        float  $new_price,
        float  $discount,
        string $currency,
        int    $user_id    = 0,
        string $note       = '',
        string $valid_from = ''  // ← laisser vide = aujourd'hui
    ): bool {
        $valid_from = $valid_from ?: current_time('Y-m-d');
        $yesterday  = date('Y-m-d', strtotime($valid_from . ' -1 day'));

        // 1. Clôture du prix actif (valid_to = veille de la nouvelle entrée en vigueur)
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table_price_history}
             SET valid_to = %s
             WHERE purchase_id = %d AND valid_to IS NULL",
            $yesterday,
            $purchase_id
        ));

        // 2. Nouveau prix
        $result = $this->wpdb->insert(
            $this->table_price_history,
            [
                'purchase_id'    => $purchase_id,
                'purchase_price' => $new_price,
                'discount'       => $discount,
                'currency'       => $currency,
                'valid_from'     => $valid_from,
                'valid_to'       => null,
                'changed_by'     => $user_id ?: null,
                'note'           => $note ?: null,
                'created_at'     => current_time('mysql'),
            ],
            ['%d', '%f', '%f', '%s', '%s', null, '%d', '%s', '%s']
        );

        // 3. Synchronisation table principale (rétrocompatibilité)
        if ($result) {
            $this->wpdb->update(
                $this->table_purchase,
                ['purchase_price' => $new_price, 'discount' => $discount, 'currency' => $currency],
                ['Id' => $purchase_id],
                ['%f', '%f', '%s'],
                ['%d']
            );
        }

        return $result !== false;
    }

    /**
     * Retourne tout l'historique des prix d'un article catalogue.
     */
    public function get_price_history(int $purchase_id): array {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table_price_history}
             WHERE purchase_id = %d
             ORDER BY valid_from DESC",
            $purchase_id
        )) ?: [];
    }

    /**
     * Retourne le prix actif à une date donnée.
     */
    public function get_price_at_date(int $purchase_id, string $date): ?object {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table_price_history}
             WHERE purchase_id = %d
               AND valid_from <= %s
               AND (valid_to IS NULL OR valid_to >= %s)
             ORDER BY valid_from DESC
             LIMIT 1",
            $purchase_id, $date, $date
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRUD DE BASE (inchangé)
    // ─────────────────────────────────────────────────────────────────────────

    public function insert_article($data) {
        return $this->wpdb->insert($this->table, $data);
    }

    public function update_article($id, $data) {
        return $this->wpdb->update($this->table, $data, ['Id' => $id]);
    }

    public function delete_article($id) {
        return $this->wpdb->delete($this->table, ['Id' => $id]);
    }

    public function get_articles_by_client_order($client_order_id) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE IdCommandeClient = %d", $client_order_id)
        );
    }

    public function get_articles_pending_delivery($order_id) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE IdCommande = %d AND Qty > Recu", $order_id)
        );
    }
}