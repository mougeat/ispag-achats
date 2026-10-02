<?php
defined('ABSPATH') or die();

class ISPAG_Achat_Renderer {
    protected $wpdb;
    protected $table;

    public static function init() {
        add_action('ispag_achat_articles_tab', [self::class, 'render_articles_tab'], 10, 1);
        add_filter('ispag_render_purchase_article_modal', [self::class, 'render_article_modal'], 10, 2);
        add_filter('ispag_purchase_article_view_data', [self::class, 'article_view_data'], 10, 2);
        add_filter('ispag_render_purchase_article_modal_form', [self::class, 'render_article_modal_form'], 10, 3);
        add_filter('ispag_render_article_block', [self::class, 'reload_article_row'], 10, 2);
        add_action('wp_ajax_ispag_apply_purchase_adjustment', [self::class, 'ajax_apply_adjustment']);
    }
    
    public static function render_articles_tab($achat_id) {
        if (empty($achat_id) || !is_numeric($achat_id)) {
            echo '<div class="ispag-notice warning"><p>No article found.</p></div>';
            return;
        }

        $repo = new ISPAG_Achat_Article_Repository();
        $articles = $repo->get_articles_by_order(null, $achat_id);

        $grouped_articles = self::group_articles_by_group_name($articles);

        self::display_purchase_adjustments($achat_id, $grouped_articles);

        if (empty($grouped_articles)) {
            echo '<div class="ispag-empty-state">';
                echo '<span class="dashicons dashicons-cart"></span>';
                echo '<div class="ispag-notice warning"><p>' . __('No items were found for this supplier order.', 'creation-reservoir') . '</p></div>';
            echo '</div>';
            // echo self::display_modal();
            // echo ISPAG_Detail_Page::display_modal();
            return;
        }

        echo '<div class="ispag-achat-modern-container">';
            
            // En-tête avec les actions globales (Print, Bulk, etc.)
            echo '<div class="ispag-achat-header-actions">';
                echo '<div class="ispag-article-header-global" style="margin-bottom: 1rem;">
                        <input type="checkbox" id="select-all-articles" class="ispag-article-checkbox">
                        <label for="select-all-articles">' .  __('Select all', 'creation-reservoir') . '</label>
                    </div>';
                
                echo '<div class="ispag-buttons-right">';
                    echo apply_filters('ispag_print_purchase_order_btn', null, $achat_id); 
                    echo ISPAG_Achat_Status_Controller::render_action_button_for_achat($achat_id);
                echo '</div>';
            echo '</div>';

            echo '<div class="ispag-achat-articles-list">';
            foreach ($grouped_articles as $group_name => $items) {
                $escaped_group = esc_html(stripslashes($group_name));
                $group_id = 'group-title-' . md5($group_name);
                
                $group_total = 0;
                foreach ($items as $it) {
                    $group_total += isset($it->total_price) ? (float) $it->total_price : (float) $it->UnitPriceNet * (int) $it->Qty;
                }
                echo '<div class="ispag-article-group-wrapper">';
                    echo '<div class="ispag-article-group-header">';
                        echo '<button type="button" class="ispag-group-toggle" aria-expanded="true" title="' . esc_attr__('Collapse / expand', 'creation-reservoir') . '"><i class="fas fa-chevron-down"></i></button>';
                        echo '<h3 id="' . esc_attr($group_id) . '">' . $escaped_group . '</h3>';
                        echo '<span class="ispag-group-count">' . count($items) . '</span>';
                        if (current_user_can('display_sales_prices')) {
                            echo '<span class="ispag-group-total">' . number_format($group_total, 2) . ' ' . esc_html(get_option('wpcb_currency', 'CHF')) . '</span>';
                        }
                        echo '<button type="button" class="ispag-btn-copy-group" data-target="' . esc_attr($group_id) . '" title="' . esc_attr__('Copy title', 'creation-reservoir') . '">📋</button>';
                    echo '</div>';

                    // Conteneur pour tous les articles de ce groupe
                    echo '<div class="ispag-article-card-container">';
                        
                        // --- BOUCLE SUR LES ARTICLES DU GROUPE ---
                        foreach ($items as $article) {
                            self::render_article_block($article);
                        }
                        
                    echo '</div>'; // .ispag-article-card-container
                echo '</div>'; // .ispag-article-group-wrapper
            }
            // Les actions groupées (bulk) sont affichées dans la colonne de gauche de la fiche
            echo '</div>';

        echo '</div>'; // .ispag-achat-modern-container
        
        // echo self::display_modal();
        // echo ISPAG_Detail_Page::display_modal();
    }

