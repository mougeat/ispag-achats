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
//                console.log(data);
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
//     btn.innerText = "Envoi...";

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
//             alert("Erreur : " + result.message);
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
    sendingText = 'Envoi...', 
    successCallback = null,
    type, 
}) {
    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerText = sendingText;

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
            alert("Erreur : " + result.message);
            return;
        }

        // Appel de ta logique personnalisée (ex: ouvrir mailto)
        if (typeof successCallback === 'function') {
            successCallback(result.data);
        }

    } catch (e) {
        console.error(e);
        alert("Une erreur est survenue.");
    } finally {
        btn.disabled = false;
        btn.innerText = originalText;
    }
}

function ispag_send_rfq(achatId, btn){
    ispag_send_generic_ajax({
        achatId: achatId,
        btn: btn,
        action: 'ispag_prepare_mail',
        sendingText: 'Envoi de l\'email...',
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
        sendingText: 'Envoi de l\'email...',
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
        sendingText: 'Envoi de l\'email...',
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
        sendingText: 'Envoi de l\'email...',
        type: 'drawing_validated',
        successCallback: (data) => {
//            console.log(data);
            send_mail(data);
            updateStatus(achatId, data.next_status);
        }
    });
}



 
function updateStatus(achatId, Id){
    fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'ispag_update_status',
            achat_id: achatId,
            etat_id: Id
        })
    }).then(() => 
       location.reload()
    // console.log('updated')
    );
}