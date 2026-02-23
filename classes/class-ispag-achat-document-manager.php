<?php
// Dans ton plugin achats
// require_once WP_PLUGIN_DIR . '/ispag-project-manager/classes/class-ispag-document-manager.php';

// class ISPAG_Achat_Document_Manager extends ISPAG_Document_Manager {

//     private static $data_to_confirme = false;

//     public static function init() {
//         // add_action('ispag_achat_analyze_pdf_keywords', [self::class, 'analyze_pdf_keywords'], 10, 3);
//         add_action('wp_ajax_analyze_and_confirm_data', [self::class, 'handle_analyze_and_confirm_data']);
//     }
//     public static function analyze_pdf_keywords($html, $file_path, $deal_id = null, $purchaseId = null) {
//         // error_log('📄 [DEBUG ACHAT] Entrée dans analyze_pdf_keywords');
//         // error_log('📄 [DEBUG ACHAT] file_path: ' . $file_path);
//         // error_log('📄 [DEBUG ACHAT] deal_id: ' . $deal_id);
//         // error_log('📄 [DEBUG ACHAT] purchaseId: ' . $purchaseId);

//         if (!file_exists($file_path)) {
// // \1('❌ [DEBUG ACHAT] Fichier PDF introuvable : ' . $file_path);
//             return;
//         }

//         require_once WP_PLUGIN_DIR . '/ispag-project-manager/libs/pdfparser/autoload.php';
//         // error_log('📄 [DEBUG ACHAT] PDF Parser chargé');

//         // 🆕 Mots-clés spécifiques pour le module achats
//         $keywords = [
//             'Durchmesser',
//             'Gesamthöhe',
//             'Volumen',
//             'Betriebsdruck',
//             'Gesamtpreis netto',
//             'sendung',
//             'Lieferschein',
//             'Rechnung',
//         ];
//         // error_log('📄 [DEBUG ACHAT] Mots-clés utilisés : ' . implode(', ', $keywords));

//         $parser = new \Smalot\PdfParser\Parser();

//         try {
//             $pdf = $parser->parseFile($file_path);
//             // error_log('📄 [DEBUG ACHAT] PDF analysé avec succès');

//             $pages = $pdf->getPages();
//             // error_log('📄 [DEBUG ACHAT] Nombre de pages trouvées : ' . count($pages));

//             $pages_with_keywords = [];
//             $summary_lines = [];

//             foreach ($pages as $index => $page) {
//                 // error_log("🔍 [DEBUG ACHAT] Analyse de la page " . ($index + 1));
//                 $text = strtolower($page->getText());
//                 $found_keywords = [];

//                 // foreach ($keywords as $word) {
//                 //     if (strpos($text, strtolower($word)) !== false) {
//                 //         $found_keywords[] = $word;
//                 //         error_log("✅ [DEBUG ACHAT] Mot-clé trouvé : {$word} (page " . ($index + 1) . ")");
//                 //     }
//                 // }

//                 // if (!empty($found_keywords)) {
//                 //     $label = implode(', ', $found_keywords);
//                 //     $summary_lines[] = "Mots-clés trouvés à la page " . ($index + 1) . " : {$label}";

//                 //     $pages_with_keywords[$index] = [
//                 //         'text' => $text,
//                 //         'keywords' => $found_keywords
//                 //     ];
//                 // }

//                 foreach ($keywords as $word) {
//                     // On cherche en insensible à la casse et on capture ce qui suit (ex: "Durchmesser 50 mm")
//                     if (preg_match('/' . preg_quote(strtolower($word), '/') . '\s*([0-9,.]+(?:\s*\w+)*)/u', $text, $matches)) {
//                         $value = trim($matches[1]);
//                         $found_keywords[] = "{$word}: {$value}";
//                         // error_log("✅ [DEBUG ACHAT] Mot-clé trouvé : {$word} => {$value} (page " . ($index + 1) . ")");
//                     }
//                 }
//                 if (!empty($found_keywords)) {
//                     $label = implode(', ', $found_keywords);
//                     $summary_lines[] = "Page " . ($index + 1) . " : {$label}";
//                     $pages_with_keywords[$index] = [
//                         'text' => $text,
//                         'keywords' => $found_keywords
//                     ];
//                 }
//             }

