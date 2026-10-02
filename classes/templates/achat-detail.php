<?php
/**
 * Fiche d'un achat : structure à 3 colonnes (comme la fiche projet) rendue immédiatement avec les informations de base ;
 * les onglets (Articles, Détails, Suivi, Documents) sont chargés en arrière-plan (voir assets/js/details-achat.js).
 */
global $wpdb;
$can_edit  = current_user_can('edit_supplier_order');
$liste_url = trailingslashit(get_site_url()) . 'liste-des-achats/';

// Fournisseur (carte de droite) : une seule petite requête
$supplier_row = !empty($achat->IdFournisseur)
    ? ISPAG_Achat_Supplier_Repository::get_supplier_row((int) $achat->IdFournisseur)
    : null;
$created_by_user = $achat->created_by ? get_userdata((int) $achat->created_by) : null;
$created_by_name = $created_by_user ? $created_by_user->display_name : '—';
$lazy_skeleton = '<div class="ispag-skeleton-wrapper" aria-hidden="true">'
    . '<span class="ispag-skeleton-line ispag-w-40"></span>'
    . '<span class="ispag-skeleton-line ispag-w-90"></span>'
    . '<span class="ispag-skeleton-line ispag-w-80"></span>'
    . '<span class="ispag-skeleton-line ispag-w-60"></span></div>';
?>

