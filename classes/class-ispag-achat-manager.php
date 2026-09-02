<?php
defined('ABSPATH') or die();

/**
 * Classe ISPAG_Achat_Manager
 * Gère les opérations de BDD et l'interface utilisateur pour les commandes d'achat ISPAG.
 * Utilise AJAX pour le traitement du formulaire, incluant la liaison aux projets clients/Hubspot.
 * Logging : Toutes les actions sont loguées dans ispag_achat_manager.log.
 */
class ISPAG_Achat_Manager
{
    private $wpdb;
    private $table_achats;
    private $table_articles;
    private $table_fournisseurs;
    protected static $instance = null;

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        $this->table_achats = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        $this->table_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_fournisseurs = $wpdb->prefix . 'achats_fournisseurs';

        // $this->logger->log_user_action('achat_manager', 'class_constructed', [], $user_id);
    }
 
    public static function init()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();

        if (self::$instance === null)
        {
            self::$instance = new self();
            // $logger->log_user_action('achat_manager', 'instance_initialized', [], $user_id);
        }

        add_shortcode('ispag_achats', [self::class, 'ispag_achats_shortcode']);
        add_shortcode('ispag_achat_detail', [self::class, 'ispag_achat_detail_shortcode']);
        // $logger->log_user_action('achat_manager', 'shortcodes_registered', [], $user_id);

        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_filter('ispag_inline_edit_purchase', [self::class, 'handle_inline_edit'], 10, 2);
        // $logger->log_user_action('achat_manager', 'hooks_registered', [], $user_id);

        add_filter('ispag_article_saved_from_purchase', [self::class, 'handle_saved_article'], 10, 3);
        add_action('ispag_achat_set_article_as_delivered', [self::$instance, 'set_article_as_delivered'], 10, 3);
        add_filter('ispag_bulk_selected_article', [self::class, 'bulk_selected_article'], 10, 2);
        add_action('wp_ajax_ispag_bulk_achat_update_articles', [self::class, 'bulk_achat_update_articles']);
        add_action('wp_ajax_ispag_delete_achat', [self::class, 'delete_achat']);
        add_action('wp_ajax_ispag_save_confirmed_data', [self::class, 'ispag_save_confirmed_data_handler']);
    }

    public static function enqueue_assets()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        // $logger->log_user_action('achat_manager', 'enqueue_assets_start', [], $user_id);

        global $wpdb;

        add_action('wp_enqueue_scripts', function()
        {
            wp_enqueue_style('ispag-main-style');
        });

        wp_enqueue_script('ispag-scroll-achats', plugin_dir_url(__FILE__) . '../assets/js/infinite-scroll-achat.js', ['jquery'], false, true);
        wp_enqueue_script('ispag-state-achats', plugin_dir_url(__FILE__) . '../assets/js/state.js', ['jquery'], false, true);
        wp_enqueue_script('ispag-details-achats', plugin_dir_url(__FILE__) . '../assets/js/details-achat.js', ['jquery'], false, true);
        wp_enqueue_script('ispag-header-achats', plugin_dir_url(__FILE__) . '../assets/js/header.js', ['jquery'], false, true);

        wp_localize_script('ispag-scroll-achats', 'ajaxurl', admin_url('admin-ajax.php'));

        wp_localize_script('ispag-scroll-achats', 'ispagVars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ispag_achat_nonce'),
            'loading_text' => __('Loading', 'creation-reservoir'),
            'all_loaded_text' => __('All projects are loaded', 'creation-reservoir'),
            'security' => wp_create_nonce('ispag_achat_nonce'),
        ]);

        // $logger->log_user_action('achat_manager', 'scripts_enqueued', [], $user_id);

        $fournisseurs = $wpdb->get_results(
            "SELECT Id, Fournisseur FROM {$wpdb->prefix}achats_fournisseurs WHERE isSupplier = 1 ORDER BY Fournisseur ASC"
        );

        // $logger->log_db_change('achat_manager', 'achats_fournisseurs', 'SELECT', ['count' => count($fournisseurs)], $user_id);

        $formatted_fournisseurs = array_map(function($f) use ($logger, $user_id)
        {
            // $logger->log_user_action('achat_manager', 'fournisseur_formatted', ['fournisseur_id' => $f->Id], $user_id);
            return ['Id' => $f->Id, 'Fournisseur' => $f->Fournisseur];
        }, $fournisseurs);

        wp_localize_script(
            'ispag-header-achats',
            'ispag_fournisseurs',
            [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ispag_achat_nonce'),
                'security' => wp_create_nonce('ispag_fournisseurs'),
                'fournisseurs' => $formatted_fournisseurs
            ]
        );

        // $logger->log_user_action('achat_manager', 'fournisseurs_localized', [], $user_id);
    }

    public static function ispag_achats_shortcode($atts)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('achat_manager', 'ispag_achats_shortcode_start', [], $user_id);

        if (!current_user_can('view_supplier_order'))
        {
            $logger->log('achat_manager', 'ERROR: User cannot view supplier order', $user_id);
            ob_start();
            echo '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i>
                        <strong>' . esc_html__('Restricted access', 'ispag-crm') . ' :</strong> ' .
                        esc_html__('You do not have the necessary rights to view this order.', 'ispag-crm') . '<br/>
                        <a href="' . home_url('/wp-login.php') . '">' . esc_html__('To login page', 'ispag-crm') . '</a>
                    </div>';
            return ob_get_clean();
        }

        $filters = [
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '',
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all',
            'fournisseur' => isset($_GET['fournisseur']) ? sanitize_text_field($_GET['fournisseur']) : 'all',
            'responsable' => isset($_GET['responsable']) ? sanitize_text_field($_GET['responsable']) : 'all',
        ];

        $logger->log_user_action('achat_manager', 'filters_applied', $filters, $user_id);

        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/achats-filters.php';

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
        echo '<tbody id="ispag-achats-list"></tbody>';
        echo '</table></div>';
        echo '<div id="ispag-achats-loading" style="display: none; text-align: center; padding: 10px;">Chargement...</div>';

        $logger->log_user_action('achat_manager', 'ispag_achats_shortcode_complete', [], $user_id);
        return ob_get_clean();
    }

    public static function shortcode_achats($atts)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('achat_manager', 'shortcode_achats_start', [], $user_id);

        if (!current_user_can('view_supplier_order'))
        {
            $logger->log('achat_manager', 'ERROR: User cannot view supplier order', $user_id);
            return '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i>
                        <strong>' . esc_html__('Restricted access', 'ispag-crm') . ' :</strong> ' .
                         esc_html__('You do not have the necessary rights to view this order.', 'ispag-crm') . '<br/>
                        <a href ="'. home_url('/wp-login.php') . '">' . esc_html__('To login page', 'ispag-crm') . '</a>
                    </div>';
        }

        $can_view_supplier_orders = current_user_can('view_supplier_order');
        $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

        $logger->log_user_action('achat_manager', 'search_query_applied', ['query' => $search_query], $user_id);

        $html = '
        <div class="ispag-toolbar">
            <form method="get">
                <input type="text" name="search" placeholder="' . __('Search', 'creation-reservoir') . ' ..." value="' . esc_attr($search_query) . '" />
                <button type="submit" class="ispag-btn">' . __('Filter / Search', 'creation-reservoir') . '</button>
                ';
                if (!empty($search_query) OR isset($_GET['select_state']))
                {
                    $html .= '<a href="' . esc_url(remove_query_arg(array('orderby', 'order', 'search', 'filter_owner', 'paged', 'select_state'))) . '" class="ispag-btn ispag-btn-grey">' . __('Reset filters', 'creation-reservoir') . '</a>';
                }
        $html .= '
            </form>
        </div>';

        if ($can_view_supplier_orders)
        {
            $status_checker = new ISPAG_Achat_status_render();
            $html .= $status_checker->render_state_buttons();
            $logger->log_user_action('achat_manager', 'status_buttons_rendered', [], $user_id);
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

        $logger->log_user_action('achat_manager', 'shortcode_achats_complete', [], $user_id);
        return $html;
    }

    public static function get_achat_etats()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('achat_manager', 'get_achat_etats_start', [], $user_id);

        global $wpdb;
        $table_etats = $wpdb->prefix . 'achats_etat_commandes_fournisseur';

        $etats = $wpdb->get_results("SELECT Id, Etat, ClassCss, color FROM {$table_etats} ORDER BY ordre ASC");
        $logger->log_db_change('achat_manager', $table_etats, 'SELECT_ALL', ['count' => count($etats)], $user_id);

        $translated_etats = [];
        foreach ($etats as $etat)
        {
            $translated_etat = [
                'Id' => $etat->Id,
                'Etat' => $etat->Etat,
                'ClassCss' => $etat->ClassCss,
                'color' => $etat->color,
                'translated' => __($etat->Etat, 'creation-reservoir')
            ];
            $translated_etats[] = $translated_etat;
            $logger->log_user_action('achat_manager', 'etat_translated', ['etat_id' => $etat->Id, 'translated' => $translated_etat['translated']], $user_id);
        }

        $logger->log_user_action('achat_manager', 'get_achat_etats_complete', ['count' => count($translated_etats)], $user_id);
        return $translated_etats;
    }

    public static function render_achat_row($achat, $index = 0)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('achat_manager', 'render_achat_row_start', ['achat_id' => $achat->Id, 'index' => $index], $user_id);

        global $wpdb;

        $date_creation = date('d.m.Y', $achat->TimestampDateCreation);
        $date_reception = $achat->TimestampDateReceptionConfirmee ? date('d.m.Y', $achat->TimestampDateReceptionConfirmee) : '-';

        $fournisseur_nom = isset($achat->fournisseur_nom) ? $achat->fournisseur_nom : '';
        if (empty($fournisseur_nom) && isset($achat->IdFournisseur))
        {
            $fournisseur_nom = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT Fournisseur FROM {$wpdb->prefix}achats_fournisseurs WHERE Id = %d",
                    $achat->IdFournisseur
                )
            );
            $logger->log_db_change('achat_manager', 'achats_fournisseurs', 'FETCH_FOURNISSEUR', ['achat_id' => $achat->Id, 'fournisseur_id' => $achat->IdFournisseur, 'fournisseur_nom' => $fournisseur_nom], $user_id);
        }

        $responsable_nom = isset($achat->responsable_nom) ? $achat->responsable_nom : '';
        if (empty($responsable_nom) && isset($achat->created_by))
        {
            $user_info = get_userdata($achat->created_by);
            $responsable_nom = $user_info ? $user_info->display_name : __('Unknown User', 'ispag-crm');
            $logger->log_db_change('achat_manager', 'users', 'FETCH_RESPONSABLE', ['achat_id' => $achat->Id, 'user_id' => $achat->created_by, 'responsable_nom' => $responsable_nom], $user_id);
        }

        $etat_id = isset($achat->EtatCommande) ? $achat->EtatCommande : 0;
        $etat_info = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT Etat, ClassCss, color FROM {$wpdb->prefix}achats_etat_commandes_fournisseur WHERE Id = %d",
                $etat_id
            )
        );

        $logger->log_db_change('achat_manager', 'achats_etat_commandes_fournisseur', 'FETCH_ETAT', ['achat_id' => $achat->Id, 'etat_id' => $etat_id], $user_id);

        $etat_text = $etat_info ? __($etat_info->Etat, 'creation-reservoir') : __('Unknown', 'creation-reservoir');
        $bgcolor = $etat_info ? $etat_info->color : '#ccc';
        $class_css = $etat_info ? $etat_info->ClassCss : '';

        $logger->log_user_action('achat_manager', 'render_achat_row_complete', ['achat_id' => $achat->Id], $user_id);

        return '
            <tr>
                <td style="background-color:#D1E7DD;">' . ($index + 1) . '</td>
                <td><a href="' . esc_url(home_url('/purchase/' . $achat->Id)) . '" target="_blank" class="ispag_achat_link"><strong>' . esc_html(stripslashes($achat->RefCommande)) . '</strong></a></td>
                <td>' . esc_html($date_creation) . '</td>
                <td>' . esc_html($date_reception) . '</td>
                <td><strong>' . esc_html($fournisseur_nom) . '</strong><br><small class="creator-name">' . __('by', 'creation-reservoir') . ' : ' . esc_html($responsable_nom) . '</small></td>
                <td>' . number_format_i18n($achat->prix_net_total, 2) . '</td>
                <td>' . esc_html($achat->ConfCmdFournisseur) . '</td>
                <td><span class="ispag-state-badge ' . esc_attr($class_css) . '" style="background-color:' . esc_attr($bgcolor) . '; opacity: 0.8;">' . esc_html($etat_text) . '</span></td>
            </tr>
        ';
    }

    public function get_supplier_command_id_by_article($article_id)
    {
        $user_id = get_current_user_id();
        $article_id = intval($article_id);
        $this->logger->log_user_action('achat_manager', 'get_supplier_command_id_by_article', ['article_id' => $article_id], $user_id);

        $query = $this->wpdb->prepare(
            "SELECT IdCommande
            FROM {$this->table_articles}
            WHERE IdCommandeClient = %d
            LIMIT 1",
            $article_id
        );

        $result = $this->wpdb->get_var($query);
        $this->logger->log_db_change('achat_manager', $this->table_articles, 'SELECT_ID_COMMAND', ['article_id' => $article_id, 'result' => $result], $user_id);

        return $result;
    }

    public static function ispag_achat_detail_shortcode($atts)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('achat_manager', 'ispag_achat_detail_shortcode_start', [], $user_id);

        ob_start();

        

        global $wpdb;

        if (!current_user_can('view_supplier_order'))
        {
            $logger->log('achat_manager', 'ERROR: User cannot view supplier order', $user_id);
            return '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i>
                        <strong>' . esc_html__('Restricted access', 'ispag-crm') . ' :</strong> ' .
                         esc_html__('You do not have the necessary rights to view this order.', 'ispag-crm') . '<br/>
                        <a href ="'. home_url('/wp-login.php') . '">' . esc_html__('To login page', 'ispag-crm') . '</a>
                    </div>';
        }

        $achat_id = get_query_var('poid');
        if (empty($achat_id) && isset($_GET['poid']))
        {
            $achat_id = sanitize_text_field($_GET['poid']);
            $logger->log_user_action('achat_manager', 'achat_id_from_get', ['achat_id' => $achat_id], $user_id);
        }

        $achat_id = absint($achat_id);
        if (!$achat_id)
        {
            $logger->log('achat_manager', 'ERROR: Missing or invalid achat_id', $user_id);
            echo '<div class="ispag-error-message">ID d\'achat manquant.</div>';
            return;
        }

        $logger->log_user_action('achat_manager', 'achat_id_resolved', ['achat_id' => $achat_id], $user_id);

        do_action('ispag_check_auto_status_for_achat', $achat_id);
        $logger->log_user_action('achat_manager', 'auto_status_check_triggered', ['achat_id' => $achat_id], $user_id);

        $repo = new ISPAG_Achat_Repository();
        $achat = $repo->get_achat_by_id(null, $achat_id);
        $logger->log_db_change('achat_manager', 'achats', 'FETCH_ACHAT', ['achat_id' => $achat_id, 'result' => !empty($achat)], $user_id);

        if (!$achat)
        {
            $logger->log('achat_manager', 'ERROR: Achat not found', $user_id);
            return 'Achat introuvable.';
        }

        $fournisseurs = $wpdb->get_results("SELECT Id, Fournisseur FROM {$wpdb->prefix}achats_fournisseurs WHERE isSupplier = 1 ORDER BY Fournisseur ASC");
        $logger->log_db_change('achat_manager', 'achats_fournisseurs', 'SELECT_ALL', ['count' => count($fournisseurs)], $user_id);

        include plugin_dir_path(__FILE__) . 'templates/achat-detail.php';

        $logger->log_user_action('achat_manager', 'ispag_achat_detail_shortcode_complete', ['achat_id' => $achat_id], $user_id);

        $title = esc_html(stripslashes($achat->RefCommande ?? 'Achat sans titre'));
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                // Récupère le titre actuel de la page (ex: 'Mon Compte - Mon Site')
                var originalTitle = document.title;
                var refCommande = '" . esc_js($title) . "';
                
                // Concatène selon vos préférences
                document.title = refCommande + ' - ' + originalTitle;
            });
        </script>";
        return ob_get_clean();
    }

    public static function bulk_selected_article($html, $achat_id)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('achat_manager', 'bulk_selected_article_start', ['achat_id' => $achat_id], $user_id);

        $can_manage_order = current_user_can('manage_order');
        if (!$can_manage_order)
        {
            $logger->log('achat_manager', 'ERROR: User cannot manage order', $user_id);
            return false;
        }

        $logger->log_user_action('achat_manager', 'bulk_actions_rendered', ['achat_id' => $achat_id], $user_id);

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
                    cb.indeterminate = true;
                    db.indeterminate = true;
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
                    alert("' . __('No article selected', 'creation-reservoir') . '");
                    return;
                }

                const data = {
                    action: \'ispag_bulk_achat_update_articles\',
                    articles: selectedIds,
                    achat_id: document.getElementById(\'achat-id\').value,
                    date_depart: document.getElementById(\'bulk-date-depart\').value,
                    livre_date: document.getElementById(\'bulk-livre-date\').value,
                    invoiced_date: document.getElementById(\'bulk-invoiced-date\').value,
                    _ajax_nonce: \'' . wp_create_nonce('ispag_bulk_update') . '\'
                };

                fetch(\'' . admin_url('admin-ajax.php') . '\', {
                    method: \'POST\',
                    headers: { \'Content-Type\': \'application/x-www-form-urlencoded\' },
                    body: new URLSearchParams(data)
                })
                .then(res => res.json())
                .then(response => {
                    const msgBox = document.getElementById(\'ispag-bulk-message\');

                    if (response.success) {
                        msgBox.textContent = response.data.message;
                        msgBox.style.display = \'block\';
                        msgBox.style.backgroundColor = \'#d4edda\';
                        msgBox.style.color = \'#155724\';
                        msgBox.style.border = \'1px solid #c3e6cb\';

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
                });
            });
        </script>
        ';
    }

    public static function bulk_achat_update_articles()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('achat_manager', 'bulk_achat_update_articles_start', [], $user_id);

        check_ajax_referer('ispag_bulk_update');
        $date_depart_has_update = false;

        $article_ids = $_POST['articles'] ?? [];
        $achat_id = $_POST['achat_id'] ?? [];

        if (!current_user_can('manage_order') || empty($article_ids))
        {
            $logger->log('achat_manager', 'ERROR: Unauthorized or empty selection', $user_id);
            wp_send_json_error(['message' => __('Unauthorized or empty selection', 'creation-reservoir')]);
        }

        $articles = isset($_POST['articles']) ? $_POST['articles'] : '';

        // Ensure it's an array before counting
        if (is_array($articles)) {
            $total = count($articles);
        } else {
            $total = 0; // or handle empty string case appropriately
        }

        $logger->log_user_action('achat_manager', 'bulk_update_authorized', ['achat_id' => $achat_id, 'article_count' => $total], $user_id);

        global $wpdb;
        $updates = [];
        $ids_raw = $_POST['articles'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

        $logger->log_user_action('achat_manager', 'articles_parsed', ['ids' => $ids], $user_id);

        $in_clause = implode(',', $ids);

        $date_depart = $_POST['date_depart'] ?? null;
        if ($date_depart)
        {
            $timestamp = strtotime($date_depart);
            if ($timestamp)
            {
                $updates[] = "TimestampDateLivraisonConfirme = '" . intval($timestamp) . "'";
                $date_depart_has_update = true;
                $logger->log_user_action('achat_manager', 'date_depart_added', ['timestamp' => $timestamp], $user_id);
            }
        }

        if (!empty($_POST['livre_date']))
        {
            $timestamp = strtotime($_POST['livre_date']);
            if ($timestamp)
            {
                $updates[] = "Recu = 1";
                $logger->log_user_action('achat_manager', 'livre_date_added', ['timestamp' => $timestamp], $user_id);
            }
        }

        if (!empty($_POST['invoiced_date']))
        {
            $timestamp = strtotime($_POST['invoiced_date']);
            if ($timestamp)
            {
                $updates[] = "Facture = 1";
                $logger->log_user_action('achat_manager', 'invoiced_date_added', ['timestamp' => $timestamp], $user_id);
            }
        }

        if (!empty($updates))
        {
            $query = "UPDATE {$wpdb->prefix}achats_articles_cmd_fournisseurs SET " . implode(', ', $updates) . " WHERE id IN ($in_clause)";
            $result = $wpdb->query($query);
            $logger->log_db_change('achat_manager', 'achats_articles_cmd_fournisseurs', 'BULK_UPDATE', ['query' => $query, 'result' => $result], $user_id);

            if ($date_depart_has_update && $date_depart)
            {
                do_action('ispag_update_delivery_date_from_purchase', null, $achat_id, $ids, $timestamp);
                $logger->log_user_action('achat_manager', 'delivery_date_update_triggered', ['achat_id' => $achat_id], $user_id);
            }
        }

        $logger->log_user_action('achat_manager', 'bulk_achat_update_articles_complete', [], $user_id);
        wp_send_json_success(['message' => __('Bulk update applied successfully', 'creation-reservoir')]);
    }

    public static function handle_inline_edit($updated, $args)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('achat_manager', 'handle_inline_edit_start', ['args' => $args], $user_id);

        global $wpdb;

        $allowed_fields = ['Fournisseur', 'RefCommande', 'ConfCmdFournisseur', 'TimestampDateCreation'];
        $table = $wpdb->prefix . 'achats_commande_liste_fournisseurs';

        if (!in_array($args['field'], $allowed_fields))
        {
            $logger->log('achat_manager', 'ERROR: Field not allowed - ' . $args['field'], $user_id);
            return false;
        }

        $logger->log_user_action('achat_manager', 'field_validated', ['field' => $args['field']], $user_id);

        if ($args['field'] == 'Fournisseur')
        {
            $supplier_id = $wpdb->get_var($wpdb->prepare(
                "SELECT Id FROM {$wpdb->prefix}achats_fournisseurs WHERE Fournisseur = %s",
                $args['value']
            ));

            $logger->log_db_change('achat_manager', 'achats_fournisseurs', 'FETCH_SUPPLIER_ID', ['supplier_name' => $args['value'], 'supplier_id' => $supplier_id], $user_id);

            if (!$supplier_id)
            {
                $logger->log('achat_manager', 'ERROR: Supplier not found - ' . $args['value'], $user_id);
                return false;
            }

            $args['value'] = $supplier_id;
            $args['field'] = 'IdFournisseur';
            $logger->log_user_action('achat_manager', 'supplier_field_adjusted', ['original_value' => $args['value'], 'new_field' => $args['field']], $user_id);
        }

        $res = $wpdb->update(
            $table,
            [$args['field'] => $args['value']],
            ['Id' => $args['deal_id']]
        );

        $logger->log_db_change('achat_manager', $table, 'UPDATE_INLINE_EDIT', ['field' => $args['field'], 'value' => $args['value'], 'deal_id' => $args['deal_id'], 'result' => $res], $user_id);

        if ($res === false)
        {
            $logger->log('achat_manager', 'ERROR: Update failed - ' . $wpdb->last_error, $user_id);
        }
        else
        {
            $logger->log_user_action('achat_manager', 'inline_edit_success', ['rows_affected' => $res], $user_id);
        }

        return $res !== false;
    }

    public static function ispag_save_confirmed_data_handler()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('achat_manager', 'ispag_save_confirmed_data_handler_start', [], $user_id);

        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
        $purchase_id = isset($_POST['purchase_id']) ? intval($_POST['purchase_id']) : 0;
        $post_datas = isset($_POST['data']) ? (array) wp_unslash($_POST['data']) : [];
        $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;

        $logger->log_user_action('achat_manager', 'data_received', ['deal_id' => $deal_id, 'purchase_id' => $purchase_id, 'article_id' => $article_id], $user_id);

        if (!$article_id && isset($post_datas['Id']))
        {
            $article_id = intval($post_datas['Id']);
            $logger->log_user_action('achat_manager', 'article_id_from_data', ['article_id' => $article_id], $user_id);
        }

        if (empty($article_id) || empty($purchase_id))
        {
            $logger->log('achat_manager', 'ERROR: Missing required data (article_id or purchase_id)', $user_id);
            wp_send_json_error('Données obligatoires manquantes (ID article ou ID achat).');
        }

        if (empty($post_datas))
        {
            $logger->log('achat_manager', 'ERROR: No data to save', $user_id);
            wp_send_json_error('Aucune donnée à enregistrer.');
        }

        $logger->log_user_action('achat_manager', 'data_validation_passed', [], $user_id);

        $sanitized_tank_data = [];
        foreach ($post_datas as $key => $value)
        {
            $sanitized_key = sanitize_key($key);

            if (is_numeric($value))
            {
                $sanitized_tank_data[$sanitized_key] = floatval($value);
            }
            elseif (is_string($value))
            {
                $sanitized_tank_data[$sanitized_key] = sanitize_text_field($value);
            }
            else
            {
                $sanitized_tank_data[$sanitized_key] = $value;
            }
        }

        $logger->log_user_action('achat_manager', 'data_sanitized', ['data' => $sanitized_tank_data], $user_id);

        $datas = [
            'deal_id' => $deal_id,
            'achat_id' => $purchase_id,
            'article_id' => $article_id,
            'tank' => $sanitized_tank_data
        ];

        $logger->log_user_action('achat_manager', 'tank_data_prepared', ['datas' => $datas], $user_id);

        $res_tank = apply_filters('ispag_auto_saver_tank_data', null, $datas, true);
        $logger->log_user_action('achat_manager', 'tank_data_filter_applied', ['result' => $res_tank], $user_id);

        $article_achat = apply_filters('ispag_get_achat_article_by_project_article_id', null, $article_id);
        $logger->log_db_change('achat_manager', 'achats_articles_cmd_fournisseurs', 'FETCH_ARTICLE_ACHAT', ['article_id' => $article_id, 'result' => $article_achat], $user_id);

        $res_purchase = false;
        if ($article_achat && isset($article_achat->Id))
        {
            $res_purchase = apply_filters('ispag_article_saved_from_purchase', null, $article_achat->Id, $sanitized_tank_data);
            $logger->log_user_action('achat_manager', 'purchase_data_filter_applied', ['result' => $res_purchase], $user_id);
        }

        if ($res_tank && $res_tank['success'])
        {
            $logger->log_user_action('achat_manager', 'save_confirmed_data_success', [], $user_id);
            wp_send_json_success([
                'message' => 'Données mises à jour avec succès.',
                'purchase_update' => $res_purchase
            ]);
        }
        else
        {
            $error_msg = isset($res_tank['message']) ? $res_tank['message'] : 'Erreur lors de la mise à jour technique.';
            $logger->log('achat_manager', 'ERROR: Save failed - ' . $error_msg, $user_id);
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

    public function set_article_as_delivered($html, $ids, $date)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('achat_manager', 'set_article_as_delivered_start', ['ids' => $ids, 'date' => $date], $user_id);

        foreach ($ids as $article_id)
        {
            $qty = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT Qty FROM {$this->table_articles} WHERE IdCommandeClient = %d",
                    $article_id
                )
            );

            $this->logger->log_db_change('achat_manager', $this->table_articles, 'FETCH_QTY', ['article_id' => $article_id, 'qty' => $qty], $user_id);

            if ($qty !== null)
            {
                $article_id_int = intval($article_id);

                $result = $this->wpdb->update(
                    $this->table_articles,
                    [
                        'Recu' => $qty,
                        'TimestampDateLivraisonConfirme' => $date
                    ],
                    ['IdCommandeClient' => $article_id_int]
                );

                $this->logger->log_db_change('achat_manager', $this->table_articles, 'UPDATE_DELIVERED', ['article_id' => $article_id, 'qty' => $qty, 'date' => $date, 'result' => $result], $user_id);
            }
        }

        $this->logger->log_user_action('achat_manager', 'set_article_as_delivered_complete', [], $user_id);
    }

    public static function delete_achat()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('achat_manager', 'delete_achat_start', [], $user_id);

        global $wpdb;

        $achat_id = isset($_POST['achat_id']) ? intval($_POST['achat_id']) : 0;
        if (!$achat_id)
        {
            $logger->log('achat_manager', 'ERROR: Invalid achat_id', $user_id);
            wp_send_json_error('ID invalide');
        }

        $logger->log_user_action('achat_manager', 'delete_achat_validated', ['achat_id' => $achat_id], $user_id);

        $table_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $table_commandes = $wpdb->prefix . 'achats_commande_liste_fournisseurs';

        $deleted_articles = $wpdb->delete($table_articles, ['IdCommande' => $achat_id]);
        $logger->log_db_change('achat_manager', $table_articles, 'DELETE_ARTICLES', ['achat_id' => $achat_id, 'deleted_count' => $deleted_articles], $user_id);

        $deleted = $wpdb->delete($table_commandes, ['Id' => $achat_id]);
        $logger->log_db_change('achat_manager', $table_commandes, 'DELETE_COMMAND', ['achat_id' => $achat_id, 'result' => $deleted], $user_id);

        if ($deleted === false)
        {
            $logger->log('achat_manager', 'ERROR: Failed to delete achat - ' . $wpdb->last_error, $user_id);
            wp_send_json_error('Échec suppression');
        }

        $logger->log_user_action('achat_manager', 'delete_achat_complete', ['achat_id' => $achat_id], $user_id);
        wp_send_json_success();
    }
}

