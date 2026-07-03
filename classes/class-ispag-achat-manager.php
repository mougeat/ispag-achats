<?php

defined('ABSPATH') or die();

class ISPAG_Achat_Manager {
 
    private $wpdb;
    private $table_achats;
    private $table_articles;
    private $table_fournisseurs;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_achats = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        $this->table_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_fournisseurs = $wpdb->prefix . 'achats_fournisseurs';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        // add_shortcode('ispag_achats', [self::class, 'shortcode_achats']);
        add_shortcode('ispag_achats', [self::class, 'ispag_achats_shortcode']);
        add_shortcode('ispag_achat_detail', [self::class, 'ispag_achat_detail_shortcode']);


        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_filter('ispag_inline_edit_purchase', [self::class, 'handle_inline_edit'], 10, 2);

        // add_action('ispag_article_saved_from_purchase', [self::class, 'handle_saved_article'], 10, 2);
        add_filter('ispag_article_saved_from_purchase', [self::class, 'handle_saved_article'], 10, 3);

        add_action('ispag_achat_set_article_as_delivered', [self::$instance, 'set_article_as_delivered'], 10, 3);
        
        add_filter('ispag_bulk_selected_article', [self::class, 'bulk_selected_article'], 10, 2);
        add_action('wp_ajax_ispag_bulk_achat_update_articles', [self::class, 'bulk_achat_update_articles']);

        add_action('wp_ajax_ispag_delete_achat', [self::class, 'delete_achat']);

