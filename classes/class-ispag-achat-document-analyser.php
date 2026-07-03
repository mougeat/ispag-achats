<?php

class ISPAG_Achat_Document_Analyser extends ISPAG_Document_Analyser {

    private static $data_to_confirme = false;

    public static function init() {
        add_action('wp_ajax_analyze_and_confirm_data', [self::class, 'handle_analyze_and_confirm_data']);
        add_action('wp_ajax_invoice_analyse', [self::class, 'handler_invoice_analyse']);
    }

    /**
     * Analyse le PDF et extrait les données via Mistral
     */
    public static function analyze_pdf_keywords($html, $file_path, $deal_id = null, $purchaseId = null) {
        if (!file_exists($file_path)) {
            return null;
        }

        require_once WP_PLUGIN_DIR . '/ispag-project-manager/libs/pdfparser/autoload.php';

        $keywords = [
            'Durchmesser', 'Gesamthöhe', 'Volumen', 'Betriebsdruck',
            'Gesamtpreis netto', 'sendung', 'Lieferschein', 'Rechnung',
        ];

        $parser = new \Smalot\PdfParser\Parser();

        try {
            $pdf = $parser->parseFile($file_path);
            $pages = $pdf->getPages();

            foreach ($pages as $index => $page) {
                $text = strtolower($page->getText());
                $found = false;

                foreach ($keywords as $word) {
                    if (strpos($text, strtolower($word)) !== false) {
                        $found = true;
                        break;
                    }
                }

                if ($found) {
                    // On envoie le texte de la page à Mistral via le filtre
                    // Note: Ton filtre ISPAG_Mistral renvoie déjà un tableau décodé
                    $result = apply_filters('ispag_send_to_mistral', null, $text, 'purchase');
                    return [$result]; // On garde le format tableau pour la compatibilité
                }
            }
        } catch (Exception $e) {
            // error_log('❌ [ISPAG ACHAT] Erreur analyse PDF : ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Handler AJAX principal
     */
    // public static function handle_analyze_and_confirm_data() {
    //     if (!isset($_POST['docId'])) {
    //         wp_send_json_error('ID document manquant.');
    //     }

    //     $docId      = intval($_POST['docId']);
    //     $file_path  = get_attached_file($docId);
    //     $purchaseId = $_POST['purchaseId'] ?? null;

    //     // 1. Extraction des données
    //     $raw_response = self::analyze_pdf_keywords(null, $file_path, null, $purchaseId);

    //     // On récupère la donnée (soit tableau déjà décodé, soit string JSON)
    //     $data_extracted = is_array($raw_response) ? $raw_response[0] : $raw_response;

    //     // Si c'est encore une chaîne (au cas où), on décode proprement
    //     if (is_string($data_extracted)) {
    //         $data_extracted = json_decode(self::clean_json_comments($data_extracted), true);
    //     }

    //     if (empty($data_extracted)) {
    //         wp_send_json_error('Extraction des données échouée ou format invalide.');
    //     }

    //     // 2. Normalisation et Comparaison
    //     $normalized_tanks = self::normalize_gemini_data($data_extracted);
    //     $existing_datas   = self::get_existing_data($purchaseId);
    //     $datas_to_confirm = self::compare_data($normalized_tanks, $existing_datas);

    //     wp_send_json_success([
    //         'data'               => $normalized_tanks,
    //         'existing_datas'     => $existing_datas,
    //         'datas_to_confirm'   => $datas_to_confirm,
    //         'needs_confirmation' => !empty($datas_to_confirm)
    //     ]);
    // }

    /**
     * Handler AJAX principal
     */
    public static function handle_analyze_and_confirm_data() {
        if (!isset($_POST['docId'])) {
            wp_send_json_error('ID document manquant.');
        }

        $docId      = intval($_POST['docId']);
        $file_path  = get_attached_file($docId);
        $purchaseId = $_POST['purchaseId'] ?? null;

        $raw_response = self::analyze_pdf_with_visual_agent($file_path, $docId, 'purchse', $purchaseId);

        if (empty($raw_response)) {
            wp_send_json_error('Extraction des données échouée ou format invalide.');
        }

        $normalized_tanks = self::normalize_gemini_data($raw_response);
        $existing_datas   = self::get_existing_data($purchaseId);
        $datas_to_confirm = self::compare_data($normalized_tanks, $existing_datas);

        wp_send_json_success([
            'data'               => $normalized_tanks,       // tanks extraits du PDF
            'existing_datas'     => $existing_datas,         // tanks en base
            'datas_to_confirm'   => $datas_to_confirm,       // mapping proposé
            'needs_confirmation' => !empty($datas_to_confirm),
            'count_extracted'    => count($normalized_tanks), // utile pour le debug
            'count_existing'     => count($existing_datas),
        ]);
    }
    /**
     * Handler AJAX analyse de facture
     */
    public static function handler_invoice_analyse() {
        global $wpdb;

        if (!isset($_POST['docId'])) {
            wp_send_json_error('ID document manquant.');
        }

        $docId      = intval($_POST['docId']);
        $file_path  = get_attached_file($docId);
        $purchaseId = isset($_POST['purchaseId']) ? intval($_POST['purchaseId']) : null;

        $raw_response = self::analyze_pdf_with_visual_agent($file_path, $docId, 'invoice_analyse', $purchaseId);

        if (empty($raw_response)) {
            wp_send_json_error('Extraction des données échouée ou format invalide.');
        }

        $data_extracted = is_string($raw_response) ? json_decode($raw_response, true) : $raw_response;

        if (!$data_extracted) {
            wp_send_json_error('Le format JSON extrait est invalide.');
        }

        // --- MISE À JOUR DE LA BASE DE DONNÉES ---
        if (!empty($purchaseId)) {
            $table_name = 'wor9711_achats_commande_liste_fournisseurs';

            // Récupérer les valeurs actuelles en BDD en une seule requête pour optimiser
            $current_data = $wpdb->get_row($wpdb->prepare(
                "SELECT delivery_number, invoice_number FROM $table_name WHERE id = %d",
                $purchaseId
            ));

            $delivery_input = $data_extracted['delivery_number'] ?? null;
            $invoice_input  = $data_extracted['invoice_number'] ?? null;

            $final_delivery_str = null;
            $final_invoice_str  = null;

            // 1. GESTION ET FUSION DES NUMÉROS DE LIVRAISON (ANTI-ÉCRASEMENT)
            if (!empty($delivery_input)) {
                $all_delivery_numbers = [];
                if (!empty($current_data->delivery_number)) {
                    $all_delivery_numbers = array_map('trim', explode(',', $current_data->delivery_number));
                }

                if (is_array($delivery_input)) {
                    foreach ($delivery_input as $num) { $all_delivery_numbers[] = trim($num); }
                } else {
                    $all_delivery_numbers[] = trim($delivery_input);
                }

                $all_delivery_numbers = array_unique(array_filter($all_delivery_numbers));
                $final_delivery_str = implode(', ', $all_delivery_numbers);
            }

            // 2. GESTION ET FUSION DES NUMÉROS DE FACTURE (ANTI-ÉCRASEMENT)
            if (!empty($invoice_input)) {
                $all_invoice_numbers = [];
                if (!empty($current_data->invoice_number)) {
                    $all_invoice_numbers = array_map('trim', explode(',', $current_data->invoice_number));
                }

                if (is_array($invoice_input)) {
                    foreach ($invoice_input as $num) { $all_invoice_numbers[] = trim($num); }
                } else {
                    $all_invoice_numbers[] = trim($invoice_input);
                }

                $all_invoice_numbers = array_unique(array_filter($all_invoice_numbers));
                $final_invoice_str = implode(', ', $all_invoice_numbers);
            }

            // 3. PRÉPARATION DE L'UPDATE
            $data_to_update = [
                // 'numero_projet_commande' => $data_extracted['numero_projet_commande'] ?? null,
                // 'articles_json'          => isset($data_extracted['articles']) ? json_encode($data_extracted['articles']) : null,
                // 'facture_doc_id'         => $docId
            ];

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
                    array('Id' => $purchaseId), // Correction : 'id' en minuscule pour correspondre aux standards SQL classiques (ou 'Id' si c'est sensible à la casse chez toi)
                    null,
                    array('%d')
                );

                if ($updated === false) {
                    wp_send_json_error('Erreur lors de la mise à jour de la base de données.');
                }
            }
        }

        wp_send_json_success([
            'data' => $data_extracted,
        ]);
    }

