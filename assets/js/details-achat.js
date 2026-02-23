jQuery(document).on('click', '.ispag-btn-copy-from-project', function () {
    const btn = jQuery(this);
    const achatId = btn.data('achat');
    const dealId = btn.data('deal-id');

    btn.prop('disabled', true).text('⏳ Copie...');

    jQuery.post(ajaxurl, {
        action: 'ispag_copy_project_address',
        achat_id: achatId,
        deal_id: dealId
    }, function (response) {
        if (response.success && response.data.html) {
            // Remplacer juste le bloc livraison
//            console.log(response.data);
            jQuery('#ispag-delivery-box').replaceWith(response.data.html);
        } else {
            alert('Erreur : ' + response.data);
        }
    }).always(() => {
        btn.prop('disabled', false).text('📥 ' + btn.text().replace('⏳ ', ''));
    });
});

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