//             if (!empty($summary_lines)) {
//                 foreach ($pages_with_keywords as $index => $page_data) {
//                     // error_log("Parcours de la page index {$index}");
//                     $data = [];
//                     $page_text = $page_data['text'];
//                     $keywords_found = $page_data['keywords'];
//                     $keyword_label = implode('/', $keywords_found); 

//                     $data[] = apply_filters('ispag_send_to_mistral', null, $page_text, 'purchase'); 
//                     // error_log("Données reçues après le filtre ispag_send_to_mistral : " . print_r($data, true));
//                     return $data;
//                 }
//             } else {
//                 // error_log('ℹ️ [DEBUG ACHAT] Aucun mot-clé trouvé dans ce document.');
//             }

//         } catch (Exception $e) {
// // \1('❌ [DEBUG ACHAT] Erreur analyse PDF achats : ' . $e->getMessage());
//         }
//     }

//     public static function handle_analyze_and_confirm_data() {
        
//         $docId = $_POST['docId']; // Récupérez le chemin du fichier depuis la requête AJAX
//         $file_path = get_attached_file($docId);
//         $deal_id = $_POST['deal_id'];
//         $purchaseId = $_POST['purchaseId'];
//         // error_log('handle_analyze_and_confirm_data : ' . print_r($_POST, true));

//         // Simuler l'appel à votre fonction existante
//         $response_data = self::analyze_pdf_keywords(null, $file_path, $deal_id, $purchaseId);
//         // error_log("Donnée extraites : " . print_r($response_data, true));
//         // TODO: Ajoutez ici la logique pour comparer $extracted_data avec les données déjà enregistrées
//         // error_log("recherche des données existante dans achat  : " . $purchaseId);
//         $existing_datas = self::get_existing_data($purchaseId); // Une fonction qui récupère les données actuelles
//         // error_log("Donnée existantes extraites : " . print_r($existing_datas, true));
//         // error_log("Debut de la comparaison : ");
//         $datas_to_confirm = self::compare_data($response_data, $existing_datas); 
//         // error_log("Donnée à confirmer : " . print_r($datas_to_confirm, true));

//         if ($response_data) {
//             wp_send_json_success([
//                 'data'                  => $response_data,
//                 'existing_datas'        => $existing_datas,
//                 'datas_to_confirm'      => $datas_to_confirm,
//                 'needs_confirmation'    => true
//             ]);
//         } else {
//             wp_send_json_error('Extraction des données échouée.');
//         }
//     }

//     public static function get_existing_data($purchase_id = null){
        
//         if(empty($purchase_id)){ 
//             return []; 
//         }

//         // Récupère les articles liés à la commande d'achat.
//         $articles = apply_filters('ispag_get_articles_by_order', null, $purchase_id);
        
//         // Si aucun article n'est trouvé, retourne un tableau vide.
//         if (empty($articles)) {
//             return [];
//         }

//         $tank_datas = [];
//         foreach ($articles as $article) {
//             // Le type '1' est-il bien celui des réservoirs ? Assurez-vous que cette condition est correcte.
//             if($article->Type == 1){ 
                
//                 $tank_data = [];

//                 // 3. Extrait toutes les données du réservoir.
//                 // Utilisez l'ID de l'article si le filtre ispag_get_tank_datas en a besoin.
//                 $extracted_datas = apply_filters('ispag_get_tank_datas', [], $article->IdCommandeClient);

//                 // 4. Vérifie que les données sont bien présentes avant de les affecter.
//                 if (!empty($extracted_datas)) {
//                     // error_log('get_existing_data --> extracted datas ' . $article->IdCommandeClient . ' :' . print_r($extracted_datas, true));
//                     $tank_data['type']                              = $extracted_datas['conception']->TankType ?? null;
//                     $tank_data['materiau']                          = $extracted_datas['conception']->Material ?? null;
//                     $tank_data['support']                           = $extracted_datas['conception']->Support ?? null;
//                     $tank_data['volume']                            = $extracted_datas['dimensions']->Volume ?? null;
//                     $tank_data['diameter']                          = $extracted_datas['dimensions']->Diameter ?? null;
//                     $tank_data['height']                            = $extracted_datas['dimensions']->Height ?? null;
//                     $tank_data['max_pressure']                      = $extracted_datas['dimensions']->MaxPressure ?? null;
//                     $tank_data['test_pressure']                     = $extracted_datas['dimensions']->TestPressure ?? null;
//                     $tank_data['qty']                               = $article->Qty ?? null;
//                     $tank_data['temperature']                       = $extracted_datas['dimensions']->usingTemperature ?? null;
//                     $tank_data['clearance']                         = $extracted_datas['dimensions']->GroundClearance ?? null;
//                     $tank_data['sales_price']                       = $extracted_datas['UnitPrice'] ?? null;
//                     $tank_data['date_depart']                       = $extracted_datas['TimestampDateLivraisonConfirme'] ?? null;
//                     $tank_data['Id']                                = $article->IdCommandeClient ?? null;

