<?php
/**
 * Classe ISPAG_Achat_Generate_Purchase_Order_PDF
 *
 * Génère un PDF de commande d'achat et le sauvegarde dans les médias WordPress.
 * Logging : Toutes les actions sont loguées dans ispag_achat_generate_purchase_order_pdf.log.
 */
class ISPAG_Achat_Generate_Purchase_Order_PDF {

    /** @var ISPAG_Logger Instance du logger. */
    private static $logger;

    /**
     * Initialise la classe et le logger.
     */
    public static function init() {
        self::$logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // self::$logger->log_user_action('achat_generate_purchase_order_pdf', 'class_initialized', [], $user_id);

        add_action('wp_ajax_ispag_generate_purchase_order_pdf', [self::class, 'generate_purchase_order_pdf'], 10, 2);
        add_filter('ispag_print_purchase_order_btn', [self::class, 'print_purchase_order_btn'], 10, 2);
    }

    /**
     * Ajoute un bouton pour générer le PDF de commande d'achat.
     *
     * @param string $html HTML existant.
     * @param int $achat_id ID de la commande d'achat.
     * @return string HTML du bouton.
     */
    public static function print_purchase_order_btn($html, $achat_id) {
        $user_id = get_current_user_id();
        self::$logger->log_user_action(
            'achat_generate_purchase_order_pdf',
            'print_purchase_order_btn_rendered',
            ['achat_id' => $achat_id],
            $user_id
        );

        $achat = apply_filters('ispag_get_achat_by_id', null, $achat_id);
        if (!in_array($achat->EtatCommande, [1])) {
            self::$logger->log(
                'achat_generate_purchase_order_pdf',
                'Bouton non affiché : état de la commande non valide (EtatCommande != 1)',
                $user_id
            );
            return;
        }

        self::$logger->log_user_action(
            'achat_generate_purchase_order_pdf',
            'button_displayed',
            ['achat_id' => $achat_id, 'EtatCommande' => $achat->EtatCommande],
            $user_id
        );

        return '<button id="generate-purchase-order-pdf" class="ispag-btn ispag-btn-secondary-outlined" data-purchase-id="' . $achat_id . '">
                <span class="dashicons dashicons-media-text"></span> ' . __('Print purchase order', 'creation-reservoir') . '
            </button>
            <script>
            document.getElementById( \'generate-purchase-order-pdf\').addEventListener(\'click\', function () {
                const ids = [...document.querySelectorAll(\'.ispag-article-checkbox:checked\')]
                    .map(cb => cb.dataset.articleId);

                const url = new URL(\'' . admin_url('admin-ajax.php') . '\');
                url.searchParams.set(\'action\', \'ispag_generate_purchase_order_pdf\');
                url.searchParams.set(\'poid\', ' . $achat_id . ');
                url.searchParams.set(\'ids\', ids.join(\',\'));

                window.open(url.toString(), \'_blank\');
            });
            </script>';
    }

    /**
     * Génère le PDF de commande d'achat.
     */
    public static function generate_purchase_order_pdf() {
        $user_id = get_current_user_id();
        self::$logger->log_user_action(
            'achat_generate_purchase_order_pdf',
            'generate_purchase_order_pdf_start',
            [],
            $user_id
        );

        if (!current_user_can('edit_supplier_order')) {
            self::$logger->log(
                'achat_generate_purchase_order_pdf',
                'ERROR: Utilisateur non autorisé (edit_supplier_order requis)',
                $user_id
            );
            wp_die('Non autorisé');
        }

        global $wpdb;

        // Récupération de l'ID d'achat
        $achat_id = get_query_var('poid');
        if (empty($achat_id) && isset($_GET['poid'])) {
            $achat_id = sanitize_text_field($_GET['poid']);
        }
        $achat_id = absint($achat_id);

        if (!$achat_id) {
            self::$logger->log(
                'achat_generate_purchase_order_pdf',
                'ERROR: ID d\'achat manquant ou invalide',
                $user_id
            );
            echo "ID d'achat manquant.";
            return;
        }

        self::$logger->log_user_action(
            'achat_generate_purchase_order_pdf',
            'achat_id_resolved',
            ['achat_id' => $achat_id],
            $user_id
        );

        $deal_id = get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null);
        self::$logger->log_user_action(
            'achat_generate_purchase_order_pdf',
            'deal_id_received',
            ['deal_id' => $deal_id],
            $user_id
        );

        if (!empty($achat_id)) {
            $details_repo = new ISPAG_Achat_Details_Repository();
            $project_data = apply_filters('ispag_get_achat_by_id', null, $achat_id);
            $supplier_info = apply_filters('ispag_get_supplier_info', null, $project_data->IdFournisseur);

            self::$logger->log_db_change(
                'achat_generate_purchase_order_pdf',
                'achats',
                'FETCH_PROJECT_DATA',
                ['achat_id' => $achat_id, 'supplier_id' => $project_data->IdFournisseur],
                $user_id
            );

            // Changement de langue selon le fournisseur
            $lang = $supplier_info['lang'] ?: 'fr_FR';
            if ($lang) {
                if (function_exists('pll_set_language')) {
                    pll_set_language($lang);
                }
                switch_to_locale($lang);
                self::$logger->log_user_action(
                    'achat_generate_purchase_order_pdf',
                    'language_switched',
                    ['lang' => $lang],
                    $user_id
                );
            }

            $parts = explode(' - ', $project_data->RefCommande, 2);
            $projectName = isset($parts[1]) ? trim($parts[1]) : '';
            $projectNum = trim($parts[0]);

            self::$logger->log_user_action(
                'achat_generate_purchase_order_pdf',
                'project_info_extracted',
                ['projectName' => $projectName, 'projectNum' => $projectNum],
                $user_id
            );

            $infos = [
                'nom_entreprise' => $supplier_info['name'],
                'AdresseDeLivraison' => $supplier_info['address'],
                'Postal code' => $supplier_info['Postal code'],
                'City' => $supplier_info['city'],
                'country' => $supplier_info['country'],
            ];
            $infos = (object) $infos;

            self::$logger->log_user_action(
                'achat_generate_purchase_order_pdf',
                'supplier_info_prepared',
                ['supplier_name' => $supplier_info['name']],
                $user_id
            );

            // Préparation des en-têtes
            $titre_project = __('Project', 'creation-reservoir');
            $titre_ref = __('Order number', 'creation-reservoir');
            $titre_delivery_date = __('Order date', 'creation-reservoir');

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

            self::$logger->log_user_action(
                'achat_generate_purchase_order_pdf',
                'table_headers_prepared',
                ['project_header' => $project_header, 'table_header' => $table_header],
                $user_id
            );

            // Récupération des articles
            $articles = [];
            $purchase_articles = apply_filters('ispag_get_articles_by_order', null, $achat_id);

            self::$logger->log_db_change(
                'achat_generate_purchase_order_pdf',
                'articles',
                'FETCH_ARTICLES',
                ['achat_id' => $achat_id, 'count' => count($purchase_articles)],
                $user_id
            );

            foreach ($purchase_articles as $article) {
                $articles[] = [
                    'ref' => $article->RefSurMesure,
                    'description' => $article->DescSurMesure,
                    'unitPrice' => number_format($article->UnitPrice, 2, '.', "'"),
                    'qty' => $article->Qty,
                    'discount' => $article->discount .'%',
                    'total' => number_format($article->total_price, 2, '.', "'")
                ];
            }

            self::$logger->log_user_action(
                'achat_generate_purchase_order_pdf',
                'articles_prepared',
                ['count' => count($articles)],
                $user_id
            );
        } else {
            self::$logger->log(
                'achat_generate_purchase_order_pdf',
                'ERROR: Aucun projet ou achat défini',
                $user_id
            );
            wp_die('Aucun projet ou achat de défini');
        }

        $title = __('Purchase order', 'creation-reservoir');
        $file_name = $title . '-' . $projectNum . '-' . $supplier_info['name'];
        $file_name = preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $file_name));

        self::$logger->log_user_action(
            'achat_generate_purchase_order_pdf',
            'file_name_prepared',
            ['file_name' => $file_name],
            $user_id
        );

        $pdf = apply_filters('ispag_generate_purchase_order_pdf', null, $project_header, $project_data, $infos, $table_header, $articles, $title);

        if ($pdf) {
            $title = sanitize_filename($title);
            $wp_upload_dir = wp_upload_dir();
            $uploadedfile = trailingslashit($wp_upload_dir['path']) . $title;
            $pdf->Output($uploadedfile, 'F');

            self::$logger->log_user_action(
                'achat_generate_purchase_order_pdf',
                'pdf_generated_and_saved',
                ['file_path' => $uploadedfile],
                $user_id
            );

            $attachment = [
                'guid' => trailingslashit($wp_upload_dir['url']) . basename($uploadedfile),
                'post_mime_type' => 'application/pdf',
                'post_title' => preg_replace('/\.[^.]+$/', '', basename($title)),
                'post_content' => '',
                'post_status' => 'inherit'
            ];

            $attach_id = wp_insert_attachment($attachment, $uploadedfile);
            self::$logger->log_db_change(
                'achat_generate_purchase_order_pdf',
                'wp_posts',
                'INSERT_ATTACHMENT',
                ['attach_id' => $attach_id, 'file' => $uploadedfile],
                $user_id
            );

            global $wpdb;
            $userId = get_current_user_id();
            $wpdb->insert(
                $wpdb->prefix . 'achats_historique',
                [
                    'hubspot_deal_id' => 0,
                    'purchase_order'  => $achat_id,
                    'Date'            => time(),
                    'dateReadable'    => current_time('mysql'),
                    'IdUser'          => $userId,
                    'Historique'      => 'Ajout d\'une pièce jointe',
                    'IdMedia'         => $attach_id,
                    'is_task'         => 0,
                    'is_done'         => 0,
                    'ClassCss'        => 'customer_order'
                ],
                [
                    '%d', // hubspot_deal_id
                    '%d', // purchase_order
                    '%d', // Date
                    '%s', // dateReadable
                    '%d', // IdUser
                    '%s', // Historique
                    '%d', // IdMedia
                    '%d', // is_task
                    '%d', // is_done
                    '%s'  // ClassCss
                ]
            );

            self::$logger->log_db_change(
                'achat_generate_purchase_order_pdf',
                'achats_historique',
                'INSERT_HISTORY',
                [
                    'achat_id' => $achat_id,
                    'attach_id' => $attach_id,
                    'user_id' => $userId
                ],
                $user_id
            );

            $pdf->Output('I', $file_name . '.pdf');
            self::$logger->log_user_action(
                'achat_generate_purchase_order_pdf',
                'pdf_output_to_browser',
                ['file_name' => $file_name . '.pdf'],
                $user_id
            );
            exit;
        } else {
            self::$logger->log(
                'achat_generate_purchase_order_pdf',
                'ERROR: Échec de la génération du PDF',
                $user_id
            );
        }
    }
}