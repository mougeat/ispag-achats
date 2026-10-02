<?php
/**
 * Modèles d'e-mail par défaut (anglais) pour les commandes fournisseur — famille « purchase_order ».
 * Ajoutés par l'installateur UNIQUEMENT s'il n'existe pas déjà un modèle pour le même type et la même langue.
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
            . "Items ordered:\n{PRODUCT_LIST}\n\n"
            . "Delivery address:\n{DELIVERY_ADDRESS}\n"
            . "Contact on site: {DELIVERY_CONTACT} {DELIVERY_PHONE}\n\n"
            . "Could you please confirm receipt of this order and send us your order confirmation with the delivery date?\n\n"
            . "You can follow this order here: {PURCHASE_URL}\n\n"
            . "Best regards,\n{USER_NAME}\n{COMPANY_NAME}",
    ],
    [
        'message_type' => 'send_proposal_request',
        'lang'         => 'en_US',
        'subject'      => 'Request for quotation - {PROJECT_NAME}',
        'message'      => "Hello {FIRST_NAME},\n\n"
            . "We would like to receive your best quotation for the following items (project \"{PROJECT_NAME}\", ref. {ORDER_NUMBER}):\n{PRODUCT_LIST}\n\n"
            . "Delivery address:\n{DELIVERY_ADDRESS}\n\n"
            . "Please send us your price and delivery time.\n\n"
            . "Best regards,\n{USER_NAME}\n{COMPANY_NAME}",
    ],
    [
        'message_type' => 'drawing_validated',
        'lang'         => 'en_US',
        'subject'      => 'Drawing validated - {PROJECT_NAME} ({ORDER_NUMBER})',
        'message'      => "Hello {FIRST_NAME},\n\n"
            . "The drawing for order {ORDER_NUMBER} (project \"{PROJECT_NAME}\") has been validated by our customer.\n"
            . "You can proceed with the production.\n\n"
            . "Items:\n{PRODUCT_LIST}\n\n"
            . "Best regards,\n{USER_NAME}\n{COMPANY_NAME}",
    ],
    [
        'message_type' => 'drawing_modified',
        'lang'         => 'en_US',
        'subject'      => 'Drawing modifications - {PROJECT_NAME} ({ORDER_NUMBER})',
        'message'      => "Hello {FIRST_NAME},\n\n"
            . "Our customer requested modifications on the drawing for order {ORDER_NUMBER} (project \"{PROJECT_NAME}\").\n"
            . "Please find the requested changes attached and send us an updated drawing.\n\n"
            . "Items:\n{PRODUCT_LIST}\n\n"
            . "Best regards,\n{USER_NAME}\n{COMPANY_NAME}",
    ],
];
