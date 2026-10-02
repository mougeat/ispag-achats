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
        // Mise en page moderne du bon de commande (priorité 20 : remplace le gabarit « bulletin de livraison » du projet manager)
        add_filter('ispag_generate_purchase_order_pdf', [self::class, 'build_pdf'], 20, 7);
    }

    /**
     * Référence courte affichée dans le PDF : pour les articles sur mesure, le titre (très long) est déjà dans la description.
     * Types : 1 = cuve (ASP), 2 = isolation (ISO), 5 = échangeur à plaques (EPT).
     */
    private static function pdf_ref($article) {
        $short = [1 => 'ASP', 2 => 'ISO', 5 => 'EPT'];
        return $short[(int) ($article->Type ?? 0)] ?? $article->RefSurMesure;
    }

    /** Construit le PDF avec la mise en page dédiée au bon de commande. */
    public static function build_pdf($default, $project_header, $project_data, $infos, $table_header, $articles, $title) {
        if (!class_exists('ISPAG_PDF_Generator')) {
            $file = WP_PLUGIN_DIR . '/ispag-project-manager/classes/class-ispag-pdf-generator.php';
            if (file_exists($file)) require_once $file;
        }
        if (!class_exists('ISPAG_PDF_Generator')) {
            return $default;
        }
        $pdf = new ISPAG_Achat_Purchase_Order_PDF();
        $pdf->generate_purchase_order($project_header, $project_data, $infos, $table_header, $articles, $title);
        return $pdf;
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
                'Button not displayed: invalid order status (EtatCommande != 1)',
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
    public static function generate_purchase_order_pdf($return = false) {
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
                'ERROR: User not authorized (edit_supplier_order required)',
                $user_id
            );
            wp_die('Not authorized');
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
            // Adresse de livraison (table des infos de commande, via l'ID d'achat) : affichée dans le PDF si renseignée
            $project_data->delivery = $details_repo->get_infos_livraison($achat_id);
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

            // Nom du projet : celui du projet lié si disponible, sinon la partie après « - » de la référence d'achat
            if (!empty($project_data->hubspot_deal_id)) {
                $linked_project = apply_filters('ispag_get_project_by_deal_id', null, $project_data->hubspot_deal_id);
                if (!empty($linked_project->ObjetCommande)) {
                    $projectName = trim(stripslashes($linked_project->ObjetCommande));
                }
            }

            self::$logger->log_user_action(
                'achat_generate_purchase_order_pdf',
                'project_info_extracted',
                ['projectName' => $projectName, 'projectNum' => $projectNum],
                $user_id
            );

            $infos = [
                'nom_entreprise' => $supplier_info['name'],
                'AdresseDeLivraison' => $supplier_info['address'],
                'DeliveryAdresse2' => $supplier_info['address_2'] ?? '',
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
            $titre_project = __('Project name', 'creation-reservoir');
            $titre_ref = __('Order number', 'creation-reservoir');
            $titre_delivery_date = __('Order date', 'creation-reservoir');

            $table_header = [
                ['label' => __('Ref', 'creation-reservoir'), 'key' => 'ref', 'width' => 20],
                ['label' => __('Description', 'creation-reservoir'), 'key' => 'description', 'width' => 90],
                ['label' => __('Unit price', 'creation-reservoir'), 'key' => 'unitPrice', 'width' => 25, 'align' => 'R'],
                ['label' => __('Qty', 'creation-reservoir'), 'key' => 'qty', 'width' => 15, 'align' => 'C'],
                ['label' => __('Disc.', 'creation-reservoir'), 'key' => 'discount', 'width' => 15, 'align' => 'C'],
                ['label' => __('Total', 'creation-reservoir'), 'key' => 'total', 'width' => 25, 'align' => 'R'],
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
                    'ref' => self::pdf_ref($article),
                    'description' => $article->DescSurMesure, // nettoyé (HTML/entités) par le gabarit PDF
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
                'ERROR: No project or purchase defined',
                $user_id
            );
            wp_die('No project or purchase defined');
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
            // Nom de fichier propre à la commande, avec extension et unique : avant, tous les bons de commande
            // s'enregistraient sous le même nom « purchase-order » (sans .pdf) et s'écrasaient les uns les autres.
            $wp_upload_dir = wp_upload_dir();
            $stored_name   = wp_unique_filename($wp_upload_dir['path'], sanitize_file_name($file_name . '.pdf'));
            $title         = $stored_name;
            $uploadedfile  = trailingslashit($wp_upload_dir['path']) . $stored_name;
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
                    'Historique'      => 'Adding an attachment',
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

            if ($return) {
                // Utilisé par le brouillon Outlook : on renvoie le PDF (déjà enregistré dans la médiathèque et l'historique)
                return ['content' => $pdf->Output('S'), 'file_name' => $file_name . '.pdf'];
            }

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
                'ERROR: PDF generation failed',
                $user_id
            );
        }
    }
}