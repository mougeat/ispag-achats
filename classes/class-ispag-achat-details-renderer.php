<?php 

class ISPAG_Achat_Details_Renderer {

    public static function init() {
        add_action('ispag_achat_details_tab', [self::class, 'display_achat_details_tab'], 10, 1);
        add_action('wp_ajax_ispag_copy_project_address', [self::class, 'copy_adress_from_project']);
        add_action('wp_ajax_ispag_set_carrybox_address', [self::class, 'ispag_set_carrybox_address']);
    }

    public static function display_achat_details_tab($achat_id) {
        $achat_repo = new ISPAG_Achat_Repository();
        $details_repo = new ISPAG_Achat_Details_Repository(); 

        $achat = apply_filters('ispag_get_achat_by_id', null, intval($achat_id));
        $infos = $details_repo->get_infos_livraison($achat_id);

        // echo '<pre>';
        // var_dump($achat);
        // echo '</pre>';

        echo '<div class="ispag-detail-section">';
        self::render_bloc_project_info($achat);
        self::render_bloc_livraison($infos, $achat);
        echo '</div>';
    }
    private static function render_bloc_project_info($achat) {
        
        // echo '<pre>';
        // var_dump($project);
        // echo '</pre>';
        $achat_id = (int) $achat->Id;
        $can_edit = current_user_can('edit_supplier_order') ;
        $bgcolor = !empty($achat->color) ? esc_attr($achat->color) : '#ccc';

        echo '<div class="ispag-box">';
        echo '<h3>' . __('Project informations', 'creation-reservoir') . '</h3>';

        echo '<p><strong>Status :</strong> <span class="ispag-state-badge" style="background-color:' . $bgcolor . '; opacity: 0.8;">' . esc_html__($achat->Etat, 'creation-reservoir') . '</span> </p>';

        echo '</div>';
    }

    private static function render_bloc_livraison($infos, $achat) {
        echo '<div class="ispag-box" id="ispag-delivery-box">';
        echo '<h3>' . __('Delivery', 'creation-reservoir') . '</h3>';

        // Ajout de DeliveryAdresse3 dans la liste
        $champs = [
            'AdresseDeLivraison' => __('Adress', 'creation-reservoir'),
            'DeliveryAdresse2'   => __('Complement', 'creation-reservoir'),
            'DeliveryAdresse3'   => __('Project Info', 'creation-reservoir'), // Nouveau
            'NIP'                => __('Postal code', 'creation-reservoir'),
            'City'               => __('City', 'creation-reservoir'),
            'PersonneContact'    => __('Contact', 'creation-reservoir'),
            'num_tel_contact'    => __('Phone', 'creation-reservoir'),
        ];

        $copie_ligne1 = [];
        $copie_ligne2 = '';

        echo '<div id="delivery-info-text">';
        foreach ($champs as $champ => $label) {
            $val = $infos->$champ ?? '';
            echo '<p><strong>' . esc_html($label) . ' :</strong> ';

            if (current_user_can('edit_supplier_order')) {
                echo '<span class="ispag-inline-edit" 
                            data-name="' . esc_attr($champ) . '" 
                            data-value="' . esc_attr($val) . '" 
                            data-deal="' . esc_attr($infos->purchase_order) . '"
                            data-source="delivery">';
                echo esc_html($val) ?: '---'; // Affiche --- si vide
                echo ' <span class="edit-icon">✏️</span></span>';
            } else {
                echo esc_html($val);
            }
            echo '</p>';

            // Logique de copie mise à jour
            if (in_array($champ, ['AdresseDeLivraison', 'DeliveryAdresse2', 'DeliveryAdresse3', 'Postal code', 'City'])) {
                if(!empty($val)) $copie_ligne1[] = $val;
            } elseif ($champ === 'PersonneContact') {
                $copie_ligne2 = $val;
            } elseif ($champ === 'num_tel_contact') {
                $copie_ligne2 .= ($copie_ligne2 ? ' : ' : '') . $val;
            }
        }
        echo '</div>';

        // Zone invisible avec texte à copier
        echo '<pre id="delivery-info-copy" style="display:none;">' .
            esc_html(implode("\n", array_filter([
                implode(", ", array_filter($copie_ligne1)),
                $copie_ligne2
            ]))) .
        '</pre>';

        

        echo '<div class="ispag-delivery-actions" style="margin-top: 10px; display: flex; gap: 10px;">';
        echo '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-btn-copy-description" data-target="#delivery-info-copy">📋</button>';
        echo '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-btn-copy-from-project" data-achat="' . esc_attr($achat->Id) . '" data-deal-id="' . esc_attr($achat->hubspot_deal_id) . '">📥 ' . __('Copy from project', 'creation-reservoir') . '</button>';
        echo '<button type="button" class="ispag-btn ispag-btn-blue-outlined ispag-btn-set-carrybox" data-achat="' . esc_attr($achat->Id) . '" data-deal-id="' . esc_attr($achat->hubspot_deal_id) . '">📦 Livraison Carry Box</button>';
        echo '</div>';
        echo '</div>';
    }

