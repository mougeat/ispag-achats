<?php
defined('ABSPATH') || exit;

/**
 * Modèles d'e-mail des commandes fournisseur (table achats_template_mail, famille « purchase_order »).
 *
 * - Page de gestion dans l'administration : ISPAG Settings → Email templates (droit manage_options).
 * - Un modèle = un type de message × une langue, avec un objet et un texte contenant des balises {TAG}
 *   remplacées à l'envoi (voir tags() et ISPAG_Achat_Status_Controller::replace_text()).
 * - Les modèles par défaut (anglais) sont ajoutés à l'installation : install/default-mail-templates.php.
 */
class ISPAG_Achat_Mail_Templates {

    const FAMILY  = 'purchase_order';
    const NONCE   = 'ispag_mail_templates';
    const DEFAULT_LANG = 'en_US';

    public static function init() {
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('wp_ajax_ispag_mail_template_save', [self::class, 'ajax_save']);
        add_action('wp_ajax_ispag_mail_template_delete', [self::class, 'ajax_delete']);
    }

    // ------------------------------------------------------------------ référentiels

    /** Types de message gérés : clé (colonne message_type) => libellé. */
    public static function types(): array {
        return [
            'send_purchase_order'   => __('Purchase order', 'creation-reservoir'),
            'send_proposal_request' => __('Request for quotation (RFQ)', 'creation-reservoir'),
            'drawing_validated'     => __('Drawing validation', 'creation-reservoir'),
            'drawing_modified'      => __('Drawing modifications', 'creation-reservoir'),
        ];
    }

