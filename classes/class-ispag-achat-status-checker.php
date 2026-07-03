<?php

/**
 * Classe ISPAG_Achat_Status_Checker
 * 
 * Gère la progression automatique des statuts des commandes fournisseurs.
 * Basée sur la version fonctionnelle fournie, avec correction pour les étapes de plans (12, 13, 14, 15).
 */
class ISPAG_Achat_Status_Checker {
    protected static $table_etats;
    protected static $table_achats;
    protected static $table_suivi;

    public static function init() {
        global $wpdb;
        self::$table_etats  = $wpdb->prefix . 'achats_etat_commandes_fournisseur';
        self::$table_achats = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        self::$table_suivi  = $wpdb->prefix . 'achats_suivi_phase_commande';

        add_action('ispag_check_auto_status',              [self::class, 'auto_status_checker']);
        add_action('ispag_check_achats_interventions',     [self::class, 'ispag_check_achats_interventions_callback']);
        add_action('ispag_save_status_changes',            [self::class, 'save_status_changes'], 10, 3);
        add_action('ispag_check_auto_status_for_achat',    function($achat_id) {
            if (!empty($achat_id)) self::auto_status_checker($achat_id);
        });

        add_action('wp', [self::class, 'maybe_schedule_cron']);

        add_filter('cron_schedules', function($schedules) {
            $schedules['twicedaily'] = [
                'interval' => 12 * 60 * 60,
                'display'  => __('Twice Daily'),
            ];
            return $schedules;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRON
    // ─────────────────────────────────────────────────────────────────────────

    public static function maybe_schedule_cron() {
        if (!wp_next_scheduled('ispag_check_auto_status')) {
            wp_schedule_event(time(), 'fifteenminutes', 'ispag_check_auto_status');
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
        foreach (['ispag_check_auto_status', 'ispag_check_achats_interventions'] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) wp_unschedule_event($timestamp, $hook);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REGISTRE DÉCLARATIF DES RÈGLES DE PROGRESSION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne les règles de progression vers chaque statut.
     * 
     * Correction :
     * - Étape 14 passe à 13 (drawing_validated) si drawingApproval est reçu.
     * - Étape 13 est ajoutée pour gérer drawing_validated.
     * - Les étapes 12, 13, 14, 15 peuvent revenir en arrière entre elles.
     */
    protected static function get_progression_registry(): array {
        return [
            // ── Plan reçu (12) ───────────────────────────────────────────────
            12 => [
                'resolver' => function($achat_id) {
                    if (self::search_doc_type($achat_id, 'product_drawing')) return 12;
                    return false;
                },
            ],

            // ── Plan validé (13) ────────────────────────────────────────────
            13 => [
                'resolver' => function($achat_id) {
                    if (self::search_doc_type($achat_id, 'drawing_validated')) return 13;
                    return false;
                },
            ],

            // ── Validation client reçue (14) → passe à 13 (drawing_validated) si drawingApproval
            14 => [
                'resolver' => function($achat_id) {
                    if (self::search_doc_type($achat_id, 'product_drawing'))  return 12;
                    if (self::search_doc_type($achat_id, 'drawingApproval'))  return 13; // Passe à 13 (drawing_validated)
                    return false;
                },
            ],

            // ── Modification de plan demandée (15) ───────────────────────────
            15 => [
                'resolver' => function($achat_id) {
                    if (self::search_doc_type($achat_id, 'drawingModification')) return 15;
                    if (self::search_doc_type($achat_id, 'drawingApproval'))     return 13; // Passe à 13 (drawing_validated)
                    if (self::search_doc_type($achat_id, 'product_drawing'))    return 12;
                    return false;
                },
            ],

            // ── Commande confirmée (3) ────────────────────────────────────────
            3 => [
                'resolver' => function($achat_id) {
                    if (
                        self::search_doc_type_in_achat($achat_id, 'ccmd') &&
                        self::is_data_order_received($achat_id, 'ConfCmdFournisseur')
                    ) return 3;
                    return false;
                },
            ],

            // ── Matériel reçu (4) ─────────────────────────────────────────────
            4 => [
                'resolver' => function($achat_id) {
                    if (self::is_article_data_complete($achat_id, 'Recu')) return 4;
                    return false;
                },
            ],

            // ── Matériel facturé (5) ──────────────────────────────────────────
            5 => [
                'resolver' => function($achat_id) {
                    if (
                        self::search_doc_type_in_achat($achat_id, 'invoice') &&
                        self::is_article_data_complete($achat_id, 'Facture')
                    ) return 5;
                    return false;
                },
            ],

            // ── Offre reçue (18) ──────────────────────────────────────────────
            18 => [
                'resolver' => function($achat_id) {
                    $has_price    = self::is_article_data_complete($achat_id, 'UnitPrice');
                    $has_quotation = self::search_doc_type_in_achat($achat_id, 'quotation')
                                  || self::search_doc_type_in_achat($achat_id, 'supplier_quotation');

                    if ($has_price && $has_quotation) return 18;
                    return false;
                },
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ORCHESTRATEUR
    // ─────────────────────────────────────────────────────────────────────────

    public static function auto_status_checker($achat_id = null) {
        global $wpdb;

        $achats = $achat_id
            ? [$wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$table_achats . " WHERE Id = %d", $achat_id))]
            : $wpdb->get_results("SELECT * FROM " . self::$table_achats . " WHERE EtatCommande < 99");

        if (!$achats) return;

        $etats    = $wpdb->get_results("SELECT * FROM " . self::$table_etats . " ORDER BY ordre ASC");
        $registry = self::get_progression_registry();

        foreach ($achats as $achat) {
            $current_status = (int)$achat->EtatCommande;

            // On cherche le statut suivant dans la liste ordonnée
            foreach ($etats as $i => $etat) {
                if ((int)$etat->Id !== $current_status) continue;
                if (!isset($etats[$i + 1])) break;

                $next_id   = (int)$etats[$i + 1]->Id;
                $next_slug = $etats[$i + 1]->ClassCss;

                if (!isset($registry[$next_id])) break;

                $resolved = ($registry[$next_id]['resolver'])($achat->Id);

                // --- CORRECTION : Autoriser les sauts entre 12, 13, 14, 15 (plans) ---
                if ($resolved !== false) {
                    // Si la nouvelle étape est une étape de plan (12, 13, 14, 15), autoriser le saut
                    if (in_array($resolved, [12, 13, 14, 15]) || in_array($current_status, [12, 13, 14, 15])) {
                        self::update_auto_status($achat->Id, $next_slug, $resolved);
                    }
                    // Sinon, vérifier que la nouvelle étape n'est pas antérieure
                    elseif ($resolved >= $current_status) {
                        self::update_auto_status($achat->Id, $next_slug, $resolved);
                    }
                }

                break;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MISE À JOUR DU STATUT
    // ─────────────────────────────────────────────────────────────────────────

    protected static function update_auto_status(int $achat_id, string $slug, int $new_status_id) {
        global $wpdb;

        $result = $wpdb->update(
            self::$table_achats,
            ['EtatCommande' => $new_status_id],
            ['Id'           => $achat_id],
            ['%d'],
            ['%d']
        );

        if ($result) {
            self::save_status_changes($achat_id, $slug, $new_status_id);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VÉRIFICATIONS MÉTIER
    // ─────────────────────────────────────────────────────────────────────────

    protected static function is_data_order_received($achat_id, $data_search) {
        global $wpdb;

        if (empty($data_search) || empty($achat_id)) return false;

        $achat = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$table_achats . " WHERE Id = %d",
            $achat_id
        ));

        return $achat && !empty($achat->$data_search);
    }

    protected static function search_doc_type_in_achat($achat_id, $doc_type) {
        global $wpdb;

        $table_historique = $wpdb->prefix . 'achats_historique';

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT ClassCss FROM {$table_historique}
             WHERE purchase_order = %d AND ClassCss = %s
             ORDER BY dateReadable DESC LIMIT 1",
            $achat_id, $doc_type
        ));

        return $found === $doc_type;
    }

    /**
     * Vérifie que le dernier document de CHAQUE article de la commande est du type attendu.
     */
    protected static function search_doc_type($achat_id, $doc_type) {
        global $wpdb;

        $table_articles_fourn = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $table_historique     = $wpdb->prefix . 'achats_historique';

        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT Id, IdCommandeClient FROM {$table_articles_fourn} WHERE IdCommande = %d",
            $achat_id
        ));

        if (!$articles) return false;

        foreach ($articles as $article) {
            $last_doc = $wpdb->get_var($wpdb->prepare(
                "SELECT ClassCss FROM {$table_historique}
                 WHERE Historique = %d ORDER BY dateReadable DESC LIMIT 1",
                (int)$article->IdCommandeClient
            ));

            if ($last_doc !== $doc_type) return false;
        }

        return true;
    }

    protected static function is_article_data_complete($achat_id, $field_name) {
        global $wpdb;

        if (empty($field_name) || empty($achat_id)) return false;

        $table_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';

        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_articles} WHERE IdCommande = %d",
            $achat_id
        ));

        if (!$articles) return false;

        foreach ($articles as $article) {
            if (!isset($article->$field_name)) return false;

            $value = trim((string)$article->$field_name);

            if ($value === '' || $value === '0' || $value === '0.00') return false;
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SUIVI
    // ─────────────────────────────────────────────────────────────────────────

    public static function save_status_changes($achat_id = null, $slug = null, $status_id = null) {
        global $wpdb;

        if (!$achat_id || !$slug || !$status_id) return false;

        $result = $wpdb->insert(
            $wpdb->prefix . 'achats_suivi_phase_commande',
            [
                'purchase_id'       => $achat_id,
                'slug_phase'        => $slug,
                'status_id'         => $status_id,
                'date_modification' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s']
        );

        return $result !== false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NOTIFICATIONS D'INTERVENTION
    // ─────────────────────────────────────────────────────────────────────────

    public static function ispag_check_achats_interventions_callback() {
        global $wpdb;

        $intervention_ids = [1, 6, 15, 14];
        $base_url         = trailingslashit(get_site_url()) . 'details-achats/';
        $now_ts           = time();
        $ids_string       = implode(',', array_map('intval', $intervention_ids));

        $achats = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, ec.Etat, c.Id
             FROM {$wpdb->prefix}achats_commande_liste_fournisseurs c
             LEFT JOIN {$wpdb->prefix}achats_etat_commandes_fournisseur ec ON ec.Id = c.EtatCommande
             WHERE c.EtatCommande IN ($ids_string)
             AND (c.TimestampDateCreation IS NOT NULL AND c.TimestampDateCreation != 0)
             AND c.TimestampDateCreation <= %d",
            $now_ts
        ));

        if (empty($achats)) return;

        foreach ($achats as $achat) {
            $purchase_url  = esc_url(add_query_arg('poid', $achat->Id, $base_url));
            $etat          = __($achat->Etat, 'creation-reservoir');
            $ref           = $achat->RefCommande;
            $titre_notif   = "🔍 Intervention requise pour la commande d'achat {$ref}";
            $msg_notif     = "Status : {$etat}";
            $msg_telegram  = "🔍 Intervention requise pour la commande d'achat {$ref} : *{$etat}*\n🌐 {$purchase_url}";

            if (class_exists('ISPAG_OneSignal_Handler')) {
                ISPAG_OneSignal_Handler::send_os_push_notification(
                    'WP_1',
                    $titre_notif,
                    $msg_notif,
                    'purchase',
                    $achat->Id
                );
            }

            do_action('ispag_send_telegram_notification', null, $msg_telegram, true, false, null, false, false);
        }
    }
}