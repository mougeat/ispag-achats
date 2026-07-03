<?php
/**
 * Template pour les champs de recherche/filtrage des achats
 * Basé sur les tables personnalisées : wor9711_achats_fournisseurs et wor9711_achats_commande_liste_fournisseurs
 */
global $wpdb;

// Récupère les statuts traduits depuis la table achats_etat_commandes_fournisseur
$statuses = $wpdb->get_results(
    "SELECT Id, Etat FROM {$wpdb->prefix}achats_etat_commandes_fournisseur ORDER BY ordre ASC"
);

// Récupère les fournisseurs depuis la table fournisseurs
$fournisseurs = $wpdb->get_results(
    "SELECT Id, Fournisseur FROM {$wpdb->prefix}achats_fournisseurs ORDER BY Fournisseur ASC"
);

// ✅ Récupère uniquement les utilisateurs ayant la capacité "edit_supplier_order"
// Récupère tous les utilisateurs
$all_users = get_users([
    'orderby' => 'display_name',
    'order' => 'ASC',
]);

// Filtre les utilisateurs pour ne garder que ceux ayant la capacité "edit_supplier_order"
$responsables = array_filter($all_users, function($user) {
    return user_can($user->ID, 'edit_supplier_order');
});
?>

<div class="ispag-toolbar" style="background: #f6f7f7; padding: 15px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
    <!-- Champ de recherche -->
    <input type="text"
           id="ispag-achats-search"
           placeholder="Rechercher par référence, numéro de commande, ou deal HubSpot..."
           class="ispag-search-field"
           value="<?php echo esc_attr($filters['search'] ?? ''); ?>">

    <!-- Filtre par statut -->
    <span class="ispag-kanban-filter-wrapper">
        <select id="ispag-achats-status-filter">
            <option value="all" <?php selected($filters['status'] ?? 'all', 'all'); ?>>Tous les statuts</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?php echo esc_attr($status->Id); ?>"
                    <?php selected($filters['status'] ?? '', $status->Id); ?>>
                    <?php echo esc_html(__($status->Etat, 'creation-reservoir')); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </span>

    <!-- Filtre par fournisseur -->
    <span class="ispag-kanban-filter-wrapper">
        <select id="ispag-achats-fournisseur-filter">
            <option value="all" <?php selected($filters['fournisseur'] ?? 'all', 'all'); ?>>Tous les fournisseurs</option>
            <?php foreach ($fournisseurs as $fournisseur): ?>
                <option value="<?php echo esc_attr($fournisseur->Id); ?>"
                    <?php selected($filters['fournisseur'] ?? '', $fournisseur->Id); ?>>
                    <?php echo esc_html($fournisseur->Fournisseur); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </span>

    <!-- Filtre par responsable -->
    <span class="ispag-kanban-filter-wrapper">
        <select id="ispag-achats-responsable-filter">
            <option value="all" <?php selected($filters['responsable'] ?? 'all', 'all'); ?>>Tous les responsables</option>
            <?php foreach ($responsables as $responsable): ?>
                <option value="<?php echo esc_attr($responsable->ID); ?>"
                    <?php selected($filters['responsable'] ?? '', $responsable->ID); ?>>
                    <?php echo esc_html($responsable->display_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </span>

    <!-- Bouton pour réinitialiser les filtres -->
    <button id="ispag-achats-clear-filters" class="ispag-btn ispag-btn-secondary-outlined">
        Réinitialiser
    </button>
</div>