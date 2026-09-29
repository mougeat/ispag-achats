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

// Mise à jour depuis une branche GitHub : inactif sauf si wp-config.php définit ISPAG_GITHUB_TOKEN et ISPAG_UPDATE_BRANCH
require_once __DIR__ . '/classes/class-ispag-github-updater.php';
ISPAG_GitHub_Updater::plugin(__FILE__, 'mougeat/ispag-achats');

// Schéma de base de données : créé à l'activation, et re-vérifié à chaque chargement si la version change
register_activation_hook(__FILE__, ['ISPAG_Achats_Installer', 'install']);
ISPAG_Achats_Installer::init();

// Pages nécessaires (créées à l'activation ou via Outils → Pages ISPAG ; jamais automatiquement)
require_once __DIR__ . '/classes/class-ispag-page-installer.php';
ISPAG_Page_Installer::register('ISPAG Achats', require __DIR__ . '/install/pages.php');
register_activation_hook(__FILE__, function () { ISPAG_Page_Installer::on_activation('ISPAG Achats'); });

add_action('init', 'ispag_load_textdomain');

new ISPAG_Purchase_URL_Rewrite();

add_action('init', function () {
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

    ISPAG_CarryBox_Manager::init();
    
    // ISPAG_Ajax_Handler::init();

    
});










