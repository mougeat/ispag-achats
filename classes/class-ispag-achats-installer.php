<?php
defined('ABSPATH') || exit;

/**
 * Création du schéma de base de données de ISPAG Achats.
 *
 * - À l'activation du plugin : install() est appelé (register_activation_hook).
 * - À chaque chargement : maybe_install() compare la version du schéma stockée en option
 *   à DB_VERSION et relance install() si elle diffère. Un plugin mis à jour par FTP
 *   (donc sans réactivation) crée ses tables manquantes dès la prochaine requête.
 *
 * Toutes les requêtes sont des CREATE TABLE IF NOT EXISTS (voir install/schema.php) :
 * sur un site existant, aucune table ni donnée n'est modifiée.
 *
 * Pour faire évoluer le schéma plus tard : ajouter la nouvelle table à schema.php, ou une
 * migration ALTER dans migrate(), puis incrémenter DB_VERSION.
 */
class ISPAG_Achats_Installer {

    const DB_VERSION = '1.9.0';
    const OPTION     = 'ispag_achats_db_version';

    /** Droits utilisés par ce plugin (voir grant_default_caps()). */
    const CAPS = ['manage_order', 'display_sales_prices', 'read_orders', 'view_supplier_order', 'edit_supplier_order', 'navigate_new_project_details_presentation', 'generate_tank'];

    public static function init() {
        add_action('plugins_loaded', [self::class, 'maybe_install'], 5);
    }

    public static function maybe_install() {
        if (get_option(self::OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install() {
        global $wpdb;

        $schema  = require dirname(__DIR__) . '/install/schema.php';
        $charset = $wpdb->get_charset_collate();
        $ok      = true;

        $suppress = $wpdb->suppress_errors(true);
        foreach ($schema as $name => $sql) {
            $sql = str_replace(['{prefix}', '{charset}'], [$wpdb->prefix, $charset], $sql);
            if ($wpdb->query($sql) === false) {
                $ok = false;
                error_log('[ISPAG Achats] Création de la table ' . $wpdb->prefix . $name . ' impossible : ' . $wpdb->last_error);
            }
        }
        if (!self::seed()) {
            $ok = false;
        }
        self::seed_mail_templates();
        $wpdb->suppress_errors($suppress);

        self::grant_default_caps();

        // On ne mémorise la version que si tout est passé : sinon on réessaie à la requête suivante.
        if ($ok) {
            update_option(self::OPTION, self::DB_VERSION);
        }
        return $ok;
    }

    /**
     * Valeurs initiales des tables de référence (install/seeds.php : ['table_sans_prefixe' => [ [colonne => valeur, …], … ]]).
     * Une table n'est remplie QUE si elle est vide : sur un site existant (production), rien n'est jamais ajouté ni modifié.
     */
    private static function seed() {
        global $wpdb;
        $dir  = dirname(__DIR__) . '/install';
        $sets = [];
        if (is_readable($dir . '/seeds.php')) {
            $sets = (array) require $dir . '/seeds.php';
        }
        // Gros jeux de données : un fichier install/seeds/<table_sans_prefixe>.php par table
        foreach ((array) glob($dir . '/seeds/*.php') as $file) {
            $sets[basename($file, '.php')] = require $file;
        }
        $ok = true;
        foreach ($sets as $name => $rows) {
            $table = $wpdb->prefix . $name;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                continue;
            }
            if ((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") > 0) {
                continue;
            }
            foreach ($rows as $row) {
                if ($wpdb->insert($table, $row) === false) {
                    $ok = false;
                    error_log('[ISPAG Achats] Valeur initiale refusée dans ' . $table . ' : ' . $wpdb->last_error);
                }
            }
        }
        return $ok;
    }

    /**
     * Modèles d'e-mail par défaut (anglais, français, allemand, italien) des commandes fournisseur (install/default-mail-templates.php).
     * Un modèle n'est ajouté que s'il n'existe pas déjà pour le même type et la même langue : les modèles existants
     * (ou modifiés depuis la page « Modèles d'e-mail ») ne sont jamais touchés.
     */
    private static function seed_mail_templates() {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_template_mail';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $file = dirname(__DIR__) . '/install/default-mail-templates.php';
        foreach ((array) require $file as $tpl) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT Id FROM `{$table}` WHERE message_family = %s AND message_type = %s AND lang = %s LIMIT 1",
                ISPAG_Achat_Mail_Templates::FAMILY, $tpl['message_type'], $tpl['lang']
            ));
            if ($exists) {
                // Mise à jour du texte par défaut, UNIQUEMENT pour un modèle installé par nous et jamais modifié :
                // marqué created_by = -1, ou (anciennes installations) created_by = 0 avec un objet identique à un objet par défaut.
                // Un modèle enregistré depuis la page d'admin porte l'ID de l'utilisateur : il n'est jamais remplacé.
                $subjects = array_merge([$tpl['subject']], (array) ($tpl['legacy_subjects'] ?? []));
                $in = implode(',', array_fill(0, count($subjects), '%s'));
                $wpdb->query($wpdb->prepare(
                    "UPDATE `{$table}` SET subject = %s, message = %s, created_by = -1
                     WHERE Id = %d AND (created_by = -1 OR (created_by = 0 AND subject IN ($in)))",
                    array_merge([$tpl['subject'], $tpl['message'], $exists], $subjects)
                ));
                continue;
            }
            $wpdb->insert($table, [
                'Brevo_id' => 0, 'lang' => $tpl['lang'], 'subject' => $tpl['subject'], 'message' => $tpl['message'],
                'message_type' => $tpl['message_type'], 'message_family' => ISPAG_Achat_Mail_Templates::FAMILY,
                'prompt' => '', 'join_doc_typ' => '', 'selectionnable' => 1, 'created_by' => -1,
            ]);
        }
    }

    /**
     * Site neuf : ces droits n'existent nulle part, donc les pages affichent « accès restreint ».
     * On les donne au rôle administrateur, mais UNIQUEMENT s'il n'en a encore aucun : sur un site
     * existant (qui gère ses droits autrement, par un plugin de rôles par ex.), rien n'est touché.
     */
    private static function grant_default_caps() {
        // Droits et rôles ISPAG : registre central dans ISPAG Project Manager (page « ISPAG Rights »)
        if (class_exists('ISPAG_Capabilities')) {
            ISPAG_Capabilities::install();
        }
    }
}
