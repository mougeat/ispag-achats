<?php

class ISPAG_Achat_Document_Analyser extends ISPAG_Document_Analyser {

    private static $data_to_confirme = false;

    public static function init() {
        add_action('wp_ajax_analyze_and_confirm_data', [self::class, 'handle_analyze_and_confirm_data']);
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
            error_log('❌ [ISPAG ACHAT] Erreur analyse PDF : ' . $e->getMessage());
        }
        return null;
    }

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

        // 1. Extraction des données
        $raw_response = self::analyze_pdf_keywords(null, $file_path, null, $purchaseId);
        
        // On récupère la donnée (soit tableau déjà décodé, soit string JSON)
        $data_extracted = is_array($raw_response) ? $raw_response[0] : $raw_response;

        // Si c'est encore une chaîne (au cas où), on décode proprement
        if (is_string($data_extracted)) {
            $data_extracted = json_decode(self::clean_json_comments($data_extracted), true);
        }

        if (empty($data_extracted)) {
            wp_send_json_error('Extraction des données échouée ou format invalide.');
        }

        // 2. Normalisation et Comparaison
        $normalized_tanks = self::normalize_gemini_data($data_extracted);
        $existing_datas   = self::get_existing_data($purchaseId);
        $datas_to_confirm = self::compare_data($normalized_tanks, $existing_datas);

        wp_send_json_success([
            'data'               => $normalized_tanks,
            'existing_datas'     => $existing_datas,
            'datas_to_confirm'   => $datas_to_confirm,
            'needs_confirmation' => !empty($datas_to_confirm)
        ]);
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

        foreach ($normalized_extracted_data as $new_tank) {
            $match_found = false;

            foreach ($existing_datas as $existing_tank) {
                // Critères de correspondance : diamètre et type (ou volume)
                if (
                    ($existing_tank['diameter'] ?? null) == ($new_tank['diameter'] ?? null) &&
                    ($existing_tank['type'] ?? null) == ($new_tank['type'] ?? null)
                ) {
                    $match_found = true;
                    $comparison = [];
                    foreach ($new_tank as $key => $value) {
                        $comparison[] = [
                            'key'      => $key,
                            'existing' => $existing_tank[$key] ?? '',
                            'new'      => $value,
                            'match'    => ($existing_tank[$key] ?? null) == $value
                        ];
                    }
                    $datas_to_confirm[] = [
                        'tank_id' => $existing_tank['Id'],
                        'fields'  => $comparison
                    ];
                    break;
                }
            }

            // Si aucune correspondance n'est trouvée pour ce réservoir extrait
            if (!$match_found) {
                $fields = [];
                foreach ($new_tank as $key => $value) {
                    $fields[] = ['key' => $key, 'existing' => '', 'new' => $value, 'match' => false];
                }
                $datas_to_confirm[] = ['tank_id' => 'new', 'fields' => $fields];
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

        foreach ($data as $item) {
            if (is_array($item) && (isset($item['type']) || isset($item['diameter']))) {
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