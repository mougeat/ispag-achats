<?php
/**
 * Classe ISPAG_Achat_Status_Checker
 *
 * Gère la progression automatique des statuts des commandes fournisseurs.
 *
 * Règles :
 * - Le flux suit chronologiquement la colonne 'ordre' de la table des états.
 * - Les étapes automatiques (is_automatic = 1) peuvent déclencher des transitions.
 * - Les étapes de plans (12, 14, 15, 16) peuvent revenir en arrière entre elles.
 * - Les documents quotation/supplier_quotation sont liés à la commande (pas aux articles).
 * Logging : Toutes les actions sont loguées dans ispag_achat_status_checker.log.
 */
class ISPAG_Achat_Status_Checker
{
    protected static $table_etats;
    protected static $table_achats;
    protected static $table_suivi;

    /** @var ISPAG_Logger Instance du logger. */
    protected static $logger;

    public static function init()
    {
        global $wpdb;
        self::$table_etats = $wpdb->prefix . 'achats_etat_commandes_fournisseur';
        self::$table_achats = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        self::$table_suivi = $wpdb->prefix . 'achats_suivi_phase_commande';
        self::$logger = ISPAG_Logger::get_instance();

        $user_id = get_current_user_id();
        // self::$logger->log_user_action('achat_status_checker', 'class_initialized', [], $user_id);

        add_action('ispag_check_auto_status', [self::class, 'auto_status_checker']);
        add_action('ispag_check_achats_interventions', [self::class, 'ispag_check_achats_interventions_callback']);
        add_action('ispag_save_status_changes', [self::class, 'save_status_changes'], 10, 3);
        add_action('ispag_check_auto_status_for_achat', function($achat_id)
        {
            if (!empty($achat_id))
            {
                $user_id = get_current_user_id();
                self::$logger->log_user_action('achat_status_checker', 'check_auto_status_for_achat_triggered', ['achat_id' => $achat_id], $user_id);
                self::auto_status_checker($achat_id);
            }
        });

        add_action('wp', [self::class, 'maybe_schedule_cron']);

        add_filter('cron_schedules', function($schedules)
        {
            $schedules['twicedaily'] = [
                'interval' => 12 * 60 * 60,
                'display' => __('Twice Daily'),
            ];
            return $schedules;
        });
    }

    // ------------------------------------------------------------------
    // CRON
    // ------------------------------------------------------------------

    public static function maybe_schedule_cron()
    {
        $user_id = get_current_user_id();
        if (!wp_next_scheduled('ispag_check_auto_status'))
        {
            wp_schedule_event(time(), 'fifteenminutes', 'ispag_check_auto_status');
            self::$logger->log_user_action('achat_status_checker', 'cron_scheduled', ['action' => 'ispag_check_auto_status'], $user_id);
        }
        if (!wp_next_scheduled('ispag_check_achats_interventions'))
        {
            wp_schedule_event(time(), 'hourly', 'ispag_check_achats_interventions');
            self::$logger->log_user_action('achat_status_checker', 'cron_scheduled', ['action' => 'ispag_check_achats_interventions'], $user_id);
        }
    }

    public static function activation_hook()
    {
        $user_id = get_current_user_id();
        self::init();
        self::maybe_schedule_cron();
        self::$logger->log_user_action('achat_status_checker', 'plugin_activated', [], $user_id);
    }

