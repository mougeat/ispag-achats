<?php
// Stub pour que Intelephense arrête de râler si Polylang n'est pas chargé

if (!function_exists('pll_set_language')) {
    /**
     * @param string $lang
     * @return void
     */
    function pll_set_language($lang) {
        // Stub : ne rien faire ici
    }
}
