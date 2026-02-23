<?php
/**
 * ISPAG Purchase Article Modal View - Restructured v2.1.2
 */
$user_can = current_user_can('manage_order'); 
?>

<div class="ispag-modal-header-v2">
    <div class="header-main">
        <div class="title-area">
            <h2><?php echo esc_html(stripslashes($article->RefSurMesure)); ?></h2>
            <span class="ispag-badge-group"><?php echo __('Purchase Item', 'creation-reservoir'); ?></span>
        </div>
        <div class="header-stats">
            <div class="stat-item">
                <span class="stat-label"><?php echo __('Quantity', 'creation-reservoir'); ?></span>
                <span class="stat-value"><?php echo intval($article->Qty) ?></span>
            </div>
            <div class="stat-item price-highlight">
                <span class="stat-label"><?php echo __('Unit Price', 'creation-reservoir'); ?></span>
                <span class="stat-value">
                    <?php echo number_format((float)$article->UnitPrice, 2, '.', ' ') ?> 
                    <small>€</small>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="ispag-modal-body-scroll">

    <div class="ispag-modal-grid">
        <div class="ispag-modal-left visual-container" style="min-height: 200px; background: #eee !important;">
            <div class="image-wrapper">
                <?php
                $img_raw = trim($article->image);
                if (empty($img_raw)) {
                    echo '<span class="dashicons dashicons-format-image" style="font-size:50px; color:#ccc;"></span>';
                } elseif (strpos($img_raw, '<svg') === 0) {
                    echo $img_raw;
                } else {
                    echo '<img src="' . htmlspecialchars($img_raw, ENT_QUOTES) . '" alt="Article" style="display:block; max-width:100%; height:auto; margin:auto;">';
                }
                ?>
            </div>
        </div>

        <div class="ispag-modal-right">
            <div class="description-card">
                <div class="card-header">
                    <h3><?php echo __('Description', 'creation-reservoir'); ?></h3>
                    <button class="ispag-btn-copy-description" id="btnCopyDesc" title="Copier">
                        <img src="https://s.w.org/images/core/emoji/15.1.0/svg/1f4cb.svg" alt="📋" style="width:14px;">
                    </button>
                </div>
                <div id="article-description" class="description-content">
                    <?php echo wp_kses_post(nl2br(stripslashes($article->DescSurMesure))); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="ispag-modal-grid ispag-bloc-common">
        
        <div class="ispag-modal-left detail-block">
            <h3><span class="dashicons dashicons-calendar-alt"></span> <?php echo __('Logistics', 'creation-reservoir'); ?></h3>
            <ul class="info-list">
                <li><strong><?php echo __('Discount', 'creation-reservoir'); ?></strong> <span><?php echo number_format($article->discount, 2) ?> %</span></li>
                <li><strong><?php echo __('Factory departure', 'creation-reservoir'); ?></strong> <span><?php echo ($article->TimestampDateLivraisonConfirme ? date('d.m.Y', $article->TimestampDateLivraisonConfirme) : '-'); ?></span></li>
            </ul>
        </div>

        <?php if ($user_can): ?>
        <div class="ispag-modal-right detail-block">
            <h3><span class="dashicons dashicons-forms"></span> <?php echo __('Purchase Progress', 'creation-reservoir'); ?></h3>
            <ul class="workflow-list">
                <?php 
                $status_steps = [
                    __('Drawing approved', 'creation-reservoir')  => (int)$article->DrawingApproved === 1,
                    __('Received / Delivered', 'creation-reservoir') => (bool)$article->Recu,
                    __('Invoiced', 'creation-reservoir')          => (bool)$article->Facture
                ];
                foreach ($status_steps as $label => $ok): ?>
                    <li class="<?php echo $ok ? 'step-done' : 'step-pending'; ?>">
                        <span class="step-icon"><?php echo $ok ? '✅' : '⚪'; ?></span>
                        <span class="step-label"><?php echo $label; ?></span>
                        <span class="step-badge"><?php echo $ok ? __('Completed', 'creation-reservoir') : __('Pending', 'creation-reservoir'); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
    
</div>

<script>
jQuery(document).ready(function($) {
    $('#btnCopyDesc').off('click').on('click', function(e) {
        e.preventDefault();
        const text = $('#article-description').text().trim();
        const $btn = $(this);
        
        navigator.clipboard.writeText(text).then(() => {
            const oldHtml = $btn.html();
            $btn.html('✅');
            setTimeout(() => $btn.html(oldHtml), 1500);
        });
    });
});
</script>