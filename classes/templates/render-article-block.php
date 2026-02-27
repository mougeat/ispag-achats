<?php
/**
 * ISPAG Purchase Article Item View - Aligned with Project UI
 */
$article_not_invoiced = null;
$user_can_view_order = current_user_can('display_sales_prices'); // Harmonisation du nom de la variable

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
?>

<div class="ispag-article <?php echo $class_secondary; ?> <?php echo $article_not_invoiced; ?> <?php echo $article_not_delivered; ?>" data-article-id="<?php echo $id; ?>">
    
    <div class="ispag-loading-overlay"><div class="ispag-spinner"></div></div>

    <div class="ispag-article-visual-group">
        <input type="checkbox" class="ispag-article-checkbox" data-article-id="<?php echo $id; ?>" <?php echo $checked_attr; ?> >
        <div class="ispag-article-image">
            <?php 
            $content = str_replace('../../', '', trim($article->image));
            if (strpos($content, '<svg') === 0) echo $content; 
            else echo '<img src="' . htmlspecialchars($content, ENT_QUOTES) . '" alt="image">';
            ?>
        </div>
    </div>

    <div class="ispag-article-header">
        <div class="ispag-title-container">
            <span class="ispag-article-title"><?php echo esc_html(stripslashes($article->RefSurMesure)); ?></span>
        </div>
        
        <div class="ispag-article-meta">
            <?php echo apply_filters('ispag_get_welding_text', null, $article->Id, false); ?>
        </div>

        <div class="ispag-article-buttons-row">
            <?php if (!empty($article->last_drawing_url)): 
                $url_plan = $article->last_drawing_url;
                $text_plan = ($user_can_manage_order || $user_is_owner) ? __('Check drawing for validation', 'creation-reservoir') : __('Drawing', 'creation-reservoir');
                
                $badge_class = $article->last_doc_type['badge_class'] ?? 'ispag-badge-secondary';
                $badge_label = ($article->last_doc_type['slug'] == 'product_drawing') ? __('To be approved', 'creation-reservoir') : (($article->last_doc_type['slug'] == 'drawingApproval') ? __('Approved', 'creation-reservoir') : __($article->last_doc_type['label'], 'creation-reservoir'));
            ?>
                <div class="ispag-drawing-wrapper">
                    <a href="<?php echo esc_url($url_plan); ?>" target="_blank" class="ispag-btn ispag-btn-secondary-outlined"><?php echo esc_html($text_plan); ?></a>
                    <span class="ispag-badge <?php echo $badge_class; ?>"><?php echo esc_html($badge_label); ?></span>
                </div>
            <?php endif; ?>
 
            <?php if(!empty($article->documents)): foreach ($article->documents as $doc): ?>
                <a href="<?php echo esc_url($doc['url']); ?>" target="_blank" class="ispag-btn ispag-btn-grey-outlined"><?php echo esc_html__($doc['label'], 'creation-reservoir'); ?></a>
            <?php endforeach; endif; ?>
        </div>

        <div class="ispag-article-dates">
            <?php if (!empty($article->TimestampDateLivraisonConfirme)): ?>
                <span class="date-item">📦 <?php echo ($article->Recu) ? __('Delivered on', 'creation-reservoir') : __('Delivery ETA', 'creation-reservoir'); ?> : <?php echo $article->date_livraison_conf; ?></span>
            <?php endif; ?>
            <?php if ($article->Recu && $user_can_view_order && $article->Facture): ?>
                <span class="date-item">💲 <?php echo __('Invoiced', 'creation-reservoir'); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="ispag-article-prices">
        <div class="ispag-article-qty"><b><?php echo $qty; ?></b> pcs</div>
        <?php if ($user_can_view_order): ?>

            <!-- AFFICHAGE DU DETAILS DE PRIX D'UNE CUVE -->
             <div class="ispag-purchase-details" style="font-size: 0.85em; margin-bottom: 5px; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                <div class="ispag-row">
                    <span>Cuve nue :</span>
                    <input type="text" name="tank-bare-price" id="tank-bare-price-<?php echo $id; ?>"> €
                </div>
                <div class="ispag-row">
                    <span>Accessoires :</span>
                    <input type="text" name="tank-accessories-price" id="tank-acc-price-<?php echo $id; ?>"> €
                </div>
            </div>

            <div class="ispag-article-prix-net" style="color:#00a32a; font-weight:bold;"><?php echo number_format($article->UnitPrice, 2); ?> €</div>
            <div class="ispag-article-rabais" style="font-size:0.8em; color:#888;">-<?php echo $article->discount; ?>%</div>
        <?php endif; ?>
    </div>

    <div class="ispag-article-actions">
        <button class="ispag-btn ispag-btn-secondary-outlined ispag-btn-view" data-article-id="<?php echo $id; ?>" title="<?php echo __('See product', 'creation-reservoir'); ?>"><i class="fas fa-search"></i></button>
        
        <?php if (($user_can_generate_tank && empty($article->DrawingApproved)) || current_user_can('manage_order')): ?>
            <button class="ispag-btn ispag-btn-warning-outlined ispag-btn-edit" data-article-id="<?php echo $id; ?>" title="<?php echo __('Edit product', 'creation-reservoir'); ?>"><i class="fas fa-edit"></i></button>
        <?php endif; ?>

        <?php 
            if ((($user_can_generate_tank && empty($article->DemandeAchatOk)) || $user_can_manage_order) ) {
                echo apply_filters('ispag_get_fitting_btn', '', $article->IdCommandeClient, $article->Id);
                echo $article->btn_heatExchanger;
            }
        ?>  

        <?php if (current_user_can('manage_order')): ?>
            <button class="ispag-btn ispag-btn-delete" data-article-id="<?php echo $id; ?>" title="<?php echo __('Delete', 'creation-reservoir'); ?>"><i class="fas fa-trash"></i></button>
        <?php endif; ?>
    </div>

</div>