    /** Onglet Documents : uniquement la liste (la zone de dépôt est dans la colonne de droite de la fiche). */
    public static function render_documents_tab($achat_id) {
        if (!class_exists('ISPAG_Attachments_Repository') || !class_exists('ISPAG_Attachments_Card_Renderer')) {
            return '<p>Error: The document management classes are not loaded.</p>';
        }
        global $wpdb;
        $renderer = new ISPAG_Attachments_Card_Renderer(new ISPAG_Attachments_Repository($wpdb));

        return '<div class="ispag-card ispag-docu-card" data-view="list" data-entity-type="purchase" data-entity-id="' . esc_attr($achat_id) . '">'
            . $renderer->render_doc_list('purchase', $achat_id, -1, true)
            . '</div>';
    }

    /** Carte « Add attachments » (zone de dépôt) de la colonne de droite. */
    public static function render_upload_card($achat_id) {
        if (!class_exists('ISPAG_Attachments_Doc_Types_Repository') || !class_exists('ISPAG_Attachments_Modal_Renderer')) {
            return '';
        }
        global $wpdb;
        $modal_renderer = new ISPAG_Attachments_Modal_Renderer(new ISPAG_Attachments_Doc_Types_Repository($wpdb));

        return '<div class="ispag-card ispag-company-card"><h5>' . esc_html__('Add attachments', 'ispag-crm') . '</h5>'
            . $modal_renderer->render_dropzone('purchase', $achat_id, 'ispag-upload-modal-dropzone')
            . '</div>';
    }

    /**
     * Boutons d'action du panneau de gauche (sous « To project » / « To purchase list »), groupés par famille
     * de fonction et séparés par un trait fin. Dépendent du statut (rafraîchis par state.js).
     */
    public static function footer_buttons_html($achat_id) {
        $groups = [
            self::get_add_article_btn($achat_id) . self::get_delivery_btn($achat_id), // articles
            self::get_delete_purchase_btn($achat_id),                                  // suppression
        ];
        $html = '';
        foreach ($groups as $group) {
            if (trim((string) $group) === '') continue;
            $html .= '<hr class="ispag-btn-sep"><div class="ispag-btn-group">' . $group . '</div>';
        }
        return $html;
    }

    /**
     * Regroupe les articles par leur propriété 'Groupe'
     */
    private static function group_articles_by_group_name($articles) {
        $grouped = [];
        
        foreach ($articles as $article) {
            // On définit un nom par défaut si le groupe est vide
            $group_name = !empty($article->Groupe) ? $article->Groupe : __('No group', 'ispag-crm');
            
            if (!isset($grouped[$group_name])) {
                $grouped[$group_name] = [];
            }
            
            $grouped[$group_name][] = $article;
        }
        
        // Tri alphabétique des groupes (insensible à la casse), "sans groupe" en dernier
        $no_group = __('No group', 'ispag-crm');
        uksort($grouped, function ($a, $b) use ($no_group) {
            if ($a === $b) return 0;
            if ($a === $no_group) return 1;
            if ($b === $no_group) return -1;
            return strnatcasecmp(stripslashes($a), stripslashes($b));
        });

        return $grouped;
    }

