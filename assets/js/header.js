window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
jQuery(document).ready(function($) {
    $(document).on('click', '.apply-auto-adjustment', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const data = {
            action: 'ispag_apply_purchase_adjustment',
            security: ispag_fournisseurs.nonce, // Assurez-vous d'avoir un nonce
            type: $btn.data('type'),
            amount: $btn.data('amount'),
            achat_id: $btn.data('achat')
        };

        $btn.prop('disabled', true).text('Applying...');

        $.post(ispag_fournisseurs.ajaxurl, data, function(response) {
            if (response.success) {
                if ($('#articles').length) {
                    // Sans recharger la page ni tous les articles : le bandeau disparaît et seule la ligne est ajoutée / mise à jour
                    $btn.closest('.ispag-notice').fadeOut(150);
                    const lineId = response.data && response.data.line_id;
                    if (typeof window.ispagUpsertPurchaseLine === 'function' && lineId) {
                        window.ispagUpsertPurchaseLine(lineId);
                    } else {
                        $(document).trigger('ispag:achat-reload-articles');
                    }
                } else {
                    location.reload();
                }
            } else {
                alert(ispagT('Error: ') + (response.data && response.data.message ? response.data.message : response.data));
                $btn.prop('disabled', false).text(ispagT('Retry'));
            }
        });
    });
});