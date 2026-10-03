<?php
/**
 * États des commandes fournisseur (export production 30.09.2026)
 * Appliqué seulement si la table est vide (voir seed() de l'installateur).
 */
defined('ABSPATH') || exit;

return [
    ['Id' => 1, 'steps' => 'purchase', 'Etat' => 'Purchase request', 'ordre' => 11, 'ClassCss' => 'non_envoyee', 'is_automatic' => 0, 'color' => 'var(--ispag-blue-light)', 'ActionText' => 'Send order', 'JsHook' => 'ispag_send_order', 'allow_price_recalculation' => 1],
    ['Id' => 2, 'steps' => 'purchase', 'Etat' => 'Supplier order sent', 'ordre' => 12, 'ClassCss' => 'envoyee', 'is_automatic' => 1, 'color' => 'var(--ispag-btn-grey)', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 1],
    ['Id' => 3, 'steps' => 'purchase', 'Etat' => 'Order confirmed', 'ordre' => 18, 'ClassCss' => 'cmd_conf', 'is_automatic' => 1, 'color' => 'var(--ispag-btn-grey)', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 0],
    ['Id' => 4, 'steps' => 'purchase', 'Etat' => 'Materials received', 'ordre' => 19, 'ClassCss' => 'materiel_recu', 'is_automatic' => 1, 'color' => 'var(--ispag-blue)', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 0],
    ['Id' => 5, 'steps' => 'purchase', 'Etat' => 'Validated invoice', 'ordre' => 20, 'ClassCss' => 'facture_valide', 'is_automatic' => 1, 'color' => '#93D3A2', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 0],
    ['Id' => 6, 'steps' => 'proposal', 'Etat' => 'RFQ', 'ordre' => 1, 'ClassCss' => 'rfq', 'is_automatic' => 1, 'color' => 'var(--ispag-blue-light)', 'ActionText' => 'Send RFQ', 'JsHook' => 'ispag_send_rfq', 'allow_price_recalculation' => 1],
    ['Id' => 10, 'steps' => 'proposal', 'Etat' => 'RFQ Done', 'ordre' => 2, 'ClassCss' => 'rfq_done', 'is_automatic' => 1, 'color' => 'var(--ispag-btn-grey)', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 1],
    ['Id' => 11, 'steps' => '', 'Etat' => 'Order canceled', 'ordre' => 50, 'ClassCss' => 'order_canceled', 'is_automatic' => 0, 'color' => '#bfad93', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 0],
    ['Id' => 12, 'steps' => 'purchase', 'Etat' => 'Drawing received', 'ordre' => 13, 'ClassCss' => 'drawing_received', 'is_automatic' => 1, 'color' => 'var(--ispag-blue)', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 1],
    ['Id' => 13, 'steps' => 'purchase', 'Etat' => 'Drawing validation sent', 'ordre' => 17, 'ClassCss' => 'drawing_validated', 'is_automatic' => 1, 'color' => 'var(--ispag-green)', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 1],
    ['Id' => 14, 'steps' => 'purchase', 'Etat' => 'Customer validation received', 'ordre' => 16, 'ClassCss' => 'customer_validation', 'is_automatic' => 1, 'color' => 'var(--ispag-red)', 'ActionText' => 'Send drawing validation', 'JsHook' => 'ispag_send_drawing_validation', 'allow_price_recalculation' => 1],
    ['Id' => 15, 'steps' => 'purchase', 'Etat' => 'Drawing modification received', 'ordre' => 14, 'ClassCss' => 'drawing_modification', 'is_automatic' => 1, 'color' => 'var(--ispag-red)', 'ActionText' => 'Send drawing modifications', 'JsHook' => 'ispag_send_drawing_modification', 'allow_price_recalculation' => 1],
    ['Id' => 16, 'steps' => 'purchase', 'Etat' => 'Drawing modification sent', 'ordre' => 15, 'ClassCss' => 'drawing_modification_sent', 'is_automatic' => 0, 'color' => 'var(--ispag-green)', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 1],
    ['Id' => 17, 'steps' => '', 'Etat' => 'Order in pause', 'ordre' => 51, 'ClassCss' => 'order_paused', 'is_automatic' => 0, 'color' => '#F5A18F', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 0],
    ['Id' => 18, 'steps' => 'proposal', 'Etat' => 'Supplier quotation received', 'ordre' => 3, 'ClassCss' => 'SupplierQotationReceived', 'is_automatic' => 1, 'color' => '#d3edf1', 'ActionText' => null, 'JsHook' => null, 'allow_price_recalculation' => 1],
];
