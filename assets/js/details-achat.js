window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
// --- COPIE DEPUIS LE PROJET ---
jQuery(document).on('click', '.ispag-btn-copy-from-project', function () {
    handleAddressUpdate(jQuery(this), 'ispag_copy_project_address');
});

// --- ADRESSE = DÉPÔT DE STOCK (plugin ISPAG Stock) ---
jQuery(document).on('click', '.ispag-btn-set-stock-location', function () {
    const btn = jQuery(this);
    const locationId = btn.closest('.ispag-stock-location-picker').find('.ispag-stock-location-select').val();
    if (!locationId) return;
    handleAddressUpdate(btn, 'ispag_set_stock_location_address', { location_id: locationId });
});

/**
 * Fonction générique pour mettre à jour l'adresse
 */
function handleAddressUpdate(btn, actionName, extra) {
    const achatId = btn.data('achat');
    const dealId = btn.data('deal-id');
    const originalText = btn.html();

    btn.prop('disabled', true).html('⏳ ...');

    jQuery.post(ajaxurl, Object.assign({
        action: actionName,
        achat_id: achatId,
        deal_id: dealId
    }, extra || {}), function (response) {
        if (response.success && response.data.html) {
            // On remplace tout le bloc par le nouveau HTML généré par PHP
            jQuery('#ispag-delivery-box').replaceWith(response.data.html);
        } else {
            alert(ispagT('Error: ') + (response.data || 'Inconnue'));
            btn.prop('disabled', false).html(originalText);
        }
    }).fail(() => {
        alert(ispagT('Network error'));
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
            alert(ispagT('Error: ') + response.data);
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
    // Recharge l'onglet Articles (ex. : après l'ajout d'un article standard)
    $(document).on('ispag:achat-reload-articles', function () {
        const $panel = $('#articles');
        $panel.data('lazyState', null);
        loadLazyTab($panel);
    });

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
    $(document).on('click', '.ispag-tabs-navigation .ispag-tab-btn[data-tab]', function () {
        const tab = $(this).data('tab');
        $('.ispag-tabs-navigation .ispag-tab-btn').removeClass('active');
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

    // --- Blocs articles : menu ⋯, clic sur la ligne, groupes repliables ---
    // --- Changement de fournisseur : cartes Supplier et Contacts mises à jour sans recharger la page ---
    $(document).on('ispag:inline-edit-saved', function (e, info) {
        if (!info || info.source !== 'purchase' || info.field !== 'Fournisseur') { return; }
        const achatId = $('#achat-status-wrapper').data('achat-id') || $('#achat-id').val() || info.id;
        $.post(ajaxurl, { action: 'ispag_achat_supplier_cards', achat_id: achatId }, function (resp) {
            if (!resp || !resp.success) { return; }
            $('.ispag-supplier-card').replaceWith(resp.data.supplier_html);
            const $contacts = $('.ispag-supplier-contacts');
            if ($contacts.length) { $contacts.replaceWith(resp.data.contacts_html); }
            else { $('.ispag-supplier-card').first().after(resp.data.contacts_html); }
            $('#tank-supplier-display').attr('data-supplier-id', resp.data.supplier_id || '');
            $(document).trigger('ispag:achat-supplier-changed', [resp.data.supplier_id]);
        });
    });


    // --- Add product : article standard du fournisseur, ou article manuel (formulaire habituel) ---
    function esc(t) { return $('<div>').text(t == null ? '' : String(t)).html(); }

    $(document).on('click', '#ispag-achat-add-product', function () {
        const achatId = $(this).data('achat-id');
        $('#ispag-achat-add-modal').remove();
        const $m = $(
            '<div id="ispag-achat-add-modal" style="position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:16px;">' +
              '<div style="background:#fff;border-radius:10px;max-width:640px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.3);">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #eee;"><h3 style="margin:0;font-size:1.1em;">Add product</h3><button type="button" class="ispag-add-close" aria-label="Close" style="background:none;border:0;font-size:26px;line-height:1;cursor:pointer;width:auto;padding:0 6px;color:#555;">&times;</button></div>' +
                '<div style="padding:14px 18px;overflow:auto;">' +
                  '<p style="margin:0 0 6px;font-weight:600;">Standard article of this supplier</p>' +
                  '<input type="search" class="ispag-add-search" placeholder="Search by title, reference or description…" style="width:100%;margin-bottom:8px;" autocomplete="off">' +
                  '<div class="ispag-add-results" style="min-height:60px;"></div>' +
                  '<hr style="margin:16px 0;">' +
                  '<button type="button" class="ispag-btn ispag-btn-secondary-outlined ispag-add-manual">Manual article…</button>' +
                '</div>' +
              '</div>' +
            '</div>').appendTo('body');

        let timer = null;
        function load() {
            const q = $m.find('.ispag-add-search').val();
            const $r = $m.find('.ispag-add-results').html('<p style="color:#888;">Loading…</p>');
            $.post(ajaxurl, { action: 'ispag_achat_supplier_std_articles', achat_id: achatId, q: q, nonce: (window.ispagVars || {}).add_product_nonce }, function (resp) {
                if (!resp || !resp.success) {
                    const msg = resp && resp.data && resp.data.message ? resp.data.message : ispagT('Error');
                    $r.html('<p style="color:#b32d2e;">' + esc(msg) + '</p>');
                    return;
                }
                const items = resp.data.items;
                if (!items.length) { $r.html('<p style="color:#888;">No standard article for this supplier.</p>'); return; }
                $r.empty();
                items.forEach(function (it) {
                    const price = it.price ? (it.price + (it.currency ? ' ' + it.currency : '') + (it.discount ? ' −' + it.discount + '%' : '')) : '';
                    $r.append(
                        '<div class="ispag-add-item" data-id="' + it.id + '" style="display:flex;gap:10px;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px dashed #e5e7eb;">' +
                          '<div style="min-width:0;"><div style="font-weight:600;">' + esc(it.title) + '</div><div style="font-size:12px;color:#6b7280;">' + esc(it.ref) + (price ? ' · ' + esc(price) : '') + '</div></div>' +
                          '<div style="display:flex;gap:6px;align-items:center;"><input type="number" min="1" value="1" class="ispag-add-qty" style="width:64px;"><button type="button" class="ispag-btn ispag-btn-red-outlined ispag-add-std">Add</button></div>' +
                        '</div>');
                });
            });
        }
        load();

        $m.on('input', '.ispag-add-search', function () { clearTimeout(timer); timer = setTimeout(load, 300); });
        $m.on('click', '.ispag-add-close', function () { $m.remove(); });
        $m.on('click', function (e) { if (e.target === $m[0]) { $m.remove(); } });
        $m.on('click', '.ispag-add-manual', function () { $m.remove(); $('#ispag-add-article').trigger('click'); });
        $m.on('click', '.ispag-add-std', function () {
            const $row = $(this).closest('.ispag-add-item');
            const $btn = $(this).prop('disabled', true);
            $.post(ajaxurl, { action: 'ispag_achat_add_standard_article', achat_id: achatId, article_id: $row.data('id'), qty: $row.find('.ispag-add-qty').val(), nonce: (window.ispagVars || {}).add_product_nonce }, function (resp) {
                if (resp && resp.success) {
                    $m.remove();
                    // L'onglet Articles est rechargé : la nouvelle ligne apparaît tout de suite
                    $('#articles').removeData('loaded').removeAttr('data-loaded');
                    $(document).trigger('ispag:achat-reload-articles');
                } else {
                    alert((resp && resp.data && resp.data.message) || 'Error');
                    $btn.prop('disabled', false);
                }
            });
        });
    });

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
        if (!ids.length) { alert(ispagT('No article selected')); return; }

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
            $msg.text(ispagT('Network error')).css({ display: 'block', background: '#f8d7da', color: '#721c24' });
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
                if (!list.length) { $res.append($('<div>').css({ color: '#999', padding: '4px' }).text(ispagT('No contact found for this supplier.'))); return; }
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
            if (r && r.success) { $row.replaceWith(r.data.html); } else { $row.css('opacity', 1); alert(ispagT('Save failed.')); }
        }).fail(function () { $row.css('opacity', 1); alert(ispagT('Network error')); });
    }
    $(document).on('click', '.ispag-sc-result', function () { scSave($(this).closest('.ispag-supplier-contact'), $(this).data('user-id')); });
    $(document).on('click', '.ispag-sc-clear', function () { scSave($(this).closest('.ispag-supplier-contact'), 0); });
});