    /**
     * Analyse le PDF complet via l'agent visuel Mistral.
     * Adapté depuis analyze_pdf_with_visual_agent() pour le contexte achat.
     *
     * @param string   $file_path   Chemin local du PDF
     * @param int      $doc_id      ID WordPress du média (pour récupérer l'URL)
     * @param int|null $purchaseId  ID de la commande fournisseur (contexte achat)
     */
    private static function analyze_pdf_with_visual_agent(string $file_path, int $doc_id, $analyseType = 'purchase', $purchaseId = null): ?array {
        if (!file_exists($file_path)) return null;

        $file_url = wp_get_attachment_url($doc_id);
        if (!$file_url) return null;


        // ── Construction du prompt (contexte achat) ───────────────────────────
        $prompt  = "Voici un fichier a analyser :\n";
        // ── Envoi à Mistral ───────────────────────────────────────────────────
        $response_data = ISPAG_Mistral::send_to_mistral(null, $prompt, $analyseType, $file_url);

        if (empty($response_data)) return null;

        // ── Décodage si la réponse est encore une chaîne JSON ────────────────
        if (is_string($response_data)) {
            $response_data = json_decode(self::clean_json_comments($response_data), true);
        }

        return is_array($response_data) ? $response_data : null;
    }

    /**
     * Récupère les données en base pour la commande
     */
    public static function get_existing_data($purchase_id = null) {
        if (empty($purchase_id)) return [];

        $articles = apply_filters('ispag_get_articles_by_order', null, $purchase_id);
        if (empty($articles)) return [];

        $tank_datas = [];
        foreach ($articles as $article) {
            if ($article->Type == 1) {
                $extracted = apply_filters('ispag_get_tank_datas', [], $article->IdCommandeClient);
                if (!empty($extracted)) {
                    $tank_datas[] = [
                        'Id'           => $article->IdCommandeClient,
                        'type'         => $extracted['conception']->TankType ?? null,
                        'materiau'     => $extracted['conception']->Material ?? null,
                        'support'      => $extracted['conception']->Support ?? null,
                        'volume'       => $extracted['dimensions']->Volume ?? null,
                        'diameter'     => $extracted['dimensions']->Diameter ?? null,
                        'height'       => $extracted['dimensions']->Height ?? null,
                        'max_pressure' => $extracted['dimensions']->MaxPressure ?? null,
                        'test_pressure'=> $extracted['dimensions']->TestPressure ?? null,
                        'temperature'  => $extracted['dimensions']->usingTemperature ?? null,
                        'clearance'    => $extracted['dimensions']->GroundClearance ?? null,
                        'qty'          => $article->Qty ?? null,
                        'sales_price'  => $extracted['UnitPrice'] ?? null,
                    ];
                }
            }
        }
        return $tank_datas;
    }

