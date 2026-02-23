<?php

class ISPAG_Achat_Status_Checker {
    protected static $table_etats;
    protected static $table_achats;
    protected static $new_status_id;
    protected static $table_suivi;

    public static function init() {
        global $wpdb;
        self::$table_etats  = $wpdb->prefix . 'achats_etat_commandes_fournisseur';
        self::$table_achats = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        self::$table_suivi = $wpdb->prefix . 'achats_suivi_phase_commande';

        // Hook CRON
        add_action('ispag_check_auto_status', [self::class, 'auto_status_checker']);
        add_action('ispag_check_achats_interventions', [self::class, 'ispag_check_achats_interventions_callback']);

        add_action('ispag_save_status_changes', [self::class, 'save_status_changes'], 10, 3);

        // Hook appelé lors de l’affichage du détail d’un achat
        add_action('ispag_check_auto_status_for_achat', function ($achat_id) {
            
            if (!empty($achat_id)) {
                self::auto_status_checker($achat_id);
            }
        });

        // CRON scheduler
        add_action('wp', [self::class, 'maybe_schedule_cron']);

        add_filter('cron_schedules', function($schedules) {
            $schedules['twicedaily'] = [
                'interval' => 12 * 60 * 60, // 12 heures en secondes
                'display' => __('Twice Daily')
            ];
            return $schedules;
        });
    }

    public static function maybe_schedule_cron() {
        if (!wp_next_scheduled('ispag_check_auto_status')) {
            wp_schedule_event(time(), 'fifteenminutes', 'ispag_check_auto_status'); // toutes les heures
        }
        if (!wp_next_scheduled('ispag_check_achats_interventions')) {
            wp_schedule_event(time(), 'hourly', 'ispag_check_achats_interventions');
        }
    }

    public static function activation_hook() {
        self::init();
        self::maybe_schedule_cron();

    }