    public static function reload_article_row($html, $article_id){

        $article = apply_filters('ispag_get_purchse_article_by_id', null, $article_id);

        if (!$article) { 
            wp_send_json_error(['message' => 'Article introuvable']);
        }

        ob_start();
        echo self::render_article_block($article);
        
        $html = ob_get_clean();
        echo $html;

    }
    public static function render_article_block($article){
        $id = (int) $article->Id;
        $checked_attr = ''; // checkbox à cocher en JS si besoin
        $facture = $article->Facture ? __('Invoiced', 'creation-reservoir') : __('Not invoiced', 'creation-reservoir');
        $qty = (int) $article->Qty;
        $deal_id = $article->hubspot_deal_id ?? $article->deal_id ?? 0;
        $user_can_edit_order = current_user_can('edit_supplier_order');
        $user_can_view_order = current_user_can('view_supplier_order');
        $user_can_generate_tank = current_user_can('generate_tank');

        $user_can_manage_order = $user_can_edit_order; 
        $user_is_owner = true; // Ou ta logique propriétaire ISPAG


        include plugin_dir_path(__FILE__) . 'templates/render-article-block.php'; 
    }

    /** Données normalisées pour la modale d'affichage commune (ISPAG_Article_View du Project Manager). */
    public static function article_view_data($data, $article_id){
        $repo    = new ISPAG_Achat_Article_Repository();
        $article = $repo->get_article_by_id(null, $article_id);
        if (!$article) {
            return null;
        }
        $show_prices = current_user_can('display_sales_prices');
        $ts          = (int) ($article->TimestampDateLivraisonConfirme ?? 0);

        $documents = [];
        foreach ((array) ($article->documents ?? []) as $doc) {
            $documents[] = ['label' => __($doc['label'], 'creation-reservoir'), 'url' => $doc['url']];
        }

        return [
            'title'       => stripslashes((string) $article->RefSurMesure),
            'subtitle'    => '',
            'image_html'  => ISPAG_Achat_Article_Repository::image_html($article->image, '', 50, 'display:block; max-width:100%; height:auto; margin:auto;'),
            'description' => $article->DescSurMesure ?? '',
            'qty'         => (int) $article->Qty,
            'unit_net'    => $show_prices ? (float) $article->UnitPriceNet : null,
            'discount'    => (float) ($article->discount ?? 0),
            'total'       => $show_prices ? (float) ($article->total_price ?? ((float) $article->UnitPriceNet * (int) $article->Qty)) : null,
            'currency'    => get_option('wpcb_currency', 'CHF'),
            'info'        => [
                [__('Factory departure', 'creation-reservoir'), $ts ? date('d.m.Y', $ts) : '-'],
            ],
            'steps'       => [
                [__('Drawing approved', 'creation-reservoir'), (int) $article->DrawingApproved === 1],
                [__('Received / Delivered', 'creation-reservoir'), !empty($article->Recu)],
                [__('Invoiced', 'creation-reservoir'), !empty($article->Facture)],
            ],
            'documents'   => $documents,
            'is_staff'    => current_user_can('manage_order'),
        ];
    }

    public static function render_article_modal($html, $article_id){
        $data = self::article_view_data(null, $article_id);
        if (!$data || !class_exists('ISPAG_Article_View')) {
            echo '<p>' . __('Article not found', 'creation-reservoir')  . '</p>';
            wp_die();
        }
        echo ISPAG_Article_View::body($data);
        return;
    }

    public static function render_article_modal_form($html, $article_id = null, $article = null, $standard_titles = null){
        $repo = new ISPAG_Achat_Article_Repository();
        $is_new = false;
        if($article_id){
            $article = $repo->get_article_by_id(null, $article_id);
        } 
        elseif($article){

        }
        else{
            echo '<p>' . __('Article not found', 'creation-reservoir')  . '</p>';
            wp_die();
        }

        // error_log('PURCHASE ARTICLE : ' . print_r($article, true));
        $user_can_edit_order = current_user_can('edit_supplier_order');
        $user_can_view_order = current_user_can('view_supplier_order');
        $id_attr = $is_new ? '' : ' data-article-id="' . intval($article_id) . '"';
        if (!$article) {
            echo '<p>' . __('Article not found', 'creation-reservoir')  . '</p>';
            wp_die();
        }
        include plugin_dir_path(__FILE__) . 'templates/modal-display-datas-form.php';
        return; 
    }
 

