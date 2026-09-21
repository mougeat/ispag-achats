<?php

class ISPAG_Achat_Repository {
    private $wpdb;
    private $table_achats;
    private $table_articles;
    private $table_fournisseurs;
    private $table_etat;
    protected $table_detail_projet;
    protected $logger; // Déclaration de la propriété logger
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_achats = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        $this->table_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_fournisseurs = $wpdb->prefix . 'achats_fournisseurs';
        $this->table_etat = $wpdb->prefix . 'achats_etat_commandes_fournisseur';
        $this->table_detail_projet = $wpdb->prefix . 'achats_details_commande';

        // Initialisation du logger si la classe ISPAG_Logger existe
        if (class_exists('ISPAG_Logger')) {
            $this->logger = ISPAG_Logger::get_instance(); 
            // Modifiez la ligne ci-dessus selon la manière dont votre instance de logger est récupérée 
            // (ex: new ISPAG_Logger() ou via un filtre/hook)
        }
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_filter('ispag_get_achats', [self::$instance, 'ispag_get_achats'], 10, 7);
        add_filter('ispag_get_achat_by_id', [self::$instance, 'get_achat_by_id'], 10, 2);
        add_filter('ispag_get_achat_id_by_article_id', [self::$instance, 'get_achat_id_by_article_id'], 10, 2);
    }

    public function get_achat_id_by_article_id($html, $article_id) {
        $query = $this->wpdb->prepare("
            SELECT IdCommande FROM {$this->table_articles}
            WHERE ID = %d
        ", $article_id);

        $result = $this->wpdb->get_var($query);
        return $result ?: null;
    }

    public function ispag_get_achats($html, $user_id = null, $all = false, $search = '', $select_state = '', $offset = 0, $limit = 20){
        return $this->get_achats($user_id, $all, $search, $select_state, $offset, $limit);
    }

    public function get_achats($user_id = null, $all = false, $search = '', $select_state = '', $offset = 0, $limit = 20) {
        $query = "
            SELECT a.*, f.Fournisseur, ar.TimestampDateLivraisonConfirme, e.Etat, e.ClassCss, e.color
            FROM {$this->table_achats} a
            LEFT JOIN {$this->table_articles} ar ON ar.IdCommande = a.Id
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            LEFT JOIN {$this->table_etat} e ON e.Id = a.EtatCommande
            WHERE (archive IS NULL || archive = 0)
        ";

        $query_params = [];

        if (!$all && $user_id) {
            $query .= " AND a.Abonne LIKE %s";
            $query_params[] = '%;' . $user_id . ';%';
        }

        if (!empty($search)) {
            $query .= " AND (a.RefCommande LIKE %s OR f.Fournisseur LIKE %s OR a.ConfCmdFournisseur LIKE %s OR a.NrCommande LIKE %d OR e.Id LIKE %d OR a.Id = %d OR a.hubspot_deal_id = %d)";
            $like = '%' . $search . '%';
            $equal = $search;

            $query_params[] = $like;
            $query_params[] = $like;
            $query_params[] = $like;
            $query_params[] = $like;
            $query_params[] = $like;
            $query_params[] = $equal;
            $query_params[] = $equal;
        }

        if (!empty($select_state)) {
            $query .= " AND a.EtatCommande = %d";
            $query_params[] = $select_state;
        }

        $query .= " GROUP BY a.Id ORDER BY a.TimestampDateCreation DESC LIMIT %d OFFSET %d";
        $query_params[] = $limit;
        $query_params[] = $offset;

        $prepared = $this->wpdb->prepare($query, ...$query_params);
        $results = $this->wpdb->get_results($prepared);

        if (!$results) {
            return [];
        }

        $base_url = trailingslashit(get_site_url()) . 'purchase/';

        $current_lang = function_exists('pll_current_language') 
            ? pll_current_language() 
            : (defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : 'fr');

        if (current_user_can('navigate_new_project_details_presentation')) {
            $slug = ($current_lang === 'de') ? 'de/project-detail' : 'projectdetail';
        } else {
            $slug = ($current_lang === 'de') ? 'de/project-detail' : 'project-detail';
        }

        $project_base_url = trailingslashit(get_site_url()) . $slug; 

        foreach ($results as $p) {
            $project = apply_filters('ispag_get_project_by_deal_id', null, $p->hubspot_deal_id);
            $p->purchase_url = esc_url($base_url . $p->Id);
            $args = array('deal_id' => $p->hubspot_deal_id);

            if ($project && isset($project->isQotation) && $project->isQotation) {
                $args['quotation'] = 1;
            }

            $p->project_url = esc_url($project_base_url . $p->hubspot_deal_id); 
            $p->purchase_total = $this->get_purchase_total(null, $p->Id);
        }

        return $results;
    }

    public function get_achat_by_id($html, $id) {
        $id = intval($id);
        if (!$id) {
            return null;
        }

        $query = "
            SELECT a.*, f.Fournisseur, f.Monnaie AS Devise, ar.TimestampDateLivraisonConfirme, e.Etat, e.ClassCss, e.color, e.allow_price_recalculation
            FROM {$this->table_achats} a
            LEFT JOIN {$this->table_articles} ar ON ar.IdCommande = a.Id
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            LEFT JOIN {$this->table_etat} e ON e.Id = a.EtatCommande
            WHERE a.Id = %d
            GROUP BY a.Id LIMIT 1
        ";

        $prepared = $this->wpdb->prepare($query, $id);
        $result = $this->wpdb->get_row($prepared);

        if (!$result) {
            return null;
        }

        $base_url = trailingslashit(get_site_url()) . 'purchase/';
        
        $current_lang = function_exists('pll_current_language') 
            ? pll_current_language() 
            : (defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : 'fr');

        if (current_user_can('navigate_new_project_details_presentation')) {
            $slug = ($current_lang === 'de') ? 'de/project-detail' : 'projectdetail';
        } else {
            $slug = ($current_lang === 'de') ? 'de/project-detail' : 'project-detail';
        }

        $project_base_url = trailingslashit(get_site_url()) . $slug . '/'; 

        $project = apply_filters('ispag_get_project_by_deal_id', null, $result->hubspot_deal_id);
        $result->purchase_url = esc_url($base_url . $result->Id);
        $args = array('deal_id' => $result->hubspot_deal_id);

        if ($project && isset($project->isQotation) && $project->isQotation) {
            $args['qotation'] = 1;
        }

        $result->project_url = esc_url($project_base_url . $result->hubspot_deal_id);
        $result->purchase_total = $this->get_purchase_total(null, $id);

        return $result;
    }

    public function get_purchase_total($html, $purchase_id) {
        $purchase_id = intval($purchase_id);
        if (!$purchase_id) return 0.0;

        $article_ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT Id FROM {$this->table_articles} WHERE IdCommande = %d",
            $purchase_id
        ));

        if (empty($article_ids)) return 0.0;

        $total_achat = 0.0;
        $repo_article = new ISPAG_Achat_Article_Repository();

        foreach ($article_ids as $id) {
            $article_data = $repo_article->get_article_by_id(null, $id);
            
            if ($article_data && isset($article_data->TotalPriceNet)) {
                $total_achat += floatval($article_data->TotalPriceNet);
            }
        }

        return $total_achat;
    }

    public function get_purchase_total_by_deal_id($html, $deal_id) {
        $user_id = get_current_user_id();
        $deal_id = intval($deal_id);

        if (isset($this->logger)) {
            $this->logger->log_user_action('achats', 'get_purchase_total_by_deal_id_start', ['deal_id' => $deal_id], $user_id);
        }

        if (!$deal_id) { 
            return 0.0;
        }

        try {
            // Fallback si $this->wpdb n'est pas initialisé dans l'instance
            $wpdb = $this->wpdb ?? $GLOBALS['wpdb'];

            if (!$wpdb) {
                throw new Exception('Objet $wpdb non disponible.');
            }

            // Vérification des noms de tables
            $table_articles = $this->table_articles ?? $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
            $table_achats   = $this->table_achats ?? $wpdb->prefix . 'achats_commande_liste_fournisseurs';

            // Construction du SQL
            $sql = $wpdb->prepare(
                "SELECT 
                    SUM(tar.UnitPrice * (1 - COALESCE(tar.Discount, 0) / 100) * tar.Qty) AS total_net 
                FROM {$this->table_articles} tar 
                INNER JOIN {$this->table_achats} tac 
                    ON tar.IdCommande = tac.Id 
                WHERE tac.hubspot_deal_id = %d",
                $deal_id
            );

            // Au lieu d'appeler log_db_change avec un tableau en 3ème position :
            // On utilise log_user_action (ou on remet les arguments dans le bon ordre pour log_db_change)

            if (isset($this->logger)) {
                $this->logger->log_user_action('achats', 'PREPARED_QUERY_TOTAL_PURCHASE', [
                    'deal_id' => $deal_id,
                    'sql'     => $sql
                ], $user_id);
            }

            // Exécution de la requête
            $raw_result = $wpdb->get_var($sql);

            // Log d'une éventuelle erreur d'exécution SQL
            if (!empty($wpdb->last_error) && isset($this->logger)) {
                $this->logger->log_user_action('achats', 'get_purchase_total_by_deal_id_sql_error', [
                    'deal_id'   => $deal_id,
                    'sql_error' => $wpdb->last_error,
                    'sql'       => $sql
                ], $user_id);
            }

            $total_achat = floatval($raw_result ?? 0.0);

            if (isset($this->logger)) {
                $this->logger->log_user_action('achats', 'get_purchase_total_by_deal_id_end', [
                    'deal_id'     => $deal_id,
                    'total_achat' => $total_achat
                ], $user_id);
            }

            return $total_achat;

        } catch (Throwable $e) {
            // Capture toute erreur PHP 7+ / 8+ ou Exception
            if (isset($this->logger)) {
                $this->logger->log_user_action('achats', 'get_purchase_total_by_deal_id_CRASH', [
                    'deal_id' => $deal_id,
                    'error'   => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ], $user_id);
            }

            return 0.0;
        }
    }
}