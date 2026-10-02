jQuery(document).ready(function($) {
    const COLS = 7;
    const $list = $('#ispag-achats-list');
    if (!$list.length) return;

    let currentPage = 1;
    let isLoading = false;
    let hasMore = true;
    let requestId = 0; // seule la dernière requête a le droit de modifier l'état

    // Socle skeleton du thème (ISPAGLoad). Repli sans skeleton si le thème n'est pas actif.
    const hasSkeleton = typeof window.ISPAGLoad === 'function';

    function readFilters() {
        return {
            search: $('#ispag-achats-search').val(),
            status: $('#ispag-achats-status-filter').val(),
            fournisseur: $('#ispag-achats-fournisseur-filter').val(),
            responsable: $('#ispag-achats-responsable-filter').val()
        };
    }

    function fetchPage(reset, data) {
        if (hasSkeleton) {
            return window.ISPAGLoad($list, {
                action: 'filter_achats_custom_tables',
                data: data,
                mode: reset ? 'replace' : 'append',
                skeleton: window.ISPAGSkeleton.rows(COLS, reset ? 10 : 3)
            });
        }
        return $.ajax({ url: ajaxurl, type: 'POST', data: $.extend({ action: 'filter_achats_custom_tables' }, data) })
            .then(function(response) {
                if (!response.success) return $.Deferred().reject(response.data);
                reset ? $list.html(response.data.html) : $list.append(response.data.html);
                return response.data;
            });
    }

    function loadAchats(reset = false) {
        // Un changement de filtre annule le chargement en cours ; un simple scroll n'empile pas les requêtes
        if (isLoading && !reset) return;
        if (!reset && !hasMore) return;

        if (reset) {
            currentPage = 1;
            hasMore = true;
        }

        const id = ++requestId;
        isLoading = true;

        fetchPage(reset, { page: currentPage, filters: readFilters() })
            .done(function(data) {
                if (id !== requestId) return;
                currentPage++;
                hasMore = !!data.has_more;
            })
            .fail(function(err) {
                if (err === 'abort' || id !== requestId) return; // remplacée par une requête plus récente
                console.error('Error chargement achats :', err);
                if (reset) $list.empty();
            })
            .always(function() {
                if (id === requestId) isLoading = false;
            });
    }

    // Écouteurs d'événements
    $('#ispag-achats-search').on('input', debounceLoadAchats);

    $('#ispag-achats-status-filter, #ispag-achats-fournisseur-filter, #ispag-achats-responsable-filter').on('change', function() {
        loadAchats(true);
    });

    $('#ispag-achats-clear-filters').on('click', function() {
        $('#ispag-achats-search').val('');
        $('#ispag-achats-status-filter, #ispag-achats-fournisseur-filter, #ispag-achats-responsable-filter').val('all');
        loadAchats(true);
    });

    // Debounce
    let debounceTimer;
    function debounceLoadAchats() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => loadAchats(true), 500);
    }

    // Infinite scroll
    $(window).on('scroll', function() {
        if ($(window).scrollTop() + $(window).height() > $(document).height() - 200) {
            loadAchats();
        }
    });

    // Chargement initial (le skeleton serveur est déjà affiché)
    loadAchats(true);
});
