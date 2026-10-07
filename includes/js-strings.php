<?php
defined('ABSPATH') || exit;

/**
 * Textes affichés par le JavaScript de ce paquet (messages d'erreur, confirmations, libellés).
 * Le JavaScript les appelle avec ispagT('Texte anglais') ; ce fichier fournit leur traduction dans la langue du site
 * (fichiers languages/ de ce paquet). Un texte absent de la liste reste tel quel (anglais).
 * Après avoir ajouté un texte dans un fichier JS, ajoutez-le ici puis lancez tools/i18n/build.py (ISPAG Project Manager).
 */
if (!function_exists('ispag_achats_js_strings')) {
    function ispag_achats_js_strings() {
        return [
        'Delete the %d selected articles? This cannot be undone.' => __('Delete the %d selected articles? This cannot be undone.', 'creation-reservoir'),
        'Error' => __('Error', 'creation-reservoir'),
        'Error: ' => __('Error: ', 'creation-reservoir'),
        'Error while deleting' => __('Error while deleting', 'creation-reservoir'),
        'Network error' => __('Network error', 'creation-reservoir'),
        'No article selected' => __('No article selected', 'creation-reservoir'),
        'No contact found for this supplier.' => __('No contact found for this supplier.', 'creation-reservoir'),
        'Retry' => __('Retry', 'creation-reservoir'),
        'Save' => __('Save', 'creation-reservoir'),
        'Save failed.' => __('Save failed.', 'creation-reservoir'),
        'The email draft could not be created. Please try again.' => __('The email draft could not be created. Please try again.', 'creation-reservoir'),
        '❌ An unknown network or server error occurred. Please check logs.' => __('❌ An unknown network or server error occurred. Please check logs.', 'creation-reservoir'),
        '❌ Network error' => __('❌ Network error', 'creation-reservoir'),
        'Compare the quote with the order' => __('Compare the quote with the order', 'creation-reservoir'),
        'No tank was found in the document.' => __('No tank was found in the document.', 'creation-reservoir'),
        'Close' => __('Close', 'creation-reservoir'),
        'This order has no tank to compare with.' => __('This order has no tank to compare with.', 'creation-reservoir'),
        'Tank' => __('Tank', 'creation-reservoir'),
        'Tank in the quote' => __('Tank in the quote', 'creation-reservoir'),
        'Tank of the order' => __('Tank of the order', 'creation-reservoir'),
        'Field' => __('Field', 'creation-reservoir'),
        'In the order' => __('In the order', 'creation-reservoir'),
        'In the quote' => __('In the quote', 'creation-reservoir'),
        'Net unit price' => __('Net unit price', 'creation-reservoir'),
        'Cancel' => __('Cancel', 'creation-reservoir'),
        'Import the selection' => __('Import the selection', 'creation-reservoir'),
        'Select at least one line to import.' => __('Select at least one line to import.', 'creation-reservoir'),
        'Saving…' => __('Saving…', 'creation-reservoir'),
        'Network error.' => __('Network error.', 'creation-reservoir'),
        'Fittings' => __('Fittings', 'creation-reservoir'),
        'No fitting found in the quote for this tank.' => __('No fitting found in the quote for this tank.', 'creation-reservoir'),
        'Add to the order' => __('Add to the order', 'creation-reservoir'),
        'Already in the order' => __('Already in the order', 'creation-reservoir'),
        'Not in the order' => __('Not in the order', 'creation-reservoir'),
        'Quantity to add' => __('Quantity to add', 'creation-reservoir'),
        'Choose the diameter of each fitting to add.' => __('Choose the diameter of each fitting to add.', 'creation-reservoir'),
        '-- Ø --' => __('-- Ø --', 'creation-reservoir'),
        '-- Accessories --' => __('-- Accessories --', 'creation-reservoir'),
        ];
    }

    /** Dictionnaire {texte anglais → texte traduit} injecté dans la page ; seuls les textes réellement traduits sont envoyés. */
    function ispag_achats_print_js_i18n() {
        $map = array_filter(ispag_achats_js_strings(), function ($translated, $english) { return $translated !== $english; }, ARRAY_FILTER_USE_BOTH);
        echo '<script>window.ISPAG_JS_I18N=Object.assign(window.ISPAG_JS_I18N||{},' . wp_json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ');'
            . 'window.ispagT=function(s){var d=window.ISPAG_JS_I18N||{};return Object.prototype.hasOwnProperty.call(d,s)?d[s]:s};</script>' . "\n";
    }
    add_action('wp_head', 'ispag_achats_print_js_i18n', 1);
    add_action('admin_head', 'ispag_achats_print_js_i18n', 1);
}
