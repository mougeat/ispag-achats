<?php

class ISPAG_Achat_Status_Controller {
    private $wpdb;
    private $table_etats;
    private $table_achats;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_etats = $wpdb->prefix . 'achats_etat_commandes_fournisseur';
        $this->table_achats = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_action('wp_ajax_ispag_get_status_options', [self::$instance, 'get_status_options']);
        add_action('wp_ajax_ispag_update_status', [self::$instance, 'ajax_update_status']);
        add_action('ispag_update_status', [self::$instance, 'update_status'], 10, 3);
        
        // add_action('wp_ajax_ispag_prepare_rfq_mail', [self::$instance, 'prepare_rfq_mail']);
        // add_action('wp_ajax_ispag_prepare_order_mail', [self::$instance, 'prepare_order_mail']);
        add_action('wp_ajax_ispag_prepare_mail', [self::$instance, 'prepare_mail_from_action']);


        

        
    }

    public function get_status_options() {
        $results = $this->wpdb->get_results("SELECT Id, Etat, ClassCss, color FROM {$this->table_etats} ORDER BY ordre ASC");

        foreach ($results as &$status) {
            // Suppose que "Etat" contient la version anglaise
            $status->Etat = __($status->Etat, 'creation-reservoir');
        }

        wp_send_json($results);
    }

    public function ajax_update_status() {
        $achat_id = isset($_POST['achat_id']) ? intval($_POST['achat_id']) : 0;
        $etat_id = isset($_POST['etat_id']) ? intval($_POST['etat_id']) : 0;
        $this->update_status(null, $achat_id, $etat_id);
    }
    public function update_status($html, $achat_id = null, $etat_id = null) {
        // $achat_id = isset($_POST['achat_id']) ? intval($_POST['achat_id']) : 0;
        // $etat_id = isset($_POST['etat_id']) ? intval($_POST['etat_id']) : 0;

        if (!$achat_id || !$etat_id) {
            wp_send_json_error(['message' => 'Invalid input']);
        }

        $updated = $this->wpdb->update(
            $this->table_achats,
            ['EtatCommande' => $etat_id],
            ['Id' => $achat_id]
        );

        // // Récupère la valeur de ClassCss
        $slug = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT ClassCss FROM {$this->table_etats} WHERE Id = %d",
                $etat_id
            )
        );
        
        do_action('ispag_save_status_changes', $achat_id, $slug, $etat_id);

        wp_send_json_success(['updated' => $updated]);
    }

    public function get_current_status($achat_id) {
        return $this->wpdb->get_row($this->wpdb->prepare("
            SELECT et.Id, et.Etat, et.ClassCss, et.color, ach.EtatCommande
            FROM {$this->table_achats} ach
            INNER JOIN {$this->table_etats} et ON ach.EtatCommande = et.Id
            WHERE ach.Id = %d
            LIMIT 1
        ", $achat_id));
    }


    public function get_next_status($current_status_id) {
        $current_order = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT ordre FROM {$this->table_etats} WHERE Id = %d
        ", $current_status_id));

        if ($current_order === null) return null;

        $next_status = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT Id FROM {$this->table_etats}
            WHERE ordre > %d
            ORDER BY ordre ASC
            LIMIT 1
        ", $current_order));

        return $next_status ?: null;
    }


    public static function render_action_button_for_achat($achat_id) {
        global $wpdb;

        // Récupérer l'état de la commande fournisseur
        $etat_id = $wpdb->get_var($wpdb->prepare("
            SELECT EtatCommande FROM {$wpdb->prefix}achats_commande_liste_fournisseurs WHERE Id = %d
        ", $achat_id));

        if (!$etat_id) return;

        // Récupérer les infos du bouton depuis la table des états
        $etat = $wpdb->get_row($wpdb->prepare("
            SELECT ActionText, JsHook, ClassCss, color
            FROM {$wpdb->prefix}achats_etat_commandes_fournisseur
            WHERE Id = %d
        ", $etat_id));

        if (!$etat || !$etat->JsHook || !$etat->ActionText) return;

        // Affichage du bouton avec les data nécessaires
        echo sprintf(
            '<button class="ispag-btn achat-action-btn %s" style="background-color:%s" data-achat-id="%d" data-hook="%s">%s</button>',
            esc_attr($etat->ClassCss),
            esc_attr($etat->color),
            intval($achat_id),
            esc_attr($etat->JsHook),
            esc_html__($etat->ActionText, 'creation-reservoir')
        );
    }

    public static function prepare_mail_from_action() {
        $achat_id = intval($_POST['achat_id'] ?? 0);
        $action_type = sanitize_text_field($_POST['type'] ?? '');

        if (!$achat_id || !$action_type) {
            // error_log('message : Paramètres manquants.');
            wp_send_json_error(['message' => 'Paramètres manquants.']);
        }

        // Liste des types valides (pour éviter les appels indésirables)
        // $valid_types = [
        //     'send_proposal_request',
        //     'send_purchase_order',
        //     'drawing_validated',
        //     'drawing_modified',

        // ];

        // if (!$action_type) {
        //     wp_send_json_error(['message' => 'Type de mail non autorisé.']);
        // }

        self::prepare_mail($achat_id, $action_type);

        

    }
    
    /**
     * prepare_mail
     *
     * @param  mixed $achat_id
     * @param  mixed $message_type
     * @return void
     */
    public static function prepare_mail($achat_id = null, $message_type = null ) {
        global $wpdb;

        // $achat_id = intval($_POST['achat_id']);
        if (!$achat_id) {
// \1('❌ message : ID de commande manquant.');
            wp_send_json_error(['message' => 'ID de commande manquant.']);
        }

        // 1. Récupérer IdFournisseur et EtatCommande
        $achat = $wpdb->get_row($wpdb->prepare("
            SELECT IdFournisseur, hubspot_deal_id FROM {$wpdb->prefix}achats_commande_liste_fournisseurs WHERE Id = %d
        ", $achat_id));
        if (!$achat){
// \1('❌ message : Commande non trouvée.');
            wp_send_json_error(['message' => 'Commande non trouvée.']);
        }

        // 2. Récupérer infos fournisseur
        $fournisseur = $wpdb->get_row($wpdb->prepare("
            SELECT IdContactCommande, IdContactPlan, Langue FROM {$wpdb->prefix}achats_fournisseurs WHERE Id = %d
        ", $achat->IdFournisseur));

        if (!$fournisseur) {
// \1('❌ message : Fournisseur introuvable.');
            wp_send_json_error(['message' => 'Fournisseur introuvable.']);
        }

        
        

        if (
            stripos($message_type, 'drawing') !== false ||
            stripos($message_type, 'plan') !== false
        ) {
            $contact_id = intval($fournisseur->IdContactPlan);
        }
        else{
            $contact_id = intval($fournisseur->IdContactCommande);
        }

        $lang = !empty($fournisseur->Langue) ? sanitize_text_field($fournisseur->Langue) : 'fr_FR';


        // 3. Récupérer contact user
        $user = get_user_by('ID', $contact_id);
        if (!$user) {
// \1('❌ message : Contact utilisateur introuvable.');
            wp_send_json_error(['message' => 'Contact utilisateur introuvable.']);
        }
        $email_contact = $user->user_email;

        // 4. Récupérer le template
        $template = $wpdb->get_row($wpdb->prepare("
            SELECT subject, message FROM {$wpdb->prefix}achats_template_mail 
            WHERE lang = %s AND message_family = 'purchase_order' AND message_type = %s
            LIMIT 1
        ", $lang, $message_type));

        if (!$template) {
// \1('❌ message : Template non trouvé pour la langue : ' . $lang);
            wp_send_json_error(['message' => 'Template non trouvé pour la langue : ' . $lang]);
        }

        // 5. Remplacer les tags
        $subject = self::replace_text($template->subject, $achat_id, $contact_id);
        $message = self::replace_text($template->message, $achat_id, $contact_id);

        $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lang = get_user_meta($contact_id, 'locale', true) ?: get_user_meta($contact_id, 'pll_language', true);

        $instance = new self();
        $current_status = $instance->get_current_status($achat_id);
        $next_status = $instance->get_next_status($current_status->Id);
        // if ($current_status AND $next_status) {
        //     $instance->update_status($achat_id, $next_status);
        // }

        // 6. Réponse avec mailto
        wp_send_json_success([
            'current_status' => $current_status->Id,
            'next_status' => $next_status,
            'achat_id' => $achat_id,
            'subject' => $subject,
            'message' => $message,
            'email_contact' => $email_contact,
            'email_copy' => ' ' // à adapter
        ]);
    }

    
    public static function replace_text($text, $achat_id, $contact_id) {
        // 1. Récupérer contact
        $user = get_user_by('ID', $contact_id);
        if (!$user) wp_send_json_error(['message' => 'Contact utilisateur introuvable.']);
        
        // 1bis. Forcer la langue si disponible (Polylang)
        $lang = get_user_meta($contact_id, 'locale', true) ?: get_user_meta($contact_id, 'pll_language', true);
        if ($lang) {
            // @intelephense-ignore-next-line
            if (function_exists('pll_set_language')) pll_set_language($lang);
            switch_to_locale($lang); // utile si tu veux charger gettext dans la bonne langue
        }


        // 2. Récupérer données de l'achat
        // $achat = (new ISPAG_Achat_Repository())->get_achats(null, true, $achat_id, 0, 1)[0];
        $repo = new ISPAG_Achat_Repository();
        $achat = $repo->get_achats(null, true, $achat_id, '', 0, 1)[0];
        

        // 3. Récupérer articles
        $articles = (new ISPAG_Achat_Article_Repository())->get_articles_by_order(null, $achat_id);

        $product_list = "\n";
        $last_group = null;

        foreach ($articles as $index => $article) {
            $group = trim($article->Groupe ?? '');

            // Si nouveau groupe, on l'affiche
            if ($group !== $last_group) {
                if ($last_group !== null) $product_list .= "\n--------------\n\n"; // sépare les groupes
                $product_list .= "🟢 $group\n\n";
                $last_group = $group;
            }

            // Ajouter description
            $product_list .= trim($article->DescSurMesure) . "\n";
        }

        // Supprimer le dernier '-------' s'il n'y a pas de groupe après
        // $product_list = rtrim($product_list, "-\n");
        $product_list = preg_replace("/-------\s*$/", "", $product_list);
        $product_list = stripslashes($product_list);

        // // 4. Récupérer projet
        // $project = (new ISPAG_Projet_Repository())->get_project_by_deal_id($achat->hubspot_deal_id);

        // 5. Remplacer les balises
        $replacements = [
            'PRENOM'   => $user->first_name,
            'NOM'   => $user->last_name,
            'PROJECT_NAME'   => $achat->RefCommande,
            'PURCHASE_LINK'  => '<a href="' . $achat->purchase_url . '">ici</a>',
            'PRODUCT_LIST'   => $product_list,
            'DELIVERY_ADRESS' => '',
            'DELIVERY_ADRESS2' => '',
            'DELIVERY_NIP' => '',
            'DELIVERY_CITY' => '',
            'DELIVERY_CONTACT' => '',
            'DELIVERY_CONTACT_PHONE' => '',
            'DELIVERY_DATE' => '',
        ];

        $text = strtr($text, $replacements);

        // 6. Nettoyer le texte
        $text = str_ireplace(['<br />', '<br/>'], "\n", $text);
        $text = preg_replace("/<hr\W*?\/?>/", str_repeat('- ', 30), $text);
        $text = strip_tags($text);

        return $text;
    }



}
