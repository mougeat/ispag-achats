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

jQuery(document).on('click', '.ispag-delete-achat', function () {
    if (!confirm("Supprimer cet achat ?")) return;

    const btn = jQuery(this);
    const achatId = btn.data('achat-id');

    jQuery.post(ajaxurl, {
        action: 'ispag_delete_achat',
        achat_id: achatId
    }, function (response) {
        if (response.success) {
            alert('Achat supprimé');
            window.close();
        } else {
            alert('Erreur: ' + response.data);
        }
    });
});
