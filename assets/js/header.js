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
                // On recharge l'onglet ou la page pour voir le changement
                location.reload(); 
            } else {
                alert(ispagT('Error: ') + response.data);
                $btn.prop('disabled', false).text(ispagT('Retry'));
            }
        });
    });
});