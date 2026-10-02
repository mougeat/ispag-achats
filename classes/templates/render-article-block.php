<?php
/**
 * ISPAG Purchase Article Item View - Aligned with Project UI
 */
$article_not_invoiced = null;
$user_can_view_order = true; // Achats : les prix (et alertes de facturation) sont toujours visibles pour qui accède à la page

// 1. Logique d'alertes (Identique au projet)
// Alerte Facturation : Reçu mais pas encore facturé
if($article->Recu && !$article->Facture){
    $article_not_invoiced = 'ispag-article-not-invoiced';
}

// Alerte Retard Livraison : Pas reçu et date de livraison dépassée
$article_not_delivered = null;
if(!$article->Recu && time() > $article->TimestampDateLivraisonConfirme && $article->TimestampDateLivraisonConfirme != 0){
    $article_not_delivered = 'ispag-article-not-delivered';
}

$class_secondary = ($article->is_secondary ?? false) ? 'ispag-article-secondary' : '';
$currency   = get_option('wpcb_currency', 'CHF');
$line_total = isset($article->total_price) ? (float) $article->total_price : (float) $article->UnitPriceNet * (int) $qty;
?>

<div class="ispag-article ispag-article--row <?php echo $class_secondary; ?> <?php echo $article_not_invoiced; ?> <?php echo $article_not_delivered; ?>" data-article-id="<?php echo $id; ?>">

    <div class="ispag-loading-overlay"><div class="ispag-spinner"></div></div>

    <div class="ispag-article-visual-group">
        <input type="checkbox" class="ispag-article-checkbox" data-article-id="<?php echo $id; ?>" <?php echo $checked_attr; ?> aria-label="<?php esc_attr_e('Select', 'creation-reservoir'); ?>">
        <div class="ispag-article-image">
            <?php echo ISPAG_Achat_Article_Repository::image_html($article->image); ?>
        </div>
    </div>

    <div class="ispag-article-header">
        <div class="ispag-title-container">
            <span class="ispag-article-title"><?php echo esc_html(stripslashes($article->RefSurMesure)); ?></span>
        </div>

        <div class="ispag-article-meta">
            <?php echo apply_filters('ispag_get_welding_text', null, $article->Id, false); ?>
        </div>

        <div class="ispag-article-chips">
            <?php if (!empty($article->IdArticleStandard) && class_exists('ISPAG_Standard_Articles_Pages') && ISPAG_Standard_Articles_Pages::can_view()): ?>
                <a href="<?php echo esc_url(ISPAG_Standard_Article_Service::article_url((int) $article->IdArticleStandard)); ?>" target="_blank" rel="noopener" class="ispag-chip ispag-chip--info ispag-chip--link ispag-std-link-btn" title="<?php echo esc_attr__('Standard article', 'creation-reservoir'); ?>">📦 <?php esc_html_e('Standard', 'creation-reservoir'); ?></a>
            <?php endif; ?>

            <?php if ($article->Recu): ?>
                <span class="ispag-chip ispag-chip--ok">✔ <?php esc_html_e('Delivered', 'creation-reservoir'); ?></span>
            <?php elseif ($article_not_delivered): ?>
                <span class="ispag-chip ispag-chip--late">⚠ <?php esc_html_e('Late', 'creation-reservoir'); ?></span>
            <?php endif; ?>

            <?php if ($article->Recu && $user_can_view_order && $article->Facture): ?>
                <span class="ispag-chip ispag-chip--ok">💲 <?php esc_html_e('Invoiced', 'creation-reservoir'); ?></span>
            <?php elseif ($article_not_invoiced && $user_can_view_order): ?>
                <span class="ispag-chip ispag-chip--warn">💲 <?php esc_html_e('Not invoiced', 'creation-reservoir'); ?></span>
            <?php endif; ?>

            <?php if (!empty($article->last_drawing_url)):
                $url_plan   = $article->last_drawing_url;
                $slug       = $article->last_doc_type['slug'] ?? '';
                $badge_label = ($slug == 'product_drawing') ? __('Drawing to be approved', 'creation-reservoir') : (($slug == 'drawingApproval') ? __('Drawing approved', 'creation-reservoir') : __($article->last_doc_type['label'] ?? 'Drawing', 'creation-reservoir'));
                $chip_class = ($slug == 'drawingApproval') ? 'ispag-chip--ok' : (($slug == 'product_drawing') ? 'ispag-chip--warn' : 'ispag-chip--info');
                $text_plan  = ($user_can_manage_order || $user_is_owner) ? __('Check drawing for validation', 'creation-reservoir') : __('Drawing', 'creation-reservoir');
            ?>
                <a href="<?php echo esc_url($url_plan); ?>" target="_blank" class="ispag-chip <?php echo $chip_class; ?> ispag-chip--link" title="<?php echo esc_attr($text_plan); ?>">📐 <?php echo esc_html($badge_label); ?></a>
            <?php endif; ?>

            <?php if (!empty($article->documents)): foreach ($article->documents as $doc): ?>
                <a href="<?php echo esc_url($doc['url']); ?>" target="_blank" class="ispag-chip ispag-chip--info ispag-chip--link">📄 <?php echo esc_html__($doc['label'], 'creation-reservoir'); ?></a>
            <?php endforeach; endif; ?>

            <?php if (!empty($article->TimestampDateLivraisonConfirme)): ?>
                <span class="ispag-chip ispag-chip--date" title="<?php echo esc_attr($article->Recu ? __('Delivered on', 'creation-reservoir') : __('Delivery ETA', 'creation-reservoir')); ?>">📦 <?php echo esc_html($article->date_livraison_conf); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="ispag-article-prices">
        <div class="ispag-article-qty"><b><?php echo $qty; ?></b> <?php esc_html_e('pcs', 'creation-reservoir'); ?></div>
        <?php if ($user_can_view_order): ?>
            <div class="ispag-article-unit">× <span class="ispag-article-prix-net" id="ispag_article_net_price_<?php echo $id; ?>"><?php echo number_format($article->UnitPriceNet, 2); ?></span>
                <?php if (!empty($article->discount) && (float) $article->discount > 0): ?>
                    <span class="ispag-article-rabais" id="ispag_article_discount_<?php echo $id; ?>">−<?php echo esc_html($article->discount); ?>%</span>
                <?php endif; ?>
            </div>
            <div class="ispag-article-total"><?php echo number_format($line_total, 2); ?> <small><?php echo esc_html($currency); ?></small></div>
        <?php endif; ?>
    </div>

    <div class="ispag-article-actions">
        <button type="button"
            class="ispag-btn ispag-btn-secondary-outlined ispag-btn-view"
            data-article-id="<?php echo $id; ?>"
            data-source="purchase"
            title="<?php echo esc_attr__('See product', 'creation-reservoir'); ?>">
            <i class="fas fa-search"></i>
        </button>

        <?php if (($user_can_generate_tank && empty($article->DrawingApproved)) || current_user_can('manage_order')): ?>
            <button type="button"
                class="ispag-btn ispag-btn-warning-outlined ispag-btn-edit"
                data-article-id="<?php echo $id; ?>"
                data-source="purchase"
                title="<?php echo esc_attr__('Edit product', 'creation-reservoir'); ?>">
                <i class="fas fa-edit"></i>
            </button>
        <?php endif; ?>

        <?php
        $fitting_html = '';
        if (($user_can_generate_tank && empty($article->DemandeAchatOk)) || $user_can_manage_order) {
            $fitting_html = apply_filters('ispag_get_fitting_btn', '', $article->IdCommandeClient, $article->Id);
        }

        $upload_dir = wp_upload_dir();
        $calc_path  = $upload_dir['basedir'] . '/ispag_pricing/article_' . $article->IdCommandeClient . '_purchase.txt';
        $calc_url   = $upload_dir['baseurl'] . '/ispag_pricing/article_' . $article->IdCommandeClient . '_purchase.txt';
        $has_calc   = file_exists($calc_path);
        $can_delete = current_user_can('manage_order');
        ?>
        <?php if ($fitting_html || $has_calc || $can_delete): ?>
            <div class="ispag-more">
                <button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-more-toggle" aria-haspopup="true" aria-expanded="false" title="<?php echo esc_attr__('More actions', 'creation-reservoir'); ?>">
                    <i class="fas fa-ellipsis-h"></i>
                </button>
                <div class="ispag-more-menu" role="menu">
                    <?php echo $fitting_html; ?>
                    <?php if ($has_calc): ?>
                        <a href="<?php echo esc_url($calc_url); ?>" class="ispag-more-item" target="_blank" role="menuitem"><?php esc_html_e('Display price calculation', 'creation-reservoir'); ?></a>
                    <?php endif; ?>
                    <?php if ($can_delete): ?>
                        <button type="button" class="ispag-more-item ispag-btn-delete" data-article-id="<?php echo $id; ?>" data-source="purchase" role="menuitem"><i class="fas fa-trash"></i> <?php esc_html_e('Delete', 'creation-reservoir'); ?></button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>
