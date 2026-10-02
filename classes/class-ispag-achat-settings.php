<?php
defined('ABSPATH') || exit;

/**
 * Réglages des achats (ISPAG Settings → Purchase settings).
 *
 * Fournisseurs « transport automatique » : pour ces fournisseurs, une commande contenant des cuves propose
 * d'ajouter la ligne TRANS (250 CHF par tranche de 1000 L). La liste était écrite en dur avec les anciens Id de
 * achats_fournisseurs (1, 3, 395) ; depuis le passage des fournisseurs dans ispag_companies, ces Id désignent d'autres
 * sociétés : le transport n'était plus proposé. La liste est maintenant un réglage.
 */
class ISPAG_Achat_Settings {

    const OPTION = 'ispag_achats_transport_suppliers';
    const LEGACY_IDS = [1, 3, 395]; // anciens Id (achats_fournisseurs)

    public static function init() {
        add_action('admin_menu', [self::class, 'admin_menu']);
    }

    /** @return int[] Id (ispag_companies) des fournisseurs avec transport automatique. */
    public static function transport_supplier_ids(): array {
        $saved = get_option(self::OPTION, null);
        if (is_array($saved)) {
            return array_map('intval', $saved);
        }
        // Jamais enregistré : on reprend les anciens Id via la table de correspondance de la migration, si elle existe encore
        $ids = self::migrated_legacy_ids();
        if ($ids) {
            update_option(self::OPTION, $ids, false);
        }
        return $ids;
    }

    private static function migrated_legacy_ids(): array {
        global $wpdb;
        $map = $wpdb->prefix . 'tmp_supplier_map';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $map)) !== $map) {
            return [];
        }
        $in  = implode(',', array_map('intval', self::LEGACY_IDS));
        $ids = $wpdb->get_col("SELECT DISTINCT company_id FROM `{$map}` WHERE old_id IN ({$in})");
        return array_values(array_filter(array_map('intval', (array) $ids)));
    }

    public static function admin_menu() {
        $title = __('Purchase settings', 'creation-reservoir');
        if (class_exists('ISPAG_Settings')) {
            add_submenu_page(ISPAG_Settings::PAGE, $title, $title, 'manage_options', 'ispag-achats-settings', [self::class, 'render']);
        } else {
            add_management_page($title, $title, 'manage_options', 'ispag-achats-settings', [self::class, 'render']);
        }
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not authorized', 'creation-reservoir'));
        }
        global $wpdb;

        $saved_notice = false;
        if (!empty($_POST['ispag_achats_settings_nonce']) && wp_verify_nonce($_POST['ispag_achats_settings_nonce'], 'ispag_achats_settings')) {
            $ids = isset($_POST['transport_suppliers']) ? array_values(array_unique(array_map('absint', (array) $_POST['transport_suppliers']))) : [];
            update_option(self::OPTION, $ids, false);
            $saved_notice = true;
        }

        $selected  = self::transport_supplier_ids();
        $suppliers = $wpdb->get_results("SELECT Id, company_name FROM {$wpdb->prefix}ispag_companies WHERE isSupplier = 1 ORDER BY company_name ASC");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Purchase settings', 'creation-reservoir'); ?></h1>
            <?php if ($saved_notice): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'creation-reservoir'); ?></p></div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('ispag_achats_settings', 'ispag_achats_settings_nonce'); ?>
                <h2><?php esc_html_e('Suppliers with automatic transport', 'creation-reservoir'); ?></h2>
                <p class="description"><?php esc_html_e('For these suppliers, an order containing tanks offers to add the transport line (TRANS: 250 CHF per 1000 L). Tick the suppliers concerned.', 'creation-reservoir'); ?></p>
                <div style="max-height:420px; overflow:auto; background:#fff; border:1px solid #dcdcde; padding:10px 14px; max-width:520px;">
                    <?php foreach ($suppliers as $s): ?>
                        <label style="display:block; padding:2px 0;">
                            <input type="checkbox" name="transport_suppliers[]" value="<?php echo (int) $s->Id; ?>" <?php checked(in_array((int) $s->Id, $selected, true)); ?>>
                            <?php echo esc_html($s->company_name); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
