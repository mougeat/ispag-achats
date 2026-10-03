<?php
defined('ABSPATH') or die();

/**
 * Contacts d'un fournisseur affichés (et modifiables) dans la fiche achat : commande/offre, plan, facturation, livraison.
 * Chaque rôle est une meta de ispag_companies_meta (voir ISPAG_Achat_Supplier_Repository::LEGACY_FIELDS) contenant l'ID d'un utilisateur WordPress (contact CRM).
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

    /**
     * IDs des contacts (utilisateurs) qui appartiennent au fournisseur :
     *  - rattachés à une entreprise du CRM qui correspond au fournisseur (même entreprise, domaine ou nom identique) ;
     *  - dont l'e-mail est au domaine du fournisseur ;
     *  - déjà choisis sur ce fournisseur (commande, plan, facturation, livraison).
     */
    public static function supplier_user_ids($supplier) {
        global $wpdb;
        $ids = [];
        foreach (array_keys(self::roles()) as $col) {
            if (!empty($supplier->$col)) {
                $ids[] = (int) $supplier->$col;
            }
        }

        $companies_table = $wpdb->prefix . 'ispag_companies';
        $company_ids = [];
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $companies_table)) === $companies_table) {
            $domain = strtolower(trim(preg_replace('#^https?://(www\.)?#i', '', (string) $supplier->compagnyDomain), " /"));
            $self_id = (int) $supplier->Id;
            $name    = trim((string) $supplier->Fournisseur);
            $company_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT Id FROM {$companies_table}
                 WHERE Id = %d
                    OR (%s <> '' AND LOWER(compagny_domain) = %s)
                    OR (%s <> '' AND LOWER(company_name) = LOWER(%s))",
                $self_id, $domain, $domain, $name, $name
            ));
        }
        if ($company_ids) {
            $ph = implode(',', array_fill(0, count($company_ids), '%d'));
            $like = [];
            $args = [];
            foreach ($company_ids as $cid) {
                $like[] = 'FIND_IN_SET(%d, REPLACE(m.meta_value, \' \', \'\'))';
                $args[] = (int) $cid;
            }
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT m.user_id FROM {$wpdb->usermeta} m WHERE m.meta_key = 'ispag_company_id' AND (" . implode(' OR ', $like) . ')',
                ...$args
            ));
            $ids = array_merge($ids, array_map('intval', $rows));
        }

        $mail_domain = strtolower(trim(preg_replace('#^https?://(www\.)?#i', '', (string) $supplier->compagnyDomain), " /"));
        if ($mail_domain !== '' && strpos($mail_domain, '.') !== false) {
            $rows = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_email LIKE %s", '%@' . $wpdb->esc_like($mail_domain)));
            $ids = array_merge($ids, array_map('intval', $rows));
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private static function get_supplier($supplier_id) {
        global $wpdb;
        return ISPAG_Achat_Supplier_Repository::get_supplier_row($supplier_id);
    }

    /** Recherche UNIQUEMENT parmi les contacts du fournisseur (sans texte : tous ses contacts). */
    public static function ajax_search() {
        self::guard();
        $supplier = self::get_supplier(absint($_POST['supplier_id'] ?? 0));
        if (!$supplier) {
            wp_send_json_error('unknown_supplier', 400);
        }
        $term = mb_strtolower(sanitize_text_field(wp_unslash($_POST['q'] ?? '')));
        $results = [];
        foreach (self::supplier_user_ids($supplier) as $uid) {
            $u = get_userdata($uid);
            if (!$u) {
                continue;
            }
            $hay = mb_strtolower($u->display_name . ' ' . $u->user_email . ' ' . $u->first_name . ' ' . $u->last_name);
            if ($term === '' || strpos($hay, $term) !== false) {
                $results[] = ['id' => (int) $u->ID, 'name' => $u->display_name, 'mail' => $u->user_email];
            }
        }
        usort($results, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        wp_send_json_success(['results' => array_slice($results, 0, 50)]);
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
        $supplier_check = self::get_supplier($supplier_id);
        if (!$supplier_check || ($user_id && (!get_userdata($user_id) || !in_array($user_id, self::supplier_user_ids($supplier_check), true)))) {
            wp_send_json_error('not_a_contact_of_this_supplier', 400);
        }
        $logical = ISPAG_Achat_Supplier_Repository::LEGACY_FIELDS[$column];
        if (!ISPAG_Achat_Supplier_Repository::set_supplier_meta($supplier_id, $logical, $user_id)) {
            wp_send_json_error('db_error', 500);
        }
        $supplier = ISPAG_Achat_Supplier_Repository::get_supplier_row($supplier_id);
        wp_send_json_success(['html' => self::render_row($supplier, $column, true)]);
    }
}
