<?php


class ISPAG_Achat_Generate_Purchase_Order_PDF {
    public static function init() {
        add_action('wp_ajax_ispag_generate_purchase_order_pdf', [self::class, 'generate_purchase_order_pdf'], 10, 2);
        add_filter('ispag_print_purchase_order_btn', [self::class, 'print_purchase_order_btn'], 10, 2);
        

    }  
    
    public static function print_purchase_order_btn($html, $achat_id){
        $achat = apply_filters('ispag_get_achat_by_id', null, $achat_id);
        if (!in_array($achat->EtatCommande, [1])) {
            return;
        }


        return '<button id="generate-purchase-order-pdf" class="ispag-btn ispag-btn-secondary-outlined" style="margin-top: 1rem;">
                📄 ' .  __('Print purchase order', 'creation-reservoir') . '
            </button>
            <script>
            document.getElementById( \'generate-purchase-order-pdf\').addEventListener(\'click\', function () {
                const ids = [...document.querySelectorAll(\'.ispag-article-checkbox:checked\')]
                    .map(cb => cb.dataset.articleId);

                

                const url = new URL(\'' . admin_url('admin-ajax.php') . '\');
                url.searchParams.set(\'action\', \'ispag_generate_purchase_order_pdf\');
                url.searchParams.set(\'poid\', getUrlParam(\'poid\'));
                url.searchParams.set(\'ids\', ids.join(\',\'));

                window.open(url.toString(), \'_blank\');
            });
            </script>';
    }


    public static function generate_purchase_order_pdf(){
        if (!current_user_can('edit_supplier_order')) {
            wp_die('Non autorisé');
        }
        global $wpdb;
    
        $deal_id = isset($_GET['deal_id']) ? sanitize_text_field($_GET['deal_id']) : '';
        $achat_id = isset($_GET['poid']) ? sanitize_text_field($_GET['poid']) : '';

        if(!empty($achat_id)){
            // $article_repo = new ISPAG_Achat_Article_Repository();
            $details_repo = new ISPAG_Achat_Details_Repository(); 
            
            // $project_repo = new ISPAG_Achat_Repository();
            // $project_data = $project_repo->get_achats(null, null, $achat_id);
            // $project_data = array();
            $project_data = apply_filters('ispag_get_achat_by_id', null, $achat_id);
            $supplier_info = apply_filters('ispag_get_supplier_info', null, $project_data->IdFournisseur);

            //On force le changement de langue suivant la langue du fournisseur:
            $lang = $supplier_info['lang'] ?: 'fr_FR';
            if ($lang) {
                if (function_exists('pll_set_language')) pll_set_language($lang);
                switch_to_locale($lang); // utile si tu veux charger gettext dans la bonne langue
            }

            $parts = explode(' - ', $project_data->RefCommande, 2);
            $projectName = isset($parts[1]) ? trim($parts[1]) : ''; // "Genève - Migros Fusterie"
            $projectNum = trim($parts[0]); // "KST300/120213"

            
            $infos = [
                'nom_entreprise' => $supplier_info['name'],
                'AdresseDeLivraison' => $supplier_info['address'],
                'Postal code' => $supplier_info['Postal code'],
                'City' => $supplier_info['city'],
                'country' => $supplier_info['country'],
            ];
            $infos = (object) $infos;

            // Récupérer projet + articles depuis la base (à adapter selon ta structure)
            $titre_project = __('Project', 'creation-reservoir');
            $titre_ref = __('Order number', 'creation-reservoir');
            $titre_delivery_date = __('Delivery date', 'creation-reservoir');

            $articles = [];
            $table_header = [
                ['label' => __('Ref', 'creation-reservoir'), 'key' => 'ref', 'width' => 20],
                ['label' => __('Description', 'creation-reservoir'), 'key' => 'description', 'width' => 90],
                ['label' => __('Unit price', 'creation-reservoir'), 'key' => 'unitPrice', 'width' => 25, 'align' => 'C'],
                ['label' => __('Quantity', 'creation-reservoir'), 'key' => 'qty', 'width' => 15, 'align' => 'C'],
                ['label' => __('Discount', 'creation-reservoir'), 'key' => 'discount', 'width' => 15, 'align' => 'C'],
                ['label' => __('Total', 'creation-reservoir'), 'key' => 'total', 'width' => 25, 'align' => 'C'],
            ];

            $project_header = [
                $titre_project => $projectName,
                $titre_ref => $projectNum,
                $titre_delivery_date => date('d.m.Y', time())
            ];
            $articles = array();
            $purchase_articles = apply_filters('ispag_get_articles_by_order', null, $achat_id);
            // echo'<pre>';
            // var_dump($purchase_articles);
            // echo'</pre>';
            foreach ($purchase_articles as $article) {
                // $article = $article_repo->get_article_by_id($id); // crée cette fonction selon ta table
                // $article = apply_filters('ispag_get_article_by_id', null, $id);
                $articles[] = [
                    'ref' => $article->RefSurMesure,
                    'description' => $article->DescSurMesure,
                    'unitPrice' => number_format($article->UnitPrice, 2, '.', "'"),
                    'qty' => $article->Qty,
                    'discount' => $article->discount .'%',
                    'total' => number_format($article->total_price, 2, '.', "'")
                ];
            }
        }
        else{
            wp_die('Aucun projet ou achat de defini');
        }

        
    

        

        $title = __('Purchase order', 'creation-reservoir');
        $file_name = $title . '-'. $projectNum . '-' . $supplier_info['name'];
        $file_name = preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $file_name));



        // require_once plugin_dir_path(__FILE__) . '/class-ispag-pdf-generator.php';
        $pdf = apply_filters('ispag_generate_purchase_order_pdf', null, $project_header, $project_data, $infos, $table_header, $articles, $title);
        if ($pdf) {
            $title = sanitize_filename($title);

            $wp_upload_dir = wp_upload_dir();
            $uploadedfile = trailingslashit ( $wp_upload_dir['path'] ) . $title;
            $pdf->Output($uploadedfile, 'F');

            $attachment = array(
            'guid' => trailingslashit ($wp_upload_dir['url']) . basename( $uploadedfile ),
            'post_mime_type' => 'application/pdf',
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($title)),
            'post_content' => '',
            'post_status' => 'inherit'
            );

            // Id of attachment if needed
            $attach_id = wp_insert_attachment( $attachment, $uploadedfile);
            $userId = get_current_user_id();
            $wpdb-> insert(
                
                $wpdb->prefix.'achats_historique',
                [
                    'Id' => '',
                    'hubspot_deal_id' => 0,
                    'purchase_order' => $achat_id,
                    'Date' => time(),
                    'dateReadable' => date('Y-m-d H:i:s'),
                    'IdUser' => $userId,
                    'Historique' => '',
                    'IdMedia' => $attach_id,
                    'ClassCss' => 'customer_order'
                    
                ]
            );
            $pdf->Output('I', $file_name . '.pdf');
            exit;

        }

    }
}