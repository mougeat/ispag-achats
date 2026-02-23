<?php

/**
 * Gère les opérations de BDD et l'interface utilisateur pour les commandes d'achat ISPAG.
 * Utilise AJAX pour le traitement du formulaire, incluant la liaison aux projets clients/Hubspot.
 */
class ISPAG_Achat_Commande_Manager {

    private $wpdb;
    private $table_commandes;      // wor9711_achats_commande_liste_fournisseurs
    private $table_fournisseurs;   // wor9711_achats_fournisseurs
    private $table_etats;          // wor9711_achats_etat_commandes_fournisseur
    private $table_projets;        // wor9711_achats_liste_commande (Projets Clients)
    private $text_domain = 'creation-reservoir'; 
    private $ajax_action = 'ispag_nouvelle_commande_ajax'; // Nom de l'action AJAX
    
    protected static $instance = null;

    /**
     * Constructeur
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        // Définir les noms de tables
        $this->table_commandes    = 'wor9711_achats_commande_liste_fournisseurs';
        $this->table_fournisseurs = 'wor9711_achats_fournisseurs';
        $this->table_etats        = 'wor9711_achats_etat_commandes_fournisseur';
        $this->table_projets      = 'wor9711_achats_liste_commande';
        
        // Charger le domaine de texte
        add_action( 'plugins_loaded', array( $this, 'load_text_domain' ) );
    }

    /**
     * Charge le domaine de texte pour le plugin.
     */
    public function load_text_domain() {
        load_plugin_textdomain( 
            $this->text_domain, 
            false, 
            dirname( plugin_basename( __FILE__ ) ) . '/languages/'
        );
    }
    
    /**
     * Point d'entrée de la classe (Singleton). Enregistre les hooks WordPress.
     */
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        // Enregistrement du Shortcode
        add_shortcode( 'ispag_form_nouvelle_commande', [self::$instance, 'display_achat_form_callback'] );
        
        // Enregistrement des hooks AJAX (connectés et déconnectés)
        add_action( 'wp_ajax_' . self::$instance->ajax_action, [self::$instance, 'handle_achat_ajax_submission'] );
        add_action( 'wp_ajax_nopriv_' . self::$instance->ajax_action, [self::$instance, 'handle_achat_ajax_submission'] );
        
