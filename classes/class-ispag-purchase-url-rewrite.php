<?php
if (!class_exists('ISPAG_Purchase_URL_Rewrite')) {
    class ISPAG_Purchase_URL_Rewrite {
        public function __construct() {
            add_action('init', [$this, 'add_rewrite_rules']);
            add_filter('query_vars', [$this, 'add_query_vars']);
        }

        public function add_rewrite_rules() {
            // Règle FR : /project-detail/123456
            add_rewrite_rule(
                '^purchase/([0-9]+)/?$',
                'index.php?pagename=details-achats&poid=$matches[1]',
                'top'
            );

            // Règle DE avec le préfixe /de/ dans le regex
            add_rewrite_rule(
                '^de/purchase/([0-9]+)/?$',
                'index.php?pagename=einkaufsdetail&poid=$matches[1]&lang=de',
                'top'
            );

            // Règle DE sans le préfixe /de/ au cas où (ex: domaine dédié ou réglage spécifique)
            add_rewrite_rule(
                '^einkaufsdetail/([0-9]+)/?$',
                'index.php?pagename=einkaufsdetail&poid=$matches[1]&lang=de',
                'top'
            );
            
        }

        public function add_query_vars($vars) {
            $vars[] = 'poid';
            return $vars;
        }
    }
}