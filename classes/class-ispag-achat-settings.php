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
    const OPT_TRANSPORT_RATE = 'ispag_achats_transport_per_1000l'; // montant du transport par tranche de 1000 L
    const OPT_CUSTOMS_RATE   = 'wpcb_custom_fee';                   // taux de dédouanement (%), aussi réglable dans ISPAG Settings
    const DEFAULT_TRANSPORT_RATE = 250.0;
    const DEFAULT_CUSTOMS_RATE   = 10.0;
    const LEGACY_IDS = [1, 3, 395]; // anciens Id (achats_fournisseurs)

    public static function init() {
        add_action('admin_menu', [self::class, 'admin_menu']);
    }

    /** Montant du transport par tranche de 1000 L de cuves (dans la devise du site, CHF par défaut). */
    public static function transport_rate(): float {
        $v = get_option(self::OPT_TRANSPORT_RATE, '');
        return ($v === '' || !is_numeric($v)) ? self::DEFAULT_TRANSPORT_RATE : (float) $v;
    }

    /** Taux de dédouanement en % du montant net (même option que ISPAG Settings → Customs clearance rate). */
    public static function customs_rate(): float {
        $v = get_option(self::OPT_CUSTOMS_RATE, '');
        return ($v === '' || !is_numeric($v)) ? self::DEFAULT_CUSTOMS_RATE : (float) $v;
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
            if (isset($_POST['transport_rate']) && is_numeric(str_replace(',', '.', $_POST['transport_rate']))) {
                update_option(self::OPT_TRANSPORT_RATE, max(0, (float) str_replace(',', '.', $_POST['transport_rate'])), false);
            }
            if (isset($_POST['customs_rate']) && is_numeric(str_replace(',', '.', $_POST['customs_rate']))) {
                update_option(self::OPT_CUSTOMS_RATE, max(0, (float) str_replace(',', '.', $_POST['customs_rate'])));
            }
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
                <h2><?php esc_html_e('Automatic amounts', 'creation-reservoir'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="transport_rate"><?php esc_html_e('Transport per 1000 L of tanks', 'creation-reservoir'); ?></label></th>
                        <td><input type="number" step="0.01" min="0" id="transport_rate" name="transport_rate" value="<?php echo esc_attr(self::transport_rate()); ?>" class="small-text"> <?php echo esc_html(get_option('wpcb_currency', 'CHF')); ?>
                            <p class="description"><?php esc_html_e('Amount of the TRANS line, per started 1000 L, for the suppliers ticked below.', 'creation-reservoir'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="customs_rate"><?php esc_html_e('Customs clearance rate (%)', 'creation-reservoir'); ?></label></th>
                        <td><input type="number" step="0.01" min="0" id="customs_rate" name="customs_rate" value="<?php echo esc_attr(self::customs_rate()); ?>" class="small-text"> %
                            <p class="description"><?php esc_html_e('Percentage of the net total for the DED line (orders in EUR). This is the same setting as “Customs clearance rate” in ISPAG Settings.', 'creation-reservoir'); ?></p></td>
                    </tr>
                </tbody></table>

                <h2><?php esc_html_e('Suppliers with automatic transport', 'creation-reservoir'); ?></h2>
                <p class="description"><?php esc_html_e('For these suppliers, an order containing tanks offers to add the transport line (TRANS line, see the amount above). Tick the suppliers concerned.', 'creation-reservoir'); ?></p>
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