<div class="ispag-detail-container ispag-achat-detail">

    <!-- Colonne de gauche : identité et actions -->
    <div class="ispag-left-panel" data-panel="left">
        <div class="ispag-card ispag-header-card ispag-achat-header">
            <div style="display:flex; align-items:baseline; gap:.4rem;">
            <span aria-hidden="true" style="font-size:1.6rem; user-select:none;">🧾</span>
            <h4 id="editable-purchase-title"
                class="ispag-editable-title"
                contenteditable="<?php echo $can_edit ? 'true' : 'false'; ?>"
                spellcheck="false"
                data-source="purchase"
                data-name="RefCommande"
                data-value="<?php echo esc_attr(stripslashes($achat->RefCommande)); ?>"
                data-deal="<?php echo esc_attr($achat->Id); ?>"
                <?php echo $can_edit ? '' : 'data-readonly="true"'; ?>
                style="margin-top:0; font-size:1.6rem; flex:1; min-width:0;">
                <?php echo esc_html(stripslashes($achat->RefCommande)); ?>
            </h4>
            </div>

            <div class="achat-meta" style="display:grid; grid-template-columns:1fr; gap:.75rem; margin-top:1rem;">
                <?php
                $fields = [
                    'Fournisseur'           => __('Supplier', 'creation-reservoir'),
                    'ConfCmdFournisseur'    => __('Order confirmation', 'creation-reservoir'),
                    'TimestampDateCreation' => __('Date', 'creation-reservoir'),
                ];
                foreach ($fields as $field => $label):
                    $value = $achat->$field;
                    $fieldType = 'text';
                    if ($field === 'TimestampDateCreation') {
                        $value = !empty($value) ? date('d.m.Y', $value) : '';
                        $fieldType = 'date';
                    }
                    $supplier_id = '';
                    if ($field === 'Fournisseur') {
                        foreach ($fournisseurs as $f) {
                            if ($f->Fournisseur === $value) { $supplier_id = $f->Id; break; }
                        }
                    }
                    ?>
                    <div>
                        <strong><?php echo esc_html($label); ?></strong><br>
                        <span class="ispag-inline-edit"
                            <?php if ($field === 'Fournisseur'): ?> id="tank-supplier-display" data-is-supplier="true" data-supplier-id="<?php echo esc_attr($supplier_id); ?>" <?php endif; ?>
                            data-source="purchase"
                            data-name="<?php echo esc_attr($field); ?>"
                            data-value="<?php echo esc_attr($value); ?>"
                            data-deal="<?php echo esc_attr($achat->Id); ?>"
                            data-field-type="<?php echo esc_attr($fieldType); ?>"
                            <?php echo $can_edit ? '' : 'data-readonly="true"'; ?>>
                            <?php echo esc_html($value); ?>
                            <?php if ($can_edit): ?>
                                <span class="edit-icon" style="cursor:pointer; margin-left:4px;">✏️</span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>

                <div id="achat-status-wrapper" data-achat-id="<?php echo esc_attr($achat->Id); ?>">
                    <strong>📌 <?php echo esc_html__('Status', 'creation-reservoir'); ?></strong><br>
                    <button id="achat-status-btn" class="ispag-btn <?php echo esc_attr($achat->ClassCss); ?>" style="background:<?php echo esc_attr($achat->color); ?>;">
                        <?php echo esc_html__($achat->Etat, 'creation-reservoir'); ?> ⌄
                    </button>
                    <ul id="achat-status-dropdown" class="ispag_status_dropdown" style="display:none; position:absolute; z-index:999; background:#fff; padding:0.5rem; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.1); list-style:none;"></ul>
                </div>

                <div>
                    <strong>👤 <?php echo esc_html__('Managed by', 'creation-reservoir'); ?></strong><br>
                    <span data-source="purchase" data-name="created_by" data-value="<?php echo esc_attr($achat->created_by); ?>"
                          data-deal="<?php echo esc_attr($achat->Id); ?>" data-field-type="text" data-readonly="true">
                        <?php echo esc_html($created_by_name); ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="ispag-card ispag-project-btn-card">
            <!-- Famille « navigation » -->
            <div class="ispag-btn-group">
                <a href="<?php echo esc_url($achat->project_url); ?>" class="ispag-btn ispag-btn-secondary-outlined"><span class="dashicons dashicons-portfolio"></span> <?php echo esc_html(__('To project', 'creation-reservoir')); ?></a>
                <a href="<?php echo esc_url(add_query_arg('search', $achat->hubspot_deal_id, $liste_url)); ?>" class="ispag-btn ispag-btn-secondary-outlined"><span class="dashicons dashicons-list-view"></span> <?php echo esc_html(__('To purchase list', 'creation-reservoir')); ?></a>
            </div>
            <!-- Autres familles (articles, suppression) : dépendent du statut, rafraîchies par state.js -->
            <div class="ispag-action-buttons-secondary">
                <?php echo ISPAG_Achat_Renderer::footer_buttons_html($achat->Id); ?>
            </div>
        </div>

        <?php
        // Actions groupées sur les articles sélectionnés (comme dans les projets) : masquées tant qu'aucun article n'est coché
        echo apply_filters('ispag_bulk_selected_article', '', $achat->Id);
        ?>
    </div>

    <!-- Contenu principal : onglets chargés en arrière-plan -->
    <div class="ispag-main-content" data-panel="main">
        <div class="ispag-tabs">
            <div class="ispag-tabs-navigation">
                <button type="button" class="ispag-tab-btn active" data-tab="articles"><?php echo __('Articles', 'creation-reservoir'); ?></button>
                <button type="button" class="ispag-tab-btn" data-tab="details"><?php echo __('Details', 'creation-reservoir'); ?></button>
                <button type="button" class="ispag-tab-btn" data-tab="suivis"><?php echo __('Follow up', 'creation-reservoir'); ?></button>
                <button type="button" class="ispag-tab-btn" data-tab="documents"><?php echo __('Document flow', 'creation-reservoir'); ?></button>
            </div>

            <?php foreach (['articles', 'details', 'suivis', 'documents'] as $tab_key): ?>
                <div class="tab-content<?php echo $tab_key === 'articles' ? ' active' : ''; ?>" id="<?php echo esc_attr($tab_key); ?>"
                     data-lazy-tab="<?php echo esc_attr($tab_key); ?>" data-achat-id="<?php echo esc_attr($achat->Id); ?>">
                    <?php echo $lazy_skeleton; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Colonne de droite : fournisseur, contacts, projet -->
    <div class="ispag-right-panel-wrapper" data-panel="right-wrapper">
        <button id="toggle-right-panel" class="ispag-panel-toggle-right" type="button"
                aria-label="<?php esc_attr_e('Display / Mask panel', 'creation-reservoir'); ?>"
                title="<?php esc_attr_e('Mask panel', 'creation-reservoir'); ?>">
            <img src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/img/ios-sidebar-hide.png'); ?>"
                 data-icon-hide="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/img/ios-sidebar-hide.png'); ?>"
                 data-icon-show="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/img/ios-sidebar-display.png'); ?>"
                 alt="" class="ispag-panel-toggle-icon">
        </button>
        <div class="ispag-right-panel" data-panel="right">

            <div class="ispag-card ispag-supplier-card" style="font-size:14px;">
                <h5><?php _e('Supplier', 'creation-reservoir'); ?></h5>
                <?php if ($supplier_row): ?>
                    <p style="margin:5px 0;"><strong><?php echo esc_html($supplier_row->Fournisseur); ?></strong></p>
                    <?php
                    $addr = array_filter([trim((string) $supplier_row->SupplierAdresse), trim(trim((string) $supplier_row->CodePostal) . ' ' . trim((string) $supplier_row->Ville)), trim((string) $supplier_row->Pays)]);
                    if ($addr): ?><p style="margin:5px 0;"><?php echo esc_html(implode(', ', $addr)); ?></p><?php endif; ?>
                    <?php if (!empty(trim((string) $supplier_row->NumTel)) && trim($supplier_row->NumTel) !== '-'): ?>
                        <p style="margin:5px 0;"><?php _e('Phone number', 'creation-reservoir'); ?>: <a href="tel:<?php echo esc_attr($supplier_row->NumTel); ?>"><?php echo esc_html($supplier_row->NumTel); ?></a></p>
                    <?php endif; ?>
                    <?php if (!empty(trim((string) $supplier_row->Mail)) && trim($supplier_row->Mail) !== '-'): ?>
                        <p style="margin:5px 0;"><?php _e('Email', 'creation-reservoir'); ?>: <a href="mailto:<?php echo esc_attr(trim($supplier_row->Mail)); ?>"><?php echo esc_html(trim($supplier_row->Mail)); ?></a></p>
                    <?php endif; ?>
                    <?php if (!empty($supplier_row->compagnyDomain)): ?>
                        <p style="margin:5px 0;"><?php _e('Website', 'creation-reservoir'); ?>: <a href="<?php echo esc_url('https://' . preg_replace('#^https?://#i', '', trim($supplier_row->compagnyDomain))); ?>" target="_blank" rel="noopener"><?php echo esc_html($supplier_row->compagnyDomain); ?></a></p>
                    <?php endif; ?>
                    <p style="margin:5px 0;"><?php _e('Currency', 'creation-reservoir'); ?>: <?php echo esc_html($supplier_row->Monnaie ?: '—'); ?> · <?php _e('Delivery time', 'creation-reservoir'); ?>: <?php echo (int) $supplier_row->deliveryDays; ?> <?php _e('days', 'creation-reservoir'); ?></p>
                <?php else: ?>
                    <p class="ispag-no-company"><?php _e('No supplier selected.', 'creation-reservoir'); ?></p>
                <?php endif; ?>
            </div>

            <?php
            // Contacts du fournisseur (commande/offre, plan, facturation, livraison), modifiables
            echo ISPAG_Achat_Supplier_Contacts::render_card($supplier_row, $can_edit);
            ?>

            <?php
            // Zone de dépôt de documents (déplacée depuis l'onglet Documents)
            echo ISPAG_Achat_Renderer::render_upload_card($achat->Id);
            ?>

            <div class="ispag-card" style="font-size:14px;">
                <h5><?php _e('Project', 'creation-reservoir'); ?></h5>
                <p style="margin:5px 0;"><a href="<?php echo esc_url($achat->project_url); ?>"><?php echo esc_html(__('Open the project', 'creation-reservoir')); ?></a></p>
                <p style="margin:5px 0; color:#666;"><?php _e('Deal ID', 'creation-reservoir'); ?>: <?php echo esc_html($achat->hubspot_deal_id); ?></p>
            </div>
        </div>
    </div>
</div>

<select id="ispag-fournisseurs-source" style="display:none;">
    <?php foreach ($fournisseurs as $f): ?>
        <option value="<?php echo esc_attr($f->Fournisseur); ?>"><?php echo esc_html($f->Fournisseur); ?></option>
    <?php endforeach; ?>
</select>

