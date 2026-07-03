jQuery(document).ready(function($) {
    let currentPage = 1;
    let isLoading = false;
    let hasMore = true;
    let currentFilters = {
        search: '',
        status: 'all',
        fournisseur: 'all',
        responsable: 'all'
    };

    // Fonction pour charger les achats
    function loadAchats(reset = false) {
        if (isLoading) return;

        if (reset) {
            currentPage = 1;
            hasMore = true;
            $('#ispag-achats-list').empty();
        }

        if (!hasMore) return;

        isLoading = true;
        $('#ispag-achats-loading').show();

        // Récupère les filtres actuels
        currentFilters = {
            search: $('#ispag-achats-search').val(),
            status: $('#ispag-achats-status-filter').val(),
            fournisseur: $('#ispag-achats-fournisseur-filter').val(),
            responsable: $('#ispag-achats-responsable-filter').val()
        };

        // Appel AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'filter_achats_custom_tables',
                page: currentPage,
                filters: currentFilters,
                // security: ispagVars.security // ✅ Utilisation de `ispagVars.security`
            },
            success: function(response) {
                if (response.success) {
                    if (reset) {
                        $('#ispag-achats-list').html(response.data.html);
                    } else {
                        $('#ispag-achats-list').append(response.data.html);
                    }
                    currentPage++;
                    hasMore = response.data.has_more;
                } else {
                    console.error('Erreur :', response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('Erreur AJAX :', error);
            },
            complete: function() {
                isLoading = false;
                $('#ispag-achats-loading').hide();
            }
        });
    }

    // Écouteurs d'événements
    $('#ispag-achats-search').on('input', function() {
        debounceLoadAchats();
    });

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
        debounceTimer = setTimeout(() => {
            loadAchats(true);
        }, 500);
    }

    // Infinite scroll
    $(window).scroll(function() {
        if ($(window).scrollTop() + $(window).height() > $(document).height() - 200) {
            loadAchats();
        }
    });

    // Chargement initial
    loadAchats(true);
});