        add_action('wp_ajax_ispag_save_confirmed_data', [self::class, 'ispag_save_confirmed_data_handler']);
    }

    public static function enqueue_assets() {
        global $wpdb;
        // wp_enqueue_style('ispag-style', plugin_dir_url(__FILE__) . '../assets/css/style.css');
        // wp_enqueue_style('ispag-style', plugin_dir_url(__FILE__) . '../assets/css/main.css');
        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_style('ispag-main-style');
        });

        wp_enqueue_script('ispag-scroll-achats', plugin_dir_url(__FILE__) . '../assets/js/infinite-scroll-achat.js', ['jquery'], false, true);
        wp_enqueue_script('ispag-state-achats', plugin_dir_url(__FILE__) . '../assets/js/state.js', ['jquery'], false, true);
        wp_enqueue_script('ispag-details-achats', plugin_dir_url(__FILE__) . '../assets/js/details-achat.js', ['jquery'], false, true);
        wp_enqueue_script('ispag-header-achats', plugin_dir_url(__FILE__) . '../assets/js/header.js', ['jquery'], false, true);

        wp_localize_script('ispag-scroll-achats', 'ajaxurl', admin_url('admin-ajax.php'));

        wp_localize_script('ispag-scroll-achats', 'ispagVars', [
            'ajaxurl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('ispag_achat_nonce'),
            'loading_text'      => __('Loading', 'creation-reservoir'),
            'all_loaded_text'   => __('All projects are loaded', 'creation-reservoir'),
            'security'          => wp_create_nonce('ispag_achat_nonce'), // Ajout de la sécurité pour les filtres
        ]);

        // Récupérer les fournisseurs pour l’inline-edit
        $fournisseurs = $wpdb->get_results(
            "SELECT Id, Fournisseur FROM {$wpdb->prefix}achats_fournisseurs WHERE isSupplier = 1 ORDER BY Fournisseur ASC"
        );

        $formatted_fournisseurs = array_map(function($f) {
            return ['Id' => $f->Id, 'Fournisseur' => $f->Fournisseur];
        }, $fournisseurs);

        wp_localize_script(
            'ispag-header-achats',
            'ispag_fournisseurs',
            [
                'ajaxurl'       => admin_url('admin-ajax.php'),
                'nonce'         => wp_create_nonce('ispag_achat_nonce'),
                'security'      => wp_create_nonce('ispag_fournisseurs'),
                'fournisseurs'  => $formatted_fournisseurs // On met les fournisseurs ici aussi
            ]
        );
    }

    public static function ispag_achats_shortcode($atts) {
        if (!current_user_can('view_supplier_order')) {
            ob_start();
            echo '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i>
                        <strong>' . esc_html__('Restricted access', 'ispag-crm') . ' :</strong> ' .
                        esc_html__('You do not have the necessary rights to view this order.', 'ispag-crm') . '<br/>
                        <a href="' . home_url('/wp-login.php') . '">' . esc_html__('To login page', 'ispag-crm') . '</a>
                    </div>';
            return ob_get_clean();
        }

        // Récupère les filtres depuis $_GET si nécessaire
        $filters = [
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '',
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all',
            'fournisseur' => isset($_GET['fournisseur']) ? sanitize_text_field($_GET['fournisseur']) : 'all',
            'responsable' => isset($_GET['responsable']) ? sanitize_text_field($_GET['responsable']) : 'all',
        ];

        // Inclut le template des filtres
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/achats-filters.php';

        // Structure du tableau
        echo '<div class="ispag-table-wrapper">';
        echo '<table class="ispag-project-table">';
        echo '<thead><tr>
                <th>#</th>
                <th>' . __('Reference', 'creation-reservoir') . '</th>
                <th>' . __('Order date', 'creation-reservoir') . '</th>
                <th>' . __('Delivery date', 'creation-reservoir') . '</th>
                <th>' . __('Supplier', 'creation-reservoir') . '</th>
                <th>' . __('Order amount', 'creation-reservoir') . '</th>
                <th>' . __('Confirmation de commande', 'creation-reservoir') . '</th>
                <th>' . __('State', 'creation-reservoir') . '</th>
            </tr></thead>';
        echo '<tbody id="ispag-achats-list"></tbody>'; // ✅ ICI les résultats seront insérés
        echo '</table></div>';
        echo '<div id="ispag-achats-loading" style="display: none; text-align: center; padding: 10px;">Chargement...</div>';

        return ob_get_clean();
    }

    public static function shortcode_achats($atts) {

        if ( ! current_user_can( 'view_supplier_order' ) ) {
            return '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i> 
                        <strong>' . esc_html__( 'Restricted access', 'ispag-crm' ) . ' :</strong> ' . 
                         esc_html__( 'You do not have the necessary rights to view this order.', 'ispag-crm' ) . '<br/>
                        <a href ="'. home_url( '/wp-login.php' ) . '">' . esc_html__( 'To login page', 'ispag-crm' ) . '</a>
                    </div>';
        }
        
        $can_view_supplier_orders = current_user_can('view_supplier_order');

        $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        

        $html = '
        <div class="ispag-toolbar" style="background: #f6f7f7; padding: 15px; border: 1px solid #ccd0d4; margin-bottom: 20px; margin-top: 20px;">
            <form method="get">
                <input type="text" name="search" placeholder="' . __('Search', 'creation-reservoir') . ' ..." value="' . esc_attr($search_query) . '" />
                <button type="submit" class="ispag-btn">' . __( 'Filter / Search', 'creation-reservoir' ) . '</button>
                ';
                if ( ! empty( $search_query ) OR isset($_GET['select_state'])) :
                    $html .= '<a href="' . esc_url( remove_query_arg( array( 'orderby', 'order', 'search', 'filter_owner', 'paged', 'select_state' ) ) ) . '" class="ispag-btn ispag-btn-grey">' . __( 'Reset filters', 'creation-reservoir' ) . '</a>';
                endif;
        $html .= '        
            </form>
        </div>';

        if($can_view_supplier_orders){
            $status_checker = new ISPAG_Achat_status_render();
            $html .= $status_checker->render_state_buttons();
        }

        $html .= '<div class="ispag-table-wrapper">';
        $html .= '<table class="ispag-project-table">';
        $html .= '<thead><tr>
            <th>#</th>
            <th>' . __('Reference', 'creation-reservoir') . '</th>  
            
            <th>' . __('Order date', 'creation-reservoir') . '</th>
            <th>' . __('Delivery date', 'creation-reservoir') . '</th>
            <th>' . __('Supplier', 'creation-reservoir') . '</th>
            <th>' . __('Order amount', 'creation-reservoir') . '</th>
            <th>' . __('Confirmation de commande', 'creation-reservoir') . '</th>
            <th>' . __('State', 'creation-reservoir') . '</th>
        </tr></thead>';
        $html .= '<tbody id="achats-list"></tbody>';
        $html .= '</table></div>';
        $html .= '<div id="scroll-loader" style="height: 40px;"></div>';

        return $html;
    }

    /**
     * Récupère les états des commandes depuis la table `wor9711_achats_etat_commandes_fournisseur`
     * et les adapte au multilingue.
     */
    public static function get_achat_etats() {
        global $wpdb;
        $table_etats = $wpdb->prefix . 'achats_etat_commandes_fournisseur';

        // Récupère tous les états
        $etats = $wpdb->get_results("SELECT Id, Etat, ClassCss, color FROM {$table_etats} ORDER BY ordre ASC");

        // Traduit les états en français (ou autre langue)
        $translated_etats = [];
        foreach ($etats as $etat) {
            $translated_etat = [
                'Id' => $etat->Id,
                'Etat' => $etat->Etat,
                'ClassCss' => $etat->ClassCss,
                'color' => $etat->color,
                'translated' => __($etat->Etat, 'creation-reservoir') // Traduit l'état
            ];
            $translated_etats[] = $translated_etat;
        }

        return $translated_etats;
    }

    public static function render_achat_row($achat, $index = 0) {
        global $wpdb;

        $date_creation = date('d.m.Y', $achat->TimestampDateCreation);
        $date_reception = $achat->TimestampDateReceptionConfirmee ? date('d.m.Y', $achat->TimestampDateReceptionConfirmee) : '-';

        // Récupérer le nom du fournisseur
        $fournisseur_nom = isset($achat->fournisseur_nom) ? $achat->fournisseur_nom : '';
        if (empty($fournisseur_nom) && isset($achat->IdFournisseur)) {
            $fournisseur_nom = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT Fournisseur FROM {$wpdb->prefix}achats_fournisseurs WHERE Id = %d",
                    $achat->IdFournisseur
                )
            );
        }

        // Récupérer le nom du responsable
        $responsable_nom = isset($achat->responsable_nom) ? $achat->responsable_nom : '';
        if (empty($responsable_nom) && isset($achat->created_by)) {
            $user_info = get_userdata($achat->created_by);
            $responsable_nom = $user_info ? $user_info->display_name : __('Unknown User', 'ispag-crm');
        }

        // Récupérer l'état traduit depuis la table `wor9711_achats_etat_commandes_fournisseur`
        $etat_id = isset($achat->EtatCommande) ? $achat->EtatCommande : 0;
        $etat_info = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT Etat, ClassCss, color FROM {$wpdb->prefix}achats_etat_commandes_fournisseur WHERE Id = %d",
                $etat_id
            )
        );

        $etat_text = $etat_info ? __($etat_info->Etat, 'creation-reservoir') : __('Unknown', 'creation-reservoir');
        $bgcolor = $etat_info ? $etat_info->color : '#ccc';
        $class_css = $etat_info ? $etat_info->ClassCss : '';

        return '
            <tr>
                <td style="background-color:#D1E7DD;">' . ($index + 1) . '</td>
                <td><a href="' . esc_url(home_url('/details-achats/?poid=' . $achat->Id)) . '" target="_blank" class="ispag_achat_link"><strong>' . esc_html(stripslashes($achat->RefCommande)) . '</strong></a></td>
                <td>' . esc_html($date_creation) . '</td>
                <td>' . esc_html($date_reception) . '</td>
                <td><strong>' . esc_html($fournisseur_nom) . '</strong><br><small class="creator-name">' . __('by', 'creation-reservoir') . ' : ' . esc_html($responsable_nom) . '</small></td>
                <td>' . number_format_i18n($achat->prix_net_total, 2) . '</td> 
                <td>' . esc_html($achat->ConfCmdFournisseur) . '</td>
                <td><span class="ispag-state-badge ' . esc_attr($class_css) . '" style="background-color:' . esc_attr($bgcolor) . '; opacity: 0.8;">' . esc_html($etat_text) . '</span></td>
            </tr>
        ';
    }

    public function get_supplier_command_id_by_article($article_id) {
        $article_id = intval($article_id); // sécurité
        $query = $this->wpdb->prepare(
            "SELECT IdCommande 
            FROM {$this->table_articles} 
            WHERE IdCommandeClient = %d 
            LIMIT 1",
            $article_id
        );

        return $this->wpdb->get_var($query);
    }

    public static function ispag_achat_detail_shortcode($atts) {

        ob_start();
        global $wpdb;
        
        if ( ! current_user_can( 'view_supplier_order' ) ) {
            return '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i> 
                        <strong>' . esc_html__( 'Restricted access', 'ispag-crm' ) . ' :</strong> ' . 
                         esc_html__( 'You do not have the necessary rights to view this order.', 'ispag-crm' ) . '<br/>
                        <a href ="'. home_url( '/wp-login.php' ) . '">' . esc_html__( 'To login page', 'ispag-crm' ) . '</a>
                    </div>';
        }
        
        // On récupère la variable via le moteur WP (prioritaire pour les jolies URLs)
        $achat_id = get_query_var('poid');
        // Si c'est vide (cas où on arrive via une URL classique ?poid=123), on check le $_GET
        if ( empty( $achat_id ) && isset( $_GET['poid'] ) ) {
            $achat_id = sanitize_text_field( $_GET['poid'] );
        }

        // Sécurité supplémentaire : s'assurer que c'est un nombre (puisque c'est un ID)
        $achat_id = absint( $achat_id );

        if ( ! $achat_id ) {
            // Gérer l'erreur ou rediriger si l'ID est manquant
            echo '<div class="ispag-error-message">ID d\'achat manquant.</div>' ;
            return;
        }
        
        if (!$achat_id) return 'Achat introuvable.';

        do_action('ispag_check_auto_status_for_achat', $achat_id);

        $repo = new ISPAG_Achat_Repository();
        $achat = $repo->get_achat_by_id(null, $achat_id);
 
        if (!$achat) return 'Achat introuvable.';

        // $achat = $achat[0];

        $fournisseurs = $wpdb->get_results("SELECT Id, Fournisseur FROM {$wpdb->prefix}achats_fournisseurs WHERE isSupplier = 1 ORDER BY Fournisseur ASC");

        include plugin_dir_path(__FILE__) . 'templates/achat-detail.php';

        // echo ISPAG_Detail_Page::display_modal();
        
        return ob_get_clean();
    }
  
    public static function bulk_selected_article($html, $achat_id){
        $can_manage_order = current_user_can('manage_order'); 
        if(!$can_manage_order){
            return false;
        }
        return '<div class="ispag-bulk-actions" style="border: 1px solid #ccc; padding: 1rem; margin: 1rem 0; display:none;">
            <h4>'. __('Bulk update selected articles', 'creation-reservoir') . '</h4>
            <input type="hidden" id="achat-id" value="'.$achat_id.'">

            <label>' . __('Factory departure date', 'creation-reservoir') . ' :
                <input type="date" id="bulk-date-depart">
            </label>

            <label>
                📦 ' . __('Delivered on', 'creation-reservoir') .' :
                <input type="date" id="bulk-livre-date">
            </label>

            <label>
                🧾 ' . __('Invoiced on', 'creation-reservoir') .' :
                <input type="date" id="bulk-invoiced-date">
            </label>


            <button id="apply-bulk-update" class="ispag-btn ispag-btn-green">' . __('Apply changes', 'creation-reservoir') . '</button>
        </div>
        <script>
            document.addEventListener(\'DOMContentLoaded\', function () {
                const cb = document.getElementById(\'bulk-demande-ok\');
                const db = document.getElementById(\'bulk-drawing-ok\');
                if(cb) {
                    cb.indeterminate = true; // état par défaut indéterminé
                    db.indeterminate = true; // état par défaut indéterminé
                }
            });
            document.querySelectorAll(\'.ispag-article-checkbox\').forEach(cb => {
                cb.addEventListener(\'change\', () => {
                    const bulkDiv = document.querySelector(\'.ispag-bulk-actions\');
                    const anyChecked = [...document.querySelectorAll(\'.ispag-article-checkbox\')].some(cb => cb.checked);
                    if (anyChecked) {
                        bulkDiv.style.display = \'block\';
                    } else {
                        bulkDiv.style.display = \'none\';
                    }
                });
            });

            document.getElementById(\'apply-bulk-update\').addEventListener(\'click\', function () {
                const selectedIds = [...document.querySelectorAll(\'.ispag-article-checkbox:checked\')].map(cb => cb.dataset.articleId);

                if (selectedIds.length === 0) {
                    alert("' .  __('No article selected', 'creation-reservoir') . '");
                    return;
                }

                const data = {
                    action: \'ispag_bulk_achat_update_articles\',
                    articles: selectedIds,
                    achat_id: document.getElementById(\'achat-id\').value,
                    date_depart: document.getElementById(\'bulk-date-depart\').value,
                    livre_date: document.getElementById(\'bulk-livre-date\').value,
                    invoiced_date: document.getElementById(\'bulk-invoiced-date\').value,
                    _ajax_nonce: \'' .  wp_create_nonce('ispag_bulk_update') . '\'
                };

                fetch(\'' .  admin_url('admin-ajax.php') . '\', {
                    method: \'POST\',
                    headers: { \'Content-Type\': \'application/x-www-form-urlencoded\' },
                    body: new URLSearchParams(data)
                })
                .then(res => res.json())
                .then(response => {
//                    console.log(\'response:\', response);
                    const msgBox = document.getElementById(\'ispag-bulk-message\');

                    if (response.success) {
                        msgBox.textContent = response.data.message;
                        msgBox.style.display = \'block\';
                        msgBox.style.backgroundColor = \'#d4edda\';
                        msgBox.style.color = \'#155724\';
                        msgBox.style.border = \'1px solid #c3e6cb\';

                        // Disparait au bout de 3 secondes
                        setTimeout(() => {
                            msgBox.style.display = \'none\';
                            location.reload();
                        }, 3000);
                    } else {
                        msgBox.textContent = response.data?.message || \'Erreur inconnue\';
                        msgBox.style.display = \'block\';
                        msgBox.style.backgroundColor = \'#f8d7da\';
                        msgBox.style.color = \'#721c24\';
                        msgBox.style.border = \'1px solid #f5c6cb\';
                    }
                    // location.reload(); // ou refresh partiel
                });
            });
        </script>

        ';

        
    }

    public static function bulk_achat_update_articles () {
        check_ajax_referer('ispag_bulk_update');

        $article_ids = $_POST['articles'] ?? [];
        $achat_id = $_POST['achat_id'] ?? [];
        if (!current_user_can('manage_order') || empty($article_ids)) {
            wp_send_json_error(['message' => __('Unauthorized or empty selection', 'creation-reservoir')]);
        }

        global $wpdb;
        $updates = [];
        $ids_raw = $_POST['articles'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

        $in_clause = implode(',', $ids);
        if ($_POST['date_depart']) {
            $updates[] = "TimestampDateLivraisonConfirme = '" . intval(strtotime($_POST['date_depart'])) . "'";
        }
        
        if (!empty($_POST['livre_date'])) {
            $timestamp = strtotime($_POST['livre_date']);
            if ($timestamp) {
                $updates[] = "Recu = 1";
                
            }
        }

        if (!empty($_POST['invoiced_date'])) {
            $timestamp = strtotime($_POST['invoiced_date']);
            if ($timestamp) {
                $updates[] = "Facture = 1";
            }
        }


        if (!empty($updates)) {
            $query = "UPDATE {$wpdb->prefix}achats_articles_cmd_fournisseurs SET " . implode(', ', $updates) . " WHERE id IN ($in_clause)";
            $wpdb->query($query);
        }
        // do_action('isag_run_auto_update', $achat_id);
        wp_send_json_success(['message' => __('Bulk update applied successfully', 'creation-reservoir')]);
    }

    public static function handle_inline_edit($updated, $args) {
        global $wpdb;

        $allowed_fields = ['Fournisseur', 'RefCommande', 'ConfCmdFournisseur', 'TimestampDateCreation'];
        $table = $wpdb->prefix . 'achats_commande_liste_fournisseurs';

        // error_log("🔧 handle_inline_edit() called with args: " . print_r($args, true));

        if (!in_array($args['field'], $allowed_fields)) {
// error_log("❌ Champ non autorisé : {$args['field']}");
            return false;
        }

        if ($args['field'] == 'Fournisseur') {
            $supplier_id = $wpdb->get_var($wpdb->prepare(
                "SELECT Id FROM {$wpdb->prefix}achats_fournisseurs WHERE Fournisseur = %s",
                $args['value']
            ));
            
            // error_log("🔍 Résultat ID fournisseur pour '{$args['value']}': " . var_export($supplier_id, true));

            if (!$supplier_id) {
// error_log("❌ Fournisseur non trouvé");
                return false;
            }

            // On remplace la valeur par l'ID trouvé
            $args['value'] = $supplier_id;
            $args['field'] = 'IdFournisseur';
        }

        $res = $wpdb->update(
            $table,
            [ $args['field'] => $args['value'] ],
            [ 'Id' => $args['deal_id'] ]
        );

        if ($res === false) {
// error_log("❌ Erreur lors de la mise à jour : " . $wpdb->last_error);
        } else {
            // error_log("✅ Mise à jour réussie (lignes modifiées : $res)");
        }

        return $res !== false;
    } 

    /**
     * Sauvegarde les données confirmées après analyse IA (Offre fournisseur)
     */
    public static function ispag_save_confirmed_data_handler() {
        // 1. Vérification de sécurité et récupération des IDs de base
        $deal_id     = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
        $purchase_id = isset($_POST['purchase_id']) ? intval($_POST['purchase_id']) : 0;
        
        // L'article_id peut arriver soit en direct, soit dans le tableau 'data' (clé 'Id')
        $post_datas  = isset($_POST['data']) ? (array) wp_unslash($_POST['data']) : [];
        $article_id  = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;

        if (!$article_id && isset($post_datas['Id'])) {
            $article_id = intval($post_datas['Id']);
        }

        // 2. Validation minimale
        if (empty($article_id) || empty($purchase_id)) {
            wp_send_json_error('Données obligatoires manquantes (ID article ou ID achat).');
        }

        if (empty($post_datas)) {
            wp_send_json_error('Aucune donnée à enregistrer.');
        }

        // 3. Sanitization rigoureuse des données extraites par l'IA
        $sanitized_tank_data = [];
        foreach ($post_datas as $key => $value) {
            $sanitized_key = sanitize_key($key);
            
            // Gestion spécifique selon le type de donnée
            if (is_numeric($value)) {
                // On garde le format float pour les dimensions/prix
                $sanitized_tank_data[$sanitized_key] = floatval($value);
            } elseif (is_string($value)) {
                $sanitized_tank_data[$sanitized_key] = sanitize_text_field($value);
            } else {
                $sanitized_tank_data[$sanitized_key] = $value;
            }
        }

        // 4. Préparation du payload pour les filtres ISPAG
        $datas = [
            'deal_id'    => $deal_id,
            'achat_id'   => $purchase_id,
            'article_id' => $article_id,
            'tank'       => $sanitized_tank_data
        ];

        // --- A. Sauvegarde des caractéristiques techniques (Cuve/Conception) ---
        // Ce filtre met à jour la configuration technique de la cuve dans le projet
        $res_tank = apply_filters('ispag_auto_saver_tank_data', null, $datas, true);

        // --- B. Sauvegarde des données commerciales (Prix/Achat) ---
        // On cherche l'ID de la ligne spécifique dans la table des achats
        $article_achat = apply_filters('ispag_get_achat_article_by_project_article_id', null, $article_id);

        $res_purchase = false;
        if ($article_achat && isset($article_achat->Id)) {
            // Ce filtre met à jour le prix d'achat, la remise, etc., trouvés par l'IA
            $res_purchase = apply_filters('ispag_article_saved_from_purchase', null, $article_achat->Id, $sanitized_tank_data);
        }

        // 5. Réponse finale
        if ($res_tank && $res_tank['success']) {
            wp_send_json_success([
                'message'         => 'Données mises à jour avec succès.',
                'purchase_update' => $res_purchase
            ]);
        } else {
            $error_msg = isset($res_tank['message']) ? $res_tank['message'] : 'Erreur lors de la mise à jour technique.';
            wp_send_json_error($error_msg);
        }
    }


    public static function handle_saved_article($html, $article_id, $post_data) {
        global $wpdb;

        $table_purchase = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $table_project  = 'wor9711_achats_details_commande';

        $data = [
            'RefSurMesure'                   => sanitize_text_field($post_data['article_title'] ?? ''),
            'DescSurMesure'                  => wp_kses_post($post_data['description'] ?? ''),
            'UnitPrice'                      => floatval($post_data['sales_price'] ?? 0),
            'discount'                       => floatval($post_data['discount'] ?? 0),
            'Qty'                            => intval($post_data['qty'] ?? 1),
            'IdArticleStandard'              => intval($post_data['IdArticleStandard'] ?? 0),
            'TimestampDateLivraisonConfirme' => !empty($post_data['date_depart']) ? strtotime($post_data['date_depart']) : 0,
        ];

        $project_data = [
            'serial_no' => sanitize_text_field($post_data['serial_no'] ?? ''),
        ];

        if (!$article_id) {
            // ── Création ──────────────────────────────────────────────────────
            $poid = intval($post_data['poid'] ?? 0);

            if (!$poid) {
                return ['success' => false, 'message' => 'poid manquant'];
            }

            $data['IdCommande'] = $poid;

            $inserted = $wpdb->insert($table_purchase, $data);

            if ($inserted === false) {
                return ['success' => false, 'message' => 'Erreur lors de l\'insertion'];
            }

            return ['success' => true, 'message' => 'Création OK'];
        }

        // ── Mise à jour de la table achat ─────────────────────────────────────
        $updated = $wpdb->update($table_purchase, $data, ['Id' => $article_id]);

        if ($updated === false) {
            return ['success' => false, 'message' => 'Erreur à la mise à jour'];
        }

        // ── Récupération de IdCommandeClient ──────────────────────────────────
        $id_commande_client = $wpdb->get_var($wpdb->prepare(
            "SELECT IdCommandeClient FROM {$table_purchase} WHERE Id = %d",
            $article_id
        ));

        // ── Mise à jour de la table projet si IdCommandeClient trouvé ─────────
        if (!empty($id_commande_client) && !empty(array_filter($project_data))) {
            $wpdb->update(
                $table_project,
                $project_data,
                ['Id' => $id_commande_client]
            );
        }

        return ['success' => true, 'message' => 'Mise à jour OK'];
    }

    public function set_article_as_delivered($html, $ids, $date) {
        foreach ($ids as $article_id) {
            $qty = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT Qty FROM {$this->table_articles} WHERE Id = %d",
                    $article_id
                )
            );

            if ($qty !== null) {
                $this->wpdb->update(
                    $this->table_articles,
                    [
                        'Recu' => $qty,
                        'TimestampDateLivraisonConfirme' => $date
                    ],
                    ['Id' => $article_id]
                );
                // Log pour vérifier
                error_log("Mise à jour de l'article $article_id : Recu=$qty, Date=$date");
            }
        }
    }


    public static function delete_achat() {
        global $wpdb;

        $achat_id = isset($_POST['achat_id']) ? intval($_POST['achat_id']) : 0;
        if (!$achat_id) {
            wp_send_json_error('ID invalide');
        }

        $table_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $table_commandes = $wpdb->prefix . 'achats_commande_liste_fournisseurs';

        // Supprimer les articles
        $wpdb->delete($table_articles, ['IdCommande' => $achat_id]);

        // Supprimer la commande
        $deleted = $wpdb->delete($table_commandes, ['Id' => $achat_id]);

        if ($deleted === false) {
            wp_send_json_error('Échec suppression');
        }

        wp_send_json_success();
    }

}


add_action('wp_ajax_ispag_load_more_achats', 'ispag_load_more_achats');
add_action('wp_ajax_nopriv_ispag_load_more_achats', 'ispag_load_more_achats');

function ispag_load_more_achats() {
    $offset = intval($_POST['offset']);
    $limit = 20;
    $search = sanitize_text_field($_POST['search']);
    $select_state = sanitize_text_field($_POST['select_state']);
    $user_id = get_current_user_id();

    $can_view_all = current_user_can('view_supplier_order');
    $can_view_own = current_user_can('read_orders');

    $repo = new ISPAG_Achat_Repository();
    $achats = [];

    if ($can_view_all) {
        $achats = $repo->get_achats(null, true, $search, $select_state, $offset, $limit);
    } elseif ($can_view_own) {
        $achats = $repo->get_achats($user_id, false, $search, '', $offset, $limit);
    }

    $html = '';
    foreach ($achats as $i => $achat) {
        $html .= ISPAG_Achat_Manager::render_achat_row($achat, $offset + $i);
    }

    wp_send_json_success(['html' => $html, 'has_more' => count($achats) === $limit]);
}



add_action('wp_ajax_filter_achats_custom_tables', 'ajax_filter_achats_custom_tables');
add_action('wp_ajax_nopriv_filter_achats_custom_tables', 'ajax_filter_achats_custom_tables');

function ajax_filter_achats_custom_tables() {
    // check_ajax_referer('ispag_achat_nonce', 'security');

    global $wpdb;

    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = 10; // Nombre d'éléments par page
    $offset = ($page - 1) * $per_page;
    $filters = isset($_POST['filters']) ? $_POST['filters'] : [];

    // 1. Initialisation de la requête SQL (sans le GROUP BY ici)
    $sql = "
        SELECT clf.*,
               f.Fournisseur AS fournisseur_nom,
               u.display_name AS responsable_nom,
               SUM(IFNULL((af.UnitPrice - af.discount) * af.Qty, 0)) AS prix_net_total
        FROM {$wpdb->prefix}achats_commande_liste_fournisseurs clf
        LEFT JOIN {$wpdb->prefix}achats_articles_cmd_fournisseurs af ON clf.Id = af.IdCommande 
        LEFT JOIN {$wpdb->prefix}achats_fournisseurs f ON clf.IdFournisseur = f.Id
        LEFT JOIN {$wpdb->users} u ON clf.created_by = u.ID
        WHERE 1=1
    ";

    // Filtre par recherche (RefCommande, NrCommande, hubspot_deal_id)
    if (!empty($filters['search'])) {
        $search_term = '%' . $wpdb->esc_like(sanitize_text_field($filters['search'])) . '%';
        $sql .= $wpdb->prepare(
            " AND (clf.RefCommande LIKE %s OR clf.NrCommande LIKE %s OR clf.hubspot_deal_id LIKE %s)",
            $search_term, $search_term, $search_term
        );
    }

    // Filtre par statut (EtatCommande)
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $sql .= $wpdb->prepare(" AND clf.EtatCommande = %d", $filters['status']);
    }

    // Filtre par fournisseur (IdFournisseur)
    if (!empty($filters['fournisseur']) && $filters['fournisseur'] !== 'all') {
        $sql .= $wpdb->prepare(" AND clf.IdFournisseur = %d", $filters['fournisseur']);
    }

    // Filtre par responsable (created_by)
    if (!empty($filters['responsable']) && $filters['responsable'] !== 'all') {
        $sql .= $wpdb->prepare(" AND clf.created_by = %d", $filters['responsable']);
    }

    // 2. placement correct du GROUP BY après tous les filtres WHERE
    $sql .= " GROUP BY clf.Id";

    // 3. Ajout du tri par défaut
    $sql .= " ORDER BY clf.TimestampDateCreation DESC";

    // 4. Ajout de la pagination
    $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);

    // Exécute la requête
    $results = $wpdb->get_results($sql);

    // Compte le nombre total de résultats (pour la pagination)
    $count_sql = "
        SELECT COUNT(*)
        FROM {$wpdb->prefix}achats_commande_liste_fournisseurs clf
        LEFT JOIN {$wpdb->prefix}achats_fournisseurs f ON clf.IdFournisseur = f.Id
        LEFT JOIN {$wpdb->users} u ON clf.created_by = u.ID
        WHERE 1=1
    ";

    // Applique les mêmes filtres pour le compte
    if (!empty($filters['search'])) {
        $search_term = '%' . $wpdb->esc_like(sanitize_text_field($filters['search'])) . '%';
        $count_sql .= $wpdb->prepare(
            " AND (clf.RefCommande LIKE %s OR clf.NrCommande LIKE %s OR clf.hubspot_deal_id LIKE %s)",
            $search_term, $search_term, $search_term
        );
    }
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $count_sql .= $wpdb->prepare(" AND clf.EtatCommande = %d", $filters['status']);
    }
    if (!empty($filters['fournisseur']) && $filters['fournisseur'] !== 'all') {
        $count_sql .= $wpdb->prepare(" AND clf.IdFournisseur = %d", $filters['fournisseur']);
    }
    if (!empty($filters['responsable']) && $filters['responsable'] !== 'all') {
        $count_sql .= $wpdb->prepare(" AND clf.created_by = %d", $filters['responsable']);
    }

    $total_results = $wpdb->get_var($count_sql);
    $has_more = ($offset + $per_page) < $total_results;

    // Génère le HTML pour chaque résultat sous forme de lignes de tableau
    $html = '';
    foreach ($results as $index => $row) {
        $html .= ISPAG_Achat_Manager::render_achat_row($row, $offset + $index);
    }

    wp_send_json_success([
        'html' => $html,
        'has_more' => $has_more,
    ]);

    wp_die();
}