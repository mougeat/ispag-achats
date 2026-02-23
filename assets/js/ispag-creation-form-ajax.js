jQuery(document).ready(function($) {

    if (typeof ispagAjax === 'undefined') {
        return; 
    }

    var form = $('#ispag-achat-form');
    var refCommandeField = $('#ref_commande');
    var idProjetField = $('#id_projet');
    // NOUVEAU : Cible le champ masqué Hubspot
    var hubspotDealIdHidden = $('#hubspot_deal_id_hidden'); 

    // Maintient la logique de mise à jour de la référence du projet
    idProjetField.on('change', function() {
        var selectedOption = $(this).find('option:selected');
        var projectRef = selectedOption.data('ref');
        // NOUVEAU : Récupère l'ID Hubspot de l'attribut data
        var hubspotId = selectedOption.data('hubspot-id'); 
        
        if (projectRef) {
            refCommandeField.val(projectRef);
            refCommandeField.prop('readonly', true); 
            // NOUVEAU : Met à jour le champ masqué pour envoyer l'ID Hubspot
            hubspotDealIdHidden.val(hubspotId); 
        } else {
            refCommandeField.val('');
            refCommandeField.prop('readonly', false);
            // NOUVEAU : Réinitialise le champ masqué si "Select customer project" est choisi
            hubspotDealIdHidden.val(''); 
        }
    });

    // Gestion de la soumission AJAX du formulaire
    form.on('submit', function(e) {
        e.preventDefault(); 

        var submitButton = $('#submit-ajax-commande');
        var messageArea = $('#ispag-form-message');
        
        // 1. Désactiver le bouton et effacer les messages
        submitButton.prop('disabled', true).val('Saving...');
        messageArea.empty().removeClass('ispag-notice success error');

        // 2. Préparation des données
        var formData = form.serializeArray();
        formData.push({name: 'action', value: ispagAjax.action});
        formData.push({name: 'nonce', value: ispagAjax.nonce});

        // 3. Envoi de la requête AJAX
        $.ajax({
            url: ispagAjax.ajaxurl, 
            type: 'POST',
            data: formData,
            dataType: 'json',
            
            success: function(response) {
                // 4. Traitement de la réponse
                if (response.success) {
                    var newOrderId = response.data.id;
                    var message = '✅ ' + response.data.message + 
                                  '<br>Redirection vers les détails de la commande dans 1 seconde...';

                    messageArea.html(message).addClass('ispag-notice success');
                    
                    // Réinitialisation du formulaire après succès
                    form[0].reset();
                    refCommandeField.prop('readonly', false); 
                    // NOUVEAU : Réinitialisation du champ Hubspot
                    hubspotDealIdHidden.val(''); 

                    // Redirection après 1 seconde (1000 millisecondes)
                    setTimeout(function() {
                        var redirectUrl = 'https://app.ispag-asp.ch/details-achats/?poid=' + newOrderId;
                        window.location.href = redirectUrl;
                    }, 1000);

                } else {
                    // Erreur : Affiche le message rouge
                    messageArea.html('❌ ' + response.data.message)
                               .addClass('ispag-notice error');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // Erreur de connexion ou autre problème HTTP/JS
                messageArea.html('❌ An unknown network or server error occurred. Please check logs.')
                           .addClass('ispag-notice error');
                console.error("AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
            },
            complete: function() {
                // Rétablir le bouton UNIQUEMENT en cas d'erreur
                if (!messageArea.hasClass('success')) {
                    submitButton.prop('disabled', false).val('Save');
                }
            }
        });
    });
});