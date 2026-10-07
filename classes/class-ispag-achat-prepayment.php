<?php
defined('ABSPATH') || exit;

/**
 * Paiement avant livraison (un seul paiement par commande d'achat).
 *
 *  - Dates suivies : facture validée dans Doxys (logiciel de traitement des factures), paiement souhaité, paiement reçu.
 *  - Un fournisseur est marqué « paiement avant livraison » dans l'onglet Fournisseur de sa fiche (meta ispag_supplier_prepay).
 *  - Pour ses commandes, une carte « Paiement » apparaît sur la fiche de l'achat : montant (par défaut le total de la commande, modifiable),
 *    date de paiement souhaitée (demandée par ISPAG) et date de paiement réelle (saisie à réception). Payé = la marchandise part le lendemain.
 *  - Droits : voir = achats / ventes (view_supplier_order ou edit_supplier_order) ; saisir ou modifier le montant et les dates = manage_supplier_payments
 *    (administrateur par défaut, à donner dans la page « ISPAG Rights »).
 *  - Statut calculé : à demander (aucune date souhaitée) · demandé · en retard (date souhaitée passée, pas payé) · payé.
 *  - Page d'administration « Paiements fournisseurs » : tableau de suivi à modifier en place, totaux, export CSV, destinataires des rappels.
 *  - Rappels quotidiens tant qu'un paiement attendu n'est pas arrivé ; le gestionnaire de l'achat est prévenu à la réception du paiement.
 *
 * Données : table achats_prepayments (une ligne par commande, créée à la première saisie).
 */
class ISPAG_Achat_Prepayment {

    const META_KEY        = 'ispag_supplier_prepay';
    const NONCE           = 'ispag_prepay';
    const PAGE            = 'ispag-prepayments';
    const OPT_RECIPIENTS  = 'ispag_prepay_recipients';   // ids des utilisateurs qui reçoivent les rappels (vide : les administrateurs)
    const CRON            = 'ispag_prepay_reminder';
    const STATUSES        = ['to_request', 'requested', 'overdue', 'paid'];

    /** Saisir ou modifier le montant (facture proforma) et les dates. */
    public static function can_edit() {
        return current_user_can('manage_supplier_payments');
    }

    /** Voir le suivi (carte, tableau). */
    public static function can_view() {
        return self::can_edit() || current_user_can('view_supplier_order') || current_user_can('edit_supplier_order');
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'achats_prepayments';
    }

    public static function init() {
        add_action('wp_ajax_ispag_prepay_save', [self::class, 'ajax_save']);
        add_action('admin_menu', [self::class, 'admin_menu'], 30);
        add_action('admin_post_ispag_prepay_csv', [self::class, 'export_csv']);
        add_action('admin_post_ispag_prepay_recipients', [self::class, 'save_recipients']);
        add_action(self::CRON, [self::class, 'send_reminders']);
        add_action('init', [self::class, 'schedule_cron']);
    }

