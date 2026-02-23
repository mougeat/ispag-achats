<?php

class ISPAG_Achat_Repository {
    private $wpdb;
    private $table_achats;
    private $table_articles;
    private $table_fournisseurs;
    private $table_etat;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_achats = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        $this->table_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_fournisseurs = $wpdb->prefix . 'achats_fournisseurs';
        $this->table_etat = $wpdb->prefix . 'achats_etat_commandes_fournisseur';
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
        return $this->get_achats($user_id,$all, $search, $select_state, $offset, $limit);

    }
    public function get_achats($user_id = null, $all = false, $search = '', $select_state = '', $offset = 0, $limit = 20) {
        $query = "
            SELECT a.*, f.Fournisseur, ar.TimestampDateLivraisonConfirme, e.Etat, e.ClassCss, e.color
            FROM {$this->table_achats} a
            LEFT JOIN {$this->table_articles} ar ON ar.IdCommande = a.Id
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            LEFT JOIN {$this->table_etat} e ON e.Id = a.EtatCommande
            WHERE 1 = 1
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
        // else {
        //     $query .= " AND a.TimestampDateCreation > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 6 MONTH))";
        // }
        if(!empty($select_state)){
            $query .= "AND a.EtatCommande = %d";
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

        // 3. Compléter les projets
        $base_url = trailingslashit(get_site_url()) . 'details-achats/';
        $project_base_url = trailingslashit(get_site_url()) . 'details-du-projet/';
        foreach ($results as $p) {
            $project = apply_filters('ispag_get_project_by_deal_id', null, $p->hubspot_deal_id);
            // error_log(print_r($project, true));
            $p->purchase_url = esc_url(add_query_arg('poid', $p->Id, $base_url));
            // Initialisation de la variable de base
            $args = array('deal_id' => $p->hubspot_deal_id);

            // Vérifie la condition pour ajouter l'argument 'quotation'
            if ($project->isQotation) {
                $args['quotation'] = 1;
            }

            // Construit l'URL avec les arguments
            $p->project_url = esc_url(add_query_arg($args, $project_base_url));
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
            SELECT a.*, f.Fournisseur, ar.TimestampDateLivraisonConfirme, e.Etat, e.ClassCss, e.color
            FROM {$this->table_achats} a
            LEFT JOIN {$this->table_articles} ar ON ar.IdCommande = a.Id
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            LEFT JOIN {$this->table_etat} e ON e.Id = a.EtatCommande
            WHERE a.Id = %d
        ";

        $query_params = [$id];


        $query .= " GROUP BY a.Id LIMIT 1";

        $prepared = $this->wpdb->prepare($query, ...$query_params);
        $result = $this->wpdb->get_row($prepared);

        if (!$result) {
            return null;
        }

        $base_url = trailingslashit(get_site_url()) . 'details-achats/';
        $project_base_url = trailingslashit(get_site_url()) . 'details-du-projet/';

        // $result->purchase_url = esc_url(add_query_arg('poid', $result->Id, $base_url));
        // $result->project_url = esc_url(add_query_arg('deal_id', $result->hubspot_deal_id, $project_base_url));

        $project = apply_filters('ispag_get_project_by_deal_id', null, $result->hubspot_deal_id);
        // error_log(print_r($project, true));
        $result->purchase_url = esc_url(add_query_arg('poid', $result->Id, $base_url));
        // Initialisation de la variable de base
        $args = array('deal_id' => $result->hubspot_deal_id);

        // Vérifie la condition pour ajouter l'argument 'quotation'
        if ($project->isQotation) {
            $args['qotation'] = 1;
        }

        // Construit l'URL avec les arguments
        $result->project_url = esc_url(add_query_arg($args, $project_base_url));
        $result->purchase_total = $this->get_purchase_total(null, $id);

        return $result;
    }

    /**
     * Calcule le montant total d'une commande d'achat spécifique
     * en tenant compte de la quantité, du prix unitaire et du rabais (supposé en pourcentage).
     *
     * @param mixed $html Non utilisé, paramètre hérité du hook d'application.
     * @param int $purchase_id L'ID de la commande d'achat (IdCommande).
     * @return float|null Le montant total de la commande, ou null en cas d'erreur ou si l'ID est invalide.
     */
    public function get_purchase_total($html, $purchase_id) {
        
        $purchase_id = intval($purchase_id);
        
        if (!$purchase_id) {
            return 0.0; // Retourne 0.0 si l'ID est invalide
        }

        // Requête SQL pour calculer le montant total : 
        // SUM( (Quantité * Prix unitaire) * (1 - (Rabais en % / 100)) )
        $query = $this->wpdb->prepare("
            SELECT
                SUM(
                    (Qty * UnitPrice) * (1 - (discount / 100))
                )
            FROM
                {$this->table_articles}
            WHERE
                IdCommande = %d
        ", $purchase_id);

        // get_var() récupère le premier champ du premier enregistrement trouvé.
        $total = $this->wpdb->get_var($query);

        // Si $total est NULL (ex: commande sans article ou erreur), on retourne 0.0.
        // Sinon, on retourne la valeur castée en float.
        return $total !== null ? (float) $total : 0.0;
    }

}