// ----------------------------------------------------------------------------
// Fonctions externes (hors classe)
// ----------------------------------------------------------------------------

add_action('wp_ajax_ispag_load_more_achats', 'ispag_load_more_achats');
add_action('wp_ajax_nopriv_ispag_load_more_achats', 'ispag_load_more_achats');

function ispag_load_more_achats()
{
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('achat_manager', 'ispag_load_more_achats_start', [], $user_id);

    $offset = intval($_POST['offset']);
    $limit = 20;
    $search = sanitize_text_field($_POST['search']);
    $select_state = sanitize_text_field($_POST['select_state']);

    $logger->log_user_action('achat_manager', 'load_more_params_received', ['offset' => $offset, 'limit' => $limit, 'search' => $search, 'select_state' => $select_state], $user_id);

    $can_view_all = current_user_can('view_supplier_order');
    $can_view_own = current_user_can('read_orders');

    $repo = new ISPAG_Achat_Repository();
    $achats = [];

    if ($can_view_all)
    {
        $achats = $repo->get_achats(null, true, $search, $select_state, $offset, $limit);
        $logger->log_db_change('achat_manager', 'achats', 'FETCH_ALL', ['offset' => $offset, 'limit' => $limit, 'count' => count($achats)], $user_id);
    }
    elseif ($can_view_own)
    {
        $achats = $repo->get_achats($user_id, false, $search, '', $offset, $limit);
        $logger->log_db_change('achat_manager', 'achats', 'FETCH_OWN', ['user_id' => $user_id, 'offset' => $offset, 'limit' => $limit, 'count' => count($achats)], $user_id);
    }

    $html = '';
    foreach ($achats as $i => $achat)
    {
        $html .= ISPAG_Achat_Manager::render_achat_row($achat, $offset + $i);
    }

    $has_more = count($achats) === $limit;
    $logger->log_user_action('achat_manager', 'ispag_load_more_achats_complete', ['has_more' => $has_more], $user_id);

    wp_send_json_success(['html' => $html, 'has_more' => $has_more]);
}

