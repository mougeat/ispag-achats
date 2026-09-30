document.addEventListener('DOMContentLoaded', function () {
    const wrapper = document.getElementById('achat-status-wrapper');
    if (!wrapper) return;
    const btn = document.getElementById('achat-status-btn');
    const dropdown = document.getElementById('achat-status-dropdown');
    const achatId = wrapper.dataset.achatId;

    btn.addEventListener('click', function () {


        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        // if (dropdown.childNodes.length === 0) {

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ispag_get_status_options'
                })
            })
            .then(res => res.json())
            .then(data => {
                    dropdown.innerHTML = ''; // sinon chaque clic ajoutait à nouveau tous les statuts
                    data.forEach(stat => {
                        const li = document.createElement('li');
                        li.textContent = stat.Etat;
                        li.style.background = stat.color;
                        li.className = stat.ClassCss;
                        li.style.padding = '5px';
                        li.style.cursor = 'pointer';
                        li.addEventListener('click', () => {
                            updateStatus(achatId, stat.Id)
                            // fetch(ajaxurl, {
                            //     method: 'POST',
                            //     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            //     body: new URLSearchParams({
                            //         action: 'ispag_update_status',
                            //         achat_id: achatId,
                            //         etat_id: stat.Id
                            //     })
                            // }).then(() => location.reload());
                        });
                        dropdown.appendChild(li);
                    });
            })
            


            // fetch(ajaxurl + '?action=ispag_get_status_options')
            //     .then(res => res.json())
            //     .then(data => {
            //         console.log(data);
            //         data.forEach(stat => {
            //             const li = document.createElement('li');
            //             li.textContent = stat.Etat;
            //             li.style.background = stat.color;
            //             li.className = stat.ClassCss;
            //             li.style.padding = '5px';
            //             li.style.cursor = 'pointer';
            //             li.addEventListener('click', () => {
            //                 fetch(ajaxurl, {
            //                     method: 'POST',
            //                     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            //                     body: new URLSearchParams({
            //                         action: 'ispag_update_status',
            //                         achat_id: achatId,
            //                         etat_id: stat.Id
            //                     })
            //                 }).then(() => location.reload());
            //             });
            //             dropdown.appendChild(li);
            //         });
            //     });
        // }
    });
});

$(document).on('click', '.achat-action-btn', function () {
    const hook = $(this).data('hook');
    const achatId = $(this).data('achat-id'); 

    if (typeof window[hook] === 'function') {
        window[hook](achatId, this);
    } else {
        console.warn('Hook JS introuvable :', hook);
    }
});

// async function ispag_send_rfq(achatId, btn) {
//     btn.disabled = true;
//     btn.innerText = "Sending...";

//     try {
//         const response = await fetch(ajaxurl, {
//             method: 'POST',
//             headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
//             body: new URLSearchParams({
//                 action: 'ispag_prepare_rfq_mail',
//                 achat_id: achatId
//             })
//         });

//         const result = await response.json();

//         if (!result.success) {
//             alert("Error: " + result.message);
//             return;
//         }

//         const { subject, message, email_contact, email_copy } = result.data;
//         console.log(result.data);

//         const mailto = `mailto:${email_contact}?cc=${email_copy}` +
//             `&subject=${encodeURIComponent(subject)}` +
//             `&body=${encodeURIComponent(message)}`;

//         window.location.href = mailto;

//     } catch (e) {
//         console.error(e);
//         alert("Une erreur est survenue.");
//     } finally {
        
//         btn.disabled = false;
//         btn.innerText = "Send RFQ";
//     }
// }
async function ispag_send_generic_ajax({ 
    achatId, 
    btn, 
    action = 'ispag_prepare_rfq_mail', 
    sendingText = 'Sending...', 
    successCallback = null,
    type, 
}) {
    console.group('🚀 ISPAG : Action ' + type);
    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerHTML = '<span class="dashicons dashicons-update spin"></span> ' + sendingText;

    try {
        const response = await fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: action,
                achat_id: achatId,
                type: type
            })
        });

        const result = await response.json();
        if (!result.success) {
            console.error('❌ Error PHP:', result.data.message);
            alert("Error: " + result.data.message);
            return;
        }

        console.info('✅ Données reçues :', result.data);
        
        if (typeof successCallback === 'function') {
            successCallback(result.data);
        }

    } catch (e) {
        console.error('🔥 Error Critique:', e);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
        console.groupEnd();
    }
}

