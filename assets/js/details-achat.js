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
            $(document).trigger('ispag:achat-tab-loaded', [$panel.data('lazy-tab'), $panel]);
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
});
