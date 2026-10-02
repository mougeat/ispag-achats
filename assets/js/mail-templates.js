(function ($) {
    const cfg = window.ispagMailTpl || {};
    const $root = $('#ispag-mailtpl');
    if (!$root.length) return;
    const $form = $('#ispag-mailtpl-form');
    const $status = $form.find('.ispag-mailtpl-status');
    let lastField = $form.find('[name="message"]');

    // Types proposés selon la famille, et jeu de balises correspondant
    function setFamily(family, type) {
        const types = (cfg.typesByFamily || {})[family] || {};
        const $type = $form.find('[name="message_type"]').empty();
        $.each(types, function (k, label) { $type.append($('<option>').val(k).text(label)); });
        if (type && types[type] !== undefined) $type.val(type);
        $form.find('.ispag-mailtpl-tagset').each(function () { $(this).prop('hidden', $(this).data('family') !== family); });
    }
    $form.find('[name="message_family"]').on('change', function () { setFamily($(this).val()); });

    function openForm(title, vals) {
        $form.find('[name="id"]').val(vals.id || 0);
        $form.find('[name="message_family"]').val(vals.family);
        setFamily(vals.family, vals.type);
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
            family: $('#ispag-mailtpl-family-filter').val() || $form.find('[name="message_family"] option:first').val(),
            type: '',
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
            family: $tr.attr('data-family'),
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

    // Recherche et filtre de famille : sans accents ni majuscules, sur tous les mots saisis
    function norm(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    function applyFilter() {
        const words = norm($('#ispag-mailtpl-search').val()).split(/\s+/).filter(Boolean);
        const family = $('#ispag-mailtpl-family-filter').val();
        let shown = 0;
        const $rows = $('#ispag-mailtpl-list tbody tr');
        $rows.each(function () {
            const hay = norm($(this).attr('data-search'));
            const ok = (!family || $(this).attr('data-family') === family) && words.every(function (w) { return hay.indexOf(w) !== -1; });
            $(this).prop('hidden', !ok);
            if (ok) shown++;
        });
        $('.ispag-mailtpl-count').text($rows.length ? shown + ' / ' + $rows.length : '');
    }
    $('#ispag-mailtpl-search').on('input', applyFilter);
    $('#ispag-mailtpl-family-filter').on('change', applyFilter);
    // La liste est rechargée après chaque enregistrement ou suppression : on réapplique le filtre
    new MutationObserver(applyFilter).observe(document.getElementById('ispag-mailtpl-list'), { childList: true });
    applyFilter();

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
        const sample = $form.find('[name="message_family"]').val() === 'project_mail' ? (cfg.sampleProject || {}) : (cfg.sample || {});
        $.each(sample, function (tag, val) { out = out.split(tag).join(val); });
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
