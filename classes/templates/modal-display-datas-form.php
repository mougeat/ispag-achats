<?php
/**
 * ISPAG Article Purchase Edit Modal View - Modernized V2
 * Alignée sur la version Projet 2.1.8
 */
$user_can_edit = $user_can_edit_order; // Utilisation de ta variable de droit
$can_view_prices = $user_can_view_order; 
?>



<form
    class="ispag-edit-article-form" <?= $id_attr ?>
    id="ispag-edit-article-form"
    data-source="purchase"
    data-purchase-id="<?php echo $article->IdCommande ; ?>"
>
    <input type="hidden" id="current-editing-article-id" value="<?= esc_attr($article->Id) ?>">
    <input type="hidden" name="IdArticleStandard" value="<?= esc_attr($article->IdArticleStandard) ?>">
    <input type="text" name="isProjectOrPurchase" value="purchase">
    
    <div class="ispag-modal-grid">
        <?php if ($is_new): ?>
            <input type="hidden" name="type" value="<?= esc_attr($article->Type) ?>">
        <?php endif; ?>
        
        <div class="ispag-modal-left visual-container" id="modal_img">
            <div class="image-wrapper">
                <?php
                $content = trim($article->image);
                if (strpos($content, '<svg') === 0) {
                    echo $content;
                } else {
                    $src = htmlspecialchars($content, ENT_QUOTES);
                    echo '<img src="' . $src . '" alt="image" class="responsive-svg">';
                }
                ?>
            </div>
        </div>

        <div class="ispag-modal-right" id="ispag-title-description-area">
            <?php if ($article->Type == 1): ?>
                <div id="ispag-tank-form-container">
                    <?php do_action('ispag_render_tank_form', $article->IdCommandeClient); ?>
                </div>
            <?php elseif ($article->Type == 5): ?>
                <div class="ispag-exchanger-form-container">
                    <?php echo apply_filters('ispag_render_plate_heat_exchanger_form', $article->IdCommandeClient); ?>
                </div>
            <?php else: 
                $description = str_ireplace(['<br>', '<br />', '<br/>'], "\n", $article->DescSurMesure);
                $description = stripslashes($description);
            ?>
                <div class="ispag-field">
                    <label><strong><?= __('Title', 'creation-reservoir') ?></strong></label>
                    <input type="text" name="article_title" value="<?= esc_attr(stripslashes($article->RefSurMesure)) ?>" list="standard-titles" id="article-title" data-type="<?= esc_attr($article->Type) ?>" style="width:100%;">
                    <datalist id="standard-titles">
                        <?php foreach ($standard_titles as $title): ?>
                            <option value="<?= esc_attr($title['title']) ?>" data-id="<?= esc_attr($title['id']) ?>">
                        <?php endforeach; ?>
                    </datalist> 
                </div>
                <div class="ispag-field" style="margin-top:15px;">
                    <label><strong><?= __('Description', 'creation-reservoir') ?></strong></label>
                    <textarea name="description" id="article-description" rows="10" style="width:100%;"><?= esc_textarea($description) ?></textarea>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($article->Type == 1): ?>
        <div class="ispag-modal-grid">
            <?php do_action('ispag_render_tank_dimensions_form', $article->IdCommandeClient, 'purchase'); ?>
        </div>
    <?php elseif ($article->Type == 5): ?>
        <div class="ispag-modal-grid">
            <?php do_action('ispag_render_plate_heat_exchanger_form', $article->IdCommandeClient, 'purchase');  ?>
        </div>
    <?php endif; ?>

    <div class="ispag-modal-grid ispag-bloc-common">
        <div class="ispag-modal-left detail-block">
            <h3><span class="dashicons dashicons-calendar-alt"></span> <?= __('Logistics', 'creation-reservoir') ?></h3>
            <?php if ($user_can_edit): ?>
            <div class="ispag-field">
                <label><?= __('Factory departure date', 'creation-reservoir') ?></label>
                <input type="date" name="date_depart" value="<?= $article->TimestampDateLivraisonConfirme ? date('Y-m-d', $article->TimestampDateLivraisonConfirme) : '' ?>" style="width:100%;">
            </div>
            <?php endif; ?>
            <div class="ispag-field">
                <label><?= __('Quantity', 'creation-reservoir') ?></label>
                <input type="number" name="qty" value="<?= esc_attr($article->Qty) ?>" step="any" style="width:100%;">
            </div>
        </div>

        <div class="ispag-modal-right detail-block">
            <h3><span class="dashicons dashicons-cart"></span> <?= __('Pricing', 'creation-reservoir') ?></h3>
            <?php if ($can_view_prices): ?>
                <div class="ispag-field">
                    <label><?= __('Gross unit price', 'creation-reservoir') ?> (€)</label>
                    <input type="text" name="sales_price" value="<?= esc_attr($article->UnitPrice) ?>" style="width:100%;">
                </div>
                <div class="ispag-field">
                    <label><?= __('Discount', 'creation-reservoir') ?> (%)</label>
                    <input type="text" name="discount" value="<?= esc_attr($article->discount) ?>" style="width:100%;">
                </div>
            <?php else: ?>
                <p class="description"><?= __('Pricing details restricted', 'creation-reservoir') ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($user_can_edit): ?>
    <div class="ispag-modal-grid" style="margin-top: 20px;">
        <div class="ispag-modal-left">
            <div class="ispag-status-box-gray" style="background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: var(--ispag-badge-border-radius); padding: 15px;">
                <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 14px; text-transform: uppercase; color: #666; letter-spacing: 0.5px;">
                    <span class="dashicons dashicons-yes" style="font-size: 18px; width: 18px; height: 18px; color: #d63638;"></span> 
                    <?= __('Status Tracking', 'creation-reservoir') ?>
                </h3>
                
                <div class="workflow-checkboxes" style="display: flex; gap: 30px; flex-wrap: wrap; align-items: center;">
                    
                    <label class="ispag-checkbox-label" style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="DrawingApproved" <?= ((int)$article->DrawingApproved === 1) ? 'checked' : '' ?>> 
                        <span><?= __('Drawing approved', 'creation-reservoir') ?></span>
                    </label>

                    <label class="ispag-checkbox-label" style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="Livre" <?= $article->Livre ? 'checked' : '' ?>> 
                        <span><?= __('Delivered', 'creation-reservoir') ?></span>
                    </label>

                    <label class="ispag-checkbox-label" style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="invoiced" <?= $article->Facture ? 'checked' : '' ?>> 
                        <span><?= __('Invoiced', 'creation-reservoir') ?></span>
                    </label>

                </div>
            </div>
        </div>

        <div class="ispag-modal-right detail-block">
            <h3><span class="dashicons dashicons-admin-settings"></span> <?= __('Classification', 'creation-reservoir') ?></h3>
            
                <div class="ispag-field">
                    <label><?= __('Drawing No', 'creation-reservoir') ?></label>
                    <input type="text" name="serial_no" value="<?= esc_attr($article->serial_no) ?>" style="width:100%;">
                </div>

        </div>
    </div>
    <?php endif; ?>

    
</form> 