    /** Langues proposées (code de locale => libellé) ; les langues déjà utilisées par un modèle sont ajoutées. */
    public static function languages(): array {
        $langs = ['en_US' => 'English', 'fr_FR' => 'Français', 'de_DE' => 'Deutsch', 'it_IT' => 'Italiano'];
        global $wpdb;
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT lang FROM " . self::table() . " WHERE message_family = %s", self::FAMILY)) as $l) {
            if ($l !== '' && !isset($langs[$l])) $langs[$l] = $l;
        }
        return $langs;
    }

    /** Balises disponibles : balise => description. */
    public static function tags(): array {
        return [
            '{FIRST_NAME}'       => __('Recipient first name', 'creation-reservoir'),
            '{LAST_NAME}'        => __('Recipient last name', 'creation-reservoir'),
            '{SUPPLIER_NAME}'    => __('Supplier name', 'creation-reservoir'),
            '{ORDER_NUMBER}'     => __('Order number', 'creation-reservoir'),
            '{PROJECT_NAME}'     => __('Project name', 'creation-reservoir'),
            '{ORDER_REF}'        => __('Full order reference (number - project)', 'creation-reservoir'),
            '{ORDER_DATE}'       => __('Order date', 'creation-reservoir'),
            '{PRODUCT_LIST}'     => __('List of items, grouped', 'creation-reservoir'),
            '{DELIVERY_ADDRESS}' => __('Delivery address (full block)', 'creation-reservoir'),
            '{DELIVERY_CONTACT}' => __('Contact on site', 'creation-reservoir'),
            '{DELIVERY_PHONE}'   => __('Contact phone number', 'creation-reservoir'),
            '{PURCHASE_URL}'     => __('Link to the order', 'creation-reservoir'),
            '{USER_NAME}'        => __('Sender name', 'creation-reservoir'),
            '{COMPANY_NAME}'     => __('Your company name', 'creation-reservoir'),
        ];
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'achats_template_mail';
    }

    // ------------------------------------------------------------------ accès aux données

    /**
     * Modèle (objet + texte) pour un type et une langue ; à défaut de modèle dans la langue du fournisseur,
     * on prend le modèle anglais par défaut. Retourne null si aucun.
     */
    public static function get_template(string $type, string $lang) {
        global $wpdb;
        foreach (array_unique([$lang, self::DEFAULT_LANG]) as $l) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT subject, message FROM " . self::table() . " WHERE message_family = %s AND message_type = %s AND lang = %s ORDER BY Id DESC LIMIT 1",
                self::FAMILY, $type, $l
            ));
            if ($row) return $row;
        }
        return null;
    }

    private static function all(): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT Id, lang, subject, message, message_type FROM " . self::table() . " WHERE message_family = %s ORDER BY message_type ASC, lang ASC",
            self::FAMILY
        ));
    }

    // ------------------------------------------------------------------ AJAX

    private static function guard() {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Not authorized', 'creation-reservoir'), 403);
        }
    }

    public static function ajax_save() {
        self::guard();
        global $wpdb;

        $id      = absint($_POST['id'] ?? 0);
        $type    = sanitize_key($_POST['message_type'] ?? '');
        $lang    = sanitize_text_field(wp_unslash($_POST['lang'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

        if (!isset(self::types()[$type]) || !preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $lang)) {
            wp_send_json_error(__('Invalid type or language', 'creation-reservoir'));
        }
        if ($subject === '' || trim($message) === '') {
            wp_send_json_error(__('Subject and message are required', 'creation-reservoir'));
        }

        // Un seul modèle par type et par langue
        $dup = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT Id FROM " . self::table() . " WHERE message_family = %s AND message_type = %s AND lang = %s AND Id <> %d LIMIT 1",
            self::FAMILY, $type, $lang, $id
        ));
        if ($dup) {
            wp_send_json_error(__('A template already exists for this type and language', 'creation-reservoir'));
        }

        $data = ['subject' => $subject, 'message' => $message, 'message_type' => $type, 'lang' => $lang, 'message_family' => self::FAMILY];
        if ($id) {
            $ok = $wpdb->update(self::table(), $data, ['Id' => $id, 'message_family' => self::FAMILY]) !== false;
        } else {
            $ok = $wpdb->insert(self::table(), $data + [
                'Brevo_id' => 0, 'prompt' => '', 'join_doc_typ' => '', 'selectionnable' => 1, 'created_by' => get_current_user_id(),
            ]) !== false;
        }
        if (!$ok) {
            wp_send_json_error(__('Error while saving', 'creation-reservoir'));
        }
        wp_send_json_success(['html' => self::render_list()]);
    }

    public static function ajax_delete() {
        self::guard();
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        if ($id) {
            $wpdb->delete(self::table(), ['Id' => $id, 'message_family' => self::FAMILY]);
        }
        wp_send_json_success(['html' => self::render_list()]);
    }

    // ------------------------------------------------------------------ page

    public static function admin_menu() {
        $title = __('Email templates', 'creation-reservoir');
        if (class_exists('ISPAG_Settings')) {
            add_submenu_page(ISPAG_Settings::PAGE, $title, $title, 'manage_options', 'ispag-mail-templates', [self::class, 'render_admin']);
        } else {
            add_management_page($title, $title, 'manage_options', 'ispag-mail-templates', [self::class, 'render_admin']);
        }
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets($hook) {
        if (strpos((string) $hook, 'ispag-mail-templates') === false) return;

        wp_enqueue_style('ispag-mail-templates', plugins_url('../assets/css/mail-templates.css', __FILE__), [], filemtime(dirname(__DIR__) . '/assets/css/mail-templates.css'));
        wp_enqueue_script('ispag-mail-templates', plugins_url('../assets/js/mail-templates.js', __FILE__), ['jquery'], filemtime(dirname(__DIR__) . '/assets/js/mail-templates.js'), true);
        wp_localize_script('ispag-mail-templates', 'ispagMailTpl', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
            'confirmDelete' => __('Delete this template?', 'creation-reservoir'),
            'defaultLang'   => self::DEFAULT_LANG,
            // valeurs d'exemple pour l'aperçu
            'sample' => [
                '{FIRST_NAME}' => 'Anna', '{LAST_NAME}' => 'Muller', '{SUPPLIER_NAME}' => 'ACME GmbH',
                '{ORDER_NUMBER}' => 'KST300/21111', '{PROJECT_NAME}' => 'Test project', '{ORDER_REF}' => 'KST300/21111 - Test project',
                '{ORDER_DATE}' => date_i18n('d.m.Y'), '{PRODUCT_LIST}' => "🟢 Tanks\n\nEnergy accumulator 1500 liters on ring\nUninsulated diameter : 1 000 mm",
                '{DELIVERY_ADDRESS}' => "ISPAG\nChamps-Paccot 19\n1627 Vaulruz", '{DELIVERY_CONTACT}' => 'John Doe', '{DELIVERY_PHONE}' => '+41 79 000 00 00',
                '{PURCHASE_URL}' => home_url('/details-achats/?poid=123'),
                '{USER_NAME}' => wp_get_current_user()->display_name, '{COMPANY_NAME}' => (string) get_option('wpcb_companyName'),
            ],
        ]);
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not authorized', 'creation-reservoir'));
        }
        echo '<div class="wrap">' . self::render_page() . '</div>';
    }

    private static function render_page(): string {
        ob_start();
        ?>
        <div class="ispag-mailtpl" id="ispag-mailtpl">
            <div class="ispag-mailtpl-head">
                <h1><?php esc_html_e('Email templates', 'creation-reservoir'); ?></h1>
                <button type="button" class="button" id="ispag-mailtpl-new">＋ <?php esc_html_e('New template', 'creation-reservoir'); ?></button>
            </div>
            <p class="ispag-mailtpl-help"><?php esc_html_e('One template per message type and language. If a supplier’s language has no template, the English one is used.', 'creation-reservoir'); ?></p>

            <div id="ispag-mailtpl-list"><?php echo self::render_list(); ?></div>

            <form id="ispag-mailtpl-form" hidden>
                <h3 id="ispag-mailtpl-form-title"></h3>
                <input type="hidden" name="id" value="0">
                <div class="ispag-mailtpl-row">
                    <label><span><?php esc_html_e('Message type', 'creation-reservoir'); ?></span>
                        <select name="message_type">
                            <?php foreach (self::types() as $k => $label): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select></label>
                    <label><span><?php esc_html_e('Language', 'creation-reservoir'); ?></span>
                        <select name="lang">
                            <?php foreach (self::languages() as $k => $label): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label . ' (' . $k . ')'); ?></option>
                            <?php endforeach; ?>
                        </select></label>
                </div>
                <label><span><?php esc_html_e('Subject', 'creation-reservoir'); ?></span>
                    <input type="text" name="subject" maxlength="250"></label>
                <label><span><?php esc_html_e('Message', 'creation-reservoir'); ?></span>
                    <textarea name="message" rows="14"></textarea></label>

                <div class="ispag-mailtpl-tags">
                    <strong><?php esc_html_e('Fields replaced when the email is sent — click to insert:', 'creation-reservoir'); ?></strong>
                    <div>
                        <?php foreach (self::tags() as $tag => $desc): ?>
                            <button type="button" class="ispag-mailtpl-tag" data-tag="<?php echo esc_attr($tag); ?>" title="<?php echo esc_attr($desc); ?>"><?php echo esc_html($tag); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ispag-mailtpl-preview" hidden>
                    <strong><?php esc_html_e('Preview (sample data)', 'creation-reservoir'); ?></strong>
                    <div class="ispag-mailtpl-preview-subject"></div>
                    <pre class="ispag-mailtpl-preview-body"></pre>
                </div>

                <div class="ispag-mailtpl-actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save', 'creation-reservoir'); ?></button>
                    <button type="button" class="button" id="ispag-mailtpl-preview-btn"><?php esc_html_e('Preview', 'creation-reservoir'); ?></button>
                    <button type="button" class="button" id="ispag-mailtpl-cancel"><?php esc_html_e('Cancel', 'creation-reservoir'); ?></button>
                    <span class="ispag-mailtpl-status" aria-live="polite"></span>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Tableau des modèles (rechargé après chaque enregistrement / suppression). */
    private static function render_list(): string {
        $types = self::types();
        $rows  = self::all();
        ob_start();
        if (!$rows) {
            echo '<p class="ispag-mailtpl-empty">' . esc_html__('No template yet.', 'creation-reservoir') . '</p>';
        } else {
            echo '<table class="ispag-mailtpl-table"><thead><tr><th>' . esc_html__('Type', 'creation-reservoir') . '</th><th>'
                . esc_html__('Language', 'creation-reservoir') . '</th><th>' . esc_html__('Subject', 'creation-reservoir') . '</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $label = $types[$r->message_type] ?? $r->message_type;
                echo '<tr data-id="' . (int) $r->Id . '" data-type="' . esc_attr($r->message_type) . '" data-lang="' . esc_attr($r->lang) . '"'
                    . ' data-subject="' . esc_attr($r->subject) . '" data-message="' . esc_attr($r->message) . '">'
                    . '<td>' . esc_html($label) . '</td><td>' . esc_html($r->lang) . '</td><td>' . esc_html($r->subject) . '</td>'
                    . '<td class="ispag-mailtpl-row-actions">'
                    . '<button type="button" class="button ispag-mailtpl-edit">✏️ ' . esc_html__('Edit', 'creation-reservoir') . '</button> '
                    . '<button type="button" class="button ispag-mailtpl-copy" title="' . esc_attr__('Duplicate (e.g. to translate)', 'creation-reservoir') . '">⧉</button> '
                    . '<button type="button" class="button ispag-mailtpl-delete" title="' . esc_attr__('Delete', 'creation-reservoir') . '">🗑</button>'
                    . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        return ob_get_clean();
    }
}
