<h2>
  <?= $is_new ? __('New article', 'creation-reservoir') : __('Edit article', 'creation-reservoir') . ' : ' . esc_html(stripslashes($article->RefSurMesure)) ?>
</h2>

<form class="ispag-edit-article-form" <?= $id_attr ?>>
  <input type="text" name="IdArticleStandard" value="<?= esc_attr($article->IdArticleStandard) ?>">
  <div class="ispag-modal-grid">
    <?php if ($is_new): ?>
      <input type="text" name="type" value="<?= esc_attr($article->Type) ?>">
      
    <?php endif; ?>
      
    <div class="ispag-modal-left" id="modal_img">
      <?php
      $content = trim($article->image);
      if (strpos($content, '<svg') === 0) {
        echo $content;
      } else {
        $src = htmlspecialchars($content, ENT_QUOTES);
        echo '<img src="' . $src . '" alt="image">';
      }
      ?>
    </div>

    <div class="ispag-modal-right" id="ispag-title-description-area">
      <?php if ($article->Type == 1): ?>
        <div id="ispag-tank-form-container">
          <?php do_action('ispag_render_tank_form', $article->IdCommandeClient); ?>
        </div> 
      <?php else:
        $description = str_ireplace(['<br>', '<br />', '<br/>'], "\n", $article->DescSurMesure);
        $description = stripslashes($description);
      ?>
        <label>
          <?= __('Title', 'creation-reservoir') ?><br>
          <input type="text" name="article_title" value="<?= esc_attr(stripslashes($article->RefSurMesure)) ?>" list="standard-titles" id="article-title" data-type="<?= esc_attr($article->Type) ?>">
          <datalist id="standard-titles">
            <?php foreach ($standard_titles as $title): ?>
              <option value="<?= esc_attr($title['title']) ?>" data-id="<?= esc_attr($title['id']) ?>">
            <?php endforeach; ?>
          </datalist>
        </label>
        <br>
        <label>
          <?= __('Description', 'creation-reservoir') ?><br>
          <textarea name="description" id="article-description" cols="40" rows="15"><?= esc_textarea($description) ?></textarea>
        </label>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($article->Type == 1): ?>
    <div class="ispag-modal-grid">
      <?php do_action('ispag_render_tank_dimensions_form', $article->IdCommandeClient); ?>
    </div>
  <?php endif; ?>

  <div class="ispag-modal-grid ispag-bloc-common">
    <?php if ($user_can_edit_order): ?>
    <div class="ispag-modal-left">
      <div class="ispag-modal-meta">
        
        <label>
          <?= __('Factory departure date', 'creation-reservoir') ?><br>
          <input type="date" name="date_depart" value="<?= $article->TimestampDateLivraisonConfirme ? date('Y-m-d', $article->TimestampDateLivraisonConfirme) : '' ?>">
        </label>

      </div>
    </div>
    <?php endif; ?>

    <div class="ispag-modal-right">
      <div class="ispag-field">
        <label>
          <?= __('Quantity', 'creation-reservoir') ?><br>
          <input type="number" name="qty" value="<?= esc_attr($article->Qty) ?>">
        </label>
      </div>
      <?php if ($user_can_edit_order): ?>
      <div class="ispag-field">
        <label>
          <?= __('Gross unit price', 'creation-reservoir') ?><br>
          <input type="text" name="sales_price" value="<?= esc_attr($article->UnitPrice) ?>"> €
        </label>
      </div>
      <div class="ispag-field">
        <label>
          <?= __('Discount', 'creation-reservoir') ?><br>
          <input type="text" name="discount" value="<?= esc_attr($article->discount) ?>"> %
        </label>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($user_can_edit_order): ?>
    <div class="ispag-modal-status">
      <label><input type="checkbox" name="DrawingApproved" <?= ((int)$article->DrawingApproved === 1) ? 'checked' : '' ?>> <?= __('Drawing approved', 'creation-reservoir') ?></label><br>
      <label><input type="checkbox" name="Livre" <?= $article->Livre ? 'checked' : '' ?>> <?= __('Delivered', 'creation-reservoir') ?></label><br>
      <label><input type="checkbox" name="invoiced" <?= $article->Facture ? 'checked' : '' ?>> <?= __('Invoiced', 'creation-reservoir') ?></label><br>
    </div>
  <?php endif; ?>

  <div class="ispag-modal-actions">
    <button type="submit" class="ispag-btn ispag-btn-red-outlined"><span class="dashicons dashicons-media-archive"></span> <?= __('Save', 'creation-reservoir') ?></button>
    <button type="button" class="ispag-btn ispag-btn-secondary-outlined" onclick="closeIspagModal()"><?= __('Cancel', 'creation-reservoir') ?></button>
  </div>
</form> 
