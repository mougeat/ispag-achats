(function ($) {
    const cfg = window.ispagMailTpl || {};
    const $root = $('#ispag-mailtpl');
    if (!$root.length) return;
    const $form = $('#ispag-mailtpl-form');
    const $status = $form.find('.ispag-mailtpl-status');
    let lastField = $form.find('[name="message"]');

    function openForm(title, vals) {
        $form.find('[name="id"]').val(vals.id || 0);
        $form.find('[name="message_type"]').val(vals.type);
        $form.find('[name="lang"]').val(vals.lang);
        $form.find('[name="subject"]').val(vals.subject);
        $form.find('[name="message"]').val(vals.message);
        $('#ispag-mailtpl-form-title').text(title);
        $form.find('.ispag-mailtpl-preview').prop('hidden', true);
        $status.text('');
        $form.prop('hidden', false);
        $form.find('[name="subject"]').trigger('focus');
        $form.get(0).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    $('#ispag-mailtpl-new').on('click', function () {
        openForm('New template', {
            id: 0,
            type: $form.find('[name="message_type"] option:first').val(),
            lang: cfg.defaultLang,
            subject: '',
            message: ''
        });
    });

    $root.on('click', '.ispag-mailtpl-edit, .ispag-mailtpl-copy', function () {
        const $tr = $(this).closest('tr');
        const copy = $(this).hasClass('ispag-mailtpl-copy');
        openForm(copy ? 'Duplicate template (choose another language or type)' : 'Edit template', {
            id: copy ? 0 : $tr.data('id'),
            type: $tr.attr('data-type'),
            lang: $tr.attr('data-lang'),
            subject: $tr.attr('data-subject'),
            message: $tr.attr('data-message')
        });
    });

    $root.on('click', '.ispag-mailtpl-delete', function () {
        if (!window.confirm(cfg.confirmDelete)) return;
        $.post(cfg.ajaxurl, { action: 'ispag_mail_template_delete', nonce: cfg.nonce, id: $(this).closest('tr').data('id') })
            .done(function (r) { if (r && r.success) { $('#ispag-mailtpl-list').html(r.data.html); } });
    });

    $('#ispag-mailtpl-cancel').on('click', function () { $form.prop('hidden', true); });

    // Insertion d'une balise à la position du curseur (objet ou message)
    $form.on('focus', 'input[name="subject"], textarea[name="message"]', function () { lastField = $(this); });
    $form.on('click', '.ispag-mailtpl-tag', function () {
        const el = lastField.get(0);
        const tag = $(this).data('tag');
        const start = el.selectionStart != null ? el.selectionStart : el.value.length;
        const end = el.selectionEnd != null ? el.selectionEnd : start;
        el.value = el.value.slice(0, start) + tag + el.value.slice(end);
        el.focus();
        el.setSelectionRange(start + tag.length, start + tag.length);
    });

    function fill(text) {
        let out = text;
        $.each(cfg.sample || {}, function (tag, val) { out = out.split(tag).join(val); });
        return out;
    }
    $('#ispag-mailtpl-preview-btn').on('click', function () {
        const $p = $form.find('.ispag-mailtpl-preview');
        $p.find('.ispag-mailtpl-preview-subject').text(fill($form.find('[name="subject"]').val()));
        $p.find('.ispag-mailtpl-preview-body').text(fill($form.find('[name="message"]').val()));
        $p.prop('hidden', false);
    });

    $form.on('submit', function (e) {
        e.preventDefault();
        const $btn = $form.find('button[type="submit"]').prop('disabled', true);
        $status.text('⏳ …');
        $.post(cfg.ajaxurl, $form.serialize() + '&action=ispag_mail_template_save&nonce=' + encodeURIComponent(cfg.nonce))
            .done(function (r) {
                if (r && r.success) {
                    $('#ispag-mailtpl-list').html(r.data.html);
                    $form.prop('hidden', true);
                } else {
                    $status.text('❌ ' + ((r && r.data) || 'Error'));
                }
            })
            .fail(function () { $status.text('❌ Network error'); })
            .always(function () { $btn.prop('disabled', false); });
    });
})(jQuery);
