<?php
// echo '<pre>';
// var_dump($article);
// echo '</pre>';
?>
 <div class="ispag-article <?php echo $class_secondary ?? null; ?>" data-article-id="<?php echo $id; ?>" data-level-secondary="<?php echo $class_secondary ?? null; ?>" >

    <!-- Checkbox -->
    <input type="checkbox" class="ispag-article-checkbox" data-article-id="<?php echo $id; ?>" <?php echo $checked_attr; ?> >

    <!-- Image -->
    <div class="ispag-article-image">
        <?php
        $content = trim($article->image);
        $content = str_replace('../../', '', $content); // on enlève les ../../
        if (strpos($content, '<svg') === 0) {
            echo $content;
        } else {
            $src = htmlspecialchars($content, ENT_QUOTES);
            echo '<img src="' . $src . '" alt="image">';
        }
        ?>
    </div>

    <!-- Titre + date de livraison -->
    <div class="ispag-article-header">
        <span class="ispag-article-title"><?php echo esc_html(stripslashes($article->RefSurMesure)); ?></span>
        <div><!-- section soudure -->
        <?php
            echo apply_filters('ispag_get_welding_text', null, $article->Id, false);
        ?>
        </div>
        <div> <!-- section button -->

 
        <!-- Bouton plan -->
        <?php if (!empty($article->last_drawing_url)):

            if($user_can_manage_order OR $user_is_owner){
                $url = $article->last_drawing_url;
                $text = __('Check drawing for validation', 'creation-reservoir');
            } else{
                $url = $article->last_drawing_url;
                $text = __('Drawing', 'creation-reservoir');
            }

            // if (empty($article->DrawingApproved) || $article->DrawingApproved === 0) {
            if($article->last_doc_type['slug'] == 'product_drawing'){
                $badge = '<span class="ispag-badge '. $article->last_doc_type['badge_class'] . '">' . esc_html__('To be approved', 'creation-reservoir') . '</span>';
            } elseif($article->last_doc_type['slug'] == 'drawingApproval'){
                $badge = '<span class="ispag-badge '. $article->last_doc_type['badge_class'] . '">' . esc_html__('Approved', 'creation-reservoir') . '</span>';
            }
            else{
                $badge = '<span class="ispag-badge '. $article->last_doc_type['badge_class'] . '">' . esc_html__($article->last_doc_type['label'], 'creation-reservoir') . '</span>';
            }
            ?>
            <div class="drawing-button-wrapper">      
                <div class="btn-inner-wrapper">       
                    <a href="<?php echo esc_url($url); ?>" target="_blank" class="ispag-btn ispag-btn-secondary-outlined"><?php echo esc_html($text); ?></a>
                </div>
                <?php echo $badge; ?>
            </div>
        <?php endif; ?>

        <?php
        // on liste les document et spreadsheet (si existe)
        if(!empty($article->documents)){
            foreach ($article->documents as $doc) {
                ?>
                <a href="<?php echo esc_url($doc['url']); ?>" target="_blank" class="ispag-btn ispag-btn-grey-outlined"><?php echo esc_html__($doc['label'], 'creation-reservoir'); ?></a>
                <?php           
            }
        }
        ?>
        </div>

        <?php if (!empty($article->TimestampDateLivraisonConfirme) ) {
            $text_delivery = (!empty($article->Recu) && $article->Recu != 0)
                ? esc_html__('Delivered on', 'creation-reservoir')
                : esc_html__('Delivery ETA', 'creation-reservoir');
            ?>
            <div class="ispag-article-date">📦 <?php echo $text_delivery; ?> : <?php echo esc_html($article->date_livraison_conf); ?></div>
        <?php } ?>
    </div>
        

    <!-- Statut & Facturation -->
    <div class="ispag-article-status">
        <div class="ispag-article-livre"><?php echo esc_html($status ?? ''); ?></div>
        <div class="ispag-article-facture"><?php echo esc_html($facture ?? ''); ?></div>
    </div>

    <!-- Quantité, prix, rabais, net -->
    <div class="ispag-article-prices">
        <div class="ispag-article-qty"><?php echo __('Quantity', 'creation-reservoir'); ?> : <?php echo $qty; ?></div>
        <?php if ($user_can_view_order): ?>
            <div class="ispag-article-prix-brut"><?php echo __('Gross unit price', 'creation-reservoir') . ': ' . $article->UnitPrice; ?> €</div>
            <div class="ispag-article-rabais"><?php echo __('Discount', 'creation-reservoir') . ': ' . $article->discount; ?> %</div>
            <div class="ispag-article-prix-net"><?php echo __('Net price', 'creation-reservoir') . ': ' . $prix_net ?? null; ?> €</div>
        <?php endif; ?>
    </div>

    <!-- Boutons d'actions -->
    <div class="ispag-article-actions">
        <button class="ispag-btn ispag-btn-secondary-outlined ispag-btn-view" data-article-id="<?php echo $id; ?>"><?php echo __('See product', 'creation-reservoir'); ?></button>
        <?php
        if (($user_can_generate_tank AND (empty($article->DrawingApproved) || $article->DrawingApproved === 0)) OR current_user_can('manage_order')) { ?>
        <button class="ispag-btn ispag-btn-warning-outlined ispag-btn-edit" data-article-id="<?php echo $id; ?>"><?php echo __('Edit product', 'creation-reservoir'); ?></button>
        <?php } ?>
        <?php
        // echo $user_can_edit_order ? '<button class="ispag-btn ispag-btn-red-outlined ispag-btn-copy" data-article-id="'. $id . '">' . __('Replicate', 'creation-reservoir') . '</button>' : '' ;
        echo $user_can_edit_order ? '<button class="ispag-btn ispag-btn-delete" data-article-id="' . $id . '">' .__('Delete', 'creation-reservoir'). '</button>' : '';
        // echo ((($user_can_generate_tank AND (empty($article->DrawingApproved) || $article->DrawingApproved === 0)) OR current_user_can('manage_order')) AND $article->Type == 1) ? apply_filters('ispag_get_fitting_btn', '', $id) : '';
        ?>
    </div>

</div> <!-- .ispag-article -->
