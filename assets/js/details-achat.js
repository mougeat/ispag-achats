// --- COPIE DEPUIS LE PROJET ---
jQuery(document).on('click', '.ispag-btn-copy-from-project', function () {
    handleAddressUpdate(jQuery(this), 'ispag_copy_project_address');
});

// --- REMPLISSAGE CARRY BOX ---
jQuery(document).on('click', '.ispag-btn-set-carrybox', function () {
    handleAddressUpdate(jQuery(this), 'ispag_set_carrybox_address');
});

/**
 * Fonction générique pour mettre à jour l'adresse
 */
function handleAddressUpdate(btn, actionName) {
    const achatId = btn.data('achat');
    const dealId = btn.data('deal-id');
    const originalText = btn.html();

    btn.prop('disabled', true).html('⏳ ...');

    jQuery.post(ajaxurl, {
        action: actionName,
        achat_id: achatId,
        deal_id: dealId
    }, function (response) {
        if (response.success && response.data.html) {
            // On remplace tout le bloc par le nouveau HTML généré par PHP
            jQuery('#ispag-delivery-box').replaceWith(response.data.html);
        } else {
            alert('Error: ' + (response.data || 'Inconnue'));
            btn.prop('disabled', false).html(originalText);
        }
    }).fail(() => {
        alert('Network error');
        btn.prop('disabled', false).html(originalText);
    });
}

jQuery(document).on('click', '.ispag-delete-achat', async function () {
    // if (!confirm("Supprimer cet achat ?")) return;

     const confirmed = await ispagConfirm(ispag_texts.confirm_delete_purchase + ' ?', {
        labelOk:     ispag_texts.delete,
        labelCancel: ispag_texts.cancel,
        danger:      true,
    });
    if (!confirmed) return;

    const btn = jQuery(this);
    const achatId = btn.data('achat-id');

    jQuery.post(ajaxurl, {
        action: 'ispag_delete_achat',
        achat_id: achatId
    }, function (response) {
        if (response.success) {
            // alert('Achat supprimé');
            window.close();
        } else {
            alert('Error: ' + response.data);
        }
    });
});


