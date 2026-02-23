        <?php $user_can = current_user_can('manage_order'); ?>
        <h2>
        <?php 
            echo esc_html(stripslashes($article->RefSurMesure));        
        ?>
        </h2>
        
        <div class="ispag-modal-grid">

            <!--  Gauche : image + description -->
            <div class="ispag-modal-left" id="modal_img">
                <?php
                $content = trim($article->image);
                if (strpos($content, '<svg') === 0) {
                    // C’est un SVG brut, on l'affiche directement
                    echo $content;
                } else {
                    // Sinon, on considère que c'est une URL ou un chemin vers une image
                    $src = htmlspecialchars($content, ENT_QUOTES);
                    echo '<img src="' . $src . '" alt="image">';
                }
                ?>
            
            
                
            </div>

            <!-- Droite : données clés -->
            <div class="ispag-modal-right">
                <p id="article-description">
                <?php 
                    // echo wp_kses_post(nl2br(stripslashes($article->DescSurMesure)));

                ?>
                </p>

                <button class="ispag-btn-copy-description" data-target="#article-description"><img draggable="false" role="img" class="emoji" alt="📋" src="https://s.w.org/images/core/emoji/15.1.0/svg/1f4cb.svg"></button>

                <script>
                // document.addEventListener('click', (e) => {
                //     const btn = e.target.closest('.ispag-btn-copy-description');
                //     if (btn) {
                //         const targetSelector = btn.getAttribute('data-copy-target');
                //         const text = document.querySelector(targetSelector)?.innerText;
                //         if (text) {
                //             navigator.clipboard.writeText(text).then(() => {
                //                 btn.innerText = '✅';
                //                 setTimeout(() => btn.innerText = '📋', 1000);
                //             });
                //         }
                //     }
                // });
                </script>




                
            </div>

        </div> <!-- ispag-modal-grid -->

        <div class="ispag-modal-grid ispag-bloc-common">
            <!-- Gauche : dates et fournisseur -->
            <div class="ispag-modal-left">
                <div class="ispag-modal-meta">
                    <p><strong><?php echo __('Factory departure date', 'creation-reservoir'); ?>:</strong> <?php echo ($article->TimestampDateLivraisonConfirme ? date('d.m.Y', $article->TimestampDateLivraisonConfirme) : '-'); ?></p>
                </div>
            </div>
            <div class="ispag-modal-right">
                <p><strong><?php echo __('Quantity', 'creation-reservoir'); ?>:</strong> <?php echo intval($article->Qty) ?></p>
                <p><strong><?php echo __('Gross unit price', 'creation-reservoir'); ?>:</strong> <?php echo number_format($article->UnitPrice, 2) ?> €</p>
                <p><strong><?php echo __('Discount', 'creation-reservoir'); ?>:</strong> <?php echo number_format($article->discount, 2) ?> %</p>
            </div>
            
        </div> <!-- .ispag-modal-grid -->
        <!-- Droite : statuts -->
        <?php if ($user_can) { ?>
            
            <div class="ispag-modal-status">
                <p><?php echo __('Drawing approved', 'creation-reservoir'); ?>: <?php echo ((int)$article->DrawingApproved === 1 ? '✅' : '❌'); ?></p>
                <p><?php echo __('Delivered', 'creation-reservoir'); ?>: <?php echo ($article->Recu ? '✅' : '❌'); ?></p>
                <p><?php echo __('Invoiced', 'creation-reservoir'); ?>: <?php echo ($article->Facture ? '✅' : '❌'); ?></p>
            </div>
            <?php
        }
        ?>