    public static function deactivation_hook()
    {
        $user_id = get_current_user_id();
        foreach (['ispag_check_auto_status', 'ispag_check_achats_interventions'] as $hook)
        {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp)
            {
                wp_unschedule_event($timestamp, $hook);
                self::$logger->log_user_action('achat_status_checker', 'cron_unscheduled', ['hook' => $hook], $user_id);
            }
        }
    }

    // ------------------------------------------------------------------
    // REGISTRE DÉCLARATIF DES RÈGLES DE PROGRESSION
    // ------------------------------------------------------------------

    protected static function get_progression_registry(): array
    {
        return [
            // === PROPOSAL (Offre) ===
            6 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_6_check', ['achat_id' => $achat_id], $user_id);

                    if (self::is_standard_articles($achat_id))
                    {
                        self::$logger->log_user_action('achat_status_checker', 'rule_6_to_10_triggered', ['achat_id' => $achat_id, 'reason' => 'All articles are standard'], $user_id);
                        return 10;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_6_to_10_not_triggered', ['achat_id' => $achat_id, 'reason' => 'UnitPrice incomplete'], $user_id);
                    return false;
                },
                'can_jump_from_any' => false,
            ],

            10 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_10_check', ['achat_id' => $achat_id], $user_id);

                    $has_quotation = self::search_doc_type_in_achat($achat_id, 'quotation')
                                   || self::search_doc_type_in_achat($achat_id, 'supplier_quotation');
                    $has_price = self::is_article_data_complete($achat_id, 'UnitPrice');

                    if ($has_quotation && $has_price)
                    {
                        self::$logger->log_user_action('achat_status_checker', 'rule_10_to_18_triggered', ['achat_id' => $achat_id, 'reason' => 'quotation + UnitPrice detected'], $user_id);
                        return 18;
                    }
                    elseif (self::is_standard_articles($achat_id))
                    {
                        self::$logger->log_user_action('achat_status_checker', 'rule_10_to_18_triggered', ['achat_id' => $achat_id, 'reason' => 'All articles are standard'], $user_id);
                        return 18;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_10_to_18_not_triggered', ['achat_id' => $achat_id, 'has_quotation' => $has_quotation, 'has_price' => $has_price], $user_id);
                    return false;
                },
                'can_jump_from_any' => false,
            ],

            18 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_18_check', ['achat_id' => $achat_id], $user_id);

                    if (self::search_doc_type_in_achat($achat_id, 'ccmd'))
                    {
                        self::$logger->log_user_action('achat_status_checker', 'rule_18_to_2_triggered', ['achat_id' => $achat_id, 'reason' => 'ccmd detected'], $user_id);
                        return 2;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_18_to_2_not_triggered', ['achat_id' => $achat_id, 'reason' => 'ccmd missing'], $user_id);
                    return false;
                },
                'can_jump_from_any' => false,
            ],

            // === PURCHASE (Achat) ===
            2 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_2_check', ['achat_id' => $achat_id], $user_id);

                    if (self::has_doc_type_in_achat($achat_id, 'product_drawing'))
                    {
                        self::$logger->log_user_action('achat_status_checker', 'rule_2_to_12_triggered', ['achat_id' => $achat_id, 'reason' => 'product_drawing detected'], $user_id);
                        return 12;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_2_to_12_not_triggered', ['achat_id' => $achat_id, 'reason' => 'product_drawing missing'], $user_id);
                    return false;
                },
                'can_jump_from_any' => false,
            ],

            // Sous-flux des plans (12, 14, 15, 16) - PEUVENT REVENIR EN ARRIÈRE
            12 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_12_check', ['achat_id' => $achat_id], $user_id);

                    if (self::has_doc_type_in_achat($achat_id, 'product_drawing'))
                    {
                        self::$logger->log_db_change('achat_status_checker', 'product_drawing', 'DETECTED', ['achat_id' => $achat_id], $user_id);
                        return 12;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_12_not_triggered', ['achat_id' => $achat_id], $user_id);
                    return false;
                },
                'can_jump_from_any' => true,
            ],

            14 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_14_check', ['achat_id' => $achat_id], $user_id);

                    if (self::has_doc_type_in_achat($achat_id, 'drawingApproval'))
                    {
                        self::$logger->log_db_change('achat_status_checker', 'drawingApproval', 'DETECTED', ['achat_id' => $achat_id], $user_id);
                        return 14;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_14_not_triggered', ['achat_id' => $achat_id], $user_id);
                    return false;
                },
                'can_jump_from_any' => true,
            ],

            15 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_15_check', ['achat_id' => $achat_id], $user_id);

                    if (self::has_doc_type_in_achat($achat_id, 'drawingModification'))
                    {
                        self::$logger->log_db_change('achat_status_checker', 'drawingModification', 'DETECTED', ['achat_id' => $achat_id], $user_id);
                        return 15;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_15_not_triggered', ['achat_id' => $achat_id], $user_id);
                    return false;
                },
                'can_jump_from_any' => true,
            ],

            16 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_16_check', ['achat_id' => $achat_id], $user_id);

                    if (self::has_doc_type_in_achat($achat_id, 'drawingModificationSent'))
                    {
                        self::$logger->log_db_change('achat_status_checker', 'drawingModificationSent', 'DETECTED', ['achat_id' => $achat_id], $user_id);
                        return 16;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_16_not_triggered', ['achat_id' => $achat_id], $user_id);
                    return false;
                },
                'can_jump_from_any' => true,
            ],

            // Étape 13 : Validation des plans envoyés (MANUELLE)
            13 => [
                'resolver' => function($achat_id)
                {
                    return false;
                },
            ],

            // Étape 3 : Confirmation de commande
            3 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_3_check', ['achat_id' => $achat_id], $user_id);

                    if (
                        self::search_doc_type_in_achat($achat_id, 'ccmd') &&
                        self::is_data_order_received($achat_id, 'ConfCmdFournisseur')
                    )
                    {
                        self::$logger->log_user_action('achat_status_checker', 'rule_3_triggered', ['achat_id' => $achat_id, 'reason' => 'ccmd + ConfCmdFournisseur detected'], $user_id);
                        return 3;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_3_not_triggered', ['achat_id' => $achat_id], $user_id);
                    return false;
                },
                'can_jump_from_any' => true,
            ],

            // Étape 4 : Matériel reçu
            4 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_4_check', ['achat_id' => $achat_id], $user_id);

                    if (self::is_article_data_complete($achat_id, 'Recu'))
                    {
                        self::$logger->log_user_action('achat_status_checker', 'rule_4_triggered', ['achat_id' => $achat_id, 'reason' => 'Recu complete'], $user_id);
                        return 4;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_4_not_triggered', ['achat_id' => $achat_id], $user_id);
                    return false;
                },
                'can_jump_from_any' => false,
            ],

            // Étape 5 : Facture validée
            5 => [
                'resolver' => function($achat_id)
                {
                    $user_id = get_current_user_id();
                    self::$logger->log_user_action('achat_status_checker', 'rule_5_check', ['achat_id' => $achat_id], $user_id);

                    if (
                        self::search_doc_type_in_achat($achat_id, 'invoice') &&
                        self::is_article_data_complete($achat_id, 'Facture')
                    )
                    {
                        self::$logger->log_user_action('achat_status_checker', 'rule_5_triggered', ['achat_id' => $achat_id, 'reason' => 'invoice + Facture complete'], $user_id);
                        return 5;
                    }

                    self::$logger->log_user_action('achat_status_checker', 'rule_5_not_triggered', ['achat_id' => $achat_id], $user_id);
                    return false;
                },
                'can_jump_from_any' => false,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // ORCHESTRATEUR
    // ------------------------------------------------------------------

    public static function auto_status_checker($achat_id = null)
    {
        global $wpdb;
        $user_id = get_current_user_id();

        self::$logger->log_user_action('achat_status_checker', 'auto_status_checker_start', ['achat_id' => $achat_id], $user_id);

        $achats = $achat_id
            ? [$wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$table_achats . " WHERE Id = %d", $achat_id))]
            : $wpdb->get_results("SELECT * FROM " . self::$table_achats . " WHERE EtatCommande < 99");

        if (!$achats)
        {
            self::$logger->log_user_action('achat_status_checker', 'no_orders_to_check', [], $user_id);
            return;
        }

        self::$logger->log_user_action('achat_status_checker', 'orders_to_process', ['count' => count($achats)], $user_id);

        $etats_db = $wpdb->get_results("SELECT * FROM " . self::$table_etats . " ORDER BY ordre ASC", OBJECT_K);
        $registry = self::get_progression_registry();
        $liste_plans = [12, 14, 15, 16];

        foreach ($achats as $achat)
        {
            $current_status = (int)$achat->EtatCommande;
            self::$logger->log_user_action('achat_status_checker', 'processing_order', ['achat_id' => $achat->Id, 'current_status' => $current_status], $user_id);

            if (!isset($etats_db[$current_status]) || (int)$etats_db[$current_status]->is_automatic === 0)
            {
                self::$logger->log_user_action('achat_status_checker', 'manual_status_skipped', ['achat_id' => $achat->Id, 'status' => $current_status], $user_id);
                continue;
            }

            $current_ordre = (int)$etats_db[$current_status]->ordre;
            $status_updated = false;

            self::$logger->log_user_action('achat_status_checker', 'checking_jump_rules', ['achat_id' => $achat->Id], $user_id);

            foreach ($registry as $status_id => $rule)
            {
                if (!isset($rule['can_jump_from_any']) || !$rule['can_jump_from_any']) continue;
                if (!isset($etats_db[$status_id]) || (int)$etats_db[$status_id]->is_automatic === 0) continue;

                $resolved = $rule['resolver']($achat->Id);
                if ($resolved !== false && $resolved !== $current_status)
                {
                    if (in_array($resolved, [12, 14, 15, 16, 3]))
                    {
                        if (in_array($resolved, $liste_plans) && !in_array($current_status, $liste_plans))
                        {
                            self::$logger->log_user_action('achat_status_checker', 'jump_to_plan_rejected', ['achat_id' => $achat->Id, 'resolved' => $resolved, 'current_status' => $current_status], $user_id);
                            continue;
                        }

                        $next_ordre = (int)$etats_db[$resolved]->ordre;
                        if ($next_ordre < $current_ordre && !in_array($current_status, $liste_plans))
                        {
                            self::$logger->log_user_action('achat_status_checker', 'jump_backward_rejected', ['achat_id' => $achat->Id, 'resolved' => $resolved, 'current_ordre' => $current_ordre, 'next_ordre' => $next_ordre], $user_id);
                            continue;
                        }

                        $slug = $etats_db[$resolved]->ClassCss;
                        self::$logger->log_user_action('achat_status_checker', 'status_jump_allowed', ['achat_id' => $achat->Id, 'from' => $current_status, 'to' => $resolved], $user_id);
                        self::update_auto_status($achat->Id, $slug, $resolved);
                        $status_updated = true;
                        break;
                    }
                }
            }

            if ($status_updated) continue;

            $ordered_etats = array_values($etats_db);
            self::$logger->log_user_action('achat_status_checker', 'checking_sequential_rules', ['achat_id' => $achat->Id], $user_id);

            foreach ($ordered_etats as $i => $etat)
            {
                if ((int)$etat->Id !== $current_status) continue;

                for ($j = $i; $j < count($ordered_etats); $j++)
                {
                    $current_id = (int)$ordered_etats[$j]->Id;
                    if ($j + 1 >= count($ordered_etats)) break;

                    $next_id = (int)$ordered_etats[$j + 1]->Id;
                    $next_slug = $ordered_etats[$j + 1]->ClassCss;
                    $next_ordre = (int)$ordered_etats[$j + 1]->ordre;

                    if (!isset($registry[$next_id]) || (int)$ordered_etats[$j]->is_automatic === 0) continue;

                    $resolved = $registry[$current_id]['resolver']($achat->Id);

                    if ($resolved !== false && $resolved === $next_id)
                    {
                        if ($next_ordre > $current_ordre)
                        {
                            self::$logger->log_user_action('achat_status_checker', 'sequential_progress_allowed', ['achat_id' => $achat->Id, 'from' => $current_status, 'to' => $next_id], $user_id);
                            self::update_auto_status($achat->Id, $next_slug, $next_id);
                            $status_updated = true;
                            break 2;
                        }
                    }
                }
                break;
            }
        }

        self::$logger->log_user_action('achat_status_checker', 'auto_status_checker_complete', [], $user_id);
    }

    // ------------------------------------------------------------------
    // MISE À JOUR DU STATUT
    // ------------------------------------------------------------------

    protected static function update_auto_status(int $achat_id, string $slug, int $new_status_id)
    {
        global $wpdb;
        $user_id = get_current_user_id();

        $result = $wpdb->update(
            self::$table_achats,
            ['EtatCommande' => $new_status_id],
            ['Id' => $achat_id],
            ['%d'],
            ['%d']
        );

        if ($result)
        {
            self::$logger->log_db_change('achat_status_checker', self::$table_achats, 'UPDATE_STATUS', ['achat_id' => $achat_id, 'slug' => $slug, 'new_status_id' => $new_status_id], $user_id);
            self::save_status_changes($achat_id, $slug, $new_status_id);
        }
        else
        {
            self::$logger->log('achat_status_checker', 'ERROR: Failed to update status for achat ' . $achat_id, $user_id);
        }
    }

    // ------------------------------------------------------------------
    // VÉRIFICATIONS MÉTIER
    // ------------------------------------------------------------------

    protected static function is_data_order_received($achat_id, $data_search)
    {
        global $wpdb;
        $user_id = get_current_user_id();

        if (empty($data_search) || empty($achat_id))
        {
            self::$logger->log('achat_status_checker', 'ERROR: Empty field or ID for is_data_order_received', $user_id);
            return false;
        }

        $achat = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$table_achats . " WHERE Id = %d",
            $achat_id
        ));

        if (!$achat)
        {
            self::$logger->log_db_change('achat_status_checker', self::$table_achats, 'FETCH_FAILED', ['achat_id' => $achat_id], $user_id);
            return false;
        }

        $result = !empty($achat->$data_search);
        if (!$result)
        {
            self::$logger->log_db_change('achat_status_checker', self::$table_achats, 'FIELD_EMPTY', ['achat_id' => $achat_id, 'field' => $data_search], $user_id);
        }
        else
        {
            self::$logger->log_db_change('achat_status_checker', self::$table_achats, 'FIELD_VALID', ['achat_id' => $achat_id, 'field' => $data_search], $user_id);
        }
        return $result;
    }

    protected static function search_doc_type_in_achat($achat_id, $doc_type)
    {
        global $wpdb;
        $user_id = get_current_user_id();

        $table_historique = $wpdb->prefix . 'achats_historique';
        self::$logger->log_db_change('achat_status_checker', $table_historique, 'SEARCH_DOC_TYPE', ['achat_id' => $achat_id, 'doc_type' => $doc_type], $user_id);

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT ClassCss FROM {$table_historique}
             WHERE purchase_order = %d AND ClassCss = %s
             ORDER BY dateReadable DESC LIMIT 1",
            $achat_id, $doc_type
        ));

        if ($found === null)
        {
            self::$logger->log_db_change('achat_status_checker', $table_historique, 'DOC_TYPE_NOT_FOUND', ['achat_id' => $achat_id, 'doc_type' => $doc_type], $user_id);
        }
        elseif ($found === $doc_type)
        {
            self::$logger->log_db_change('achat_status_checker', $table_historique, 'DOC_TYPE_FOUND', ['achat_id' => $achat_id, 'doc_type' => $doc_type], $user_id);
        }
        else
        {
            self::$logger->log_db_change('achat_status_checker', $table_historique, 'DOC_TYPE_MISMATCH', ['achat_id' => $achat_id, 'found' => $found, 'expected' => $doc_type], $user_id);
        }

        return $found === $doc_type;
    }

    protected static function has_doc_type_in_achat($achat_id, $doc_type): bool
    {
        global $wpdb;
        $user_id = get_current_user_id();

        $table_articles_fourn = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $table_historique = $wpdb->prefix . 'achats_historique';

        self::$logger->log_db_change('achat_status_checker', $table_articles_fourn, 'CHECK_DOC_TYPE', ['achat_id' => $achat_id, 'doc_type' => $doc_type], $user_id);

        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT Id, IdCommandeClient FROM {$table_articles_fourn} WHERE IdCommande = %d",
            $achat_id
        ));

        if (!$articles)
        {
            self::$logger->log_db_change('achat_status_checker', $table_articles_fourn, 'NO_ARTICLES_FOUND', ['achat_id' => $achat_id], $user_id);
            return false;
        }

        foreach ($articles as $article)
        {
            $last_doc = $wpdb->get_var($wpdb->prepare(
                "SELECT ClassCss FROM {$table_historique}
                 WHERE Historique = %d ORDER BY dateReadable DESC LIMIT 1",
                (int)$article->IdCommandeClient
            ));

            if ($last_doc === $doc_type)
            {
                self::$logger->log_db_change('achat_status_checker', $table_historique, 'DOC_TYPE_FOUND_FOR_ARTICLE', ['achat_id' => $achat_id, 'article_id' => $article->IdCommandeClient, 'doc_type' => $doc_type], $user_id);
                return true;
            }
        }

        self::$logger->log_db_change('achat_status_checker', $table_historique, 'DOC_TYPE_NOT_FOUND_FOR_ANY_ARTICLE', ['achat_id' => $achat_id, 'doc_type' => $doc_type], $user_id);
        return false;
    }

    protected static function is_article_data_complete($achat_id, $field_name)
    {
        global $wpdb;
        $user_id = get_current_user_id();

        if (empty($field_name) || empty($achat_id))
        {
            self::$logger->log('achat_status_checker', 'ERROR: Empty field or ID for is_article_data_complete', $user_id);
            return false;
        }

        $table_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        self::$logger->log_db_change('achat_status_checker', $table_articles, 'CHECK_FIELD_COMPLETENESS', ['achat_id' => $achat_id, 'field' => $field_name], $user_id);

        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_articles} WHERE IdCommande = %d",
            $achat_id
        ));

        if (!$articles)
        {
            self::$logger->log_db_change('achat_status_checker', $table_articles, 'NO_ARTICLES_FOUND', ['achat_id' => $achat_id], $user_id);
            return false;
        }

        foreach ($articles as $article)
        {
            if (!isset($article->$field_name))
            {
                self::$logger->log_db_change('achat_status_checker', $table_articles, 'FIELD_NOT_FOUND', ['achat_id' => $achat_id, 'article_id' => $article->Id, 'field' => $field_name], $user_id);
                return false;
            }

            $value = trim((string)$article->$field_name);
            if ($value === '' || $value === '0' || $value === '0.00')
            {
                self::$logger->log_db_change('achat_status_checker', $table_articles, 'FIELD_EMPTY', ['achat_id' => $achat_id, 'article_id' => $article->Id, 'field' => $field_name, 'value' => $value], $user_id);
                return false;
            }
        }

        self::$logger->log_db_change('achat_status_checker', $table_articles, 'ALL_FIELDS_VALID', ['achat_id' => $achat_id, 'field' => $field_name], $user_id);
        return true;
    }

    protected static function is_standard_articles($achat_id)
    {
        global $wpdb;
        $user_id = get_current_user_id();

        if (empty($achat_id))
        {
            self::$logger->log('achat_status_checker', 'ERROR: Empty ID for is_standard_articles', $user_id);
            return false;
        }

        $table_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        self::$logger->log_db_change('achat_status_checker', $table_articles, 'CHECK_STANDARD_ARTICLES', ['achat_id' => $achat_id], $user_id);

        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_articles} WHERE IdCommande = %d",
            $achat_id
        ));

        if (!$articles)
        {
            self::$logger->log_db_change('achat_status_checker', $table_articles, 'NO_ARTICLES_FOUND', ['achat_id' => $achat_id], $user_id);
            return false;
        }

        foreach ($articles as $article)
        {
            if (!isset($article->IdArticleStandard))
            {
                self::$logger->log_db_change('achat_status_checker', $table_articles, 'STANDARD_FIELD_NOT_FOUND', ['achat_id' => $achat_id, 'article_id' => $article->Id], $user_id);
                return false;
            }

            $value = trim((string)$article->IdArticleStandard);
            if ($value === '' || $value === '0' || $value === '0.00')
            {
                self::$logger->log_db_change('achat_status_checker', $table_articles, 'STANDARD_FIELD_EMPTY', ['achat_id' => $achat_id, 'article_id' => $article->Id, 'value' => $value], $user_id);
                return false;
            }
        }

        self::$logger->log_db_change('achat_status_checker', $table_articles, 'ALL_ARTICLES_STANDARD', ['achat_id' => $achat_id], $user_id);
        return true;
    }

    // ------------------------------------------------------------------
    // SUIVI / LOGS HISTORIQUE SQL
    // ------------------------------------------------------------------

    public static function save_status_changes($achat_id = null, $slug = null, $status_id = null)
    {
        global $wpdb;
        $user_id = get_current_user_id();

        if (!$achat_id || !$slug || !$status_id)
        {
            self::$logger->log('achat_status_checker', 'ERROR: Missing parameters for save_status_changes', $user_id);
            return false;
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'achats_suivi_phase_commande',
            [
                'purchase_id' => $achat_id,
                'slug_phase' => $slug,
                'status_id' => $status_id,
                'date_modification' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s']
        );

        if ($result)
        {
            self::$logger->log_db_change('achat_status_checker', 'achats_suivi_phase_commande', 'INSERT_STATUS_CHANGE', ['achat_id' => $achat_id, 'slug' => $slug, 'status_id' => $status_id], $user_id);
        }
        else
        {
            self::$logger->log('achat_status_checker', 'ERROR: Failed to save status change for achat ' . $achat_id, $user_id);
        }

        return $result !== false;
    }

    // ------------------------------------------------------------------
    // NOTIFICATIONS D'INTERVENTION
    // ------------------------------------------------------------------
  
    public static function ispag_check_achats_interventions_callback()
    {
        global $wpdb;
        $user_id = get_current_user_id();

        // Logging du début de l'exécution
        self::$logger->log_user_action('achat_status_checker', 'interventions_callback_start', [], $user_id);

        // Statuts nécessitant une intervention
        $intervention_ids = [1, 6, 15, 14];
        $base_url = trailingslashit(get_site_url()) . 'purchase/';
        $now_ts = time();
        $ids_string = implode(',', array_map('intval', $intervention_ids));

        // Récupération des commandes nécessitant une intervention
        $achats = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, ec.Etat, c.Id, c.created_by
            FROM {$wpdb->prefix}achats_commande_liste_fournisseurs c
            LEFT JOIN {$wpdb->prefix}achats_etat_commandes_fournisseur ec ON ec.Id = c.EtatCommande
            WHERE c.EtatCommande IN ($ids_string)
            AND (c.TimestampDateCreation IS NOT NULL AND c.TimestampDateCreation != 0)
            AND c.TimestampDateCreation <= %d",
            $now_ts
        ));

        // Logging du nombre de commandes récupérées
        self::$logger->log_db_change('achat_status_checker', 'achats_commande_liste_fournisseurs', 'FETCH_INTERVENTIONS', ['count' => count($achats)], $user_id);

        // Si aucune commande ne nécessite d'intervention, on sort
        if (empty($achats)) {
            self::$logger->log_user_action('achat_status_checker', 'no_interventions_found', [], $user_id);
            return;
        }

        // Traitement de chaque commande
        foreach ($achats as $achat) {
            $purchase_url = esc_url($base_url . $achat->Id);
            $etat = __($achat->Etat, 'creation-reservoir');
            $ref = $achat->RefCommande;
            $titre_notif = "🔍 Intervention requise pour la commande d'achat {$ref}";
            $msg_notif = "Statut : {$etat}";

            // Logging de la préparation de la notification
            self::$logger->log_user_action('achat_status_checker', 'intervention_notification_prepared', [
                'achat_id' => $achat->Id,
                'ref' => $ref,
                'etat' => $etat
            ], $user_id);

            // Envoi de la notification via ISPAG_Notifications_Manager
            if (class_exists('ISPAG_Notifications_Manager')) {
                $destinataires = [];
                if (!empty($achat->created_by)) {
                    $destinataires[] = (int)$achat->created_by; // Créateur de la commande
                }
                $destinataires[] = 1; // Admin par défaut

                // Envoi de la notification (gère tous les canaux : CRM, OneSignal, Mail, Telegram, etc.)
                ISPAG_Notifications_Manager::send(
                    $destinataires, // Tableau des IDs utilisateurs
                    'purchase_followup', // Type de notification
                    $titre_notif, // Titre
                    $msg_notif, // Contenu
                    $purchase_url, // URL
                    $achat->Id // ID de l'entité (commande)
                );

                // Logging de l'envoi de la notification
                self::$logger->log_user_action('achat_status_checker', 'notification_sent', [
                    'achat_id' => $achat->Id,
                    'destinataires' => $destinataires
                ], $user_id);
            }
        }
    }
}