    public static function deactivation_hook() {
        $timestamp = wp_next_scheduled('ispag_check_auto_status');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ispag_check_auto_status');
        }
        $timestamp = wp_next_scheduled('ispag_check_achats_interventions');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ispag_check_achats_interventions');
        }
    }

    public static function auto_status_checker($achat_id = null) {
        global $wpdb;

        $achats = $achat_id
            ? [$wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$table_achats . " WHERE Id = %d", $achat_id))]
            : $wpdb->get_results("SELECT * FROM " . self::$table_achats . " WHERE EtatCommande < 99");

        if (!$achats) return;

        $etats = $wpdb->get_results("SELECT * FROM " . self::$table_etats . " ORDER BY ordre ASC");

        foreach ($achats as $achat) {

            $current_status = intval($achat->EtatCommande);

            foreach ($etats as $i => $etat) {
                if (intval($etat->Id) === $current_status && isset($etats[$i + 1])) {
                    $next = $etats[$i + 1];

                    if (self::can_progress_to($achat, $next->Id)) {
                        self::update_auto_status($achat->Id, $next->ClassCss);
                    }

                    break;
                }
            }
        }
    }

    protected static function can_progress_to($achat, $next_status_id) {
        self::$new_status_id = $next_status_id;
        // Tu peux adapter selon les règles de ton process métier
        switch ($next_status_id) {
            case 14:
                if(self::search_doc_type($achat->Id, 'product_drawing')){
                    self::$new_status_id = 12;
                    return true;
                } elseif(self::search_doc_type($achat->Id, 'drawingApproval')){
                    self::$new_status_id = 14;
                    return true;
                }
            case 12: // Étape : Plan reçu
                return self::search_doc_type($achat->Id, 'product_drawing');
            case 15: // Étape : Plan reçu
                if(self::search_doc_type($achat->Id, 'drawingModification')){
                    self::$new_status_id = 15;
                    return true;
                } elseif(self::search_doc_type($achat->Id, 'drawingApproval')){
                    self::$new_status_id = 14;
                    return true;
                }
            case 3: // Étape : commande confirme
                return (self::search_doc_type_in_achat($achat->Id, 'ccmd') AND self::is_data_order_received($achat->Id, 'ConfCmdFournisseur'));
            case 5: // Étape : materiel facturé
                return (self::search_doc_type_in_achat($achat->Id, 'invoice') AND self::is_article_data_complete($achat->Id, 'Facture'));
            case 4: // Étape : materiel reçu
                return self::is_article_data_complete($achat->Id, 'Recu');
            case 18: // Étape : offre reçue
                return (self::is_article_data_complete($achat->Id, 'UnitPrice') AND (self::search_doc_type_in_achat($achat->Id, 'quotation') OR self::search_doc_type_in_achat($achat->Id, 'supplier_quotation')));
            default:
                return false;
        }
    }

    protected static function update_auto_status($achat_id, $slug = null) {
        $new_status_id = self::$new_status_id;
        global $wpdb;
        $result = $wpdb->update(
            self::$table_achats,
            ['EtatCommande' => $new_status_id],
            ['Id' => $achat_id],
            ['%d'],
            ['%d']
        );

        if($result){
            self::save_status_changes($achat_id, $slug, $new_status_id);
        }
    }

    protected static function is_data_order_received($achat_id, $data_search) {
        global $wpdb;

        if (empty($data_search) || empty($achat_id)) return false;

        $table_achats = $wpdb->prefix . 'achats_commande_liste_fournisseurs';

        $achat = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_achats} WHERE Id = %d",
            $achat_id
        ));

        if (!$achat) return false;

        return !empty($achat->$data_search);
    }



    protected static function search_doc_type_in_achat($achat_id, $doc_typ){

        global $wpdb;

        $table_historique     = $wpdb->prefix . 'achats_historique';

        $last_doc_type = $wpdb->get_var($wpdb->prepare(
            "SELECT ClassCss FROM {$table_historique}
            WHERE purchase_order = %d AND ClassCss = %s
            ORDER BY Date DESC
            LIMIT 1",
            $achat_id, $doc_typ
        ));


        // Si pas de document ou le dernier n’est pas un plan, on arrête
        if ($last_doc_type !== $doc_typ) {
            return false;
        }


        

        return true;

    }

    protected static function search_doc_type($achat_id, $doc_typ){

        global $wpdb;

        // $doc_typ = 'drawingApproval';        

        $table_articles_fourn = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $table_articles_proj  = $wpdb->prefix . 'achats_details_commande';
        $table_historique     = $wpdb->prefix . 'achats_historique';

        // Récupère tous les articles fournisseurs liés à la commande
        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT Id, IdCommandeClient FROM {$table_articles_fourn} WHERE IdCommande = %d",
            $achat_id
        ));

        if (!$articles) return false;
        

        foreach ($articles as $article) {
            $id_commande_client = intval($article->IdCommandeClient);

            // On récupère le dernier document (si existant) lié à cet article
            $last_doc_type = $wpdb->get_var($wpdb->prepare(
                "SELECT ClassCss FROM {$table_historique}
                WHERE Historique = %d
                ORDER BY Date DESC
                LIMIT 1",
                $id_commande_client
            ));


            // Si pas de document ou le dernier n’est pas un plan, on arrête
            if ($last_doc_type !== $doc_typ) {
                return false;
            }
        }

        

        return true;

    }

    protected static function is_article_data_complete($achat_id, $field_name) {
        global $wpdb;

        if (empty($field_name) || empty($achat_id)) return false;

        $table_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';

        // Récupère tous les articles liés à la commande fournisseur
        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_articles} WHERE IdCommande = %d",
            $achat_id
        ));

        if (!$articles) return false;

        foreach ($articles as $article) {
            if (!isset($article->$field_name)) return false;

            $value = trim((string)$article->$field_name);

            if ($value === '' || $value === '0' || $value === '0.00') {
                return false; // champ vide ou zéro
            }
        }

        return true;
    }

    public static function save_status_changes($achat_id = null, $slug = null, $status_id = null) {
        global $wpdb;

        // echo '<pre>';
        // var_dump($achat_id);
        // var_dump($slug);
        // var_dump($status_id);
        // echo '</pre>';

        if (!$achat_id || !$slug || !$status_id) {
            return false;
        }


        $result = $wpdb->insert(
            $wpdb->prefix . 'achats_suivi_phase_commande',
            [
                'purchase_id'        => $achat_id,
                'slug_phase'         => $slug,
                'status_id'          => $status_id,
                'date_modification'  => current_time('mysql'),
            ],
            [
                '%d', '%s', '%d', '%s'
            ]
        );

        return $result !== false;
    }

    public static function ispag_check_achats_interventions_callback() {
        global $wpdb;
        $intervention_ids = [1, 6, 15, 14];
        $base_url = trailingslashit(get_site_url()) . 'details-achats/';
        
        $log_file = WP_CONTENT_DIR . '/ispag_cron.log';
        $now_ts = time();
        $current_time_str = date('Y-m-d H:i:s');

        // On transforme le tableau [1, 6, 15, 14] en chaîne "1,6,15,14" pour le SQL
        $ids_string = implode(',', array_map('intval', $intervention_ids));

        // 1. Une seule requête avec "IN (...)"
        $query = $wpdb->prepare(
            "SELECT
                c.*,
                ec.Etat,
                c.Id
            FROM {$wpdb->prefix}achats_commande_liste_fournisseurs c
            LEFT JOIN {$wpdb->prefix}achats_etat_commandes_fournisseur ec ON ec.Id = c.EtatCommande
            WHERE c.EtatCommande IN ($ids_string) 
            AND (c.TimestampDateCreation IS NOT NULL AND c.TimestampDateCreation != 0)
            AND c.TimestampDateCreation <= %d",
            $now_ts
        );

        // Log de la requête unique
        error_log("[{$current_time_str}] Optimized SQL Query (IN $ids_string): " . $query . PHP_EOL, 3, $log_file);

        $achats = $wpdb->get_results($query);

        if (!empty($achats)) {
            error_log("[{$current_time_str}] Found " . count($achats) . " total results." . PHP_EOL, 3, $log_file);
            
            foreach ($achats as $achat) {
                // Ex: envoyer un mail, notifier un admin, mettre à jour un champ...
                $purchase_url = esc_url(add_query_arg('poid', $achat->Id, $base_url));
                $etat = __($achat->Etat, 'creation-reservoir');
                $ref_commande_echappee = ISPAG_Telegram_Notifier::escape_markdown_v2($achat->RefCommande);
                $etat_echappe = ISPAG_Telegram_Notifier::escape_markdown_v2($etat);
                $ref_commande_echappee = $achat->RefCommande;
                $etat_echappe = $etat;
                $msg = "🔍 Intervention requise pour la commande d'achat {$ref_commande_echappee} : *{$etat_echappe}*\n🌐 {$purchase_url}";
                do_action('ispag_send_telegram_notification', null, $msg, true, false, null, false, false);
                if(class_exists('ISPAG_OneSignal_Handler')){
                    ISPAG_OneSignal_Handler::send_os_push_notification(1, 'ACHATS', 'MESSAE DE TEST');
                }
            }
        }
    }

}
