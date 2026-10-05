<?php
defined('ABSPATH') || exit;

/**
 * Brouillon d'e-mail (.eml) de la commande fournisseur, avec le bon de commande PDF en pièce jointe.
 *
 * Pièces jointes selon le type de message :
 *   - send_purchase_order : le bon de commande PDF (+ plans validés des cuves liées et bulletin de livraison, pour une commande d'isolation ou de soudure) ;
 *   - drawing_modified    : le dernier fichier « Drawing modification » de chaque article de la commande ;
 *   - drawing_validated   : le dernier fichier « Drawing approval » (validation) de chaque article de la commande.
 *
 * Un lien mailto: ne peut pas joindre de fichier. On génère donc un fichier .eml marqué « X-Unsent: 1 » :
 * en double-cliquant dessus, Outlook (classique, Windows) ouvre un nouveau message prêt à envoyer, avec le
 * destinataire, l'objet, le texte du modèle et le PDF déjà joint.
 */
class ISPAG_Achat_Mail_Draft {

    const ACTION = 'ispag_download_order_eml';

    /** Types de message gérés : null = bon de commande PDF, sinon slug du type de document (achats_doc_types) à joindre. */
    const TYPES = [
        'send_purchase_order' => null,
        'drawing_modified'    => 'drawingModification',
        'drawing_validated'   => 'drawingApproval',
    ];

    public static function supports($type) {
        return array_key_exists($type, self::TYPES);
    }

