<?php
/**
 * Classe ISPAG_Achat_Document_Analyser
 *
 * Analyse les documents PDF (devis, factures, etc.) pour extraire les données d'achat.
 * Utilise Mistral pour l'analyse et le logging pour tracer toutes les actions.
 * Logging : Toutes les actions sont loguées dans ispag_achat_document_analyser.log.
 */
class ISPAG_Achat_Document_Analyser extends ISPAG_Document_Analyser {

    private static $data_to_confirm = false;
    private static $logger;

    /**
     * Initialise la classe et le logger.
     */
    public static function init() {
        self::$logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // self::$logger->log_user_action('achat_document_analyser', 'class_initialized', [], $user_id);

        add_action('wp_ajax_analyze_and_confirm_data', [self::class, 'handle_analyze_and_confirm_data']);
        add_action('wp_ajax_invoice_analyse', [self::class, 'handler_invoice_analyse']);
    }

    /**
     * Analyse le PDF et extrait les données via Mistral.
     *
     * @param string $html HTML du document (non utilisé ici).
     * @param string $file_path Chemin du fichier PDF.
     * @param int|null $deal_id ID de l'affaire Hubspot.
     * @param int|null $purchaseId ID de la commande d'achat.
     * @return array|null Données extraites ou null en cas d'erreur.
     */
    public static function analyze_pdf_keywords($html, $file_path, $deal_id = null, $purchaseId = null) {
        $user_id = get_current_user_id();
        self::$logger->log_user_action(
            'achat_document_analyser',
            'analyze_pdf_keywords_start',
            ['file_path' => $file_path, 'deal_id' => $deal_id, 'purchaseId' => $purchaseId],
            $user_id
        );

        if (!file_exists($file_path)) {
            self::$logger->log('achat_document_analyser', 'ERROR: Fichier PDF introuvable - ' . $file_path, $user_id);
            return null;
        }

        require_once WP_PLUGIN_DIR . '/ispag-project-manager/libs/pdfparser/autoload.php';

        $keywords = [
            'Durchmesser', 'Gesamthöhe', 'Volumen', 'Betriebsdruck',
            'Gesamtpreis netto', 'sendung', 'Lieferschein', 'Rechnung',
        ];

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file_path);
            $pages = $pdf->getPages();

