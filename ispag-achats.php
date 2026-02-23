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

add_action('init', function () {
    ISPAG_Achat_Repository::init();
    ISPAG_Achat_Manager::init();
    ISPAG_Achat_Status_Controller::init();
    ISPAG_Achat_Renderer::init();
    ISPAG_Achat_Logger::init();
    ISPAG_Achat_Details_Renderer::init();
    ISPAG_Achat_Status_Checker::init();
    ISPAG_Achat_status_render::init();
    new ISPAG_Document_Manager();
    ISPAG_Achat_Article_Repository::init();
    ISPAG_Achat_Generate_Purchase_Order_PDF::init();
    ISPAG_Achat_Supplier_Repository::init();
    ISPAG_Achat_Document_Analyser::init();
    ISPAG_Achat_Commande_Manager::init();
    
    // ISPAG_Ajax_Handler::init();

    
});