    /**
     * Derniers documents d'un type (slug) pour chaque article de la commande : une ligne par article, le plus récent.
     * Les documents d'article sont rattachés à l'article du projet (IdCommandeClient) dans achats_historique.Historique.
     *
     * @return array<int, array{path:string,name:string,mime:string}>
     */
    public static function latest_documents($achat_id, $slug) {
        global $wpdb;
        $articles = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT IdCommandeClient FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs WHERE IdCommande = %d AND IdCommandeClient > 0",
            $achat_id
        ));
        return self::latest_documents_for_articles($articles, $slug);
    }

    /** Dernier document d'un type (slug) pour chacun des articles de projet donnés (sans doublon). */
    public static function latest_documents_for_articles(array $articles, $slug) {
        global $wpdb;
        $files = [];
        $seen  = [];
        foreach ($articles as $article_id) {
            $media_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT IdMedia FROM {$wpdb->prefix}achats_historique
                 WHERE Historique = %s AND ClassCss = %s AND IdMedia > 0
                 ORDER BY dateReadable DESC, Id DESC LIMIT 1",
                (string) $article_id, $slug
            ));
            if (!$media_id || isset($seen[$media_id])) continue;
            $path = get_attached_file($media_id);
            if (!$path || !is_readable($path)) continue;
            $seen[$media_id] = true;
            $mime = get_post_mime_type($media_id) ?: 'application/octet-stream';
            $files[] = ['path' => $path, 'name' => basename($path), 'mime' => $mime];
        }
        return $files;
    }

    /**
     * Plans validés des cuves concernées par une commande d'isolation ou de soudure : les lignes d'isolation (Type 2)
     * et de soudure (Type 3) du projet sont liées à leur cuve (linked_tank) ; on joint le dernier « Drawing approval » de chacune.
     */
    public static function linked_tank_plans($achat_id) {
        global $wpdb;
        $tanks = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT d.linked_tank
             FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs c
             JOIN {$wpdb->prefix}achats_details_commande d ON d.Id = c.IdCommandeClient
             WHERE c.IdCommande = %d AND c.IdCommandeClient > 0 AND d.Type IN (2, 3) AND d.linked_tank > 0",
            $achat_id
        ));
        return $tanks ? self::latest_documents_for_articles($tanks, 'drawingApproval') : [];
    }

    /** La commande contient-elle des lignes d'isolation (Type 2) ou de soudure (Type 3) du projet ? */
    public static function is_site_work_order($achat_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs c
             JOIN {$wpdb->prefix}achats_details_commande d ON d.Id = c.IdCommandeClient
             WHERE c.IdCommande = %d AND c.IdCommandeClient > 0 AND d.Type IN (2, 3) LIMIT 1",
            $achat_id
        ));
    }

    /**
     * Bulletin de livraison PDF (toutes les lignes de la commande, adresse de la commande, QR code / lien de signature),
     * même contenu que le bouton « Bulletin de livraison » de la commande. @return array{content:string,name:string}|null
     */
    public static function delivery_note_attachment($achat_id) {
        global $wpdb;
        $needed = ['ISPAG_Delivery_Receipt', 'ISPAG_Delivery_Note_PDF'];
        foreach ($needed as $c) { if (!class_exists($c)) return null; }
        if (!class_exists('ISPAG_PDF_Generator')) {
            $f = WP_PLUGIN_DIR . '/ispag-project-manager/classes/class-ispag-pdf-generator.php';
            if (file_exists($f)) require_once $f;
        }
        $achat = apply_filters('ispag_get_achat_by_id', null, $achat_id);
        if (empty($achat)) return null;
        $infos = (new ISPAG_Achat_Details_Repository())->get_infos_livraison($achat_id);

        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT Id FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs WHERE IdCommande = %d ORDER BY Id", $achat_id
        ));
        $articles = []; $line_ids = []; $project_ids = [];
        foreach ($lines as $row) {
            $line = apply_filters('ispag_get_purchse_article_by_id', null, (int) $row->Id);
            if (empty($line)) continue;
            $line_ids[] = (int) $line->Id;
            if (!empty($line->IdCommandeClient)) $project_ids[] = (int) $line->IdCommandeClient;
            $articles[] = ['ref' => $line->serial_no ?: $line->Id, 'description' => $line->RefSurMesure, 'qty' => $line->Qty];
        }
        if (!$articles) return null;

        $title = __('Delivery note', 'creation-reservoir');
        $project_header = [
            __('Project', 'creation-reservoir')         => $achat->RefCommande ?? '',
            __('Project number', 'creation-reservoir')  => $achat->NrCommande ?? '',
            __('Delivery date', 'creation-reservoir')   => date('d.m.Y'),
        ];
        $table_header = [
            ['label' => __('Reference', 'creation-reservoir'), 'key' => 'ref', 'width' => 40],
            ['label' => __('Description', 'creation-reservoir'), 'key' => 'description', 'width' => 110],
            ['label' => __('Quantity', 'creation-reservoir'), 'key' => 'qty', 'width' => 30, 'align' => 'C'],
        ];
        $payload = [
            'title'          => $title,
            'company'        => $achat->nom_entreprise ?? '',
            'project_header' => $project_header,
            'infos'          => array_intersect_key((array) $infos, array_flip(['AdresseDeLivraison', 'DeliveryAdresse2', 'DeliveryAdresse3', 'NIP', 'City', 'PersonneContact', 'num_tel_contact'])),
            'table_header'   => $table_header,
            'articles'       => $articles,
            'article_ids'    => array_values(array_unique($project_ids)),
            'purchase_line_ids' => $line_ids,
        ];
        $qr_url = ISPAG_Delivery_Receipt::create($payload, (int) ($achat->hubspot_deal_id ?? 0), (int) $achat_id);
        $pdf = new ISPAG_Delivery_Note_PDF();
        $pdf->generate($project_header, $achat, $infos, $table_header, $articles, $title, ['qr_url' => $qr_url]);
        $name = sanitize_file_name(sanitize_title($title) . '-' . ($achat->NrCommande ?? $achat_id)) . '.pdf';
        return ['content' => $pdf->Output('S'), 'name' => $name];
    }

    /** Nombre de pièces jointes que le brouillon contiendra (pour avertir si aucune). */
    public static function attachments_count($achat_id, $type) {
        $slug = self::TYPES[$type] ?? null;
        return $slug === null ? 1 + count(self::linked_tank_plans($achat_id)) + (self::is_site_work_order($achat_id) ? 1 : 0) : count(self::latest_documents($achat_id, $slug));
    }

    const HELP_META   = 'ispag_eml_help_seen';
    const HELP_ACTION = 'ispag_eml_help_seen';

    public static function init() {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'download']);
        add_action('wp_ajax_' . self::HELP_ACTION, [self::class, 'ajax_help_seen']);
    }

    /** L'utilisateur a-t-il déjà lu l'aide « ouvrir automatiquement le brouillon » ? */
    public static function help_seen() {
        return (bool) get_user_meta(get_current_user_id(), self::HELP_META, true);
    }

    public static function ajax_help_seen() {
        check_ajax_referer('ispag_eml_help', 'nonce');
        update_user_meta(get_current_user_id(), self::HELP_META, 1);
        wp_send_json_success();
    }

    /**
     * Fenêtre d'aide (affichée automatiquement au premier envoi, puis à la demande avec le bouton « i »).
     * Le navigateur télécharge le brouillon .eml : un réglage unique lui fait ouvrir ces fichiers tout seul.
     */
    public static function help_modal_html() {
        ob_start();
        ?>
        <div id="ispag-eml-help" class="ispag-eml-help" hidden
             data-seen="<?php echo self::help_seen() ? '1' : '0'; ?>"
             data-nonce="<?php echo esc_attr(wp_create_nonce('ispag_eml_help')); ?>">
            <div class="ispag-eml-help__box" role="dialog" aria-modal="true" aria-labelledby="ispag-eml-help-title">
                <h3 id="ispag-eml-help-title"><?php esc_html_e('Your email is ready', 'creation-reservoir'); ?></h3>
                <p><?php esc_html_e('A small file ending in “.eml” has just been downloaded. Open it: your email appears in Outlook with the recipient, the text and the attachments already filled in.', 'creation-reservoir'); ?></p>
                <p><strong><?php esc_html_e('Want it to open by itself next time?', 'creation-reservoir'); ?></strong>
                   <?php esc_html_e('Do this once:', 'creation-reservoir'); ?></p>
                <ol>
                    <li><?php esc_html_e('In your browser, find the downloaded file (download bar, or the download arrow at the top right).', 'creation-reservoir'); ?></li>
                    <li><?php esc_html_e('Click the “…” (or “⋮”) next to the file.', 'creation-reservoir'); ?></li>
                    <li><?php esc_html_e('Choose “Always open files of this type”.', 'creation-reservoir'); ?></li>
                </ol>
                <p class="ispag-eml-help__small"><?php esc_html_e('If the file does not open in Outlook: right-click it, choose “Open with”, select Outlook and tick “Always use this app”.', 'creation-reservoir'); ?></p>
                <p class="ispag-eml-help__small"><?php esc_html_e('You can read this again at any time with the “i” button next to the send button.', 'creation-reservoir'); ?></p>
                <div class="ispag-eml-help__actions">
                    <button type="button" class="ispag-btn ispag-btn-secondary-outlined ispag-eml-help__close"><?php esc_html_e('Got it', 'creation-reservoir'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Adresse de téléchargement du brouillon (protégée par un nonce lié à la commande). */
    public static function download_url($achat_id, $type) {
        return add_query_arg([
            'action'   => self::ACTION,
            'achat_id' => (int) $achat_id,
            'type'     => $type,
            '_wpnonce' => wp_create_nonce(self::ACTION . '_' . (int) $achat_id),
        ], admin_url('admin-ajax.php'));
    }

    public static function download() {
        $achat_id = absint($_GET['achat_id'] ?? 0);
        $type     = sanitize_key($_GET['type'] ?? '');

        if (!$achat_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', self::ACTION . '_' . $achat_id)) {
            wp_die('Invalid request', '', 403);
        }
        if (!current_user_can('edit_supplier_order')) {
            wp_die('Not authorized', '', 403);
        }
        if (!self::supports($type)) {
            wp_die('Unsupported message type', '', 400);
        }

        $mail = ISPAG_Achat_Status_Controller::build_mail($achat_id, $type);
        if (is_wp_error($mail)) {
            wp_die(esc_html($mail->get_error_message()));
        }

        $slug        = self::TYPES[$type];
        $attachments = [];
        if ($slug === null) {
            // Le PDF (mêmes données que le bouton « Print purchase order » ; enregistré dans les documents de la commande)
            $_GET['poid'] = $achat_id;
            $pdf = ISPAG_Achat_Generate_Purchase_Order_PDF::generate_purchase_order_pdf(true);
            if (!is_array($pdf) || empty($pdf['content'])) {
                wp_die('The purchase order PDF could not be generated.');
            }
            $attachments[] = ['content' => $pdf['content'], 'name' => $pdf['file_name'], 'mime' => 'application/pdf'];
            // + plans validés des cuves concernées (commande d'isolation ou de soudure)
            foreach (self::linked_tank_plans($achat_id) as $f) {
                $attachments[] = ['content' => file_get_contents($f['path']), 'name' => $f['name'], 'mime' => $f['mime']];
            }
            // + bulletin de livraison (isolation / soudure : à faire signer sur place via le QR code)
            if (self::is_site_work_order($achat_id) && ($dn = self::delivery_note_attachment($achat_id))) {
                $attachments[] = ['content' => $dn['content'], 'name' => $dn['name'], 'mime' => 'application/pdf'];
            }
            $name = preg_replace('/\.pdf$/i', '', $pdf['file_name']) . '.eml';
        } else {
            foreach (self::latest_documents($achat_id, $slug) as $f) {
                $attachments[] = ['content' => file_get_contents($f['path']), 'name' => $f['name'], 'mime' => $f['mime']];
            }
            $name = sanitize_file_name($mail['subject'] ?: $type) . '.eml';
        }

        $eml = self::build_eml($mail, $attachments);

        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: message/rfc822');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($eml));
        echo $eml;
        exit;
    }

    private static function encode_header($text) {
        $text = str_replace(["\r", "\n"], ' ', (string) $text);
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /** Message MIME : texte brut UTF-8 + pièces jointes ([['content'=>…, 'name'=>…, 'mime'=>…], …]). */
    public static function build_eml(array $mail, array $attachments) {
        $boundary = '=_ispag_' . md5(uniqid('', true));
        $text     = preg_replace("/\r\n|\r|\n/", "\r\n", (string) $mail['message']);

        $h = [];
        $h[] = 'X-Unsent: 1'; // ouvre le message en mode rédaction dans Outlook
        $h[] = 'To: ' . trim($mail['email_contact']);
        $cc = trim((string) ($mail['email_copy'] ?? ''));
        if ($cc !== '') $h[] = 'Cc: ' . $cc;
        $h[] = 'Subject: ' . self::encode_header($mail['subject']);
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($text), 76, "\r\n");

        foreach ($attachments as $att) {
            $fname = self::encode_header($att['name']);
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$att['mime']}; name=\"{$fname}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n";
            $body .= chunk_split(base64_encode((string) $att['content']), 76, "\r\n");
        }
        $body .= "--{$boundary}--\r\n";

        return implode("\r\n", $h) . "\r\n\r\n" . $body;
    }
}