    /**
     * Compare les réservoirs extraits avec ceux en base
     */
    public static function compare_data($normalized_extracted_data, $existing_datas) {
        $datas_to_confirm = [];
        $already_matched  = []; // évite de matcher deux fois le même existing

        foreach ($normalized_extracted_data as $new_tank) {
            $match_found    = false;
            $best_match_idx = null;

            foreach ($existing_datas as $idx => $existing_tank) {
                if (in_array($idx, $already_matched)) continue;

                $diameter_match = ($existing_tank['diameter'] ?? null) == ($new_tank['diameter'] ?? null);
                $type_match     = ($existing_tank['type']     ?? null) == ($new_tank['type']     ?? null);

                // Critère principal : diamètre + type
                if ($diameter_match && $type_match) {
                    // Critère de départage : volume (si disponible)
                    $volume_match = empty($new_tank['volume'])
                        || ($existing_tank['volume'] ?? null) == ($new_tank['volume'] ?? null);

                    if ($volume_match) {
                        $best_match_idx = $idx;
                        break; // match parfait
                    }

                    // Match partiel — on garde quand même si pas de meilleur candidat
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
                    if ($key === 'Id') continue; // on ne compare pas l'ID extrait
                    $comparison[] = [
                        'key'      => $key,
                        'existing' => $existing_tank[$key] ?? '',
                        'new'      => $value,
                        'match'    => ($existing_tank[$key] ?? null) == $value,
                    ];
                }

                $datas_to_confirm[] = [
                    'tank_id' => $existing_tank['Id'],
                    'titre'   => $new_tank['titre'] ?? "Réservoir {$best_match_idx}",
                    'fields'  => $comparison,
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
                    'titre'   => $new_tank['titre'] ?? 'Nouveau réservoir',
                    'fields'  => $fields,
                ];
            }
        }

        return $datas_to_confirm;
    }

    /**
     * Normalise la sortie pour avoir toujours un tableau de réservoirs
     */
    public static function normalize_gemini_data($data) {
        $tanks = [];

        // Si Mistral a renvoyé { "tanks": { "1": {...}, "2": {...} } }
        if (isset($data['tanks'])) {
            $data = $data['tanks'];
        }

        // $data est maintenant [ "1" => [...], "2" => [...] ]
        // ou directement [ [...], [...] ]
        foreach ($data as $key => $item) {
            if (is_array($item) && (isset($item['type']) || isset($item['diameter']) || isset($item['volume']))) {
                // On conserve la clé du prompt pour le débogage si besoin
                $tanks[] = $item;
            }
        }

        return $tanks;
    }

    /**
     * Nettoie les commentaires HTML insérés par l'IA
     */
    public static function clean_json_comments($json_string) {
        if (!is_string($json_string)) return $json_string;
        // Supprime $json_string = preg_replace('//s', '', $json_string);
        // Supprime // commentaires de fin de ligne
        $json_string = preg_replace('/(?<!:)\/\/.*/', '', $json_string);
        return trim($json_string);
    }
}