    public static function schedule_cron() {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(strtotime('tomorrow 07:00', current_time('timestamp')) - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS), 'daily', self::CRON);
        }
    }

    // ------------------------------------------------------------------ logique

    /** Statut d'un paiement d'après ses dates (Y-m-d ou vide) et la date du jour. */
    public static function status($desired, $paid, $today) {
        if (!empty($paid)) return 'paid';
        if (empty($desired)) return 'to_request';
        return $desired < $today ? 'overdue' : 'requested';
    }

    public static function status_label($status) {
        $labels = [
            'to_request' => __('To request', 'creation-reservoir'),
            'requested'  => __('Requested', 'creation-reservoir'),
            'overdue'    => __('Overdue', 'creation-reservoir'),
            'paid'       => __('Paid', 'creation-reservoir'),
        ];
        return $labels[$status] ?? $status;
    }

    public static function status_color($status) {
        return ['to_request' => '#6b7280', 'requested' => '#2563eb', 'overdue' => '#dc2626', 'paid' => '#16a34a'][$status] ?? '#6b7280';
    }

    /** « Marchandise libérée : départ le jj.mm.aaaa » (lendemain du paiement), vide si pas payé. */
    public static function ship_note($paid) {
        if (empty($paid)) return '';
        return sprintf(__('Payment received: the goods can leave on %s.', 'creation-reservoir'), date_i18n('d.m.Y', strtotime($paid . ' +1 day')));
    }

    public static function valid_date($v) {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return false;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Valeur de la base pour un champ ('' = effacé = NULL) ou false si invalide. */
    public static function clean($field, $raw) {
        $raw = trim((string) $raw);
        if ($field === 'Amount') {
            if ($raw === '') return null;
            $v = str_replace([" ", "'", ','], ['', '', '.'], $raw);
            return is_numeric($v) && (float) $v >= 0 ? round((float) $v, 2) : false;
        }
        if ($field === 'DoxysDate' || $field === 'DesiredDate' || $field === 'PaidDate') {
            if ($raw === '') return null;
            return self::valid_date($raw) ? $raw : false;
        }
        return false;
    }

    public static function supplier_requires($company_id) {
        global $wpdb;
        if (!(int) $company_id) return false;
        $v = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}ispag_companies_meta WHERE company_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
            (int) $company_id, self::META_KEY
        ));
        return (string) $v === '1';
    }

    public static function get_row($order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE IdCommande = %d', (int) $order_id));
    }

    /** Total de la commande d'achat (somme des lignes), pour le montant par défaut. */
    public static function order_total($order_id) {
        return (float) (new ISPAG_Achat_Repository())->get_purchase_total(null, (int) $order_id);
    }

    public static function currency() {
        return (string) get_option('wpcb_currency', 'CHF');
    }

    public static function money($v) {
        return number_format((float) $v, 2, '.', "'") . ' ' . self::currency();
    }

    /**
     * Enregistre un champ ; renvoie le résumé à afficher ou WP_Error.
     * Effacer la date souhaitée remet aussi le rappel à zéro (une nouvelle date redéclenche les rappels).
     */
    public static function save($order_id, $field, $raw, $user_id = 0) {
        global $wpdb;
        $order_id = (int) $order_id;
        $value = self::clean($field, $raw);
        if ($value === false) return new WP_Error('invalid', __('Invalid value.', 'creation-reservoir'));

        $table = self::table();
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (IdCommande) VALUES (%d)", $order_id));
        $before = self::get_row($order_id);

        $data = [$field => $value, 'updated_by' => (int) $user_id];
        $format = [$field === 'Amount' ? '%f' : '%s', '%d'];
        if ($value === null) $format[0] = '%s';
        if ($field === 'DesiredDate') { $data['LastReminder'] = null; $format[] = '%s'; }
        if ($wpdb->update($table, $data, ['IdCommande' => $order_id], $format, ['%d']) === false) {
            return new WP_Error('db', __('Database update failed.', 'creation-reservoir'));
        }

        $row = self::get_row($order_id);
        if ($field === 'PaidDate' && !empty($row->PaidDate) && (empty($before) || $before->PaidDate !== $row->PaidDate)) {
            self::notify_paid($order_id, $row);
        }
        return self::summary($order_id, $row);
    }

    /** Ce que la carte et le tableau affichent après une modification. */
    public static function summary($order_id, $row = null) {
        $row = $row ?: self::get_row($order_id);
        $today = current_time('Y-m-d');
        $status = self::status($row->DesiredDate ?? '', $row->PaidDate ?? '', $today);
        $amount = ($row && $row->Amount !== null) ? (float) $row->Amount : self::order_total($order_id);
        return [
            'status'       => $status,
            'status_label' => self::status_label($status),
            'status_color' => self::status_color($status),
            'ship_note'    => self::ship_note($row->PaidDate ?? ''),
            'amount'       => $amount,
            'amount_text'  => self::money($amount),
        ];
    }

    // ------------------------------------------------------------------ AJAX

    public static function ajax_save() {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'creation-reservoir')], 403);
        }
        if (!self::can_edit()) {
            wp_send_json_error(['message' => __('Unauthorized', 'creation-reservoir')], 403);
        }
        global $wpdb;
        $order_id = absint($_POST['order_id'] ?? 0);
        $field    = sanitize_text_field(wp_unslash($_POST['field'] ?? ''));
        $value    = wp_unslash($_POST['value'] ?? '');
        $supplier = $order_id ? (int) $wpdb->get_var($wpdb->prepare("SELECT IdFournisseur FROM {$wpdb->prefix}achats_commande_liste_fournisseurs WHERE Id = %d", $order_id)) : 0;
        if (!$supplier) wp_send_json_error(['message' => __('Order not found.', 'creation-reservoir')]);
        if (!self::supplier_requires($supplier)) wp_send_json_error(['message' => __('This supplier does not require payment before delivery.', 'creation-reservoir')]);
        if (!in_array($field, ['Amount', 'DoxysDate', 'DesiredDate', 'PaidDate'], true)) wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);

        $res = self::save($order_id, $field, $value, get_current_user_id());
        if (is_wp_error($res)) wp_send_json_error(['message' => $res->get_error_message()]);
        wp_send_json_success($res);
    }

    // ------------------------------------------------------------------ carte sur la fiche de l'achat

    /** Carte « Paiement » (vide si le fournisseur n'exige pas de paiement avant livraison). */
    public static function render_card($achat) {
        if (empty($achat->Id) || !self::supplier_requires((int) ($achat->IdFournisseur ?? 0))) return '';
        if (!self::can_view()) return '';
        $can  = self::can_edit();
        $row  = self::get_row((int) $achat->Id);
        $sum  = self::summary((int) $achat->Id, $row);
        $amount_input = ($row && $row->Amount !== null) ? number_format((float) $row->Amount, 2, '.', '') : number_format($sum['amount'], 2, '.', '');
        $nonce = wp_create_nonce(self::NONCE);
        ob_start();
        ?>
        <div id="ispag-prepay-card" class="ispag-prepay" data-order="<?php echo (int) $achat->Id; ?>" data-nonce="<?php echo esc_attr($nonce); ?>" style="margin-top:1rem;padding-top:.75rem;border-top:1px solid #e5e7eb;">
            <strong>💳 <?php esc_html_e('Payment before delivery', 'creation-reservoir'); ?></strong>
            <span id="ispag-prepay-status" class="ispag-prepay-badge" style="display:inline-block;margin-left:.4rem;padding:1px 8px;border-radius:999px;color:#fff;font-size:.8rem;background:<?php echo esc_attr($sum['status_color']); ?>"><?php echo esc_html($sum['status_label']); ?></span>
            <div style="display:grid;gap:.5rem;margin-top:.5rem;font-size:.9rem;">
                <label><?php esc_html_e('Amount to pay (proforma invoice)', 'creation-reservoir'); ?> (<?php echo esc_html(self::currency()); ?>)<br>
                    <input type="number" step="0.01" min="0" data-prepay="Amount" value="<?php echo esc_attr($amount_input); ?>" <?php disabled(!$can); ?> style="width:100%;"></label>
                <?php if (!$row || $row->Amount === null): ?>
                    <small style="color:#6b7280;margin-top:-.3rem;"><?php esc_html_e('Pre-filled with the order total: enter the amount of the proforma invoice when you receive it.', 'creation-reservoir'); ?></small>
                <?php endif; ?>
                <label><?php esc_html_e('Invoice validated in Doxys', 'creation-reservoir'); ?><br>
                    <input type="date" data-prepay="DoxysDate" value="<?php echo esc_attr($row->DoxysDate ?? ''); ?>" <?php disabled(!$can); ?> style="width:100%;"></label>
                <label><?php esc_html_e('Requested payment date', 'creation-reservoir'); ?><br>
                    <input type="date" data-prepay="DesiredDate" value="<?php echo esc_attr($row->DesiredDate ?? ''); ?>" <?php disabled(!$can); ?> style="width:100%;"></label>
                <label><?php esc_html_e('Payment date received', 'creation-reservoir'); ?><br>
                    <input type="date" data-prepay="PaidDate" value="<?php echo esc_attr($row->PaidDate ?? ''); ?>" <?php disabled(!$can); ?> style="width:100%;"></label>
            </div>
            <p id="ispag-prepay-ship" style="margin:.5rem 0 0;font-size:.85rem;color:#16a34a;"><?php echo esc_html($sum['ship_note']); ?></p>
            <p id="ispag-prepay-msg" style="margin:.25rem 0 0;font-size:.8rem;" aria-live="polite"></p>
        </div>
        <script>
        (function () {
            var card = document.getElementById('ispag-prepay-card');
            if (!card) return;
            card.addEventListener('change', function (e) {
                var f = e.target.closest('[data-prepay]');
                if (!f) return;
                var msg = document.getElementById('ispag-prepay-msg');
                var body = new URLSearchParams({ action: 'ispag_prepay_save', nonce: card.dataset.nonce, order_id: card.dataset.order, field: f.dataset.prepay, value: f.value });
                msg.style.color = '#6b7280'; msg.textContent = '…';
                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) throw new Error((res.data && res.data.message) || 'Error');
                        var d = res.data, b = document.getElementById('ispag-prepay-status');
                        b.textContent = d.status_label; b.style.background = d.status_color;
                        document.getElementById('ispag-prepay-ship').textContent = d.ship_note;
                        msg.style.color = '#16a34a'; msg.textContent = <?php echo wp_json_encode(__('Saved', 'creation-reservoir')); ?>;
                        setTimeout(function () { msg.textContent = ''; }, 2000);
                    })
                    .catch(function (err) { msg.style.color = '#dc2626'; msg.textContent = err.message; });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------ tableau de suivi (administration)

    public static function admin_menu() {
        if (!class_exists('ISPAG_Settings')) return;
        $title = __('Supplier payments', 'creation-reservoir');
        add_submenu_page(ISPAG_Settings::PAGE, $title, $title, 'view_supplier_order', self::PAGE, [self::class, 'render_page']);
    }

    /**
     * Commandes des fournisseurs « paiement avant livraison » avec leur paiement.
     * @return object[] Id, RefCommande, IdFournisseur, supplier, created_by, Amount, DesiredDate, PaidDate, delivery (timestamp ou 0)
     */
    public static function rows() {
        global $wpdb;
        $p = $wpdb->prefix;
        $sql = "SELECT a.Id, a.RefCommande, a.IdFournisseur, a.created_by, a.EtatCommande, f.company_name AS supplier,
                    pp.Amount, pp.DoxysDate, pp.DesiredDate, pp.PaidDate,
                    (SELECT COALESCE(NULLIF(MAX(l.TimestampDateLivraisonConfirme), 0), NULLIF(MAX(l.TimestampDateLivraison), 0), 0)
                       FROM {$p}achats_articles_cmd_fournisseurs l WHERE l.IdCommande = a.Id) AS delivery
                FROM {$p}achats_commande_liste_fournisseurs a
                INNER JOIN {$p}ispag_companies f ON f.Id = a.IdFournisseur
                LEFT JOIN " . self::table() . " pp ON pp.IdCommande = a.Id
                WHERE EXISTS (SELECT 1 FROM {$p}ispag_companies_meta m WHERE m.company_id = a.IdFournisseur AND m.meta_key = %s AND m.meta_value = '1')
                ORDER BY (pp.PaidDate IS NULL) DESC, COALESCE(pp.DesiredDate, '9999-12-31') ASC, a.Id DESC";
        return (array) $wpdb->get_results($wpdb->prepare($sql, self::META_KEY));
    }

    public static function render_page() {
        if (!self::can_view()) wp_die(esc_html__('Access denied', 'creation-reservoir'));
        $can = self::can_edit();
        $today  = current_time('Y-m-d');
        $filter = isset($_GET['pstatus']) ? sanitize_key($_GET['pstatus']) : 'open';
        $all    = self::rows();
        $counts = array_fill_keys(self::STATUSES, 0);
        $items  = [];
        $total  = 0.0;
        foreach ($all as $r) {
            $r->status = self::status($r->DesiredDate, $r->PaidDate, $today);
            $r->amount_value = $r->Amount !== null ? (float) $r->Amount : self::order_total((int) $r->Id);
            $counts[$r->status]++;
            $show = $filter === 'all' || ($filter === 'open' ? $r->status !== 'paid' : $r->status === $filter);
            if ($show) { $items[] = $r; $total += $r->amount_value; }
        }
        $nonce = wp_create_nonce(self::NONCE);
        $base  = admin_url('admin.php?page=' . self::PAGE);
        $tabs  = ['open' => __('To follow', 'creation-reservoir'), 'to_request' => self::status_label('to_request'), 'requested' => self::status_label('requested'),
                  'overdue' => self::status_label('overdue'), 'paid' => self::status_label('paid'), 'all' => __('All', 'creation-reservoir')];
        echo '<div class="wrap"><h1>' . esc_html__('Supplier payments', 'creation-reservoir') . '</h1>';
        echo '<p class="description">' . esc_html__('Suppliers who are paid before they deliver (tick "Payment before delivery" in the Supplier tab of the company).', 'creation-reservoir') . ' '
            . ($can ? esc_html__('Change a date or an amount directly in the table: it is saved at once. Once the payment has arrived, the goods leave the next day.', 'creation-reservoir')
                    : esc_html__('You can see the dates and amounts; only the people in charge of payments can change them.', 'creation-reservoir')) . '</p>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Saved.', 'creation-reservoir') . '</p></div>';

        echo '<ul class="subsubsub">';
        $links = [];
        foreach ($tabs as $key => $label) {
            $n = $key === 'all' ? count($all) : ($key === 'open' ? count($all) - $counts['paid'] : $counts[$key]);
            $links[] = '<li><a href="' . esc_url(add_query_arg('pstatus', $key, $base)) . '"' . ($filter === $key ? ' class="current"' : '') . '>' . esc_html($label) . ' <span class="count">(' . (int) $n . ')</span></a>';
        }
        echo implode(' | </li>', $links) . '</li></ul><br class="clear">';

        if (!$items) {
            echo '<p><em>' . esc_html__('No order to show.', 'creation-reservoir') . '</em></p>';
        } else {
            echo '<table class="widefat striped" id="ispag-prepay-table" data-nonce="' . esc_attr($nonce) . '"><thead><tr>'
                . '<th>' . esc_html__('Project', 'creation-reservoir') . '</th><th>' . esc_html__('Supplier', 'creation-reservoir') . '</th>'
                . '<th>' . esc_html__('Delivery date', 'creation-reservoir') . '</th><th>' . esc_html__('Amount', 'creation-reservoir') . ' (' . esc_html(self::currency()) . ')</th>'
                . '<th>' . esc_html__('Invoice validated in Doxys', 'creation-reservoir') . '</th>'
                . '<th>' . esc_html__('Requested payment date', 'creation-reservoir') . '</th><th>' . esc_html__('Payment date received', 'creation-reservoir') . '</th>'
                . '<th>' . esc_html__('Status', 'creation-reservoir') . '</th></tr></thead><tbody>';
            foreach ($items as $r) {
                $purchase_url = home_url('/purchase/' . (int) $r->Id . '/');
                echo '<tr data-order="' . (int) $r->Id . '">'
                    . '<td><a href="' . esc_url($purchase_url) . '" target="_blank" rel="noopener">' . esc_html(stripslashes($r->RefCommande)) . '</a></td>'
                    . '<td>' . esc_html($r->supplier) . '</td>'
                    . '<td>' . esc_html($r->delivery ? date_i18n('d.m.Y', (int) $r->delivery) : '—') . '</td>'
                    . '<td><input type="number" step="0.01" min="0" data-prepay="Amount" value="' . esc_attr(number_format($r->amount_value, 2, '.', '')) . '" style="width:120px"' . ($can ? '' : ' disabled') . '></td>'
                    . '<td><input type="date" data-prepay="DoxysDate" value="' . esc_attr($r->DoxysDate ?? '') . '"' . ($can ? '' : ' disabled') . '></td>'
                    . '<td><input type="date" data-prepay="DesiredDate" value=""' . esc_attr($r->DesiredDate ?? '') . '"' . ($can ? '' : ' disabled') . '></td>'
                    . '<td><input type="date" data-prepay="PaidDate" value="' . esc_attr($r->PaidDate ?? '') . '"' . ($can ? '' : ' disabled') . '></td>'
                    . '<td><span class="ispag-prepay-badge" style="display:inline-block;padding:1px 8px;border-radius:999px;color:#fff;background:' . esc_attr(self::status_color($r->status)) . '">' . esc_html(self::status_label($r->status)) . '</span>'
                    . ' <small class="ispag-prepay-ship" style="color:#16a34a">' . esc_html(self::ship_note($r->PaidDate)) . '</small></td></tr>';
            }
            echo '</tbody><tfoot><tr><th colspan="3">' . esc_html__('Total shown', 'creation-reservoir') . '</th><th colspan="5"><strong id="ispag-prepay-total">' . esc_html(self::money($total)) . '</strong></th></tr></tfoot></table>';
            echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ispag_prepay_csv&pstatus=' . $filter), 'ispag_prepay_csv')) . '">' . esc_html__('Export CSV', 'creation-reservoir') . '</a></p>';
            if ($can) self::print_table_script();
        }

        self::render_recipients_form();
        echo '</div>';
    }

    private static function print_table_script() {
        ?>
        <script>
        (function () {
            var table = document.getElementById('ispag-prepay-table');
            if (!table) return;
            table.addEventListener('change', function (e) {
                var f = e.target.closest('[data-prepay]');
                if (!f) return;
                var tr = f.closest('tr');
                var body = new URLSearchParams({ action: 'ispag_prepay_save', nonce: table.dataset.nonce, order_id: tr.dataset.order, field: f.dataset.prepay, value: f.value });
                f.style.outline = '2px solid #9ca3af';
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) throw new Error((res.data && res.data.message) || 'Error');
                        var d = res.data, b = tr.querySelector('.ispag-prepay-badge');
                        b.textContent = d.status_label; b.style.background = d.status_color;
                        tr.querySelector('.ispag-prepay-ship').textContent = d.ship_note;
                        f.style.outline = '2px solid #16a34a';
                        var amount = tr.querySelector('[data-prepay="Amount"]'); if (amount && f !== amount) amount.value = Number(d.amount).toFixed(2);
                        var sum = 0; table.querySelectorAll('[data-prepay="Amount"]').forEach(function (i) { sum += parseFloat(i.value) || 0; });
                        document.getElementById('ispag-prepay-total').textContent = sum.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, "'") + ' <?php echo esc_js(self::currency()); ?>';
                        setTimeout(function () { f.style.outline = ''; }, 1500);
                    })
                    .catch(function (err) { f.style.outline = '2px solid #dc2626'; alert(err.message); });
            });
        })();
        </script>
        <?php
    }

    // ------------------------------------------------------------------ CSV et destinataires

    public static function export_csv() {
        if (!self::can_view() || !check_admin_referer('ispag_prepay_csv')) wp_die(esc_html__('Access denied', 'creation-reservoir'));
        $today  = current_time('Y-m-d');
        $filter = isset($_GET['pstatus']) ? sanitize_key($_GET['pstatus']) : 'open';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="supplier-payments-' . $today . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Project', 'Supplier', 'Delivery date', 'Amount', 'Invoice validated in Doxys', 'Requested payment date', 'Payment date received', 'Status'], ';');
        foreach (self::rows() as $r) {
            $status = self::status($r->DesiredDate, $r->PaidDate, $today);
            if (!($filter === 'all' || ($filter === 'open' ? $status !== 'paid' : $status === $filter))) continue;
            $amount = $r->Amount !== null ? (float) $r->Amount : self::order_total((int) $r->Id);
            fputcsv($out, [stripslashes($r->RefCommande), $r->supplier, $r->delivery ? date('Y-m-d', (int) $r->delivery) : '', number_format($amount, 2, '.', ''), $r->DoxysDate, $r->DesiredDate, $r->PaidDate, self::status_label($status)], ';');
        }
        fclose($out);
        exit;
    }

    /** Destinataires des rappels : la liste choisie, à défaut les administrateurs. */
    public static function recipients() {
        $ids = array_filter(array_map('intval', (array) get_option(self::OPT_RECIPIENTS, [])));
        if (!$ids) $ids = array_map(function ($u) { return (int) $u->ID; }, get_users(['role' => 'administrator', 'fields' => ['ID']]));
        return array_values(array_unique($ids));
    }

    private static function render_recipients_form() {
        $chosen = array_filter(array_map('intval', (array) get_option(self::OPT_RECIPIENTS, [])));
        $users  = array_filter(get_users(['orderby' => 'display_name']), function ($u) { return user_can($u, 'edit_supplier_order'); });
        echo '<hr><h2>' . esc_html__('Who receives the reminders', 'creation-reservoir') . '</h2>';
        echo '<p class="description">' . esc_html__('Every morning, these people are reminded of each payment that was requested for today or earlier and has not arrived. Nobody ticked: the administrators.', 'creation-reservoir') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ispag_prepay_recipients');
        echo '<input type="hidden" name="action" value="ispag_prepay_recipients">';
        foreach ($users as $u) {
            echo '<label style="display:block;margin:.2rem 0"><input type="checkbox" name="recipients[]" value="' . (int) $u->ID . '"' . checked(in_array((int) $u->ID, $chosen, true), true, false) . '> ' . esc_html($u->display_name . ' (' . $u->user_email . ')') . '</label>';
        }
        submit_button(__('Save', 'creation-reservoir'));
        echo '</form>';
    }

    public static function save_recipients() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied', 'creation-reservoir'));
        check_admin_referer('ispag_prepay_recipients');
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) ($_POST['recipients'] ?? [])))));
        update_option(self::OPT_RECIPIENTS, $ids);
        wp_safe_redirect(add_query_arg('saved', 1, admin_url('admin.php?page=' . self::PAGE)));
        exit;
    }

    // ------------------------------------------------------------------ notifications

    /** Rappel quotidien : paiement demandé pour aujourd'hui ou avant, non reçu, pas déjà rappelé aujourd'hui. */
    public static function send_reminders() {
        if (!class_exists('ISPAG_Notifications_Manager')) return 0;
        global $wpdb;
        $today = current_time('Y-m-d');
        $p = $wpdb->prefix;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT pp.*, a.RefCommande, f.company_name AS supplier
               FROM " . self::table() . " pp
               INNER JOIN {$p}achats_commande_liste_fournisseurs a ON a.Id = pp.IdCommande
               INNER JOIN {$p}ispag_companies f ON f.Id = a.IdFournisseur
              WHERE pp.PaidDate IS NULL AND pp.DesiredDate IS NOT NULL AND pp.DesiredDate <= %s
                AND (pp.LastReminder IS NULL OR pp.LastReminder < %s)
                AND EXISTS (SELECT 1 FROM {$p}ispag_companies_meta m WHERE m.company_id = a.IdFournisseur AND m.meta_key = %s AND m.meta_value = '1')",
            $today, $today, self::META_KEY
        ));
        $sent = 0;
        foreach ($rows as $r) {
            $days = (int) floor((strtotime($today) - strtotime($r->DesiredDate)) / DAY_IN_SECONDS);
            $amount = $r->Amount !== null ? (float) $r->Amount : self::order_total((int) $r->IdCommande);
            $title = $days > 0
                ? sprintf(__('Payment overdue (%d days): %s', 'creation-reservoir'), $days, $r->supplier)
                : sprintf(__('Payment expected today: %s', 'creation-reservoir'), $r->supplier);
            $text = sprintf(__('%1$s: %2$s, requested for %3$s. The goods stay here until the payment has arrived.', 'creation-reservoir'),
                stripslashes($r->RefCommande), self::money($amount), date_i18n('d.m.Y', strtotime($r->DesiredDate)));
            ISPAG_Notifications_Manager::send(self::recipients(), 'supplier_payment_due', $title, $text, 'purchase/' . (int) $r->IdCommande . '/', (int) $r->IdCommande, ['order_id' => (int) $r->IdCommande]);
            $wpdb->update(self::table(), ['LastReminder' => $today], ['IdCommande' => (int) $r->IdCommande], ['%s'], ['%d']);
            $sent++;
        }
        return $sent;
    }

    /** Le gestionnaire de l'achat apprend que le paiement est arrivé (sauf s'il l'a saisi lui-même). */
    private static function notify_paid($order_id, $row) {
        if (!class_exists('ISPAG_Notifications_Manager')) return;
        global $wpdb;
        $o = $wpdb->get_row($wpdb->prepare(
            "SELECT a.RefCommande, a.created_by, f.company_name AS supplier FROM {$wpdb->prefix}achats_commande_liste_fournisseurs a
               LEFT JOIN {$wpdb->prefix}ispag_companies f ON f.Id = a.IdFournisseur WHERE a.Id = %d", (int) $order_id));
        if (!$o || !(int) $o->created_by || (int) $o->created_by === get_current_user_id()) return;
        ISPAG_Notifications_Manager::send(
            [(int) $o->created_by], 'supplier_payment_received',
            sprintf(__('Payment received: %s', 'creation-reservoir'), $o->supplier),
            sprintf(__('%1$s: the payment arrived on %2$s. The goods can leave on %3$s.', 'creation-reservoir'),
                stripslashes($o->RefCommande), date_i18n('d.m.Y', strtotime($row->PaidDate)), date_i18n('d.m.Y', strtotime($row->PaidDate . ' +1 day'))),
            'purchase/' . (int) $order_id . '/', (int) $order_id, ['order_id' => (int) $order_id]
        );
    }
}