    public static function ispag_set_carrybox_address() {
        $achat_id = intval($_POST['achat_id'] ?? 0);
        $deal_id = intval($_POST['deal_id'] ?? 0);
        
        if (!$achat_id) wp_send_json_error('ID Achat manquant');

        // 1. Récupération des infos du projet pour le nom
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $objet_commande = ($project && !empty($project->ObjetCommande)) 
            ? stripslashes($project->ObjetCommande) 
            : 'Projet #' . $deal_id;

        global $wpdb;
        $table = $wpdb->prefix . 'achats_info_commande';

        // 2. Préparation des données d'adresse
        $data_delivery = array(
            'AdresseDeLivraison' => 'Carry Box', 
            'DeliveryAdresse2'   => '58 rte du Nant d’Avril',
            'DeliveryAdresse3'   => 'ISPAG - ' . $objet_commande,
            'NIP'                => '1214',
            'City'               => 'Vernier-Genève'
        );

        // 3. On vérifie si la ligne existe déjà pour ce purchase_order
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE purchase_order = %d", 
            $achat_id
        ));

        if ($exists) {
            // La ligne existe : on met à jour
            $wpdb->update(
                $table,
                $data_delivery,
                array('purchase_order' => $achat_id)
            );
        } else {
            // La ligne n'existe pas : on l'insère
            $data_delivery['purchase_order'] = $achat_id;
            $wpdb->insert($table, $data_delivery);
        }

        // 4. RÉGÉNÉRATION DU HTML POUR LE FRONT-END
        ob_start();
        
        // On recharge l'objet complet pour être sûr d'avoir toutes les colonnes
        $achat_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE purchase_order = %d", 
            $achat_id
        ));
        
        if ($achat_data) {
            // On passe l'objet aux deux paramètres de la méthode de rendu
            self::render_bloc_livraison($achat_data, $achat_data); 
        } else {
            echo '<div class="error">Erreur de récupération des données.</div>';
        }
        
        $html = ob_get_clean();
        wp_send_json_success(['html' => $html]);
    }


    public static function copy_adress_from_project(){
        $achat_id = intval($_POST['achat_id'] ?? 0);
        $deal_id = intval($_POST['deal_id'] ?? 0);

        if (!$achat_id) {
            wp_send_json_error('ID achat manquant');
        }
        if (!$deal_id) {
            wp_send_json_error('ID projet manquant');
        }

        $project_repo = new ISPAG_Project_Details_Repository();
        $project_infos = $project_repo->get_infos_livraison($deal_id);
        if (!$project_infos) {
            wp_send_json_error('Adresse projet introuvable');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'achats_info_commande';

        // On vérifie si la ligne existe déjà
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE purchase_order = %d",
            $achat_id
        ));

        $data = [
            'AdresseDeLivraison' => $project_infos->AdresseDeLivraison,
            'DeliveryAdresse2'   => $project_infos->DeliveryAdresse2,
            'DeliveryAdresse3'   => $project_infos->DeliveryAdresse3,
            'NIP'                => $project_infos->NIP,
            'City'               => $project_infos->City,
            'PersonneContact'    => $project_infos->PersonneContact,
            'num_tel_contact'    => $project_infos->num_tel_contact,
            'purchase_order'     => $achat_id,
        ];

        if ($exists) {
            $wpdb->update($table, $data, ['purchase_order' => $achat_id]);
        } else {
            $wpdb->insert($table, $data);
        }

        ob_start();
        self::render_bloc_livraison($project_infos, (object)['Id' => $achat_id, 'hubspot_deal_id' => $deal_id]);
        $html = ob_get_clean();

        wp_send_json_success([
            'purchase_order' => $achat_id,
            'html' => $html,
            'project_info' => $project_infos
        ]);
    }

}
