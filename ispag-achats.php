<?php
/**
 * Plugin Name: ISPAG Achats
 * Description: Gestion des achats - détail, articles, documents, etc.
 */

defined('ABSPATH') || exit;

spl_autoload_register(function ($class) {
    $prefix = 'ISPAG_';
    $base_dir = __DIR__ . '/classes/';
    if (strpos($class, $prefix) === 0) {
        $class_name = strtolower(str_replace('_', '-', $class));
        $file = $base_dir . 'class-' . $class_name . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

// Mise à jour depuis une branche GitHub (jeton + branche : wp-config.php ou Outils → Updates ISPAG ; « main » par défaut)
require_once __DIR__ . '/classes/class-ispag-github-updater.php';
ISPAG_GitHub_Updater::plugin(__FILE__, 'mougeat/ispag-achats');

// Schéma de base de données : créé à l'activation, et re-vérifié à chaque chargement si la version change
register_activation_hook(__FILE__, ['ISPAG_Achats_Installer', 'install']);
ISPAG_Achats_Installer::init();

// ISPAG_Logger : le vrai (classes/class-ispag-logger.php d'ISPAG Project Manager, s'il est présent) passe en premier ;
// sinon classe de secours. Enregistré dès le chargement : l'activation du plugin utilise déjà le logger.
spl_autoload_register(function ($class) {
    if ($class !== 'ISPAG_Logger') return;
    $real = defined('ISPAG_PROJECT_MANAGER_DIR') ? ISPAG_PROJECT_MANAGER_DIR . 'classes/class-ispag-logger.php' : '';
    if ($real && is_readable($real)) { require_once $real; return; }
    require_once __DIR__ . '/' . 'install/fallback-logger.php';
});


// Pages nécessaires (créées à l'activation ou via Outils → Pages ISPAG ; jamais automatiquement)
require_once __DIR__ . '/classes/class-ispag-page-installer.php';
ISPAG_Page_Installer::register('ISPAG Achats', require __DIR__ . '/install/pages.php');
register_activation_hook(__FILE__, function () { ISPAG_Page_Installer::on_activation('ISPAG Achats'); });

/**
 * Traductions FR / DE (fichiers dans languages/ : <domaine>-fr_FR.mo, <domaine>-de_DE.mo ; générés par tools/i18n/build.py d'ISPAG Project Manager).
 * Toute variante de langue du site est couverte : fr_CH, fr_BE… utilisent le français ; de_CH, de_DE_formal, de_AT… l'allemand.
 * Les textes de base sont en anglais : sans fichier pour la langue du site, l'anglais est affiché.
 */
if (!function_exists('ispag_load_translations_from')) {
    function ispag_load_translations_from($dir) {
        $locale   = determine_locale();
        $fallback = ['fr' => 'fr_FR', 'de' => 'de_DE'][substr($locale, 0, 2)] ?? '';
        if ($fallback === '' || !is_dir($dir)) return;
        foreach ((array) glob(rtrim($dir, '/\\') . '/*-' . $fallback . '.mo') as $mo) {
            $domain = basename($mo, '-' . $fallback . '.mo');
            $exact  = rtrim($dir, '/\\') . '/' . $domain . '-' . $locale . '.mo';
            load_textdomain($domain, is_readable($exact) ? $exact : $mo);
        }
    }
}

add_action('init', function () { ispag_load_translations_from(__DIR__ . '/languages'); });

// Chemin d'ISPAG Project Manager, quel que soit le nom de son dossier (un ZIP GitHub donne « ispag-project-manager-<branche> »)
if (!function_exists('ispag_project_manager_dir')) {
    function ispag_project_manager_dir() {
        return defined('ISPAG_PROJECT_MANAGER_DIR') ? ISPAG_PROJECT_MANAGER_DIR : WP_PLUGIN_DIR . '/ispag-project-manager/';
    }
}

add_action('admin_notices', function () {
    if (!defined('ISPAG_PROJECT_MANAGER_DIR')) {
        echo '<div class="notice notice-warning"><p><strong>ISPAG Achats</strong> s\'appuie sur <strong>ISPAG Project Manager</strong> (documents, PDF, articles) : activez-le pour un fonctionnement complet.</p></div>';
    }
});

new ISPAG_Purchase_URL_Rewrite();

add_action('init', function () {
    // Les classes d'achats étendent celles d'ISPAG Project Manager (documents, PDF) : sans lui, on n'initialise rien
    // (l'avis ci-dessus l'explique) au lieu de provoquer une erreur fatale sur tout le site.
    if (!defined('ISPAG_PROJECT_MANAGER_DIR')) return;

    ISPAG_Achat_Repository::init();
    ISPAG_Achat_Manager::init();
    ISPAG_Achat_Status_Controller::init();
    ISPAG_Achat_Renderer::init();
    ISPAG_Achat_Logger::init();
    ISPAG_Achat_Details_Renderer::init();
    ISPAG_Achat_Status_Checker::init();
    ISPAG_Achat_status_render::init();
    // new ISPAG_Document_Manager();
    ISPAG_Achat_Article_Repository::init();
    ISPAG_Achat_Generate_Purchase_Order_PDF::init();
    ISPAG_Achat_Supplier_Repository::init();
    ISPAG_Achat_Document_Analyser::init();
    ISPAG_Achat_Commande_Manager::init();
    ISPAG_Achat_Supplier_Contacts::init();
    ISPAG_Achat_Add_Product::init();
    ISPAG_Achat_Mail_Templates::init();
    ISPAG_Achat_Mail_Draft::init();
    ISPAG_Achat_Settings::init();

    ISPAG_CarryBox_Manager::init();
    
    // ISPAG_Ajax_Handler::init();

    
});










