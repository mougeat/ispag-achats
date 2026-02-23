document.addEventListener('DOMContentLoaded', function () {
    let offset = 0;
    const limit = 20;
    let loading = false;
    let hasMore = true;

    const loader = document.getElementById('scroll-loader');
    const listContainer = document.getElementById('achats-list');

     // On ne lance rien si les éléments ne sont pas là
    if (!loader || !listContainer) return;
    
    loader.innerHTML = '<div class="loading-spinner" style="text-align:center;">' + ispagVars.loading_text + '...</div>';


    // Déclenchement au scroll
    window.addEventListener('scroll', handleScroll);

    // Chargement initial
    loadPurchases();

    // Pré-chargement si page trop courte
    window.addEventListener('load', () => {
        if (loader.getBoundingClientRect().top < window.innerHeight) {
            loadPurchases();
        }
    });    
    
    function updateLinkTargets() {
        const links = listContainer.querySelectorAll('.ispag_achat_link');
        if (links.length === 1) {
            links[0].removeAttribute('target');
        } else {
            links.forEach(link => {
                if (!link.hasAttribute('target')) {
                    link.setAttribute('target', '_blank');
                }
            });
        }
    }
    
    function loadPurchases() {
        if (loading || !hasMore) return;
        loading = true;

        const search = new URLSearchParams(window.location.search).get('search') || '';
        const select_state = new URLSearchParams(window.location.search).get('select_state') || '';
        // const qotation = new URLSearchParams(window.location.search).get('qotation') === '1' ? '1' : '0';


        // const meta = document.getElementById('projets-meta');
        // const qotation = meta ? meta.dataset.qotation : '0';
        // const search = meta ? meta.dataset.search : '';
        
        const formData = new FormData();
        formData.append('action', 'ispag_load_more_achats');
        formData.append('offset', offset);
        formData.append('search', search);
        formData.append('select_state', select_state);

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                listContainer.insertAdjacentHTML('beforeend', data.data.html);
                offset += limit;
                hasMore = data.data.has_more;
                updateLinkTargets();
                if (!hasMore) {
                    loader.innerHTML = '<p style="text-align:center; color:#777;">' + ispagVars.all_loaded_text + '.</p>';
                }
            }
        })
        .finally(() => loading = false);
    }



    function handleScroll() {
        const loaderTop = loader.getBoundingClientRect().top;
        const windowBottom = window.innerHeight;

        if (loaderTop - windowBottom < 100) {
            loadPurchases();
        }
    }
});

