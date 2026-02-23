<?php 

class ISPAG_Achat_Details_Renderer {

    public static function init() {
        add_action('ispag_achat_details_tab', [self::class, 'display_achat_details_tab'], 10, 1);
        add_action('wp_ajax_ispag_copy_project_address', [self::class, 'copy_adress_from_project']);
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

        $champs = [
            'AdresseDeLivraison' => __('Adress', 'creation-reservoir'),
            'DeliveryAdresse2'   => __('Complement', 'creation-reservoir'),
            'Postal code'                => __('Postal code', 'creation-reservoir'),
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

            if (current_user_can('edit_supplier_order') ) {
                echo '<span class="ispag-inline-edit" 
                            data-name="' . esc_attr($champ) . '" 
                            data-value="' . esc_attr($val) . '" 
                            data-deal="' . esc_attr($infos->purchase_order) . '"
                            data-source="delivery">';
                echo esc_html($val) . ' <span class="edit-icon">✏️</span>';
                echo '</span>';
            } else {
                echo esc_html($val);
            }

            echo '</p>';

            // Texte brut à copier
            if (in_array($champ, ['AdresseDeLivraison', 'DeliveryAdresse2', 'Postal code', 'City'])) {
                $copie_ligne1[] = $val;
            } elseif ($champ === 'PersonneContact') {
                $copie_ligne2 = $val;
            } elseif ($champ === 'num_tel_contact') {
                $copie_ligne2 .= ': ' . $val;
            }
        }
        echo '</div>';

        // Zone invisible avec texte à copier
        echo '<pre id="delivery-info-copy" style="display:none;">' .
            esc_html(implode("\n", [
                implode("\n", array_filter($copie_ligne1)),
                $copie_ligne2
            ])) .
        '</pre>';


        // Bouton de copie
        echo '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-btn-copy-description" data-target="#delivery-info-copy">📋</button>';

        echo '<button 
            type="button" 
            class="ispag-btn ispag-btn-grey-outlined ispag-btn-copy-from-project" 
            data-achat="' . esc_attr($achat->Id) . '" 
            data-deal-id="' . esc_attr($achat->hubspot_deal_id) . '">
            📥 ' . __('Copy from project', 'creation-reservoir') . '
        </button>';


        echo '</div>';
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
            'Postal code'                => $project_infos->NIP,
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