        // Enqueue des scripts et localisation des variables AJAX
        add_action( 'wp_enqueue_scripts', [self::$instance, 'enqueue_ajax_script'] );
    }

    /**
     * Enqueue le script JS de soumission AJAX et passe les variables nécessaires.
     */
    public function enqueue_ajax_script() {
        wp_register_script( 
            'ispag-form-ajax', 
            plugins_url( '../assets/js/ispag-creation-form-ajax.js', __FILE__ ), // Chemin du JS
            array('jquery'), 
            '1.0', 
            true 
        );
        wp_enqueue_script( 'ispag-form-ajax' );

        // Localisation des variables AJAX (URL et Nonce)
        wp_localize_script( 'ispag-form-ajax', 'ispagAjax', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'nouvelle_commande_action' ), 
            'action'  => $this->ajax_action, 
        ));
    }
    
    // --------------------------------------------------------------------
    // --- Méthodes de Récupération (SELECT) ---
    // --------------------------------------------------------------------

    /**
     * Récupère la liste des projets clients (avec ID Hubspot).
     * @return array Tableau d'objets (id, NumCommande, ObjetCommande, hubspot_deal_id)
     */
    private function get_liste_projets() {
        // $sql = "SELECT id, NumCommande, ObjetCommande, hubspot_deal_id FROM {$this->table_projets} WHERE isQotation IS NULL ORDER BY ObjetCommande ASC";
        $sql = "SELECT id, NumCommande, ObjetCommande, project_status, hubspot_deal_id FROM {$this->table_projets} WHERE isQotation IS NULL ORDER BY project_status DESC, ObjetCommande ASC";
        return $this->wpdb->get_results( $sql );
    }

    /**
     * Récupère la liste des fournisseurs actifs.
     */
    private function get_liste_fournisseurs() {
        $sql = $this->wpdb->prepare(
            "SELECT Id, Fournisseur FROM {$this->table_fournisseurs} WHERE isSupplier = %d ORDER BY Fournisseur ASC",
            1
        );
        return $this->wpdb->get_results( $sql );
    }

    /**
     * Récupère la liste des états de commande.
     */
    private function get_liste_etats_commande() {
        $sql = "SELECT Id, Etat FROM {$this->table_etats} ORDER BY ordre ASC";
        return $this->wpdb->get_results( $sql );
    }

    // --------------------------------------------------------------------
    // --- Méthode d'Enregistrement (INSERT) ---
    // --------------------------------------------------------------------
    
    /**
     * Enregistre une nouvelle commande d'achat dans la base de données.
     * @param string $ref_commande_final La référence formatée ou manuelle.
     * @param int $id_fournisseur L'ID du fournisseur.
     * @param int $etat_commande L'ID de l'état initial.
     * @param string $abonne L'ID de l'utilisateur formaté ";id;".
     * @param int $hubspot_deal_id L'ID de l'affaire Hubspot.
     * @return int|bool L'ID inséré ou false en cas d'échec.
     */
    private function enregistrer_nouvelle_commande( $ref_commande_final, $id_fournisseur, $etat_commande, $abonne, $hubspot_deal_id ) {
        if ( empty( $ref_commande_final ) || empty( $id_fournisseur ) || empty( $etat_commande ) ) {
            // error_log( 'Erreur d\'enregistrement : Champs obligatoires manquants.' );
            return false;
        }

        $data = array(
            'RefCommande'           => sanitize_text_field( $ref_commande_final ), 
            'IdFournisseur'         => intval( $id_fournisseur ),
            'EtatCommande'          => intval( $etat_commande ),
            'Abonne'                => sanitize_text_field( $abonne ), 
            'TimestampDateCreation' => time(),
            'Total'                 => 0.0,
            'hubspot_deal_id'       => intval( $hubspot_deal_id ), 
            // Valeurs par défaut
            'NrCommande' => '', 'TimestampDateReception' => 0, 
            'TimestampDateReceptionConfirmee' => 0, 'Remarque' => '', 'ConfCmdFournisseur' => '',
        );

        // Format: %s, %d, %d, %s, %d, %f, %d, %s, %d, %d, %s, %s (Notez le %d pour hubspot_deal_id)
        $format = array(
            '%s', '%d', '%d', '%s', '%d', '%f', '%d', '%s', '%d', '%d', '%s', '%s',
        );

        $result = $this->wpdb->insert( $this->table_commandes, $data, $format );

        if ( $result === false ) {
            // error_log( 'Erreur lors de l\'insertion de la commande: ' . $this->wpdb->last_error );
            return false;
        }

        return $this->wpdb->insert_id;
    }
    
    // --------------------------------------------------------------------
    // --- Gestion du Formulaire (Callback Shortcode et Traitement AJAX) ---
    // --------------------------------------------------------------------

    /**
     * Fonction de callback pour le shortcode. Génère le HTML du formulaire.
     */
    public function display_achat_form_callback() {
        $fournisseurs = $this->get_liste_fournisseurs();
        $etats_commande = $this->get_liste_etats_commande();
        $projets = $this->get_liste_projets(); 
        
        ob_start();
        ?>
        <div class="ispag-achat-commande-form-container">
            <h3><?php echo esc_html__( 'New purchase request', $this->text_domain ); ?></h3>
            
            <form method="post" id="ispag-achat-form" class="vertical-form"> 
                
                
                
                <div>
                    <input type="text" id="hubspot_deal_id_hidden" name="hubspot_deal_id_hidden" value="">
                    
                    <label for="id_projet"><?php echo esc_html__( 'Customer Project (Optional)', $this->text_domain ); ?> : </label>
                    <select id="id_projet" name="id_projet" class="form-field">
                        <option 
                            value="" 
                            data-ref="" 
                            data-hubspot-id="0"
                        >
                            <?php echo esc_html__( 'Select customer project', $this->text_domain ); ?>
                        </option>
                        <?php foreach ( $projets as $projet ) : 
                            $ref_projet = 'KST300/' . esc_attr($projet->NumCommande) . ' - ' . esc_html($projet->ObjetCommande);
                            $hubspot_id = absint($projet->hubspot_deal_id);
                            ?>
                            <option 
                                value="<?php echo esc_attr( $projet->id ); ?>" 
                                data-ref="<?php echo esc_attr($ref_projet); ?>"
                                data-hubspot-id="<?php echo esc_attr($hubspot_id); ?>"
                            >
                                <?php echo esc_html($projet->ObjetCommande); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="ref_commande"><?php echo esc_html__( 'Reference', $this->text_domain ); ?> : </label>
                    <input type="text" id="ref_commande" name="ref_commande" required class="form-field">
                </div>

                <div>
                    <label for="id_fournisseur"><?php echo esc_html__( 'Supplier', $this->text_domain ); ?> : </label>
                    <select id="id_fournisseur" name="id_fournisseur" required class="form-field">
                        <option value=""><?php echo esc_html__( 'Select supplier', $this->text_domain ); ?></option>
                        <?php foreach ( $fournisseurs as $fournisseur ) : ?>
                            <option value="<?php echo esc_attr( $fournisseur->Id ); ?>">
                                <?php echo esc_html( $fournisseur->Fournisseur ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="etat_commande"><?php echo esc_html__( 'State', $this->text_domain ); ?> : </label>
                    <select id="etat_commande" name="etat_commande" required class="form-field">
                        <option value=""><?php echo esc_html__( 'Select start state', $this->text_domain ); ?></option>
                        <?php foreach ( $etats_commande as $etat ) : ?>
                            <option value="<?php echo esc_attr( $etat->Id ); ?>">
                                <?php echo esc_html__( $etat->Etat, $this->text_domain ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="ispag-form-message"></div>
                
                <div>
                    <input type="submit" id="submit-ajax-commande" name="submit_nouvelle_commande" 
                        value="<?php echo esc_attr__( 'Save', $this->text_domain ); ?>" 
                        class="ispag-btn ispag-btn-red-outlined">
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Gère la soumission du formulaire et l'enregistrement dans la BDD via AJAX.
     */
    public function handle_achat_ajax_submission() {
        // 1. Vérification de sécurité (Nonce) 
        if ( ! check_ajax_referer( 'nouvelle_commande_action', 'nonce', false ) ) {
            $this->send_json_error( esc_html__( 'Security error. Invalid request. (Nonce expired or invalid)', $this->text_domain ), 'security' );
        }

        // 2. Vérification des permissions
        if ( ! current_user_can( 'view_supplier_order' ) ) {
            $this->send_json_error( esc_html__( 'Permission error. You do not have permission to perform this action.', $this->text_domain ), 'permission' );
        }

        // 3. Récupération et nettoyage des données POST
        $ref_commande    = sanitize_text_field( $_POST['ref_commande'] );
        $id_fournisseur  = intval( $_POST['id_fournisseur'] );
        $etat_commande   = intval( $_POST['etat_commande'] );
        $id_projet       = isset($_POST['id_projet']) ? intval( $_POST['id_projet'] ) : 0; 
        $hubspot_deal_id = isset($_POST['hubspot_deal_id_hidden']) ? intval( $_POST['hubspot_deal_id_hidden'] ) : 0; 

        // 4. Traitement de la référence finale
        $ref_commande_final = $ref_commande;
        if ($id_projet > 0) {
            $projet = $this->wpdb->get_row( 
                $this->wpdb->prepare(
                    "SELECT NumCommande, ObjetCommande FROM {$this->table_projets} WHERE id = %d", 
                    $id_projet
                )
            );
            
            if ($projet) {
                // Formatage : KST300/{NumCommande} - {ObjetCommande}
                $ref_commande_final = 'KST300/' . $projet->NumCommande . ' - ' . $projet->ObjetCommande;
            }
        }
        
        // 5. Formatage de l'abonné (utilisateur courant) : ;id;
        $current_user = wp_get_current_user();
        $abonne       = ';' . $current_user->ID . ';'; 

        // 6. Validation essentielle
        if ( empty( $ref_commande_final ) || $id_fournisseur <= 0 || $etat_commande <= 0 ) {
            $this->send_json_error( esc_html__( 'Error: Required fields are missing. Please complete the form.', $this->text_domain ), 'mandatory' );
        }

        // 7. Enregistrement
        $nouvel_id = $this->enregistrer_nouvelle_commande( 
            $ref_commande_final, 
            $id_fournisseur, 
            $etat_commande, 
            $abonne,
            $hubspot_deal_id
        );

        // 8. Réponse JSON
        if ( $nouvel_id ) {
            $message = sprintf( esc_html__( 'New purchase request successfully saved (ID: %d).', $this->text_domain ), $nouvel_id );
            $this->send_json_success( $message, $nouvel_id );
        } else {
            $this->send_json_error( esc_html__( 'Error saving the purchase request to the database. Please contact support.', $this->text_domain ), 'db' );
        }
    }
    
    /**
     * Helper pour envoyer une réponse JSON de succès et terminer la requête.
     */
    private function send_json_success( $message, $id ) {
        wp_send_json_success( array( 
            'message' => $message, 
            'id' => $id, 
        ) );
        wp_die();
    }

    /**
     * Helper pour envoyer une réponse JSON d'erreur et terminer la requête.
     */
    private function send_json_error( $message, $code ) {
        wp_send_json_error( array( 
            'message' => $message, 
            'code' => $code, 
        ) );
        wp_die();
    }
    
}
// LIGNE CRUCIALE : Initialisation de la classe. 
add_action( 'plugins_loaded', ['ISPAG_Achat_Commande_Manager', 'init'] );