// --- ADRESSE DE LIVRAISON : lecture / édition de tous les champs d'un coup ---
(function ($) {
    function box(el) { return $(el).closest('#ispag-delivery-box'); }

    $(document).on('click', '.ispag-delivery-edit-btn', function () {
        const $b = box(this);
        $b.find('.ispag-delivery-view').prop('hidden', true);
        $b.find('.ispag-delivery-actions').prop('hidden', true);
        $b.find('.ispag-delivery-edit-btn').prop('hidden', true);
        $b.find('.ispag-delivery-form').prop('hidden', false).find('input:first').trigger('focus');
        initDeliveryPhone($b);
    });

    // Téléphone : sélecteur de pays + formatage (intl-tel-input, comme dans le CRM ; chargé par le thème)
    function initDeliveryPhone($b) {
        const input = $b.find('input[name="num_tel_contact"]').get(0);
        if (!input || $(input).data('iti') || typeof window.intlTelInput === 'undefined') return;
        const utils = (window.ispag_params && ispag_params.utils_url) || 'https://cdn.jsdelivr.net/npm/intl-tel-input@20.0.5/build/js/utils.js';
        const iti = window.intlTelInput(input, {
            initialCountry: 'ch',
            preferredCountries: ['ch', 'fr', 'be', 'de'],
            separateDialCode: true,
            allowDropdown: true,
            dropdownContainer: document.body,
            utilsScript: utils
        });
        $(input).data('iti', iti);
    }

    function destroyDeliveryPhone($form) {
        const $input = $form.find('input[name="num_tel_contact"]');
        const iti = $input.data('iti');
        if (iti) { iti.destroy(); $input.removeData('iti'); }
    }

    $(document).on('click', '.ispag-delivery-cancel-btn', function () {
        const $b = box(this);
        const $f = $b.find('.ispag-delivery-form');
        destroyDeliveryPhone($f);
        $f.prop('hidden', true).get(0).reset();
        $b.find('.ispag-delivery-view, .ispag-delivery-actions, .ispag-delivery-edit-btn').prop('hidden', false);
    });

    // Échap = annuler
    $(document).on('keydown', '.ispag-delivery-form input', function (e) {
        if (e.key === 'Escape') { $(this).closest('#ispag-delivery-box').find('.ispag-delivery-cancel-btn').trigger('click'); }
    });

    // Code postal → ville (si la ville est vide)
    $(document).on('blur', '.ispag-delivery-form input[name="NIP"]', function () {
        const zip = $.trim(this.value);
        const $city = $(this).closest('form').find('input[name="City"]');
        if (!zip || $.trim($city.val())) return;
        const country = zip.length <= 4 ? 'CH' : 'FR';
        fetch('https://api.zippopotam.us/' + country + '/' + encodeURIComponent(zip))
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                const city = d && d.places && d.places[0] && d.places[0]['place name'];
                if (city && !$.trim($city.val())) { $city.val(city); }
            })
            .catch(function () {});
    });

    $(document).on('submit', '.ispag-delivery-form', function (e) {
        e.preventDefault();
        const $form = $(this);
        const $b = box(this);
        const $btn = $form.find('button[type="submit"]');
        const $status = $form.find('.ispag-delivery-status');

        // Téléphone : validation + enregistrement au format international lisible (+41 79 123 45 67)
        const $phone = $form.find('input[name="num_tel_contact"]');
        const iti = $phone.data('iti');
        if (iti && $.trim($phone.val())) {
            if (!iti.isValidNumber()) {
                $status.text('❌ ' + (window.ispag_texts && ispag_texts.invalid_phone ? ispag_texts.invalid_phone : 'Invalid phone number'));
                $phone.trigger('focus');
                return;
            }
            const fmt = (window.intlTelInputUtils && intlTelInputUtils.numberFormat) ? intlTelInputUtils.numberFormat.INTERNATIONAL : undefined;
            $phone.val(fmt !== undefined ? iti.getNumber(fmt) : iti.getNumber());
        } else if (iti) {
            $phone.val('');
        }
        const data = $form.serializeArray();
        data.push({ name: 'action', value: 'ispag_achat_save_delivery' });
        data.push({ name: 'achat_id', value: $b.data('achat') });
        data.push({ name: 'nonce', value: $b.data('nonce') });

        $btn.prop('disabled', true);
        $status.text('⏳ …');
        $.post(ajaxurl, $.param(data)).done(function (response) {
            if (response && response.success && response.data && response.data.html) {
                $b.replaceWith(response.data.html);
            } else {
                $btn.prop('disabled', false);
                $status.text('❌ ' + ((response && response.data) || 'Error'));
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text(ispagT('❌ Network error'));
        });
    });
})(jQuery);
