<?php
defined('ABSPATH') || exit;

/**
 * Brouillon d'e-mail (.eml) de la commande fournisseur, avec le bon de commande PDF en pièce jointe.
 *
 * Un lien mailto: ne peut pas joindre de fichier. On génère donc un fichier .eml marqué « X-Unsent: 1 » :
 * en double-cliquant dessus, Outlook (classique, Windows) ouvre un nouveau message prêt à envoyer, avec le
 * destinataire, l'objet, le texte du modèle et le PDF déjà joint.
 */
class ISPAG_Achat_Mail_Draft {

    const ACTION = 'ispag_download_order_eml';

    public static function init() {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'download']);
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
        if ($type !== 'send_purchase_order') {
            wp_die('Unsupported message type', '', 400);
        }

        $mail = ISPAG_Achat_Status_Controller::build_mail($achat_id, $type);
        if (is_wp_error($mail)) {
            wp_die(esc_html($mail->get_error_message()));
        }

        // Le PDF (mêmes données que le bouton « Print purchase order » ; enregistré dans les documents de la commande)
        $_GET['poid'] = $achat_id;
        $pdf = ISPAG_Achat_Generate_Purchase_Order_PDF::generate_purchase_order_pdf(true);
        if (!is_array($pdf) || empty($pdf['content'])) {
            wp_die('The purchase order PDF could not be generated.');
        }

        $eml  = self::build_eml($mail, $pdf['content'], $pdf['file_name']);
        $name = preg_replace('/\.pdf$/i', '', $pdf['file_name']) . '.eml';

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

    /** Message MIME : texte brut UTF-8 + PDF en pièce jointe. */
    public static function build_eml(array $mail, $pdf_content, $pdf_name) {
        $boundary = '=_ispag_' . md5(uniqid('', true));
        $text     = preg_replace("/\r\n|\r|\n/", "\r\n", (string) $mail['message']);
        $pdf_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $pdf_name);

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
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/pdf; name=\"{$pdf_name}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$pdf_name}\"\r\n\r\n";
        $body .= chunk_split(base64_encode($pdf_content), 76, "\r\n");
        $body .= "--{$boundary}--\r\n";

        return implode("\r\n", $h) . "\r\n\r\n" . $body;
    }
}
