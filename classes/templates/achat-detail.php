<?php
// Infos de tête
// echo '<pre>';
// var_dump($achat);
// echo '</pre>';
?>
<?php if(current_user_can('view_supplier_order')): ?>
    <a href="<?= esc_url($achat->project_url) ?>" class="ispag-btn ispag-btn-secondary-outlined"><?= esc_html(__('To project', 'creation-reservoir')) ?></a>
<?php endif; 
$can_edit = current_user_can('edit_supplier_order');
?>
 
<div class="ispag-achat-header">
    <h2 style="margin-top:0; font-size:1.8rem;" class="ispag-inline-edit"
        data-source="purchase"
        data-name="RefCommande"
        data-value="<?php echo esc_attr(stripslashes($achat->RefCommande)); ?>"
        data-deal="<?php echo esc_attr($achat->Id); ?>"
        <?php echo $can_edit ? '' : 'data-readonly="true"'; ?> >
        🧾 <?php echo esc_html(stripslashes($achat->RefCommande)); ?>
        <?php if ($can_edit): ?>
            <span class="edit-icon" style="cursor:pointer; margin-left:4px;">
                <img draggable="false" role="img" class="emoji" alt="✏️"
                    src="https://s.w.org/images/core/emoji/15.1.0/svg/270f.svg"
                    width="14" height="14">
            </span>
        <?php endif; ?>
    </h2>


    <div class="achat-meta" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-top:1rem;">
        <?php
        $fields = [
            'Fournisseur'           => __('Supplier', 'creation-reservoir'),
            'ConfCmdFournisseur'    => __('Order confirmation', 'creation-reservoir'),
            'TimestampDateCreation' => __('Date', 'creation-reservoir')
        ];

        foreach ($fields as $field => $label):
            $value = $achat->$field;
            $fieldType = 'text';
            
            if ($field === 'TimestampDateCreation') {
                $value = !empty($value) ? date('d.m.Y', $value) : '';
                $fieldType = 'date';
            }

            // Récupération de l'ID fournisseur pour le data-attribute
            $supplier_id = '';
            if ($field === 'Fournisseur') {
                // On cherche l'ID dans ton tableau $fournisseurs basé sur le nom ($value)
                foreach ($fournisseurs as $f) {
                    if ($f->Fournisseur === $value) {
                        $supplier_id = $f->Id;
                        break;
                    }
                }
            }
        ?>
        <div>
            <strong><?php echo $label; ?></strong><br>
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
                    <span class="edit-icon" style="cursor:pointer; margin-left:4px;">
                        <img draggable="false" role="img" class="emoji" alt="✏️" src="https://s.w.org/images/core/emoji/15.1.0/svg/270f.svg" width="14" height="14">
                    </span>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
        
        

        <div id="achat-status-wrapper" data-achat-id="<?php echo esc_attr($achat->Id); ?>">
            <strong>📌 <?php echo __('Status', 'creation-reservoir'); ?></strong><br>
            <button id="achat-status-btn" class="ispag-btn <?php echo esc_attr($achat->ClassCss); ?>" style="background:<?php echo esc_attr($achat->color); ?>;">
                <?php echo esc_html__($achat->Etat, 'creation-reservoir'); ?> ⌄
            </button>
            <ul id="achat-status-dropdown" class="ispag_status_dropdown" style="display:none; position:absolute; z-index:999; background:#fff; padding:0.5rem; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.1); list-style:none;">
            </ul>
        </div>
    </div>
</div>

<select id="ispag-fournisseurs-source" style="display:none;">
    <?php foreach ($fournisseurs as $f): ?>
        <option value="<?php echo esc_attr($f->Fournisseur); ?>"><?php echo esc_html($f->Fournisseur); ?></option>
    <?php endforeach; ?>
</select>

<!-- message information -->
<div id="ispag-bulk-message" class="bulk_message"></div>
<div class="ispag-article-header-global" style="margin-bottom: 1rem;">
    <input type="checkbox" id="select-all-articles" class="ispag-article-checkbox">
    <label for="select-all-articles"><?php echo __('Select all', 'creation-reservoir'); ?></label>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('select-all-articles');
    const checkboxes = document.querySelectorAll('.ispag-article-checkbox');

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        });

        checkboxes.forEach(cb => {
            cb.addEventListener('change', function () {
                const allChecked = [...checkboxes].every(c => c.checked);
                selectAll.checked = allChecked;
            });
        });
    }
});
</script>

<!-- Onglets -->
<div class="ispag-tabs">
    <ul class="tab-titles">
        <li class="active" data-tab="articles"><?php echo __('Articles', 'creation-reservoir'); ?></li>
        <li data-tab="details"><?php echo __('Details', 'creation-reservoir'); ?></li>
        <li data-tab="suivis"><?php echo __('Follow up', 'creation-reservoir'); ?></li>
        <li data-tab="documents"><?php echo __('Document flow', 'creation-reservoir'); ?></li>
    </ul>

    <div class="tab-content active" id="articles">
        <?php do_action('ispag_achat_articles_tab', $achat->Id); ?>
    </div>
    
    <div class="tab-content" id="details">
        <?php do_action('ispag_achat_details_tab', $achat->Id); ?>
    </div>
    <div class="tab-content" id="suivis">
        <?php do_action('ispag_display_achat_suivi', $achat->Id); ?>
    </div>
    <div class="tab-content" id="documents">
        <?php apply_filters('ispag_display_doc_manager', $achat->Id, true);  ?>
    </div>
</div>

<script>
document.querySelectorAll('.ispag-achat-tabs li').forEach(tab => {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.ispag-achat-tabs li').forEach(li => li.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
    });
});
</script>
