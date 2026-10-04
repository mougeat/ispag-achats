<?php 
defined('ABSPATH') || exit;

class ISPAG_Achat_Details_Renderer {

    public static function init() {
        add_action('ispag_achat_details_tab', [self::class, 'display_achat_details_tab'], 10, 1);
        add_action('wp_ajax_ispag_copy_project_address', [self::class, 'copy_adress_from_project']);
        add_action('wp_ajax_ispag_set_carrybox_address', [self::class, 'ispag_set_carrybox_address']);
        add_action('wp_ajax_ispag_achat_save_delivery', [self::class, 'ajax_save_delivery']);
        add_action('wp_ajax_ispag_set_stock_location_address', [self::class, 'ajax_set_stock_location_address']);
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
        self::render_bloc_livraison($infos, (int) $achat->Id, (int) $achat->hubspot_deal_id);
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

    /** Champs de l'adresse de livraison : colonne => libellé. */
    private static function delivery_fields(): array {
        return [
            'AdresseDeLivraison' => __('Adress', 'creation-reservoir'),
            'DeliveryAdresse2'   => __('Complement', 'creation-reservoir'),
            'DeliveryAdresse3'   => __('Project Info', 'creation-reservoir'),
            'NIP'                => __('Postal code', 'creation-reservoir'),
            'City'               => __('City', 'creation-reservoir'),
            'PersonneContact'    => __('Contact', 'creation-reservoir'),
            'num_tel_contact'    => __('Phone number', 'creation-reservoir'),
        ];
    }

    /**
     * Bloc « Delivery » : adresse en lecture, avec un seul formulaire d'édition (bouton Edit) qui enregistre tous les champs d'un coup.
     */
    private static function render_bloc_livraison($infos, $achat_id, $deal_id = 0) {
        $achat_id = (int) $achat_id;
        $can_edit = current_user_can('edit_supplier_order');
        $fields   = self::delivery_fields();
        $val = function ($k) use ($infos) { return trim(stripslashes((string) ($infos->$k ?? ''))); };

        // Lignes affichées / copiées : adresse (3 lignes), « NPA Ville », puis contact
        $address_lines = array_values(array_filter([$val('AdresseDeLivraison'), $val('DeliveryAdresse2'), $val('DeliveryAdresse3')]));
        $zip_city = trim($val('NIP') . ' ' . $val('City'));
        if ($zip_city !== '') $address_lines[] = $zip_city;
        $contact_line = implode(' : ', array_filter([$val('PersonneContact'), $val('num_tel_contact')]));
        $copy_text = implode("\n", array_filter([implode("\n", $address_lines), $contact_line]));

        echo '<div class="ispag-box" id="ispag-delivery-box" data-achat="' . esc_attr($achat_id) . '" data-nonce="' . esc_attr(wp_create_nonce('ispag_achat_delivery')) . '">';
        echo '<div class="ispag-delivery-head"><h3>' . esc_html__('Delivery', 'creation-reservoir') . '</h3>';
        if ($can_edit) {
            echo '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-delivery-edit-btn">✏️ ' . esc_html__('Edit', 'creation-reservoir') . '</button>';
        }
        echo '</div>';

        // --- Lecture ---
        echo '<div class="ispag-delivery-view" id="delivery-info-text">';
        if ($address_lines || $contact_line !== '') {
            echo '<address class="ispag-delivery-address">';
            foreach ($address_lines as $i => $line) {
                echo ($i === 0 ? '<strong>' . esc_html($line) . '</strong>' : esc_html($line)) . '<br>';
            }
            echo '</address>';
            if ($contact_line !== '') {
                echo '<p class="ispag-delivery-contact">👤 ' . esc_html($contact_line) . '</p>';
            }
        } else {
            echo '<p class="ispag-delivery-empty">' . esc_html__('No delivery address yet.', 'creation-reservoir') . '</p>';
        }
        echo '</div>';

        // --- Édition : un formulaire pour tous les champs ---
        if ($can_edit) {
            echo '<form class="ispag-delivery-form" hidden>';
            foreach ($fields as $name => $label) {
                $wide = in_array($name, ['AdresseDeLivraison', 'DeliveryAdresse2', 'DeliveryAdresse3', 'PersonneContact'], true);
                $type = $name === 'num_tel_contact' ? 'tel' : 'text';
                echo '<label class="ispag-delivery-field' . ($wide ? ' is-wide' : '') . '"><span>' . esc_html($label) . '</span>'
                    . '<input type="' . $type . '" name="' . esc_attr($name) . '" value="' . esc_attr($val($name)) . '"'
                    . ($name === 'NIP' ? ' inputmode="numeric" autocomplete="postal-code"' : '') . '></label>';
            }
            echo '<div class="ispag-delivery-form-actions">'
                . '<button type="submit" class="ispag-btn ispag-btn-green">' . esc_html__('Save', 'creation-reservoir') . '</button>'
                . '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-delivery-cancel-btn">' . esc_html__('Cancel', 'creation-reservoir') . '</button>'
                . '<span class="ispag-delivery-status" aria-live="polite"></span>'
                . '</div>';
            echo '</form>';
        }

        echo '<pre id="delivery-info-copy" style="display:none;">' . esc_html($copy_text) . '</pre>';

        echo '<div class="ispag-delivery-actions">';
        echo '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-btn-copy-description" data-target="#delivery-info-copy">📋</button>';
        if ($can_edit) {
            echo '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-btn-copy-from-project" data-achat="' . esc_attr($achat_id) . '" data-deal-id="' . esc_attr($deal_id) . '">📥 ' . __('Copy from project', 'creation-reservoir') . '</button>';
            echo '<button type="button" class="ispag-btn ispag-btn-blue-outlined ispag-btn-set-carrybox" data-achat="' . esc_attr($achat_id) . '" data-deal-id="' . esc_attr($deal_id) . '">📦 Carry Box delivery</button>';
            // Dépôts de stock (plugin ISPAG Stock) : l'adresse de livraison devient celle du dépôt choisi
            $stock_locations = (array) apply_filters('ispag_stock_delivery_locations', []);
            if ($stock_locations) {
                echo '<span class="ispag-stock-location-picker"><select class="ispag-stock-location-select" aria-label="' . esc_attr__('Stock location', 'creation-reservoir') . '">';
                echo '<option value="">' . esc_html__('Deliver to stock location…', 'creation-reservoir') . '</option>';
                foreach ($stock_locations as $loc) {
                    echo '<option value="' . (int) $loc['id'] . '">' . esc_html($loc['name']) . '</option>';
                }
                echo '</select> <button type="button" class="ispag-btn ispag-btn-blue-outlined ispag-btn-set-stock-location" data-achat="' . esc_attr($achat_id) . '" data-deal-id="' . esc_attr($deal_id) . '">🏭 ' . esc_html__('Use', 'creation-reservoir') . '</button></span>';
            }
        }
        echo '</div>';
        echo '</div>';
    }

    /** Upsert de l'adresse de livraison (clé : purchase_order). */
    private static function save_delivery_row(int $achat_id, array $data, int $deal_id = 0) {
        global $wpdb;
        $table  = $wpdb->prefix . 'achats_info_commande';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT Id FROM $table WHERE purchase_order = %d LIMIT 1", $achat_id));
        if ($exists) {
            return $wpdb->update($table, $data, ['Id' => (int) $exists]) !== false;
        }
        // Colonnes NOT NULL sans valeur par défaut : on les renseigne à l'insertion
        $defaults = ['AdresseDeLivraison' => '', 'DeliveryAdresse2' => '', 'DeliveryAdresse3' => '', 'NIP' => '', 'City' => '',
                     'Comment' => '', 'PersonneContact' => '', 'num_tel_contact' => '', 'unloadingFacilities' => 0];
        return $wpdb->insert($table, array_merge($defaults, $data, ['purchase_order' => $achat_id, 'hubspot_deal_id' => $deal_id])) !== false;
    }

    /** Enregistre tous les champs du formulaire d'adresse d'un coup et renvoie le bloc rafraîchi. */
    public static function ajax_save_delivery() {
        check_ajax_referer('ispag_achat_delivery', 'nonce');
        if (!current_user_can('edit_supplier_order')) {
            wp_send_json_error(__('Not authorized', 'creation-reservoir'), 403);
        }
        $achat_id = absint($_POST['achat_id'] ?? 0);
        if (!$achat_id) {
            wp_send_json_error('ID Achat manquant');
        }
        $achat = apply_filters('ispag_get_achat_by_id', null, $achat_id);
        $deal_id = $achat ? (int) $achat->hubspot_deal_id : 0;

        $data = [];
        foreach (array_keys(self::delivery_fields()) as $name) {
            $data[$name] = sanitize_text_field(wp_unslash($_POST[$name] ?? ''));
        }
        if (!self::save_delivery_row($achat_id, $data, $deal_id)) {
            wp_send_json_error(__('Error while saving', 'creation-reservoir'));
        }

        $infos = (new ISPAG_Achat_Details_Repository())->get_infos_livraison($achat_id);
        ob_start();
        self::render_bloc_livraison($infos, $achat_id, $deal_id);
        wp_send_json_success(['html' => ob_get_clean()]);
    }

    public static function ispag_set_carrybox_address() {
        if (!current_user_can('edit_supplier_order')) wp_send_json_error('Not authorized', 403);
        $achat_id = intval($_POST['achat_id'] ?? 0);
        $deal_id = intval($_POST['deal_id'] ?? 0);
        
        if (!$achat_id) wp_send_json_error('ID Achat manquant');

        // 1. Récupération des infos du projet pour le nom
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $objet_commande = ($project && !empty($project->ObjetCommande)) 
            ? stripslashes($project->ObjetCommande) 
            : 'Projet #' . $deal_id;

        // 2. Préparation des données d'adresse
        $data_delivery = array(
            'AdresseDeLivraison' => 'Carry Box',
            'DeliveryAdresse2'   => '58 rte du Nant d’Avril',
            'DeliveryAdresse3'   => 'ISPAG - ' . $objet_commande,
            'NIP'                => '1214',
            'City'               => 'Vernier-Genève'
        );
        self::save_delivery_row($achat_id, $data_delivery, $deal_id);

        $manager = new ISPAG_CarryBox_Manager($deal_id);
        $manager->generate_carrybox_process();

        // 3. Régénération du bloc pour le front-end
        $infos = (new ISPAG_Achat_Details_Repository())->get_infos_livraison($achat_id);
        ob_start();
        self::render_bloc_livraison($infos, $achat_id, $deal_id);
        wp_send_json_success(['html' => ob_get_clean()]);
    }


    /** Adresse de livraison = celle d'un emplacement de stock (le nom du dépôt est la première ligne : le stock reconnaît ainsi le lieu). */
    public static function ajax_set_stock_location_address() {
        if (!current_user_can('edit_supplier_order')) wp_send_json_error('Not authorized', 403);
        $achat_id = intval($_POST['achat_id'] ?? 0);
        $deal_id  = intval($_POST['deal_id'] ?? 0);
        $loc_id   = intval($_POST['location_id'] ?? 0);
        if (!$achat_id || !$loc_id) wp_send_json_error('Missing data');

        $found = null;
        foreach ((array) apply_filters('ispag_stock_delivery_locations', []) as $loc) {
            if ((int) $loc['id'] === $loc_id) { $found = $loc; break; }
        }
        if (!$found) wp_send_json_error('Unknown stock location');

        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $objet   = ($project && !empty($project->ObjetCommande)) ? stripslashes($project->ObjetCommande) : 'Projet #' . $deal_id;
        self::save_delivery_row($achat_id, [
            'AdresseDeLivraison' => $found['name'],
            'DeliveryAdresse2'   => $found['address'],
            'DeliveryAdresse3'   => 'ISPAG - ' . $objet,
            'NIP'                => $found['zip'],
            'City'               => $found['city'],
        ], $deal_id);

        $infos = (new ISPAG_Achat_Details_Repository())->get_infos_livraison($achat_id);
        ob_start();
        self::render_bloc_livraison($infos, $achat_id, $deal_id);
        wp_send_json_success(['html' => ob_get_clean()]);
    }

    public static function copy_adress_from_project(){
        if (!current_user_can('edit_supplier_order')) wp_send_json_error('Not authorized', 403);
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

        $data = [
            'AdresseDeLivraison' => $project_infos->AdresseDeLivraison,
            'DeliveryAdresse2'   => $project_infos->DeliveryAdresse2,
            'DeliveryAdresse3'   => $project_infos->DeliveryAdresse3,
            'NIP'                => $project_infos->NIP,
            'City'               => $project_infos->City,
            'PersonneContact'    => $project_infos->PersonneContact,
            'num_tel_contact'    => $project_infos->num_tel_contact,
        ];
        self::save_delivery_row($achat_id, $data, $deal_id);

        $infos = (new ISPAG_Achat_Details_Repository())->get_infos_livraison($achat_id);
        ob_start();
        self::render_bloc_livraison($infos, $achat_id, $deal_id);
        $html = ob_get_clean();

        wp_send_json_success([
            'purchase_order' => $achat_id,
            'html' => $html,
        ]);
    }

}
