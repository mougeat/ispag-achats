<?php

class ISPAG_Achat_Logger {
    private static $table;

    public static function init() {
        global $wpdb;
        self::$table = $wpdb->prefix . 'achats_historique';
    }

    public static function log_change($achat_id, $field, $old_value, $new_value) {
        global $wpdb;

        if ($old_value === $new_value) return;

        $wpdb->insert(
            self::$table, 
            [
                'hubspot_deal_id' => $achat_id, // Si IdAchat correspond à l'ID HubSpot
                'purchase_order'  => 0,         // À remplacer par ta variable si tu en as une
                'Date'            => time(),    // Stocke le Timestamp Unix (bigint)
                'dateReadable'    => current_time('mysql'), // Stocke la date lisible (datetime)
                'IdUser'          => get_current_user_id(),
                'Historique'      => "Modification du champ {$field} : de '{$old_value}' à '{$new_value}'", // On log le changement dans le texte
                'IdMedia'         => 0,
                'is_task'         => 0,
                'is_done'         => 0,
                'ClassCss'        => 'text-muted' // Exemple de classe CSS
            ],
            [
                '%d', // hubspot_deal_id (int)
                '%d', // purchase_order (int)
                '%d', // Date (bigint)
                '%s', // dateReadable (datetime / string)
                '%d', // IdUser (int)
                '%s', // Historique (text)
                '%d', // IdMedia (int)
                '%d', // is_task (tinyint)
                '%d', // is_done (tinyint)
                '%s'  // ClassCss (text)
            ]
        );
    }
}
