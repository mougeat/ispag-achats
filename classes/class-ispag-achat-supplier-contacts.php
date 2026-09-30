<?php
defined('ABSPATH') or die();

/**
 * Contacts d'un fournisseur affichés (et modifiables) dans la fiche achat : commande/offre, plan, facturation, livraison.
 * Chaque rôle est une colonne de achats_fournisseurs contenant l'ID d'un utilisateur WordPress (contact CRM).
 */
class ISPAG_Achat_Supplier_Contacts {

    /** colonne => libellé */
    public static function roles() {
        return [
            'IdContactCommande'    => __('Order / quotation contact', 'creation-reservoir'),
            'IdContactPlan'        => __('Drawing contact', 'creation-reservoir'),
            'IdContactFacturation' => __('Billing contact', 'creation-reservoir'),
            'IdContactLivraison'   => __('Delivery contact', 'creation-reservoir'),
        ];
    }

    public static function init() {
        add_action('wp_ajax_ispag_achat_search_contacts', [self::class, 'ajax_search']);
        add_action('wp_ajax_ispag_achat_set_supplier_contact', [self::class, 'ajax_set']);
    }

    /** Ligne d'un contact (nom, e-mail, téléphone). */
    public static function render_row($supplier, $column, $can_edit) {
        $roles = self::roles();
        $uid   = isset($supplier->$column) ? (int) $supplier->$column : 0;
        $user  = $uid > 0 ? get_userdata($uid) : null;

        ob_start();
        echo '<div class="ispag-supplier-contact" data-role="' . esc_attr($column) . '" data-supplier-id="' . (int) $supplier->Id . '" style="margin:8px 0; padding-bottom:6px; border-bottom:1px solid #eee;">';
        echo '<div style="color:#666; font-size:12px;">' . esc_html($roles[$column]) . '</div>';
        echo '<div class="ispag-sc-info">';
        if ($user) {
            $phone = get_user_meta($uid, 'billing_phone', true);
            echo '<strong><a href="' . esc_url(home_url('/contact/' . $uid . '/')) . '">' . esc_html($user->display_name) . '</a></strong>';
            if ($user->user_email) {
                echo '<br><a href="mailto:' . esc_attr($user->user_email) . '">' . esc_html($user->user_email) . '</a>';
            }
            if ($phone) {
                echo '<br><a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>';
            }
        } else {
            echo '<span style="color:#999;">—</span>';
        }
        if ($can_edit) {
            echo ' <span class="ispag-sc-edit" role="button" tabindex="0" title="' . esc_attr__('Change', 'creation-reservoir') . '" style="cursor:pointer; margin-left:4px;">✏️</span>';
        }
        echo '</div></div>';
        return ob_get_clean();
    }

    /** Carte « Contacts » complète (colonne de droite). */
    public static function render_card($supplier, $can_edit) {
        if (!$supplier) {
            return '';
        }
        $html = '<div class="ispag-card ispag-contact-card ispag-supplier-contacts" style="font-size:14px;"><h5>' . esc_html__('Contacts', 'creation-reservoir') . '</h5>';
        foreach (array_keys(self::roles()) as $column) {
            $html .= self::render_row($supplier, $column, $can_edit);
        }
        return $html . '</div>';
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    private static function guard() {
        check_ajax_referer('ispag_achat_nonce', 'nonce');
        if (!current_user_can('edit_supplier_order')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    public static function ajax_search() {
        self::guard();
        $term = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
        if (mb_strlen($term) < 2) {
            wp_send_json_success(['results' => []]);
        }
        $query = new WP_User_Query([
            'search'         => '*' . $term . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name', 'user_nicename'],
            'number'         => 15,
            'orderby'        => 'display_name',
            'fields'         => ['ID', 'display_name', 'user_email'],
        ]);
        $results = [];
        foreach ((array) $query->get_results() as $u) {
            $results[] = ['id' => (int) $u->ID, 'name' => $u->display_name, 'mail' => $u->user_email];
        }
        wp_send_json_success(['results' => $results]);
    }

    public static function ajax_set() {
        self::guard();
        global $wpdb;
        $supplier_id = absint($_POST['supplier_id'] ?? 0);
        $column      = sanitize_text_field(wp_unslash($_POST['role'] ?? ''));
        $user_id     = absint($_POST['user_id'] ?? 0); // 0 = retirer le contact
        if (!$supplier_id || !array_key_exists($column, self::roles())) {
            wp_send_json_error('bad_request', 400);
        }
        if ($user_id && !get_userdata($user_id)) {
            wp_send_json_error('unknown_user', 400);
        }
        $table = $wpdb->prefix . 'achats_fournisseurs';
        if ($wpdb->update($table, [$column => $user_id], ['Id' => $supplier_id], ['%d'], ['%d']) === false) {
            wp_send_json_error('db_error', 500);
        }
        $supplier = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE Id = %d", $supplier_id));
        wp_send_json_success(['html' => self::render_row($supplier, $column, true)]);
    }
}
