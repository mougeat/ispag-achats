<?php
defined('ABSPATH') or die();

class ISPAG_Achat_Article_Repository {
    protected $wpdb;
    protected $table;
    protected $table_projet;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_projet = $wpdb->prefix . 'achats_details_commande';
    }

    public static function init(){
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_filter('ispag_get_achat_article_by_project_article_id', [self::$instance, 'get_achat_article_by_project_article_id'], 10, 2);
        add_filter('ispag_get_articles_by_order', [self::$instance, 'get_articles_by_order'], 10, 2);

    }
 

    public function get_articles_by_order($html, $order_id) {
        if (empty($order_id) || !is_numeric($order_id)) {
            // error_log("ISPAG_Achat_Article_Repository: order_id invalide");
            return [];
        }

        // $sql = $this->wpdb->prepare(
        //     "SELECT c.*, dp.Type FROM {$this->table} c LEFT JOIN {$this->table_projet} dp ON dp.Id = c.IdCommandeClient WHERE c.IdCommande = %d ORDER BY tri ASC",
        //     $order_id
        // );
        $sql = $this->wpdb->prepare(
            "SELECT 
                c.*, 
                dp.Type, 
                tp.image,
                ap.supplier_reference AS RefSurMesureSupplier, 
                ap.supplier_description AS DescSurMesureSupplier, 
                ap.purchase_price AS UnitPriceSupplier, 
                ap.currency, 
                ap.discount, 
                ap.delivery_days
            FROM {$this->table} c
            LEFT JOIN {$this->wpdb->prefix}achats_commande_liste_fournisseurs cf ON cf.Id = c.IdCommande
            LEFT JOIN {$this->table_projet} dp ON dp.Id = c.IdCommandeClient
            LEFT JOIN {$this->wpdb->prefix}achats_articles_purchase ap ON ap.article_id = c.IdArticleStandard AND ap.supplier_id = cf.IdFournisseur
            LEFT JOIN {$this->wpdb->prefix}achats_type_prestations tp ON tp.Id = dp.Type
            WHERE c.IdCommande = %d
            ORDER BY tri ASC",
            $order_id
        );


        $results = $this->wpdb->get_results($sql);
        if (!is_array($results)) {
            // error_log("ISPAG_Achat_Article_Repository: erreur SQL pour IdCommande = $order_id");
            return [];
        }

        foreach ($results as &$article) {
            if (empty($article->image)) {
                $article->image = plugin_dir_url(__FILE__) . "../../../assets/img/placeholder.webp";
            }
            else {
                $article->image = wp_get_attachment_url($article->image);
            }
            $article->Groupe = apply_filters('ispag_get_groupe_by_article_id', null, $article->IdCommandeClient);
            // Si article de type cuve
            if ($article->Type == 1) {
                
                $article->RefSurMesure = apply_filters('ispag_get_tank_title', $article->RefSurMesure, $article->IdCommandeClient);
                $article->DescSurMesure = apply_filters('ispag_get_tank_description', $article->DescSurMesure, $article->IdCommandeClient, true);
                $article->last_drawing_url = apply_filters('ispag_get_last_drawing_url', '', $article->IdCommandeClient);
                $article->DrawingApproved = apply_filters('ispag_get_drawing_approval', '', $article->IdCommandeClient);
                $article->last_doc_type = apply_filters('ispag_get_if_last_drawing_or_modif', '', $article->IdCommandeClient);
                $article->welding_text_informations = apply_filters('ispag_get_welding_text', null, $article->Article ?? null, $article->IdCommandeClient);
                $article->tank_on_site_welded = apply_filters('ispag_get_tank_on_site_welded', $article->Article ?? null, $article->IdCommandeClient);
                $article->image = apply_filters('ispag_get_tank_svg', null, $article->IdCommandeClient, false);
            }
            else{
                $article->RefSurMesure = !empty($article->RefSurMesureSupplier) ? $article->RefSurMesureSupplier : $article->RefSurMesure;
                $article->DescSurMesure = !empty($article->DescSurMesureSupplier) ? $article->DescSurMesureSupplier : $article->DescSurMesure;
                $article->UnitPrice = !empty($article->UnitPriceSupplier) ? $article->UnitPriceSupplier : $article->UnitPrice;
                
            }


            $article->total_price = floatval($article->UnitPrice) * intval($article->Qty);
            $article->date_livraison = date('d/m/Y', $article->TimestampDateLivraison);
            $article->date_livraison_conf = date('d/m/Y', $article->TimestampDateLivraisonConfirme);
            
        }

        return $results;
    }

    public function get_achat_article_by_project_article_id($html, $id) {
        
        $sql = $this->wpdb->prepare("SELECT Id FROM {$this->table} WHERE IdCommandeClient = %d LIMIT 1", $id);
        $id_result = $this->wpdb->get_var($sql);
        return $this->get_article_by_id($id_result);
    }
    public function get_article_by_id($id) {
        // $sql = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE Id = %d", $id);
        $sql = $this->wpdb->prepare(
            "SELECT 
                c.*, 
                dp.Type, 
                tp.image,
                ap.supplier_reference AS RefSurMesureSupplier, 
                ap.supplier_description AS DescSurMesureSupplier, 
                ap.purchase_price AS UnitPriceSupplier, 
                ap.currency, 
                ap.discount, 
                ap.delivery_days
            FROM {$this->table} c
            LEFT JOIN {$this->wpdb->prefix}achats_commande_liste_fournisseurs cf ON cf.Id = c.IdCommande
            LEFT JOIN {$this->table_projet} dp ON dp.Id = c.IdCommandeClient
            LEFT JOIN {$this->wpdb->prefix}achats_articles_purchase ap ON ap.article_id = c.IdArticleStandard AND ap.supplier_id = cf.IdFournisseur
            LEFT JOIN {$this->wpdb->prefix}achats_type_prestations tp ON tp.Id = dp.Type
            WHERE c.Id = %d
            ORDER BY tri ASC",
            $id
        );
        $row = $this->wpdb->get_row($sql);
        if (!$row) return null;

        // $row->Type = 1;
        if (empty($row->image)) {
                $row->image = plugin_dir_url(__FILE__) . "../../../assets/img/placeholder.webp";
            }
            else {
                $row->image = wp_get_attachment_url($row->image);
            }

        $row->Groupe = apply_filters('ispag_get_groupe_by_article_id', null, $row->IdCommandeClient);
            // Si article de type cuve
        if ($row->Type == 1) {
            $row->RefSurMesure = apply_filters('ispag_get_tank_title', $row->RefSurMesure, $row->IdCommandeClient);
            $row->DescSurMesure = apply_filters('ispag_get_tank_description', $row->DescSurMesure, $row->IdCommandeClient, true);
            $row->last_drawing_url = apply_filters('ispag_get_last_drawing_url', '', $row->IdCommandeClient);
            $row->DrawingApproved = apply_filters('ispag_get_drawing_approval', '', $row->IdCommandeClient);
            $row->last_doc_type = apply_filters('ispag_get_if_last_drawing_or_modif', '', $row->IdCommandeClient);
            $row->welding_text_informations = apply_filters('ispag_get_welding_text', null, $row->IdCommandeClient, false);
            $row->tank_on_site_welded = apply_filters('ispag_get_tank_on_site_welded', null, $row->IdCommandeClient);
            $row->image = apply_filters('ispag_get_tank_svg', null, $row->IdCommandeClient, false);
        }
        else{
            $row->RefSurMesure = !empty($row->RefSurMesureSupplier) ? $row->RefSurMesureSupplier : $row->RefSurMesure;
            $row->DescSurMesure = !empty($row->DescSurMesureSupplier) ? $row->DescSurMesureSupplier : $row->DescSurMesure;
            $row->UnitPrice = !empty($row->UnitPriceSupplier) ? $row->UnitPriceSupplier : $row->UnitPrice;
        }


        $row->total_price = floatval($row->UnitPrice) * intval($row->Qty);
        $row->date_livraison = date('d/m/Y', $row->TimestampDateLivraison);
        $row->date_livraison_conf = date('d/m/Y', $row->TimestampDateLivraisonConfirme);

        return $row;
    }

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
        $sql = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE IdCommandeClient = %d", $client_order_id);
        return $this->wpdb->get_results($sql);
    }

    public function get_articles_pending_delivery($order_id) {
        $sql = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE IdCommande = %d AND Qty > Recu", $order_id);
        return $this->wpdb->get_results($sql);
    }
}
