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

        $wpdb->insert(self::$table, [
            'IdAchat'   => $achat_id,
            'Field'     => $field,
            'OldValue'  => $old_value,
            'NewValue'  => $new_value,
            'UserId'    => get_current_user_id(),
            'Date'      => current_time('mysql')
        ]);
    }
}
