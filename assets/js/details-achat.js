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
            alert('Erreur : ' + (response.data || 'Inconnue'));
            btn.prop('disabled', false).html(originalText);
        }
    }).fail(() => {
        alert('Erreur réseau');
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
            alert('Erreur: ' + response.data);
        }
    });
});


// --- ONGLETS SECONDAIRES CHARGÉS À LA DEMANDE (détails / suivis / documents) ---
// Le panneau contient déjà un skeleton rendu par le serveur ; le contenu réel est récupéré
// au premier affichage de l'onglet (clic, onglet actif au chargement, ou lien ?delivery=true).
jQuery(function ($) {
    function loadLazyTab($panel) {
        if (!$panel.length || $panel.data('lazyState')) return; // déjà chargé ou en cours
        $panel.data('lazyState', 'loading');

        const request = {
            action: 'ispag_achat_load_tab',
            nonce: (window.ispagVars || {}).nonce,
            achat_id: $panel.data('achat-id'),
            tab: $panel.data('lazy-tab')
        };

        function fail() {
            $panel.data('lazyState', null); // permet de réessayer au prochain clic
            $panel.html('<div class="ispag-notice warning"><p>Chargement impossible. Cliquez à nouveau sur l\'onglet pour réessayer.</p></div>');
        }

        if (typeof window.ISPAGLoad === 'function') {
            window.ISPAGLoad($panel, {
                action: request.action,
                data: request,
                skeleton: window.ISPAGSkeleton.lines(5)
            }).done(function () {
                $panel.data('lazyState', 'loaded');
            }).fail(function (err) {
                if (err !== 'abort') fail();
            });
        } else {
            // Repli sans skeleton du thème
            $.post(ajaxurl, request).done(function (response) {
                if (!response || !response.success) return fail();
                $panel.data('lazyState', 'loaded').html(response.data.html).trigger('ispag:loaded', [response.data]);
            }).fail(fail);
        }
    }

    $(document).on('click', '.tab-titles li[data-tab]', function () {
        loadLazyTab($('#' + $(this).data('tab') + '[data-lazy-tab]'));
    });

    // Onglet déjà actif au chargement (ex. ?delivery=true activé par tabs.js)
    setTimeout(function () {
        loadLazyTab($('.tab-content.active[data-lazy-tab]'));
    }, 0);
});
