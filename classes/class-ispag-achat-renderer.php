<?php
defined('ABSPATH') or die();

class ISPAG_Achat_Renderer {
    protected $wpdb;
    protected $table;

    public static function init() {
        add_action('ispag_achat_articles_tab', [self::class, 'render_articles_tab'], 10, 1);
        add_filter('ispag_render_purchase_article_modal', [self::class, 'render_article_modal'], 10, 2);
        add_filter('ispag_render_purchase_article_modal_form', [self::class, 'render_article_modal_form'], 10, 3);
        add_filter('ispag_render_article_block', [self::class, 'reload_article_row'], 10, 2);
    }
    
    public static function render_articles_tab($achat_id) {
        if (empty($achat_id) || !is_numeric($achat_id)) {
            echo '<div class="ispag-notice warning"><p>Aucun article trouvé.</p></div>';
            return;
        }

        $repo = new ISPAG_Achat_Article_Repository();
        $articles = $repo->get_articles_by_order(null, $achat_id);

        if (empty($articles)) {
            echo '<div class="ispag-empty-state">';
                echo '<span class="dashicons dashicons-cart"></span>';
                echo '<p>' . __('Aucun article pour cette commande fournisseur.', 'creation-reservoir') . '</p>';
                echo '<div class="ispag-actions-group">';
                    echo self::get_add_article_btn($achat_id);
                    echo self::get_delete_purchase_btn($achat_id);
                echo '</div>';
            echo '</div>';
            // echo self::display_modal();
            echo ISPAG_Detail_Page::display_modal();
            return;
        }

        echo '<div class="ispag-achat-modern-container">';
            
            // En-tête avec les actions globales (Print, Bulk, etc.)
            echo '<div class="ispag-achat-header-actions">';
                echo apply_filters('ispag_bulk_selected_article', '', $achat_id);
                echo '<div class="ispag-buttons-right">';
                    echo apply_filters('ispag_print_purchase_order_btn', null, $achat_id);
                echo '</div>';
            echo '</div>';

            echo '<div class="ispag-achat-articles-list">';
            foreach ($articles as $article) {
                $escaped_group = esc_html(stripslashes($article->Groupe ?? ''));
                $group_id = 'group-title-' . md5($article->Groupe ?? '');
                
                echo '<div class="ispag-article-group-wrapper">';
                    echo '<div class="ispag-article-group-header">';
                        echo '<h3 id="' . esc_attr($group_id) . '"><span class="dashicons dashicons-category"></span> ' . $escaped_group . '</h3>';
                        echo '<button class="ispag-btn-copy-group" data-target="' . esc_attr($group_id) . '" title="Copier le titre">📋</button>';
                    echo '</div>';

                    // Chaque bloc d'article sera rendu avec le nouveau design via cette méthode
                    echo '<div class="ispag-article-card-container">';
                        self::render_article_block($article);
                    echo '</div>';
                echo '</div>';
            }
            echo '</div>';

            // Barre d'actions flottante ou fixe en bas
            echo '<div class="ispag-achat-footer-actions">';
                ISPAG_Achat_Status_Controller::render_action_button_for_achat($achat_id);
                echo '<div class="ispag-action-buttons-secondary">';
                    echo self::get_add_article_btn($achat_id);
                    echo self::get_delivery_btn($achat_id);
                    echo self::get_delete_purchase_btn($achat_id);
                echo '</div>';
            echo '</div>';

        echo '</div>'; // .ispag-achat-modern-container
        
        // echo self::display_modal();
        echo ISPAG_Detail_Page::display_modal();
    }

    public static function reload_article_row($html, $article_id){
        // $repo = new ISPAG_Achat_Article_Repository();
        // $article = $repo->get_article_by_id($article_id);
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

    public static function render_article_modal($html, $article_id){
        $repo = new ISPAG_Achat_Article_Repository();
        $article = $repo->get_article_by_id(null, $article_id);
        // $article = apply_filters('ispag_get_article_by_id', null, $article_id);

        $standard_titles = apply_filters('ispag_get_standard_titles_by_type', $article->Type);
        $user_can_edit_order = current_user_can('edit_supplier_order');
        $user_can_view_order = current_user_can('view_supplier_order');

        if (!$article) {
            echo '<p>Article introuvable.</p>';
            wp_die();
        }
        include plugin_dir_path(__FILE__) . 'templates/modal-display-datas.php';
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
            echo '<p>Article introuvable.</p>';
            wp_die();
        }
        // $article = apply_filters('ispag_get_article_by_id', null, $article_id);

        // $standard_titles = apply_filters('ispag_get_standard_titles_by_type', $article->Type);

        // error_log('STANDARD ARTICLE TYPE : ' . $article->Type);
        // error_log('STANDARD ARTICLE : ' . print_r($standard_titles, true));
        $user_can_edit_order = current_user_can('edit_supplier_order');
        $user_can_view_order = current_user_can('view_supplier_order');
        $id_attr = $is_new ? '' : ' data-article-id="' . intval($article_id) . '"';
        if (!$article) {
            echo '<p>Article introuvable.</p>';
            wp_die();
        }
        include plugin_dir_path(__FILE__) . 'templates/modal-display-datas-form.php';
        return;
    }
 
    // private static function display_modal(){ 
    //     return '<div id="ispag-modal" class="ispag-product-modal" style="display:none;">
    //         <div class="ispag-modal-content">
    //             <span class="ispag-modal-close">&times;</span>
    //             <div id="ispag-modal-body">
    //                 <!-- Le contenu sera injecté ici en JS -->
    //             </div>
    //         </div>
    //     </div>
    //     ' . apply_filters('ispag_get_modal_fitting', '');

    // }

    private static function get_delivery_btn($achat_id = null){
        $achat = apply_filters('ispag_get_achat_by_id', null, $achat_id);
        $array_etat = [3, 4, 5];
        if(!in_array($achat->EtatCommande, $array_etat)){
            return;
        }
        return '<button id="generate-pdf" class="ispag-btn ispag-btn-secondary-outlined" style="margin-top: 1rem;">
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
            ' .  __('Delete', 'creation-reservoir') . '
        </button>';
    }

    private static function get_add_article_btn($achat_id){
        // $achat = apply_filters('ispag_get_achat_by_id', null, $achat_id);
        // if (!in_array($achat->EtatCommande, [1, 2, 6, 10])) {
        //     return;
        // }
        return '<button id="ispag-add-article" data-poid="' . esc_attr($achat_id) .'" class="ispag-btn ispag-btn-secondary-outlined"><span class="dashicons dashicons-plus-alt"></span> ' . __('Add product', 'creation-reservoir'). '</button>';
    }
}