    private static function get_delivery_btn($achat_id = null){
        $achat = apply_filters('ispag_get_achat_by_id', null, $achat_id);
        $array_etat = [3, 4, 5];
        if(!in_array($achat->EtatCommande, $array_etat)){
            return;
        }
        return '<button id="generate-pdf" class="ispag-btn ispag-btn-secondary-outlined" >
                📄 ' .  __('Delivery note', 'creation-reservoir') . '
            </button>
            <script>
            document.getElementById( \'generate-pdf\').addEventListener(\'click\', function () {
                const ids = [...document.querySelectorAll(\'.ispag-article-checkbox:checked\')]
                    .map(cb => cb.dataset.articleId);

                if (ids.length === 0) {
                    alert("' .  __('No items selected', 'creation-reservoir') . '.");
                    return;
                }

                const url = new URL(\'' . admin_url('admin-ajax.php') . '\');
                url.searchParams.set(\'action\', \'ispag_generate_pdf\');
                url.searchParams.set(\'poid\', getUrlParam(\'poid\'));
                url.searchParams.set(\'ids\', ids.join(\',\'));

                window.open(url.toString(), \'_blank\');
            });
            </script>';
    }

    private static function get_delete_purchase_btn($achat_id){

        $achat = apply_filters('ispag_get_achat_by_id', null, $achat_id);
        if (!in_array($achat->EtatCommande, [1, 2, 6, 10])) {
            return;
        }

        return '<button class="ispag-btn ispag-btn-danger ispag-delete-achat" data-achat-id="' . esc_attr($achat_id) .'">
            <span class="dashicons dashicons-trash"></span> ' .  __('Delete', 'creation-reservoir') . '
        </button>';
    }

    private static function get_add_article_btn($achat_id){
        // $achat = apply_filters('ispag_get_achat_by_id', null, $achat_id);
        // if (!in_array($achat->EtatCommande, [1, 2, 6, 10])) {
        //     return;
        // }
        return '<button id="ispag-add-article" data-poid="' . esc_attr($achat_id) .'"  source="purchase" class="ispag-btn ispag-btn-secondary-outlined"><span class="dashicons dashicons-plus-alt"></span> ' . __('Add product', 'creation-reservoir'). '</button>';
    }

    private static function display_purchase_adjustments($achat_id, $grouped_articles) {
        global $wpdb;
        
        $repo_achat = new ISPAG_Achat_Repository();
        $achat = $repo_achat->get_achat_by_id(null, $achat_id);
        // error_log('[DEBUG] ARTICLE ACHATS');
        // error_log(print_r($grouped_articles, true));
        
        if (!$achat) return;

        if ($achat->allow_price_recalculation == 0) {
            return;
        }

        $supplier_id = intval($achat->IdFournisseur);
        $currency = strtoupper($achat->Devise ?? 'CHF');
        $target_suppliers = [1, 3, 395]; 
        
        $transport_found = false;
        $dedouanement_found = false;
        $total_volume = 0;
        $total_amount_net_taxable = 0; // On va cumuler le TotalPriceNet ici
        $current_transport_price = 0;
        $current_dedouanement_price = 0;

        foreach ($grouped_articles as $group_name => $items) {
            foreach ($items as $art) { // On boucle sur les articles du groupe
                $ref = isset($art->RefSurMesure) ? strtoupper(trim($art->RefSurMesure)) : '';
                
                $total_price_net = floatval($art->TotalPriceNet ?? 0);
                $unit_price = floatval($art->UnitPrice ?? 0);
                $qty = intval($art->Qty ?? 0);
                $type = intval($art->Type ?? 0);
                $types_cuves = [1, 6, 7, 9, 10, 12];

                if ($ref === 'TRANS') {
                    $transport_found = true;
                    $current_transport_price = $unit_price;
                } elseif ($ref === 'DED') {
                    $dedouanement_found = true;
                    $current_dedouanement_price = $unit_price;
                } else {
                    // Logique de Volume
                    if (in_array($type, $types_cuves)) {
                        // Vérifiez bien si technical_volume existe dans l'objet $art
                        if (!empty($art->technical_volume)) {
                            $total_volume += (floatval($art->technical_volume) * $qty);
                        } 
                        elseif (preg_match('/(\d+)\s*litres/i', $art->RefSurMesure, $matches)) {
                            $total_volume += floatval($matches[1]) * $qty;
                        }
                    }
                    
                    // Calcul montant taxable
                    if ($total_price_net > 0) {
                        $total_amount_net_taxable += $total_price_net;
                    } else {
                        $total_amount_net_taxable += ($unit_price * $qty);
                    }
                }
            }
        }

        // --- CALCUL TRANSPORT ---
        if (in_array($supplier_id, $target_suppliers) && $total_volume > 0) {
            $theoretical_trans = ceil($total_volume / 1000) * 250;
            if (!$transport_found || abs($current_transport_price - $theoretical_trans) > 1.00) {
                $msg = "Transport: " . number_format($total_volume, 0, '.', "'") . " L calculated.";
                self::render_adjustment_notice($msg, "Apply transport ($theoretical_trans CHF)", 'TRANS', $theoretical_trans, $achat_id);
            }
        }

        // --- CALCUL DÉDOUANEMENT (Basé sur le NET) ---
        if ($currency === 'EUR' && $total_amount_net_taxable > 0) {
            // Calcul des 10% sur le montant net total
            $theoretical_ded = round($total_amount_net_taxable * 0.10, 2);
            
            if (!$dedouanement_found || abs($current_dedouanement_price - $theoretical_ded) > 1.00) {
                $msg = "Customs clearance (10%) on a net total of " . number_format($total_amount_net_taxable, 2) . " EUR.";
                self::render_adjustment_notice($msg, "Apply customs clearance ($theoretical_ded EUR)", 'DED', $theoretical_ded, $achat_id);
            }
        }
    }

    private static function render_adjustment_notice($message, $btn_label, $type, $amount, $achat_id) {
        ?>
        <div class="ispag-notice info" style="display: flex; justify-content: space-between; align-items: center; background: #e7f3ff; border-left: 4px solid #2196F3; padding: 10px 15px; margin-bottom: 15px;">
            <span><span class="dashicons dashicons-warning" style="color:#2196F3;"></span> <?php echo esc_html($message); ?></span>
            <button class="ispag-btn ispag-btn-primary apply-auto-adjustment" 
                    data-type="<?php echo $type; ?>" 
                    data-amount="<?php echo $amount; ?>" 
                    data-achat="<?php echo $achat_id; ?>">
                <?php echo esc_html($btn_label); ?> 
            </button>
        </div>
        <?php
    }
    public static function ajax_apply_adjustment() {
        check_ajax_referer('ispag_achat_nonce', 'security');

        if (!current_user_can('edit_supplier_order')) {
            wp_send_json_error('Permissions insuffisantes.');
        }

        global $wpdb;
        $type     = sanitize_text_field($_POST['type']); // 'TRANS' ou 'DED'
        $amount   = floatval($_POST['amount']);
        $achat_id = intval($_POST['achat_id']);
        $table    = $wpdb->prefix . 'achats_articles_cmd_fournisseurs'; // À vérifier selon votre table réelle

        if (!$achat_id || !$amount) {
            wp_send_json_error('Invalid data.');
        }

        // 1. Vérifier si l'article existe déjà dans cette commande
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT Id FROM $table WHERE IdCommande = %d AND RefSurMesure = %s",
            $achat_id,
            $type
        ));

        if ($existing_id) {
            // MISE À JOUR
            $updated = $wpdb->update(
                $table,
                [
                    'UnitPrice' => $amount,
                    'Qty'       => 1,
                ],
                ['Id' => $existing_id],
                ['%f', '%d', '%d'],
                ['%d']
            );
            
            if ($updated !== false) {
                wp_send_json_success('Article updated.');
            }
        } else {
            // CRÉATION
            $description = ($type === 'TRANS') ? 'Frais de transport selon volume' : 'Customs clearance fees (10%)';
            
            $inserted = $wpdb->insert(
                $table,
                [
                    'IdCommande'    => $achat_id,
                    'RefSurMesure'  => $type,
                    'DescSurMesure' => $description,
                    'Qty'           => 1,
                    'UnitPrice'     => $amount
                ],
                ['%d', '%s', '%s', '%d', '%f', '%d', '%s']
            );

            if ($inserted) {
                wp_send_json_success('Article added.');
            }
        }

        wp_send_json_error('Error while saving to the database.');
    }
}