add_action('wp_ajax_filter_achats_custom_tables', 'ajax_filter_achats_custom_tables');
add_action('wp_ajax_nopriv_filter_achats_custom_tables', 'ajax_filter_achats_custom_tables');

function ajax_filter_achats_custom_tables()
{
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('achat_manager', 'ajax_filter_achats_custom_tables_start', [], $user_id);

    global $wpdb;

    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = 10;
    $offset = ($page - 1) * $per_page;
    $filters = isset($_POST['filters']) ? $_POST['filters'] : [];

    $logger->log_user_action('achat_manager', 'filter_params_received', ['page' => $page, 'per_page' => $per_page, 'filters' => $filters], $user_id);

    $sql = "
        SELECT clf.*, f.Fournisseur AS fournisseur_nom, u.display_name AS responsable_nom,
               SUM(IFNULL((af.UnitPrice - af.discount) * af.Qty, 0)) AS prix_net_total
        FROM {$wpdb->prefix}achats_commande_liste_fournisseurs clf
        LEFT JOIN {$wpdb->prefix}achats_articles_cmd_fournisseurs af ON clf.Id = af.IdCommande
        LEFT JOIN {$wpdb->prefix}achats_fournisseurs f ON clf.IdFournisseur = f.Id
        LEFT JOIN {$wpdb->users} u ON clf.created_by = u.ID
        WHERE 1=1
    ";

    if (!empty($filters['search']))
    {
        $search_term = '%' . $wpdb->esc_like(sanitize_text_field($filters['search'])) . '%';
        $sql .= $wpdb->prepare(
            " AND (clf.RefCommande LIKE %s OR clf.NrCommande LIKE %s OR clf.hubspot_deal_id LIKE %s)",
            $search_term, $search_term, $search_term
        );
        $logger->log_user_action('achat_manager', 'search_filter_applied', ['search_term' => $filters['search']], $user_id);
    }

    if (!empty($filters['status']) && $filters['status'] !== 'all')
    {
        $sql .= $wpdb->prepare(" AND clf.EtatCommande = %d", $filters['status']);
        $logger->log_user_action('achat_manager', 'status_filter_applied', ['status' => $filters['status']], $user_id);
    }

    if (!empty($filters['fournisseur']) && $filters['fournisseur'] !== 'all')
    {
        $sql .= $wpdb->prepare(" AND clf.IdFournisseur = %d", $filters['fournisseur']);
        $logger->log_user_action('achat_manager', 'fournisseur_filter_applied', ['fournisseur' => $filters['fournisseur']], $user_id);
    }

    if (!empty($filters['responsable']) && $filters['responsable'] !== 'all')
    {
        $sql .= $wpdb->prepare(" AND clf.created_by = %d", $filters['responsable']);
        $logger->log_user_action('achat_manager', 'responsable_filter_applied', ['responsable' => $filters['responsable']], $user_id);
    }

    $sql .= " GROUP BY clf.Id";
    $sql .= " ORDER BY clf.TimestampDateCreation DESC";
    $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);

    $results = $wpdb->get_results($sql);
    $logger->log_db_change('achat_manager', 'achats_commande_liste_fournisseurs', 'FILTERED_SELECT', ['count' => count($results)], $user_id);

    $count_sql = "
        SELECT COUNT(*)
        FROM {$wpdb->prefix}achats_commande_liste_fournisseurs clf
        LEFT JOIN {$wpdb->prefix}achats_fournisseurs f ON clf.IdFournisseur = f.Id
        LEFT JOIN {$wpdb->users} u ON clf.created_by = u.ID
        WHERE 1=1
    ";

    if (!empty($filters['search']))
    {
        $search_term = '%' . $wpdb->esc_like(sanitize_text_field($filters['search'])) . '%';
        $count_sql .= $wpdb->prepare(
            " AND (clf.RefCommande LIKE %s OR clf.NrCommande LIKE %s OR clf.hubspot_deal_id LIKE %s)",
            $search_term, $search_term, $search_term
        );
    }
    if (!empty($filters['status']) && $filters['status'] !== 'all')
    {
        $count_sql .= $wpdb->prepare(" AND clf.EtatCommande = %d", $filters['status']);
    }
    if (!empty($filters['fournisseur']) && $filters['fournisseur'] !== 'all')
    {
        $count_sql .= $wpdb->prepare(" AND clf.IdFournisseur = %d", $filters['fournisseur']);
    }
    if (!empty($filters['responsable']) && $filters['responsable'] !== 'all')
    {
        $count_sql .= $wpdb->prepare(" AND clf.created_by = %d", $filters['responsable']);
    }

    $total_results = $wpdb->get_var($count_sql);
    $has_more = ($offset + $per_page) < $total_results;

    $logger->log_user_action('achat_manager', 'filter_results_processed', ['total_results' => $total_results, 'has_more' => $has_more], $user_id);

    $html = '';
    foreach ($results as $index => $row)
    {
        $html .= ISPAG_Achat_Manager::render_achat_row($row, $offset + $index);
    }

    $logger->log_user_action('achat_manager', 'ajax_filter_achats_custom_tables_complete', [], $user_id);
    wp_send_json_success(['html' => $html, 'has_more' => $has_more]);
    wp_die();
}