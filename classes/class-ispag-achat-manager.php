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

        add_shortcode('ispag_achats', [self::class, 'shortcode_achats']);
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

        wp_enqueue_script('ispag-scroll-achats', plugin_dir_url(__FILE__) . '../assets/js/infinite-scroll-achat.js', [], false, true);
        wp_enqueue_script('ispag-state-achats', plugin_dir_url(__FILE__) . '../assets/js/state.js', [], false, true);
        wp_enqueue_script('ispag-details-achats', plugin_dir_url(__FILE__) . '../assets/js/details-achat.js', [], false, true);
        wp_enqueue_script('ispag-header-achats', plugin_dir_url(__FILE__) . '../assets/js/header.js', [], false, true);

        wp_localize_script('ispag-scroll-achats', 'ajaxurl', admin_url('admin-ajax.php'));

        wp_localize_script('ispag-scroll-achats', 'ispagVars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'loading_text' => __('Loading', 'creation-reservoir'),
            'all_loaded_text' => __('All projects are loaded', 'creation-reservoir'),
        ]);

        // Récupérer les fournisseurs pour l’inline-edit
        $fournisseurs = $wpdb->get_results(
            "SELECT Id, Fournisseur FROM {$wpdb->prefix}achats_fournisseurs WHERE isSupplier = 1 ORDER BY Fournisseur ASC"
        );

        wp_localize_script(
            'ispag-header-achats',
            'ispag_fournisseurs',
            array_map(function($f) {
                return ['Id' => $f->Id, 'Fournisseur' => $f->Fournisseur];
            }, $fournisseurs)
        );
    }

    public static function shortcode_achats($atts) {
        $can_view_supplier_orders = current_user_can('view_supplier_order');

        $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

        $html = '<form method="get" style="margin-bottom: 20px;">
            <input type="text" name="search" placeholder="' . __('Search', 'creation-reservoir') . ' ..." value="' . esc_attr($search_query) . '" />
            <button type="submit">' . __('Search', 'creation-reservoir') . '</button>
        </form>';

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

    public static function render_achat_row($achat, $index = 0) {
        $date = date('d.m.Y', $achat->TimestampDateCreation);
        $reception = $achat->TimestampDateLivraisonConfirme ? date('d.m.Y', $achat->TimestampDateLivraisonConfirme) : '-';
        $bgcolor = !empty($achat->color) ? esc_attr($achat->color) : '#ccc';

        return '
            <tr> 
                <td style="background-color:#D1E7DD;">' . ($index + 1) . '</td>
                <td><a href="' . esc_url($achat->purchase_url) . '" target="_blank" class="ispag_achat_link">' . esc_html(stripslashes($achat->RefCommande)) . '</a></td>
                
                <td>' . esc_html($date) . '</td>
                <td>' . esc_html($reception) . '</td>
                <td>' . esc_html(stripslashes($achat->Fournisseur)) . '</td>
                <td>' . number_format_i18n($achat->purchase_total, 2) . '</td>
                <td>' . esc_html($achat->ConfCmdFournisseur) . '</td>
                <td><span class="ispag-state-badge" style="background-color:' . $bgcolor . '; opacity: 0.8;">' . esc_html__($achat->Etat, 'creation-reservoir') . '</span></td>
                
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
        
        if(!current_user_can('view_supplier_order')) return '';
        
        $achat_id = isset($_GET['poid']) ? intval($_GET['poid']) : 0;
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

        error_log("🔧 handle_inline_edit() called with args: " . print_r($args, true));

        if (!in_array($args['field'], $allowed_fields)) {
error_log("❌ Champ non autorisé : {$args['field']}");
            return false;
        }

        if ($args['field'] == 'Fournisseur') {
            $supplier_id = $wpdb->get_var($wpdb->prepare(
                "SELECT Id FROM {$wpdb->prefix}achats_fournisseurs WHERE Fournisseur = %s",
                $args['value']
            ));
            
            error_log("🔍 Résultat ID fournisseur pour '{$args['value']}': " . var_export($supplier_id, true));

            if (!$supplier_id) {
error_log("❌ Fournisseur non trouvé");
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
error_log("❌ Erreur lors de la mise à jour : " . $wpdb->last_error);
        } else {
            error_log("✅ Mise à jour réussie (lignes modifiées : $res)");
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
// \1('Data received for article ' .$article_id . ' in handle_saved_article ' . print_r($post_data, true));


        $data = [
            'RefSurMesure' => sanitize_text_field($post_data['article_title'] ?? ''),
            'DescSurMesure' => wp_kses_post($post_data['description'] ?? ''),
            'UnitPrice' => floatval($post_data['sales_price'] ?? 0),
            'discount' => floatval($post_data['discount'] ?? 0),
            'Qty' => intval($post_data['qty'] ?? 1),
            'IdArticleStandard' => intval($post_data['IdArticleStandard'] ?? 0),
            'TimestampDateLivraisonConfirme' => !empty($post_data['date_depart']) ? strtotime($post_data['date_depart']) : 0,
            // 'Livre' => isset($post_data['Livre']) ? 1 : null,
            // 'Facture' => isset($post_data['invoiced']) ? time() : null,
        ];


        if (!$article_id) {
            // Création
            $poid = intval($post_data['poid'] ?? 0);

            if (!$poid) {
                return ['success' => false, 'message' => 'poid manquant'];
            }

            $data['IdCommande'] = $poid;

            $inserted = $wpdb->insert($wpdb->prefix . 'achats_articles_cmd_fournisseurs', $data);

            if ($inserted === false) {
                return ['success' => false, 'message' => 'Erreur lors de l’insertion'];
            }

            return ['success' => true, 'message' => 'Création OK'];
        }

        // Mise à jour
        $updated = $wpdb->update($wpdb->prefix . 'achats_articles_cmd_fournisseurs', $data, ['Id' => $article_id]);

        if ($updated === false) {
            return ['success' => false, 'message' => 'Erreur à la mise à jour'];
        }

        return ['success' => true, 'message' => 'Mise à jour OK'];
    }

    public function set_article_as_delivered($html, $ids, $date) {
        foreach ($ids as $article_id) {
            // Récupérer la valeur actuelle de Qty pour cet article
            $qty = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT Qty FROM {$this->table_articles} WHERE IdCommandeClient = %d",
                    $article_id
                )
            );

            if ($qty !== null) {
                // Mettre à jour Recu avec la valeur de Qty
                $this->wpdb->update(
                    $this->table_articles,
                    ['Recu' => $qty],
                    ['IdCommandeClient' => $article_id]
                );
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
