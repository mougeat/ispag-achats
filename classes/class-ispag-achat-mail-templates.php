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
    const FAMILY_PROJECT = 'project_mail'; // e-mails clients d'étape de projet (envoyés par ISPAG Project Manager)
    const NONCE   = 'ispag_mail_templates';
    const DEFAULT_LANG = 'en_US';

    public static function init() {
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('wp_ajax_ispag_mail_template_save', [self::class, 'ajax_save']);
        add_action('wp_ajax_ispag_mail_template_delete', [self::class, 'ajax_delete']);
    }

    // ------------------------------------------------------------------ référentiels

    /** Familles de modèles : clé (colonne message_family) => libellé. */
    public static function families(): array {
        return [
            self::FAMILY         => __('Supplier orders', 'creation-reservoir'),
            self::FAMILY_PROJECT => __('Project follow-up (customer)', 'creation-reservoir'),
        ];
    }

    /** Types de message des commandes fournisseur : clé (colonne message_type) => libellé. */
    private static function purchase_types(): array {
        return [
            'send_purchase_order'   => __('Purchase order', 'creation-reservoir'),
            'send_proposal_request' => __('Request for quotation (RFQ)', 'creation-reservoir'),
            'drawing_validated'     => __('Drawing validation', 'creation-reservoir'),
            'drawing_modified'      => __('Drawing modifications', 'creation-reservoir'),
        ];
    }

    /** Types d'e-mail client : un par étape du suivi de projet (SlugPhase => titre de l'étape). */
    private static function project_types(): array {
        global $wpdb;
        $slug_table = $wpdb->prefix . 'achats_slug_phase';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $slug_table)) !== $slug_table) return [];
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT SlugPhase, TitrePhase FROM {$slug_table}
             WHERE Brevo_id > 0 OR SlugPhase IN (SELECT message_type FROM " . self::table() . " WHERE message_family = %s)
             ORDER BY Ordre ASC",
            self::FAMILY_PROJECT
        )) as $r) {
            $out[$r->SlugPhase] = $r->TitrePhase . ' (' . $r->SlugPhase . ')';
        }
        return $out;
    }

    /** Types par famille : [famille => [type => libellé]]. */
    public static function types_by_family(): array {
        return [self::FAMILY => self::purchase_types(), self::FAMILY_PROJECT => self::project_types()];
    }

    /** Tous les types, à plat (la clé d'un type est unique d'une famille à l'autre). */
    public static function types(): array {
        return self::purchase_types() + self::project_types();
    }

    /** Langues proposées (code de locale => libellé) ; les langues déjà utilisées par un modèle sont ajoutées. */
    public static function languages(): array {
        $langs = ['en_US' => 'English', 'fr_FR' => 'Français', 'de_DE' => 'Deutsch', 'it_IT' => 'Italiano'];
        global $wpdb;
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT lang FROM " . self::table() . " WHERE message_family IN (%s, %s)", self::FAMILY, self::FAMILY_PROJECT)) as $l) {
            if ($l !== '' && !isset($langs[$l])) $langs[$l] = $l;
        }
        return $langs;
    }

    /** Balises des e-mails clients d'étape (remplacées par ISPAG_Phase_Mail du plugin ISPAG Project Manager). */
    public static function project_tags(): array {
        return [
            '{PRENOM}'         => __('Recipient first name', 'creation-reservoir'),
            '{NOM}'            => __('Recipient last name', 'creation-reservoir'),
            '{PROJECT_NAME}'   => __('Project name', 'creation-reservoir'),
            '{PROJECT_NUMBER}' => __('Order number', 'creation-reservoir'),
            '{PROJECT_URL}'    => __('Link to the project (address only)', 'creation-reservoir'),
            '{PROJECT_LINK}'   => __('Link to the project (clickable: "view the project" in the recipient language)', 'creation-reservoir'),
            '{PRODUCT_LIST}'   => __('List of items, grouped', 'creation-reservoir'),
            '{DELIVERY_LIST}'  => __('Items not delivered yet that have a delivery date, grouped, with their planned delivery', 'creation-reservoir'),
            '{DELIVERY_DATE}'  => __('Planned delivery date (or period)', 'creation-reservoir'),
            '{DELIVERY_ADRESS}' => __('Delivery address', 'creation-reservoir'),
            '{DELIVERY_NIP}'   => __('Delivery postal code', 'creation-reservoir'),
            '{DELIVERY_CITY}'  => __('Delivery city', 'creation-reservoir'),
            '{DELIVERY_CONTACT}' => __('On-site contact', 'creation-reservoir'),
            '{DELIVERY_CONTACT_PHONE}' => __('On-site contact phone', 'creation-reservoir'),
            '{RETURN_DATE}'    => __('Deadline to return the approved drawings (working days from sending, set in ISPAG Settings → Plan reminders); in a reminder, the date of the next reminder', 'creation-reservoir'),
            '{ORDER_DATE}'     => __('Order date', 'creation-reservoir'),
            '{RETURN_DAYS}'    => __('Number of working days given to return the drawings', 'creation-reservoir'),
            '{SURVEY_LINK}'    => __('Satisfaction survey link', 'creation-reservoir'),
            '{IF_DRAWINGS}'    => __('Start of a text kept only if the order contains a type 1 item (drawings to approve) — close it with {/IF_DRAWINGS}', 'creation-reservoir'),
            '{/IF_DRAWINGS}'   => __('End of the "with drawings" text', 'creation-reservoir'),
            '{IF_NO_DRAWINGS}' => __('Start of a text kept only if the order has no type 1 item — close it with {/IF_NO_DRAWINGS}', 'creation-reservoir'),
            '{/IF_NO_DRAWINGS}' => __('End of the "without drawings" text', 'creation-reservoir'),
            '{IF_ATTACHMENTS}' => __('Start of a text kept only if a document is attached to the e-mail — close it with {/IF_ATTACHMENTS}', 'creation-reservoir'),
            '{/IF_ATTACHMENTS}' => __('End of the "with attachments" text', 'creation-reservoir'),
            '{IF_NO_ATTACHMENTS}' => __('Start of a text kept only if no document is attached — close it with {/IF_NO_ATTACHMENTS}', 'creation-reservoir'),
            '{/IF_NO_ATTACHMENTS}' => __('End of the "without attachments" text', 'creation-reservoir'),
            '{USER_NAME}'      => __('Name of the person who triggered the e-mail', 'creation-reservoir'),
        ];
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
            "SELECT Id, lang, subject, message, message_type, message_family FROM " . self::table() . " WHERE message_family IN (%s, %s) ORDER BY message_family ASC, message_type ASC, lang ASC",
            self::FAMILY, self::FAMILY_PROJECT
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
        $family  = sanitize_key($_POST['message_family'] ?? self::FAMILY);
        $type    = sanitize_text_field(wp_unslash($_POST['message_type'] ?? ''));
        $lang    = sanitize_text_field(wp_unslash($_POST['lang'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

        if (!isset(self::families()[$family]) || !isset(self::types_by_family()[$family][$type]) || !preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $lang)) {
            wp_send_json_error(__('Invalid type or language', 'creation-reservoir'));
        }
        if ($subject === '' || trim($message) === '') {
            wp_send_json_error(__('Subject and message are required', 'creation-reservoir'));
        }

        // Un seul modèle par type et par langue
        $dup = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT Id FROM " . self::table() . " WHERE message_family = %s AND message_type = %s AND lang = %s AND Id <> %d LIMIT 1",
            $family, $type, $lang, $id
        ));
        if ($dup) {
            wp_send_json_error(__('A template already exists for this type and language', 'creation-reservoir'));
        }

        $data = ['subject' => $subject, 'message' => $message, 'message_type' => $type, 'lang' => $lang, 'message_family' => $family];
        if ($id) {
            // created_by renseigné = modèle modifié par un utilisateur : l'installateur ne le remplace plus jamais
            $ok = $wpdb->update(self::table(), $data + ['created_by' => get_current_user_id()], ['Id' => $id, 'message_family' => $family]) !== false;
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
            $wpdb->query($wpdb->prepare(
                "DELETE FROM " . self::table() . " WHERE Id = %d AND message_family IN (%s, %s)",
                $id, self::FAMILY, self::FAMILY_PROJECT
            ));
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
            'typesByFamily' => self::types_by_family(),
            'sampleProject' => [
                '{PRENOM}' => 'Anna', '{NOM}' => 'Muller', '{PROJECT_NAME}' => 'Test project', '{PROJECT_NUMBER}' => 'KST300/21111',
                '{PROJECT_URL}' => home_url('/project-detail/123'), '{PROJECT_LINK}' => 'Test project', '{PRODUCT_LIST}' => "Tanks\n- Energy accumulator 1500 liters",
                '{DELIVERY_DATE}' => date_i18n('d.m.Y'), '{DELIVERY_LIST}' => "Tanks\n- Energy accumulator 1500 liters: " . date_i18n('d.m.Y'), '{RETURN_DATE}' => date_i18n('d.m.Y', strtotime('+7 days')), '{RETURN_DAYS}' => '5', '{ORDER_DATE}' => date_i18n('d.m.Y'), '{DELIVERY_ADRESS}' => 'Champs-Paccot 19', '{DELIVERY_NIP}' => '1627', '{DELIVERY_CITY}' => 'Vaulruz',
                '{DELIVERY_CONTACT}' => 'John Doe', '{DELIVERY_CONTACT_PHONE}' => '+41 79 000 00 00', '{SURVEY_LINK}' => 'https://example.com/survey',
                '{USER_NAME}' => wp_get_current_user()->display_name,
            ],
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

            <div class="ispag-mailtpl-filters">
                <input type="search" id="ispag-mailtpl-search" placeholder="<?php esc_attr_e('Search a template (type, language, subject, text)…', 'creation-reservoir'); ?>" aria-label="<?php esc_attr_e('Search', 'creation-reservoir'); ?>">
                <select id="ispag-mailtpl-family-filter" aria-label="<?php esc_attr_e('Family', 'creation-reservoir'); ?>">
                    <option value=""><?php esc_html_e('All families', 'creation-reservoir'); ?></option>
                    <?php foreach (self::families() as $k => $label): ?>
                        <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="ispag-mailtpl-count" aria-live="polite"></span>
            </div>

            <div id="ispag-mailtpl-list"><?php echo self::render_list(); ?></div>

            <form id="ispag-mailtpl-form" hidden>
                <h3 id="ispag-mailtpl-form-title"></h3>
                <input type="hidden" name="id" value="0">
                <div class="ispag-mailtpl-row">
                    <label><span><?php esc_html_e('Family', 'creation-reservoir'); ?></span>
                        <select name="message_family">
                            <?php foreach (self::families() as $k => $label): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select></label>
                    <label><span><?php esc_html_e('Message type', 'creation-reservoir'); ?></span>
                        <select name="message_type">
                            <?php foreach (self::types_by_family() as $fam => $types): foreach ($types as $k => $label): ?>
                                <option value="<?php echo esc_attr($k); ?>" data-family="<?php echo esc_attr($fam); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; endforeach; ?>
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
                    <?php foreach ([self::FAMILY => self::tags(), self::FAMILY_PROJECT => self::project_tags()] as $fam => $tags): ?>
                        <div class="ispag-mailtpl-tagset" data-family="<?php echo esc_attr($fam); ?>">
                            <?php foreach ($tags as $tag => $desc): ?>
                                <button type="button" class="ispag-mailtpl-tag" data-tag="<?php echo esc_attr($tag); ?>" title="<?php echo esc_attr($desc); ?>"><?php echo esc_html($tag); ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
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
        $families = self::families();
        $rows  = self::all();
        ob_start();
        if (!$rows) {
            echo '<p class="ispag-mailtpl-empty">' . esc_html__('No template yet.', 'creation-reservoir') . '</p>';
        } else {
            echo '<table class="ispag-mailtpl-table"><thead><tr><th>' . esc_html__('Family', 'creation-reservoir') . '</th><th>' . esc_html__('Type', 'creation-reservoir') . '</th><th>'
                . esc_html__('Language', 'creation-reservoir') . '</th><th>' . esc_html__('Subject', 'creation-reservoir') . '</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $label = $types[$r->message_type] ?? $r->message_type;
                $family_label = $families[$r->message_family] ?? $r->message_family;
                echo '<tr data-id="' . (int) $r->Id . '" data-family="' . esc_attr($r->message_family) . '" data-type="' . esc_attr($r->message_type) . '" data-lang="' . esc_attr($r->lang) . '"'
                    . ' data-subject="' . esc_attr($r->subject) . '" data-message="' . esc_attr($r->message) . '" data-search="' . esc_attr(wp_strip_all_tags($family_label . ' ' . $label . ' ' . $r->message_type . ' ' . $r->lang . ' ' . $r->subject . ' ' . $r->message)) . '">'
                    . '<td>' . esc_html($family_label) . '</td><td>' . esc_html($label) . '</td><td>' . esc_html($r->lang) . '</td><td>' . esc_html($r->subject) . '</td>'
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