//                     $tank_datas[] = $tank_data;
//                 }
//             }
//         } 
//         // error_log('get_tank_datas ' . $article->IdCommandeClient . ' :' . print_r($tank_datas, true));
//         return $tank_datas;
//     }

//     public static function compare_data($extracted_data, $existing_datas) {
// // \1('extracted datas (brut) :' . print_r($extracted_data, true));
// // \1('existing datas :' . print_r($existing_datas, true));

//         // 1. Normalise les données extraites pour obtenir un format fiable.
//         $normalized_extracted_data = self::normalize_gemini_data($extracted_data);
//         $datas_to_confirm = [];
// // \1('extracted datas (normalized) :' . print_r($normalized_extracted_data, true));

//         // 2. Parcourt les données existantes.
//         foreach ($existing_datas as $existing_tank_data) {
//             // 3. Parcourt les données normalisées extraites pour trouver une correspondance.
//             foreach ($normalized_extracted_data as $new_tank_data) {
//                 // Assure-toi que les deux ensembles de données sont des tableaux
//                 // et que les clés existent pour éviter les erreurs.
//                 if (
//                     is_array($existing_tank_data) && is_array($new_tank_data) &&
//                     ($existing_tank_data['type'] ?? null) == ($new_tank_data['type'] ?? null)
//                         &&
//                     ($existing_tank_data['materiau'] ?? null) == ($new_tank_data['materiau'] ?? null)
//                     //     &&
//                     // ($existing_tank_data['support'] ?? null) == ($new_tank_data['support'] ?? null)
//                         &&
//                     ($existing_tank_data['diameter'] ?? null) == ($new_tank_data['diameter'] ?? null)
//                 ) {
//                     $new_tank_data['Id'] = $existing_tank_data['Id'];
//                     error_log('SUCCESSSS^(?!.*//).*error_logSSSSSSSSS WE FOUND A TANK !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
//                     self::$data_to_confirme = true;
//                     foreach ($existing_tank_data as $key => $value) {
//                         $datas_to_confirm[] = [
//                             'key' => $key,
//                             'existing' => $value,
//                             'new' => $new_tank_data[$key],
//                         ];
//                     }
//                     return $datas_to_confirm;

//                 }
//                 else{
// // \1('Pas de correspondance trouvée');
//                     $new_tank_data['Id'] = $existing_tank_data['Id'];
//                     foreach ($new_tank_data as $key => $value) {
//                         $datas_to_confirm[] = [
//                             'key' => $key,
//                             'existing' => '',
//                             'new' => $value,
//                         ];
//                     }
//                     return $datas_to_confirm;
//                 }
//             }
//         }
//         return [];
//     }

//     /**
//      * Normalise la structure de données extraites par Gemini.
//      * @param array $extracted_data La donnée potentiellement mal formatée.
//      * @return array Un tableau de données de réservoirs uniforme et simple.
//      */
//     public static function normalize_gemini_data($extracted_data) {
//         // Si la donnée est déjà un tableau simple (ex: [ { ... } ]), on la retourne telle quelle.
//         if (isset($extracted_data[0]) && is_array($extracted_data[0]) && isset($extracted_data[0]['type'])) {
//             return $extracted_data;
//         }

//         $normalized_tanks = [];

//         // Gère le cas où l'objet est imbriqué dans des clés numériques (ex: [0] => [1] => { ... })
//         foreach ($extracted_data as $top_level_array) {
//             if (is_array($top_level_array)) {
//                 foreach ($top_level_array as $tank_data) {
//                     if (is_array($tank_data) && isset($tank_data['type'])) {
//                         $normalized_tanks[] = $tank_data;
//                     }
//                 }
//             }
//         }
//         return $normalized_tanks;
//     }
// }
