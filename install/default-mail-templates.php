<?php
/**
 * Modèles d'e-mail par défaut (anglais, français, allemand, italien) pour les commandes fournisseur — famille « purchase_order ».
 * Ajoutés par l'installateur UNIQUEMENT s'il n'existe pas déjà un modèle pour le même type et la même langue.
 * 'legacy_subjects' : anciens objets par défaut, pour reconnaître (et mettre à jour) un modèle installé par nous et jamais modifié.
 * Les balises {TAG} sont remplacées à l'envoi (liste : ISPAG_Achat_Mail_Templates::tags()).
 */
defined('ABSPATH') || exit;

return [
    [
        'message_type' => 'send_purchase_order',
        'lang'         => 'en_US',
        'subject'      => 'Purchase order {ORDER_NUMBER} - {PROJECT_NAME}',
        'message'      => "Hello {FIRST_NAME},\n\n"
            . "Please find attached our purchase order {ORDER_NUMBER} for the project \"{PROJECT_NAME}\".\n\n"
            . "Delivery address:\n{DELIVERY_ADDRESS}\n"
            . "Contact on site: {DELIVERY_CONTACT} {DELIVERY_PHONE}\n\n"
            . "Could you please confirm receipt of this order and send us your order confirmation with the delivery date?\n\n"
            . "Best regards,",
    ],
    [
        'message_type' => 'send_proposal_request',
        'lang'         => 'en_US',
        'subject'      => 'Request for quotation {ORDER_NUMBER} - {PROJECT_NAME}',
        'legacy_subjects' => ['Request for quotation - {PROJECT_NAME}'],
        'message'      => "Hello {FIRST_NAME},\n\n"
            . "We would like to receive your best quotation for the following items (project \"{PROJECT_NAME}\", ref. {ORDER_NUMBER}):\n{PRODUCT_LIST}\n\n"
            . "Please send us your price and delivery time.\n\n"
            . "Best regards,",
    ],
    [
        'message_type' => 'drawing_validated',
        'lang'         => 'en_US',
        'subject'      => 'Drawing validated - {PROJECT_NAME} ({ORDER_NUMBER})',
        'message'      => "Hello {FIRST_NAME},\n\n"
            . "The drawing for order {ORDER_NUMBER} (project \"{PROJECT_NAME}\") has been validated by our customer (validation attached).\n"
            . "You can proceed with the production.\n\n"
            . "Best regards,",
    ],
    [
        'message_type' => 'drawing_modified',
        'lang'         => 'en_US',
        'subject'      => 'Drawing modifications - {PROJECT_NAME} ({ORDER_NUMBER})',
        'message'      => "Hello {FIRST_NAME},\n\n"
            . "Our customer requested modifications on the drawing for order {ORDER_NUMBER} (project \"{PROJECT_NAME}\").\n"
            . "Please find the requested changes attached and send us an updated drawing.\n\n"
            . "Best regards,",
    ],
    [
        'message_type' => 'send_purchase_order',
        'lang'         => 'fr_FR',
        'subject'      => 'Commande {ORDER_NUMBER} - {PROJECT_NAME}',
        'message'      => "Bonjour {FIRST_NAME},\n\nVeuillez trouver ci-joint notre commande {ORDER_NUMBER} pour le projet « {PROJECT_NAME} ».\n\nAdresse de livraison :\n{DELIVERY_ADDRESS}\nContact sur place : {DELIVERY_CONTACT} {DELIVERY_PHONE}\n\nPourriez-vous nous confirmer la bonne réception de cette commande et nous envoyer votre confirmation de commande avec la date de livraison ?\n\nMeilleures salutations,",
    ],
    [
        'message_type' => 'send_proposal_request',
        'lang'         => 'fr_FR',
        'subject'      => "Demande d'offre {ORDER_NUMBER} - {PROJECT_NAME}",
        'legacy_subjects' => ["Demande d'offre - {PROJECT_NAME}"],
        'message'      => "Bonjour {FIRST_NAME},\n\nNous souhaiterions recevoir votre meilleure offre pour les articles suivants (projet « {PROJECT_NAME} », réf. {ORDER_NUMBER}) :\n{PRODUCT_LIST}\n\nMerci de nous indiquer votre prix et votre délai de livraison.\n\nMeilleures salutations,",
    ],
    [
        'message_type' => 'drawing_validated',
        'lang'         => 'fr_FR',
        'subject'      => 'Plan validé - {PROJECT_NAME} ({ORDER_NUMBER})',
        'message'      => "Bonjour {FIRST_NAME},\n\nLe plan de la commande {ORDER_NUMBER} (projet « {PROJECT_NAME} ») a été validé par notre client (validation en pièce jointe).\nVous pouvez lancer la fabrication.\n\nMeilleures salutations,",
    ],
    [
        'message_type' => 'drawing_modified',
        'lang'         => 'fr_FR',
        'subject'      => 'Modifications du plan - {PROJECT_NAME} ({ORDER_NUMBER})',
        'message'      => "Bonjour {FIRST_NAME},\n\nNotre client demande des modifications sur le plan de la commande {ORDER_NUMBER} (projet « {PROJECT_NAME} »).\nVous trouverez les modifications demandées en pièce jointe ; merci de nous envoyer un plan mis à jour.\n\nMeilleures salutations,",
    ],
    [
        'message_type' => 'send_purchase_order',
        'lang'         => 'de_DE',
        'subject'      => 'Bestellung {ORDER_NUMBER} - {PROJECT_NAME}',
        'message'      => "Guten Tag {FIRST_NAME},\n\nanbei erhalten Sie unsere Bestellung {ORDER_NUMBER} für das Projekt „{PROJECT_NAME}“.\n\nLieferadresse:\n{DELIVERY_ADDRESS}\nKontakt vor Ort: {DELIVERY_CONTACT} {DELIVERY_PHONE}\n\nBitte bestätigen Sie uns den Erhalt dieser Bestellung und senden Sie uns Ihre Auftragsbestätigung mit dem Liefertermin.\n\nFreundliche Grüsse",
    ],
    [
        'message_type' => 'send_proposal_request',
        'lang'         => 'de_DE',
        'subject'      => 'Anfrage {ORDER_NUMBER} - {PROJECT_NAME}',
        'legacy_subjects' => ['Anfrage - {PROJECT_NAME}'],
        'message'      => "Guten Tag {FIRST_NAME},\n\nwir bitten Sie um Ihr bestes Angebot für die folgenden Artikel (Projekt „{PROJECT_NAME}“, Ref. {ORDER_NUMBER}):\n{PRODUCT_LIST}\n\nBitte teilen Sie uns Ihren Preis und Ihre Lieferzeit mit.\n\nFreundliche Grüsse",
    ],
    [
        'message_type' => 'drawing_validated',
        'lang'         => 'de_DE',
        'subject'      => 'Zeichnung freigegeben - {PROJECT_NAME} ({ORDER_NUMBER})',
        'message'      => "Guten Tag {FIRST_NAME},\n\ndie Zeichnung zur Bestellung {ORDER_NUMBER} (Projekt „{PROJECT_NAME}“) wurde von unserem Kunden freigegeben (Freigabe im Anhang).\nSie können mit der Fertigung beginnen.\n\nFreundliche Grüsse",
    ],
    [
        'message_type' => 'drawing_modified',
        'lang'         => 'de_DE',
        'subject'      => 'Zeichnungsänderungen - {PROJECT_NAME} ({ORDER_NUMBER})',
        'message'      => "Guten Tag {FIRST_NAME},\n\nunser Kunde wünscht Änderungen an der Zeichnung zur Bestellung {ORDER_NUMBER} (Projekt „{PROJECT_NAME}“).\nDie gewünschten Änderungen finden Sie im Anhang; bitte senden Sie uns eine aktualisierte Zeichnung.\n\nFreundliche Grüsse",
    ],
    [
        'message_type' => 'send_purchase_order',
        'lang'         => 'it_IT',
        'subject'      => 'Ordine {ORDER_NUMBER} - {PROJECT_NAME}',
        'message'      => "Buongiorno {FIRST_NAME},\n\nin allegato trovate il nostro ordine {ORDER_NUMBER} per il progetto «{PROJECT_NAME}».\n\nIndirizzo di consegna:\n{DELIVERY_ADDRESS}\nReferente sul posto: {DELIVERY_CONTACT} {DELIVERY_PHONE}\n\nVi preghiamo di confermarci la ricezione di questo ordine e di inviarci la conferma d'ordine con la data di consegna.\n\nCordiali saluti,",
    ],
    [
        'message_type' => 'send_proposal_request',
        'lang'         => 'it_IT',
        'subject'      => 'Richiesta di offerta {ORDER_NUMBER} - {PROJECT_NAME}',
        'legacy_subjects' => ['Richiesta di offerta - {PROJECT_NAME}'],
        'message'      => "Buongiorno {FIRST_NAME},\n\nvorremmo ricevere la vostra migliore offerta per i seguenti articoli (progetto «{PROJECT_NAME}», rif. {ORDER_NUMBER}):\n{PRODUCT_LIST}\n\nVi preghiamo di indicarci prezzo e tempi di consegna.\n\nCordiali saluti,",
    ],
    [
        'message_type' => 'drawing_validated',
        'lang'         => 'it_IT',
        'subject'      => 'Disegno approvato - {PROJECT_NAME} ({ORDER_NUMBER})',
        'message'      => "Buongiorno {FIRST_NAME},\n\nil disegno dell'ordine {ORDER_NUMBER} (progetto «{PROJECT_NAME}») è stato approvato dal nostro cliente (approvazione in allegato).\nPotete avviare la produzione.\n\nCordiali saluti,",
    ],
    [
        'message_type' => 'drawing_modified',
        'lang'         => 'it_IT',
        'subject'      => 'Modifiche al disegno - {PROJECT_NAME} ({ORDER_NUMBER})',
        'message'      => "Buongiorno {FIRST_NAME},\n\nil nostro cliente ha richiesto modifiche al disegno dell'ordine {ORDER_NUMBER} (progetto «{PROJECT_NAME}»).\nTrovate le modifiche richieste in allegato; vi preghiamo di inviarci un disegno aggiornato.\n\nCordiali saluti,",
    ],
];
