<?php
defined('ABSPATH') || exit;

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
        $this->update_status(null, $achat_id, $etat_id, 'is_manual');
    }
    public function update_status($html, $achat_id = null, $etat_id = null, $is_manual = null) {
        if (!$achat_id || !$etat_id) {
            wp_send_json_error(['message' => 'Invalid input']);
        }

        // Récupère le slug du statut
        $slug = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT ClassCss FROM {$this->table_etats} WHERE Id = %d",
                $etat_id
            )
        );

        // Met à jour le statut ET marque comme manuel
        $updated = $this->wpdb->update(
            $this->table_achats,
            [
                'EtatCommande' => $etat_id,
                'is_manual'    => $is_manual, // <-- AJOUT : Marque comme changement manuel
            ],
            ['Id' => $achat_id],
            ['%d', '%d'],
            ['%d']
        );

        do_action('ispag_save_status_changes', $achat_id, $slug, $etat_id);

        // De quoi mettre à jour la page sans la recharger : nouveau statut + boutons qui en dépendent
        $current = $this->get_current_status($achat_id);
        ob_start();
        self::render_action_button_for_achat($achat_id);
        $action_html = ob_get_clean();
        $footer_html = class_exists('ISPAG_Achat_Renderer') ? ISPAG_Achat_Renderer::footer_buttons_html($achat_id) : '';

        wp_send_json_success([
            'updated'     => $updated,
            'status'      => $current ? ['Id' => (int) $current->Id, 'Etat' => __($current->Etat, 'creation-reservoir'), 'ClassCss' => $current->ClassCss, 'color' => $current->color] : null,
            'action_html' => $action_html,
            'footer_html' => $footer_html,
        ]);
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
            '<button class="ispag-btn ispag-btn-secondary-outlined achat-action-btn" data-achat-id="%d" data-hook="%s"><span class="dashicons dashicons-migrate"></span> %s</button>',
            intval($achat_id),
            esc_attr($etat->JsHook),
            esc_html__($etat->ActionText, 'creation-reservoir')
        );
    }

    public static function prepare_mail_from_action() {
        try {
            $achat_id = intval($_POST['achat_id'] ?? 0);
            $action_type = sanitize_text_field($_POST['type'] ?? '');

            if (!$achat_id || !$action_type) {
                wp_send_json_error(['message' => 'Missing parameters (ID or Type).']);
            }

            self::prepare_mail($achat_id, $action_type);

        } catch (Throwable $e) {
            // Renvoie l'erreur PHP réelle au format JSON pour que ton JS ne crash pas
            wp_send_json_error([
                'message' => 'Fatal PHP error: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
    
    /**
     * prepare_mail
     *
     * @param  mixed $achat_id
     * @param  mixed $message_type
     * @return void
     */
    public static function prepare_mail($achat_id = null, $message_type = null ) {
        $mail = self::build_mail($achat_id, $message_type);
        if (is_wp_error($mail)) {
            wp_send_json_error(['message' => $mail->get_error_message()]);
        }
        // Commande, modifications et validations de plans : brouillon Outlook (.eml) avec les pièces jointes ; les autres messages en mailto
        if (class_exists('ISPAG_Achat_Mail_Draft') && ISPAG_Achat_Mail_Draft::supports($message_type)) {
            $mail['eml_url'] = ISPAG_Achat_Mail_Draft::download_url($achat_id, $message_type);
            $mail['attachments_count'] = ISPAG_Achat_Mail_Draft::attachments_count($achat_id, $message_type);
        }
        wp_send_json_success($mail);
    }

    /**
     * Prépare le mail (destinataire, objet, texte avec balises remplacées) d'un type de message pour une commande.
     *
     * @return array|WP_Error
     */
    public static function build_mail($achat_id = null, $message_type = null ) {
        global $wpdb;

        // $achat_id = intval($_POST['achat_id']);
        if (!$achat_id) {
            return new WP_Error('mail', 'ID de commande manquant.');
        }

        // 1. Récupérer IdFournisseur et EtatCommande
        $achat = $wpdb->get_row($wpdb->prepare("
            SELECT IdFournisseur, hubspot_deal_id FROM {$wpdb->prefix}achats_commande_liste_fournisseurs WHERE Id = %d
        ", $achat_id));
        if (!$achat){
            return new WP_Error('mail', 'Order not found.');
        }

        // 2. Récupérer infos fournisseur
        $meta_table = $wpdb->prefix . 'ispag_companies_meta';
        $get_meta = function ($key) use ($wpdb, $meta_table, $achat) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$meta_table} WHERE company_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
                $achat->IdFournisseur,
                $key
            ));
        };
        $fournisseur = $wpdb->get_row($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}ispag_companies WHERE id = %d
        ", $achat->IdFournisseur));
        if ($fournisseur) {
            $fournisseur->IdContactCommande = $get_meta('ispag_supplier_contact_order');
            $fournisseur->IdContactPlan     = $get_meta('ispag_supplier_contact_plan');
            $fournisseur->Langue            = $get_meta('ispag_supplier_lang');
        }

        if (!$fournisseur) {
            return new WP_Error('mail', 'Supplier not found.');
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

        $lang = !empty($fournisseur->Langue) ? ISPAG_Achat_Mail_Templates::normalize_lang(sanitize_text_field($fournisseur->Langue)) : 'fr_FR';
        if ($lang === '') $lang = 'fr_FR';


        // 3. Récupérer contact user
        $user = get_user_by('ID', $contact_id);
        if (!$user) {
            return new WP_Error('mail', 'Contact utilisateur introuvable.');
        }
        $email_contact = $user->user_email;

        // 4. Récupérer le template (langue du fournisseur, à défaut le modèle anglais par défaut)
        $template = ISPAG_Achat_Mail_Templates::get_template($message_type, $lang);

        if (!$template) {
            return new WP_Error('mail', 'Template not found for type "' . $message_type . '" (language: ' . $lang . '). Create it in the Email templates page.');
        }

        // 5. Remplacer les tags
        $subject = self::replace_text($template->subject, $achat_id, $contact_id, $lang);
        $message = self::replace_text($template->message, $achat_id, $contact_id, $lang);

        $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // $lang = get_user_meta($contact_id, 'locale', true) ?: get_user_meta($contact_id, 'pll_language', true);

        $instance = new self();
        $current_status = $instance->get_current_status($achat_id);
        $next_status = $instance->get_next_status($current_status->Id);

        // 6. Données du mail
        return [
            'current_status' => $current_status->Id,
            'next_status' => $next_status,
            'achat_id' => $achat_id,
            'subject' => $subject,
            'message' => $message,
            'email_contact' => $email_contact,
            'email_copy' => ' ' // à adapter
        ];
    }

    
    public static function replace_text($text, $achat_id, $contact_id, $lang) {
        // 1. Récupérer contact et langue
        $user = get_user_by('ID', $contact_id);
        if (!$user) wp_send_json_error(['message' => 'Contact utilisateur introuvable.']);

        // $lang = get_user_meta($contact_id, 'locale', true) ?: get_user_meta($contact_id, 'pll_language', true);
        // error_log('[SEND MAIL DEBUG] Lang du destinataire ID' . $contact_id . ' --> ' . $lang);

        // 2. Définir la langue AVANT toute récupération de données
        $switched = false;
        if ($lang) {
            if (function_exists('pll_set_language')) pll_set_language($lang);
            $switched = (bool) switch_to_locale($lang);
        }

        // error_log('[SEND MAIL DEBUG] Langue active avant récup articles: ' . (function_exists('pll_current_language') ? pll_current_language() : get_locale()));

        // 3. Récupérer données de l'achat et articles (maintenant en bonne langue)
        $repo = new ISPAG_Achat_Repository();
        $achat = $repo->get_achats(null, true, $achat_id, '', 0, 1)[0];
        $articles = (new ISPAG_Achat_Article_Repository())->get_articles_by_order(null, $achat_id, $lang);

        // 4. Construire la liste des produits avec traduction explicite
        $product_list = "\n";
        $last_group = null;

        foreach ($articles as $article) {
            $group = trim($article->Groupe ?? '');
            $desc = trim($article->DescSurMesure ?? '');

            // Traduire explicitement les champs si nécessaire (exemple pour ACF ou métadonnées)
            if (function_exists('pll_translate_string')) {
                $group = pll_translate_string($group, $lang);
                $desc = pll_translate_string($desc, $lang);
            }

            if ($group !== $last_group) {
                if ($last_group !== null) $product_list .= "\n--------------\n\n";
                $product_list .= "🟢 $group\n\n";
                $last_group = $group;
            }
            $product_list .= $desc . "\n";
        }

        // Supprimer le dernier '-------' s'il n'y a pas de groupe après
        // $product_list = rtrim($product_list, "-\n");
        $product_list = preg_replace("/-------\s*$/", "", $product_list);
        $product_list = stripslashes($product_list);

        // // 4. Récupérer projet
        // $project = (new ISPAG_Projet_Repository())->get_project_by_deal_id($achat->hubspot_deal_id);

        // 5. Remplacer les balises : {TAG} (voir ISPAG_Achat_Mail_Templates::tags()) + anciennes balises sans accolades
        $ref_parts    = explode(' - ', (string) $achat->RefCommande, 2);
        $order_number = trim($ref_parts[0]);
        $project_name = isset($ref_parts[1]) ? trim($ref_parts[1]) : '';
        $delivery     = (new ISPAG_Achat_Details_Repository())->get_infos_livraison($achat_id);
        $d = function ($k) use ($delivery) { return trim(stripslashes((string) ($delivery->$k ?? ''))); };
        $zip_city = trim($d('NIP') . ' ' . $d('City'));
        $delivery_block = implode("\n", array_filter([$d('AdresseDeLivraison'), $d('DeliveryAdresse2'), $d('DeliveryAdresse3'), $zip_city]));
        $sender = wp_get_current_user();

        $replacements = [
            '{FIRST_NAME}'       => $user->first_name,
            '{LAST_NAME}'        => $user->last_name,
            '{SUPPLIER_NAME}'    => (string) ($achat->Fournisseur ?? ''),
            '{ORDER_NUMBER}'     => $order_number,
            '{PROJECT_NAME}'     => $project_name,
            '{ORDER_REF}'        => stripslashes((string) $achat->RefCommande),
            '{ORDER_DATE}'       => !empty($achat->TimestampDateCreation) ? date_i18n('d.m.Y', (int) $achat->TimestampDateCreation) : date_i18n('d.m.Y'),
            '{PRODUCT_LIST}'     => $product_list,
            '{DELIVERY_ADDRESS}' => $delivery_block,
            '{DELIVERY_CONTACT}' => $d('PersonneContact'),
            '{DELIVERY_PHONE}'   => $d('num_tel_contact'),
            '{PURCHASE_URL}'     => (string) $achat->purchase_url,
            '{USER_NAME}'        => $sender->display_name,
            '{COMPANY_NAME}'     => (string) get_option('wpcb_companyName'),

            // Anciennes balises (modèles existants)
            'PRENOM'   => $user->first_name,
            'NOM'   => $user->last_name,
            'PROJECT_NAME'   => $achat->RefCommande,
            'PURCHASE_LINK'  => '<a href="' . $achat->purchase_url . '">ici</a>',
            'PRODUCT_LIST'   => $product_list,
            'DELIVERY_ADRESS' => $d('AdresseDeLivraison'),
            'DELIVERY_ADRESS2' => $d('DeliveryAdresse2'),
            'DELIVERY_NIP' => $d('NIP'),
            'DELIVERY_CITY' => $d('City'),
            'DELIVERY_CONTACT' => $d('PersonneContact'),
            'DELIVERY_CONTACT_PHONE' => $d('num_tel_contact'),
            'DELIVERY_DATE' => '',
        ];

        $text = strtr($text, $replacements);

        // Les données ont été préparées dans la langue du fournisseur : on rend sa langue à la suite de la requête
        if ($switched) restore_previous_locale();

        // 6. Nettoyer le texte
        $text = str_ireplace(['<br />', '<br/>'], "\n", $text);
        $text = preg_replace("/<hr\W*?\/?>/", str_repeat('- ', 30), $text);
        $text = strip_tags($text);

        return $text;
    }



}