            foreach ($pages as $index => $page) {
                $text = strtolower($page->getText());
                $found = false;

                foreach ($keywords as $word) {
                    if (strpos($text, strtolower($word)) !== false) {
                        $found = true;
                        self::$logger->log_db_change(
                            'achat_document_analyser',
                            'pdf_page',
                            'KEYWORD_FOUND',
                            ['page_index' => $index, 'keyword' => $word],
                            $user_id
                        );
                        break;
                    }
                }

                if ($found) {
                    $result = apply_filters('ispag_send_to_mistral', null, $text, 'purchase');
                    self::$logger->log_user_action(
                        'achat_document_analyser',
                        'mistral_analysis_requested',
                        ['page_index' => $index],
                        $user_id
                    );
                    return [$result];
                }
            }
        } catch (Exception $e) {
            self::$logger->log('achat_document_analyser', 'ERROR: Erreur lors de l\'analyse du PDF - ' . $e->getMessage(), $user_id);
        }

        self::$logger->log('achat_document_analyser', 'WARNING: Aucun mot-clé trouvé dans le PDF', $user_id);
        return null;
    }

    /**
     * Handler AJAX principal pour l'analyse et la confirmation des données.
     */
    public static function handle_analyze_and_confirm_data() {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('achat_document_analyser', 'handle_analyze_and_confirm_data_start', [], $user_id);

        if (!isset($_POST['docId'])) {
            self::$logger->log('achat_document_analyser', 'ERROR: ID document manquant', $user_id);
            wp_send_json_error('ID document manquant.');
        }

        $docId = intval($_POST['docId']);
        $file_path = get_attached_file($docId);
        $purchaseId = $_POST['purchaseId'] ?? null;

        self::$logger->log_user_action(
            'achat_document_analyser',
            'ajax_data_received',
            ['docId' => $docId, 'purchaseId' => $purchaseId],
            $user_id
        );

        $raw_response = self::analyze_pdf_with_visual_agent($file_path, $docId, 'purchase', $purchaseId);

        if (empty($raw_response)) {
            self::$logger->log('achat_document_analyser', 'ERROR: Extraction des données échouée ou format invalide', $user_id);
            wp_send_json_error('Extraction des données échouée ou format invalide.');
        }

        self::$logger->log_user_action(
            'achat_document_analyser',
            'data_extracted',
            ['raw_response' => $raw_response],
            $user_id
        );

        $normalized_tanks = self::normalize_gemini_data($raw_response);
        $existing_datas = self::get_existing_data($purchaseId);
        $datas_to_confirm = self::compare_data($normalized_tanks, $existing_datas);

        self::$logger->log_user_action(
            'achat_document_analyser',
            'data_comparison_complete',
            [
                'count_extracted' => count($normalized_tanks),
                'count_existing' => count($existing_datas),
                'count_to_confirm' => count($datas_to_confirm)
            ],
            $user_id
        );

        wp_send_json_success([
            'data' => $normalized_tanks,
            'existing_datas' => $existing_datas,
            'datas_to_confirm' => $datas_to_confirm,
            'needs_confirmation' => !empty($datas_to_confirm),
            'count_extracted' => count($normalized_tanks),
            'count_existing' => count($existing_datas),
        ]);
    }

    /**
     * Handler AJAX pour l'analyse de facture.
     */
    public static function handler_invoice_analyse() {
        global $wpdb;
        $user_id = get_current_user_id();
        self::$logger->log_user_action('achat_document_analyser', 'handler_invoice_analyse_start', [], $user_id);

        if (!isset($_POST['docId'])) {
            self::$logger->log('achat_document_analyser', 'ERROR: ID document manquant', $user_id);
            wp_send_json_error('ID document manquant.');
        }

        $docId = intval($_POST['docId']);
        $file_path = get_attached_file($docId);
        $purchaseId = isset($_POST['purchaseId']) ? intval($_POST['purchaseId']) : null;

        self::$logger->log_user_action(
            'achat_document_analyser',
            'invoice_analyse_data_received',
            ['docId' => $docId, 'purchaseId' => $purchaseId],
            $user_id
        );

        $raw_response = self::analyze_pdf_with_visual_agent($file_path, $docId, 'invoice_analyse', $purchaseId);

        if (empty($raw_response)) {
            self::$logger->log('achat_document_analyser', 'ERROR: Extraction des données de facture échouée', $user_id);
            wp_send_json_error('Extraction des données échouée ou format invalide.');
        }

        $data_extracted = is_string($raw_response) ? json_decode($raw_response, true) : $raw_response;

        if (!$data_extracted) {
            self::$logger->log('achat_document_analyser', 'ERROR: Format JSON invalide pour les données de facture', $user_id);
            wp_send_json_error('Le format JSON extrait est invalide.');
        }

        self::$logger->log_user_action(
            'achat_document_analyser',
            'invoice_data_extracted',
            ['data' => $data_extracted],
            $user_id
        );

        // --- MISE À JOUR DE LA BASE DE DONNÉES ---
        if (!empty($purchaseId)) {
            $table_name = 'wor9711_achats_commande_liste_fournisseurs';

            $current_data = $wpdb->get_row($wpdb->prepare(
                "SELECT delivery_number, invoice_number FROM $table_name WHERE id = %d",
                $purchaseId
            ));

            self::$logger->log_db_change(
                'achat_document_analyser',
                $table_name,
                'SELECT_CURRENT_DATA',
                ['purchaseId' => $purchaseId, 'current_data' => $current_data],
                $user_id
            );

            $delivery_input = $data_extracted['delivery_number'] ?? null;
            $invoice_input = $data_extracted['invoice_number'] ?? null;

            $final_delivery_str = null;
            $final_invoice_str = null;

            // 1. GESTION ET FUSION DES NUMÉROS DE LIVRAISON (ANTI-ÉCRASEMENT)
            if (!empty($delivery_input)) {
                $all_delivery_numbers = [];
                if (!empty($current_data->delivery_number)) {
                    $all_delivery_numbers = array_map('trim', explode(',', $current_data->delivery_number));
                }

                if (is_array($delivery_input)) {
                    foreach ($delivery_input as $num) {
                        $all_delivery_numbers[] = trim($num);
                    }
                } else {
                    $all_delivery_numbers[] = trim($delivery_input);
                }

                $all_delivery_numbers = array_unique(array_filter($all_delivery_numbers));
                $final_delivery_str = implode(', ', $all_delivery_numbers);
                self::$logger->log_db_change(
                    'achat_document_analyser',
                    $table_name,
                    'MERGE_DELIVERY_NUMBERS',
                    ['old' => $current_data->delivery_number ?? '', 'new' => $final_delivery_str],
                    $user_id
                );
            }

            // 2. GESTION ET FUSION DES NUMÉROS DE FACTURE (ANTI-ÉCRASEMENT)
            if (!empty($invoice_input)) {
                $all_invoice_numbers = [];
                if (!empty($current_data->invoice_number)) {
                    $all_invoice_numbers = array_map('trim', explode(',', $current_data->invoice_number));
                }

                if (is_array($invoice_input)) {
                    foreach ($invoice_input as $num) {
                        $all_invoice_numbers[] = trim($num);
                    }
                } else {
                    $all_invoice_numbers[] = trim($invoice_input);
                }

                $all_invoice_numbers = array_unique(array_filter($all_invoice_numbers));
                $final_invoice_str = implode(', ', $all_invoice_numbers);
                self::$logger->log_db_change(
                    'achat_document_analyser',
                    $table_name,
                    'MERGE_INVOICE_NUMBERS',
                    ['old' => $current_data->invoice_number ?? '', 'new' => $final_invoice_str],
                    $user_id
                );
            }

            // 3. PRÉPARATION DE L'UPDATE
            $data_to_update = [];

            if (!is_null($final_delivery_str)) {
                $data_to_update['delivery_number'] = $final_delivery_str;
            }
            if (!is_null($final_invoice_str)) {
                $data_to_update['invoice_number'] = $final_invoice_str;
            }

            // Nettoyage des valeurs nulles
            $data_to_update = array_filter($data_to_update, function($value) {
                return !is_null($value);
            });

            if (!empty($data_to_update)) {
                $updated = $wpdb->update(
                    $table_name,
                    $data_to_update,
                    array('Id' => $purchaseId),
                    null,
                    array('%d')
                );

                if ($updated === false) {
                    self::$logger->log('achat_document_analyser', 'ERROR: Échec de la mise à jour de la base de données - ' . $wpdb->last_error, $user_id);
                    wp_send_json_error('Erreur lors de la mise à jour de la base de données.');
                } else {
                    self::$logger->log_db_change(
                        'achat_document_analyser',
                        $table_name,
                        'UPDATE_SUCCESS',
                        ['purchaseId' => $purchaseId, 'data' => $data_to_update],
                        $user_id
                    );
                }
            }
        }

        wp_send_json_success([
            'data' => $data_extracted,
        ]);
    }

    /**
     * Analyse le PDF complet via l'agent visuel Mistral.
     *
     * @param string $file_path Chemin local du PDF.
     * @param int $doc_id ID WordPress du média.
     * @param string $analyseType Type d'analyse (ex: 'purchase', 'invoice_analyse').
     * @param int|null $purchaseId ID de la commande fournisseur.
     * @return array|null Données extraites ou null en cas d'erreur.
     */
    private static function analyze_pdf_with_visual_agent(string $file_path, int $doc_id, $analyseType = 'purchase', $purchaseId = null): ?array {
        $user_id = get_current_user_id();
        self::$logger->log_user_action(
            'achat_document_analyser',
            'analyze_pdf_with_visual_agent_start',
            ['file_path' => $file_path, 'analyseType' => $analyseType, 'purchaseId' => $purchaseId],
            $user_id
        );

        if (!file_exists($file_path)) {
            self::$logger->log('achat_document_analyser', 'ERROR: Fichier PDF introuvable - ' . $file_path, $user_id);
            return null;
        }

        $file_url = wp_get_attachment_url($doc_id);
        if (!$file_url) {
            self::$logger->log('achat_document_analyser', 'ERROR: URL du fichier introuvable pour doc_id - ' . $doc_id, $user_id);
            return null;
        }

        $prompt = "Voici un fichier à analyser :\n";
        $response_data = ISPAG_Mistral::send_to_mistral(null, $prompt, $analyseType, $file_url);

        if (empty($response_data)) {
            self::$logger->log('achat_document_analyser', 'ERROR: Réponse vide de Mistral', $user_id);
            return null;
        }

        if (is_string($response_data)) {
            $response_data = json_decode(self::clean_json_comments($response_data), true);
        }

        self::$logger->log_user_action(
            'achat_document_analyser',
            'mistral_response_received',
            ['response_data' => $response_data],
            $user_id
        );

        return is_array($response_data) ? $response_data : null;
    }

    /**
     * Récupère les données en base pour la commande.
     *
     * @param int|null $purchase_id ID de la commande.
     * @return array Données existantes.
     */
    public static function get_existing_data($purchase_id = null) {
        $user_id = get_current_user_id();
        self::$logger->log_user_action(
            'achat_document_analyser',
            'get_existing_data_start',
            ['purchase_id' => $purchase_id],
            $user_id
        );

        if (empty($purchase_id)) {
            self::$logger->log('achat_document_analyser', 'WARNING: purchase_id vide, retourne un tableau vide', $user_id);
            return [];
        }

        $articles = apply_filters('ispag_get_articles_by_order', null, $purchase_id);
        if (empty($articles)) {
            self::$logger->log_db_change(
                'achat_document_analyser',
                'articles',
                'NO_ARTICLES_FOUND',
                ['purchase_id' => $purchase_id],
                $user_id
            );
            return [];
        }

        $tank_datas = [];
        foreach ($articles as $article) {
            if ($article->Type == 1) {
                $extracted = apply_filters('ispag_get_tank_datas', [], $article->IdCommandeClient);
                if (!empty($extracted)) {
                    $tank_datas[] = [
                        'Id' => $article->IdCommandeClient,
                        'type' => $extracted['conception']->TankType ?? null,
                        'materiau' => $extracted['conception']->Material ?? null,
                        'support' => $extracted['conception']->Support ?? null,
                        'volume' => $extracted['dimensions']->Volume ?? null,
                        'diameter' => $extracted['dimensions']->Diameter ?? null,
                        'height' => $extracted['dimensions']->Height ?? null,
                        'max_pressure' => $extracted['dimensions']->MaxPressure ?? null,
                        'test_pressure' => $extracted['dimensions']->TestPressure ?? null,
                        'temperature' => $extracted['dimensions']->usingTemperature ?? null,
                        'clearance' => $extracted['dimensions']->GroundClearance ?? null,
                        'qty' => $article->Qty ?? null,
                        'sales_price' => $extracted['UnitPrice'] ?? null,
                    ];
                }
            }
        }

        self::$logger->log_db_change(
            'achat_document_analyser',
            'tank_datas',
            'EXTRACTED',
            ['purchase_id' => $purchase_id, 'count' => count($tank_datas)],
            $user_id
        );

        return $tank_datas;
    }

    /**
     * Compare les réservoirs extraits avec ceux en base.
     *
     * @param array $normalized_extracted_data Données extraites normalisées.
     * @param array $existing_datas Données existantes en base.
     * @return array Données à confirmer.
     */
    public static function compare_data($normalized_extracted_data, $existing_datas) {
        $user_id = get_current_user_id();
        self::$logger->log_user_action(
            'achat_document_analyser',
            'compare_data_start',
            [
                'count_extracted' => count($normalized_extracted_data),
                'count_existing' => count($existing_datas)
            ],
            $user_id
        );

        $datas_to_confirm = [];
        $already_matched = [];

        foreach ($normalized_extracted_data as $new_tank) {
            $match_found = false;
            $best_match_idx = null;

            foreach ($existing_datas as $idx => $existing_tank) {
                if (in_array($idx, $already_matched)) continue;

                $diameter_match = ($existing_tank['diameter'] ?? null) == ($new_tank['diameter'] ?? null);
                $type_match = ($existing_tank['type'] ?? null) == ($new_tank['type'] ?? null);

                if ($diameter_match && $type_match) {
                    $volume_match = empty($new_tank['volume'])
                        || ($existing_tank['volume'] ?? null) == ($new_tank['volume'] ?? null);

                    if ($volume_match) {
                        $best_match_idx = $idx;
                        break;
                    }

                    if ($best_match_idx === null) {
                        $best_match_idx = $idx;
                    }
                }
            }

            if ($best_match_idx !== null) {
                $match_found = true;
                $already_matched[] = $best_match_idx;
                $existing_tank = $existing_datas[$best_match_idx];

                $comparison = [];
                foreach ($new_tank as $key => $value) {
                    if ($key === 'Id') continue;
                    $match = ($existing_tank[$key] ?? null) == $value;
                    $comparison[] = [
                        'key' => $key,
                        'existing' => $existing_tank[$key] ?? '',
                        'new' => $value,
                        'match' => $match,
                    ];
                    if (!$match) {
                        self::$logger->log_db_change(
                            'achat_document_analyser',
                            'tank_comparison',
                            'FIELD_MISMATCH',
                            [
                                'tank_id' => $existing_tank['Id'],
                                'field' => $key,
                                'existing' => $existing_tank[$key] ?? '',
                                'new' => $value
                            ],
                            $user_id
                        );
                    }
                }

                $datas_to_confirm[] = [
                    'tank_id' => $existing_tank['Id'],
                    'titre' => $new_tank['titre'] ?? "Réservoir {$best_match_idx}",
                    'fields' => $comparison,
                ];
            }

            if (!$match_found) {
                $fields = [];
                foreach ($new_tank as $key => $value) {
                    if ($key === 'Id') continue;
                    $fields[] = ['key' => $key, 'existing' => '', 'new' => $value, 'match' => false];
                }
                $datas_to_confirm[] = [
                    'tank_id' => 'new',
                    'titre' => $new_tank['titre'] ?? 'Nouveau réservoir',
                    'fields' => $fields,
                ];
                self::$logger->log_user_action(
                    'achat_document_analyser',
                    'new_tank_detected',
                    ['titre' => $new_tank['titre'] ?? 'Nouveau réservoir'],
                    $user_id
                );
            }
        }

        self::$logger->log_user_action(
            'achat_document_analyser',
            'compare_data_complete',
            ['count_to_confirm' => count($datas_to_confirm)],
            $user_id
        );

        return $datas_to_confirm;
    }

    /**
     * Normalise la sortie pour avoir toujours un tableau de réservoirs.
     *
     * @param array $data Données brutes.
     * @return array Données normalisées.
     */
    public static function normalize_gemini_data($data) {
        $user_id = get_current_user_id();
        self::$logger->log_user_action(
            'achat_document_analyser',
            'normalize_gemini_data_start',
            ['data' => $data],
            $user_id
        );

        $tanks = [];

        if (isset($data['tanks'])) {
            $data = $data['tanks'];
        }

        foreach ($data as $key => $item) {
            if (is_array($item) && (isset($item['type']) || isset($item['diameter']) || isset($item['volume']))) {
                $tanks[] = $item;
            }
        }

        self::$logger->log_user_action(
            'achat_document_analyser',
            'normalize_gemini_data_complete',
            ['count_tanks' => count($tanks)],
            $user_id
        );

        return $tanks;
    }

    /**
     * Nettoie les commentaires HTML insérés par l'IA.
     *
     * @param string $json_string Chaîne JSON à nettoyer.
     * @return string Chaîne JSON nettoyée.
     */
    public static function clean_json_comments($json_string) {
        if (!is_string($json_string)) {
            return $json_string;
        }
        $json_string = preg_replace('/(?<!:)\/\/.*/', '', $json_string);
        return trim($json_string);
    }
}