<?php

class ISPAG_CarryBox_Manager extends ISPAG_Purchase_Request_Generator {

    protected $id_carrybox = 444; // À vérifier dans ta table wor9711_achats_fournisseurs

    public function __construct($deal_id) {
        parent::__construct($deal_id);
    }

    public static function init() {
        // Action AJAX pour le bouton "Envoyer chez Carry Box"
        add_action('wp_ajax_ispag_create_carrybox_order', [self::class, 'ajax_create_order']);
    }

    /**
     * Handler AJAX
     */
    public static function ajax_create_order() {
        ob_start();
        
        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
        
        if (!$deal_id || !current_user_can('manage_order')) {
            ob_end_clean();
            wp_send_json_error(['message' => 'Accès refusé ou Deal ID manquant']);
        }

        try {
            $manager = new self($deal_id);
            $result = $manager->generate_carrybox_process();
            
            if (ob_get_length()) ob_clean();
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            if (ob_get_length()) ob_clean();
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Logique métier complète : Création commande + Article générique + Adresse
     */
    public function generate_carrybox_process() {
        // 1. Récupération des infos projet
        $project = apply_filters('ispag_get_project_by_deal_id', null, $this->deal_id);
        if (!$project) throw new Exception("Projet introuvable.");

        // 2. Détermination de la référence et de l'état
        if (intval($project->isQotation) === 1) {
            $ref = "LOGISTIQUE - " . $project->ObjetCommande;
            $etat = get_option('wpcb_first_qotation_state');
        } else {
            $ref = "LOGISTIQUE - " . get_option('wpcb_kst') . '/' . $project->NumCommande . ' - ' . $project->ObjetCommande;
            $etat = get_option('wpcb_first_order_state');
        }

        // 3. Création de l'entête de commande
        $this->wpdb->insert(
            $this->table_liste_commandes,
            [
                'hubspot_deal_id'       => $this->deal_id,
                'IdFournisseur'         => $this->id_carrybox,
                'TimestampDateCreation' => time(),
                'EtatCommande'          => $etat,
                'RefCommande'           => $ref,
                'Abonne'                => ';1;'
            ]
        );

        $achat_id = $this->wpdb->insert_id;
        if (!$achat_id) throw new Exception("Erreur création entête commande.");

        // 4. AJOUT DE L'ARTICLE DE LIVRAISON (SANS PRIX)
        // On insère une ligne dans la table des détails articles fournisseurs
        $this->wpdb->insert(
            $this->table_commandes,
            [
                'IdCommande'        => $achat_id,
                'IdArticleStandard' => 0, // 0 car c'est un article "libre"
                'IdCommandeClient'  => 0, // Pas lié à un article spécifique du devis
                'RefSurMesure'      => 'LOGISTIQUE',
                'DescSurMesure'     => __('Logistics and storage - Carry Box', 'creation-reservoir'),
                'Qty'               => 1,
                'UnitPrice'         => 0, // Sans prix comme demandé
            ],
            ['%d', '%d', '%d', '%s', '%s', '%d', '%f']
        );

        // 5. Mise à jour de l'adresse de livraison
        $this->set_delivery_address($achat_id, $project);

        return [
            'message'  => 'Commande Carry Box créée avec article logistique.',
            'achat_id' => $achat_id
        ];
    }

    /**
     * Injection de l'adresse Carry Box dans achats_info_commande
     */
    private function set_delivery_address($achat_id, $project) {
        $table_info = $this->wpdb->prefix . 'achats_info_commande';
        $objet = !empty($project->ObjetCommande) ? stripslashes($project->ObjetCommande) : 'Projet #' . $this->deal_id;

        $data = [
            'purchase_order'     => $achat_id,
            'AdresseDeLivraison' => 'Carry Box', 
            'DeliveryAdresse2'   => '58 rte du Nant d’Avril',
            'DeliveryAdresse3'   => 'ISPAG - ' . $objet,
            'NIP'                => '1214',
            'City'               => 'Vernier-Genève'
        ];

        // On utilise REPLACE pour gérer l'existence ou non
        $this->wpdb->replace($table_info, $data);
    }
}

// Initialisation