// --- ONGLETS CHARGÉS EN ARRIÈRE-PLAN (articles / détails / suivis / documents) ---
// La structure de la page et les informations de base sont rendues par le serveur ; chaque panneau contient un skeleton.
// L'onglet actif est chargé tout de suite, les autres l'un après l'autre en tâche de fond (ou au clic s'ils ne sont pas prêts).
jQuery(function ($) {
    function loadLazyTab($panel) {
        if (!$panel.length || $panel.data('lazyState')) return $.Deferred().resolve().promise(); // déjà chargé ou en cours
        $panel.data('lazyState', 'loading');
        const done = $.Deferred();

        const request = {
            action: 'ispag_achat_load_tab',
            nonce: (window.ispagVars || {}).nonce,
            achat_id: $panel.data('achat-id'),
            tab: $panel.data('lazy-tab')
        };

        function fail() {
            $panel.data('lazyState', null); // permet de réessayer au prochain clic
            $panel.html('<div class="ispag-notice warning"><p>Loading failed. Click the tab again to retry.</p></div>');
            done.resolve();
        }

        function ok() {
            $panel.data('lazyState', 'loaded');
            afterTabInjected($panel.data('lazy-tab'), $panel);
            done.resolve();
        }

        if (typeof window.ISPAGLoad === 'function') {
            window.ISPAGLoad($panel, {
                action: request.action,
                data: request,
                skeleton: window.ISPAGSkeleton.lines(5)
            }).done(ok).fail(function (err) {
                if (err !== 'abort') fail(); else done.resolve();
            });
        } else {
            // Repli sans skeleton du thème
            $.post(ajaxurl, request).done(function (response) {
                if (!response || !response.success) return fail();
                $panel.html(response.data.html).trigger('ispag:loaded', [response.data]);
                ok();
            }).fail(fail);
        }
        return done.promise();
    }

    // Les boutons/blocs d'un onglet chargé après coup doivent être « branchés » (les scripts s'initialisent au chargement de la page)
    function afterTabInjected(tab, $panel) {
        if (tab === 'articles') {
            ['attachEditModalEvents', 'attachViewModalEvents', 'bindStandardTitleListener'].forEach(function (fn) {
                if (typeof window[fn] === 'function') { try { window[fn](); } catch (e) { console.warn(fn, e); } }
            });
            if (typeof window.initTristateToggle === 'function') {
                document.querySelectorAll('.ispag-toggle-chip').forEach(window.initTristateToggle);
            }
        }
        // Zones de dépôt de documents (CRM) : à initialiser sur le contenu injecté
        $(document).trigger('ispag:content-injected', [$panel]);
        $(document).trigger('ispag:achat-tab-loaded', [tab, $panel]);
    }

    // Affichage des onglets (les panneaux existent déjà ; on charge s'ils ne le sont pas encore)
    $(document).on('click', '.tab-titles li[data-tab]', function () {
        const tab = $(this).data('tab');
        $('.tab-titles li').removeClass('active');
        $(this).addClass('active');
        $('.tab-content').removeClass('active');
        const $panel = $('#' + tab + '[data-lazy-tab]').addClass('active');
        loadLazyTab($panel);
    });

    // Chargement en tâche de fond : onglet actif d'abord, puis les autres à la suite
    function idle(fn) {
        if (window.requestIdleCallback) { window.requestIdleCallback(fn, { timeout: 1500 }); } else { setTimeout(fn, 200); }
    }
    setTimeout(function () {
        const $panels = $('.tab-content[data-lazy-tab]');
        const $active = $panels.filter('.active').first();
        const queue = [$active].concat($panels.not($active).toArray().map(function (el) { return $(el); }));
        (function next() {
            const $p = queue.shift();
            if (!$p) return;
            loadLazyTab($p).always(function () { idle(next); });
        })();
    }, 0);

    // --- Sélection d'articles (chargés en arrière-plan : gestionnaires délégués) ---
    function updateBulkActions() {
        const $boxes = $('.ispag-article-checkbox');
        const any = $boxes.filter(':checked').length > 0;
        $('#select-all-articles').prop('checked', $boxes.length > 0 && $boxes.filter(':checked').length === $boxes.length);
        $('.ispag-bulk-actions').css('display', any ? 'block' : 'none');
    }
    $(document).on('change', '#select-all-articles', function () {
        $('.ispag-article-checkbox').prop('checked', this.checked);
        updateBulkActions();
    });
    $(document).on('change', '.ispag-article-checkbox', updateBulkActions);

    // --- Actions groupées : ne recharge que les articles modifiés ---
    function reloadArticleRows(ids) {
        (ids || []).forEach(function (id) {
            $.post(ajaxurl, { action: 'ispag_reload_article_row', article_id: id, is_purchase: 'true' }, function (html) {
                const $row = $('.ispag-article[data-article-id="' + id + '"]');
                if ($row.length && typeof html === 'string' && html.trim() !== '') { $row.replaceWith(html); }
            });
        });
        // Boutons Edit / View des articles remplacés
        setTimeout(function () { afterTabInjected('articles', $('#articles')); }, 800);
    }
    window.ispagReloadArticleRows = reloadArticleRows;

    $(document).on('click', '#apply-bulk-update', function () {
        const $bulk = $(this).closest('.ispag-bulk-actions');
        const $msg = $('#ispag-bulk-message');
        const ids = $('.ispag-article .ispag-article-checkbox:checked').map(function () { return $(this).data('article-id'); }).get();
        if (!ids.length) { alert('No article selected'); return; }

        const $btn = $(this).prop('disabled', true);
        $.post(ajaxurl, {
            action: 'ispag_bulk_achat_update_articles',
            articles: ids.join(','),
            achat_id: $('#achat-id').val(),
            date_depart: $('#bulk-date-depart').val(),
            livre_date: $('#bulk-livre-date').val(),
            invoiced_date: $('#bulk-invoiced-date').val(),
            _ajax_nonce: (window.ispagVars || {}).bulk_nonce
        }).done(function (response) {
            const ok = response && response.success;
            $msg.text((response && response.data && response.data.message) || (ok ? 'Done' : 'Unknown error'))
                .css({ display: 'block', background: ok ? '#d4edda' : '#f8d7da', color: ok ? '#155724' : '#721c24' });
            if (ok) {
                reloadArticleRows(ids);
                $('.ispag-article-checkbox').prop('checked', false);
                $bulk.find('input[type="date"]').val('');
                setTimeout(function () { $msg.hide(); $bulk.hide(); }, 2500);
                $(document).trigger('ispag:achat-articles-changed');
            }
        }).fail(function () {
            $msg.text('Network error').css({ display: 'block', background: '#f8d7da', color: '#721c24' });
        }).always(function () { $btn.prop('disabled', false); });
    });

    // --- Contacts du fournisseur : choisir / changer / retirer un contact ---
    $(document).on('click keydown', '.ispag-sc-edit', function (e) {
        if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
        const $row = $(this).closest('.ispag-supplier-contact');
        const $info = $row.find('.ispag-sc-info');
        if ($row.data('editing')) return;
        $row.data('editing', true).data('infoHtml', $info.html());
        $info.html(
            '<input type="search" class="ispag-sc-search" placeholder="Filter this supplier\'s contacts..." style="width:100%; margin-bottom:4px;" autocomplete="off">' +
            '<div class="ispag-sc-results" style="max-height:180px; overflow:auto;"></div>' +
            '<div style="margin-top:4px;"><button type="button" class="ispag-btn ispag-sc-clear">Remove</button> ' +
            '<button type="button" class="ispag-btn ispag-sc-cancel">Cancel</button></div>'
        );
        $info.find('.ispag-sc-search').trigger('focus').trigger('input');   // affiche tout de suite les contacts du fournisseur
    });

    function scClose($row) {
        $row.find('.ispag-sc-info').html($row.data('infoHtml') || '');
        $row.data('editing', false);
    }
    $(document).on('click', '.ispag-sc-cancel', function () { scClose($(this).closest('.ispag-supplier-contact')); });
    $(document).on('keydown', '.ispag-sc-search', function (e) { if (e.key === 'Escape') scClose($(this).closest('.ispag-supplier-contact')); });

    let scTimer = null;
    $(document).on('input', '.ispag-sc-search', function () {
        const $input = $(this);
        const $res = $input.siblings('.ispag-sc-results');
        clearTimeout(scTimer);
        scTimer = setTimeout(function () {
            const q = $.trim($input.val());
            const supplierId = $input.closest('.ispag-supplier-contact').data('supplier-id');
            $.post(ajaxurl, { action: 'ispag_achat_search_contacts', nonce: (window.ispagVars || {}).nonce, supplier_id: supplierId, q: q }).done(function (r) {
                $res.empty();
                const list = (r && r.success && r.data.results) || [];
                if (!list.length) { $res.append($('<div>').css({ color: '#999', padding: '4px' }).text('No contact found for this supplier.')); return; }
                list.forEach(function (c) {
                    $('<div class="ispag-sc-result" role="button" tabindex="0">').css({ cursor: 'pointer', padding: '4px', borderBottom: '1px solid #f0f0f0' })
                        .attr('data-user-id', c.id).text(c.name + (c.mail ? ' — ' + c.mail : '')).appendTo($res);
                });
            });
        }, 250);
    });

    function scSave($row, userId) {
        $row.css('opacity', 0.5);
        $.post(ajaxurl, {
            action: 'ispag_achat_set_supplier_contact',
            nonce: (window.ispagVars || {}).nonce,
            supplier_id: $row.data('supplier-id'),
            role: $row.data('role'),
            user_id: userId
        }).done(function (r) {
            if (r && r.success) { $row.replaceWith(r.data.html); } else { $row.css('opacity', 1); alert('Save failed.'); }
        }).fail(function () { $row.css('opacity', 1); alert('Network error'); });
    }
    $(document).on('click', '.ispag-sc-result', function () { scSave($(this).closest('.ispag-supplier-contact'), $(this).data('user-id')); });
    $(document).on('click', '.ispag-sc-clear', function () { scSave($(this).closest('.ispag-supplier-contact'), 0); });
});