function ispag_send_rfq(achatId, btn){
    ispag_send_generic_ajax({
        achatId: achatId,
        btn: btn,
        action: 'ispag_prepare_mail',
        sendingText: 'Sending the email...',
        type: 'send_proposal_request',
        
        successCallback: (data) => {
//            console.log(data);
            send_mail(data);
            updateStatus(achatId, data.next_status);
        }
    });
}

function ispag_send_order(achatId, btn) {
    ispag_send_generic_ajax({
        achatId: achatId,
        btn: btn,
        action: 'ispag_prepare_mail',
        sendingText: 'Sending the email...',
        type: 'send_purchase_order',
        successCallback: (data) => {
//            console.log(data);
            send_mail(data);
            updateStatus(achatId, data.next_status);
        }
    });
}

function ispag_send_drawing_modification(achatId, btn) {
    ispag_send_generic_ajax({
        achatId: achatId,
        btn: btn,
        action: 'ispag_prepare_mail',
        sendingText: 'Sending the email...',
        type: 'drawing_modified',
        successCallback: (data) => {
//            console.log(data);
            send_mail(data);
            updateStatus(achatId, data.next_status);
        }
    });
}

function ispag_send_drawing_validation(achatId, btn) {
    ispag_send_generic_ajax({
        achatId: achatId,
        btn: btn,
        action: 'ispag_prepare_mail',
        sendingText: 'Sending the email...',
        type: 'drawing_validated',
        successCallback: (data) => {
//            console.log(data);
            send_mail(data);
            updateStatus(achatId, data.next_status);
        }
    });
}
 


 
function updateStatus(achatId, Id){
    return fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'ispag_update_status',
            achat_id: achatId,
            etat_id: Id
        })
    })
    .then(res => res.json())
    .then(res => {
        if (!res || !res.success) { throw new Error('update failed'); }
        applyStatusChange(res.data);
    })
    .catch(() => {
        // Repli : si la réponse n'est pas celle attendue, on recharge la page comme avant
        location.reload();
    });
}

/**
 * Met à jour la page après un changement de statut, sans la recharger :
 * bouton de statut, bouton d'action suivant, boutons du bas de l'onglet Articles, et onglet Suivi (rechargé en arrière-plan).
 */
function applyStatusChange(data) {
    const $ = window.jQuery;
    if (data.status) {
        const btn = document.getElementById('achat-status-btn');
        if (btn) {
            btn.className = 'ispag-btn ' + (data.status.ClassCss || '');
            btn.style.background = data.status.color || '';
            btn.textContent = data.status.Etat + ' ⌄';
        }
    }
    const dropdown = document.getElementById('achat-status-dropdown');
    if (dropdown) { dropdown.style.display = 'none'; dropdown.innerHTML = ''; }

    if ($) {
        // bouton d'action lié au statut (Send order, Send RFQ…)
        const $right = $('.ispag-achat-header-actions .ispag-buttons-right');
        $right.find('.achat-action-btn').remove();
        if (data.action_html) { $right.append(data.action_html); }
        // boutons du bas (ajout, bon de livraison, suppression) : dépendent du statut
        if (typeof data.footer_html === 'string') { $('.ispag-action-buttons-secondary').html(data.footer_html); }
        // onglet Suivi : à recharger (en arrière-plan si visible, sinon au prochain affichage)
        const $suivi = $('#suivis[data-lazy-tab]');
        if ($suivi.length) {
            $suivi.data('lazyState', null);
            if ($suivi.hasClass('active')) { $('.tab-titles li[data-tab="suivis"]').trigger('click'); }
            else { $suivi.html('<div class="ispag-skeleton-wrapper" aria-hidden="true"><span class="ispag-skeleton-line ispag-w-60"></span><span class="ispag-skeleton-line ispag-w-90"></span></div>'); }
        }
    }
}
