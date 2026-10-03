<?php
defined('ABSPATH') || exit;

/**
 * Classe ISPAG_Achat_status_render
 * 
 * Gère l'affichage des étapes de suivi pour les commandes fournisseurs.
 * Inspiré de la structure de display_ispag_suivis() pour les projets,
 * mais adapté aux achats (sans Brevo_id, avec gestion des étapes automatiques).
 */
class ISPAG_Achat_status_render {
    
    /** @var wpdb Instance de la base de données WordPress */
    private $wpdb;
    
    /** @var string Nom de la table d'historique des documents */
    private $table_historique;
    
    /** @var string Nom de la table des états des commandes */
    private $table_etat_commande;
    
    /** @var ISPAG_Achat_status_render Instance unique (Singleton) */
    protected static $instance = null;

    // ─────────────────────────────────────────────────────────────────────────
    // INITIALISATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Initialise l'instance et les hooks.
     */
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        add_action('ispag_display_achat_suivi', [self::$instance, 'display_achat_suivi'], 10, 1);
    }

    /**
     * Constructeur : initialise les propriétés.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_historique = $wpdb->prefix . 'achats_historique';
        $this->table_etat_commande = $wpdb->prefix . 'achats_etat_commandes_fournisseur';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RÉCUPÉRATION DES DONNÉES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Récupère la liste complète des états des commandes fournisseurs.
     * 
     * @return array Tableau des états (id, label, ordre, class, color, is_automatic).
     */
    public function get_all_state_liste() {
        $results = $this->wpdb->get_results("
            SELECT Id, Etat, ordre, ClassCss, color, is_automatic
            FROM {$this->table_etat_commande}
            ORDER BY ordre ASC
        ");

        $states = [];
        foreach ($results as $row) {
            $states[] = [
                'id'            => (int) $row->Id,
                'label'         => $row->Etat,
                'ordre'         => (int) $row->ordre,
                'class'         => $row->ClassCss,
                'color'         => $row->color,
                'is_automatic'  => isset($row->is_automatic) ? (int) $row->is_automatic : 0,
            ];
        }
        return $states;
    }

    /**
     * Récupère le dernier statut enregistré pour chaque étape d'une commande.
     * 
     * @param int $achat_id ID de la commande fournisseur.
     * @return array Tableau associatif (clé = slug_phase, valeur = objet suivi).
     */
    public static function get_last_statuses_by_slug($achat_id) {
        global $wpdb;

        if (!$achat_id) return [];

        $table = $wpdb->prefix . 'achats_suivi_phase_commande';

        $results = $wpdb->get_results(
            $wpdb->prepare("
                SELECT s1.*
                FROM $table s1
                INNER JOIN (
                    SELECT slug_phase, MAX(date_modification) as max_date
                    FROM $table
                    WHERE purchase_id = %d
                    GROUP BY slug_phase
                ) s2 ON s1.slug_phase = s2.slug_phase AND s1.date_modification = s2.max_date
                WHERE s1.purchase_id = %d
            ", $achat_id, $achat_id),
            OBJECT
        );

        $by_slug = [];
        foreach ($results as $row) {
            $by_slug[$row->slug_phase] = $row;
        }
        return $by_slug;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AFFICHAGE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Affiche les boutons de sélection des états (pour filtrage).
     * 
     * @param int|null $selected ID de l'état sélectionné.
     */
    public function render_state_buttons($selected = null) {
        $states = $this->get_all_state_liste();
        $current_url = remove_query_arg('select_state');

        echo '<div class="ispag-state-buttons" style="display:flex; flex-wrap:wrap; gap:8px;">';

        foreach ($states as $state) {
            $is_selected = ($selected == $state['id']);
            $url = esc_url(add_query_arg('select_state', $state['id'], $current_url));

            echo '<a href="' . $url . '" class="ispag-btn ispag-state-badge ' . esc_attr($state['class']);
            if ($is_selected) echo ' ispag-btn-active';
            echo '" style="background-color:' . esc_attr($state['color']) . '; text-decoration:none;">';
            echo esc_html__($state['label'], 'creation-reservoir');
            echo '</a> ';
        }

        echo '</div>';
    }

    /**
     * Affiche le suivi des étapes d'une commande fournisseur avec la même présentation que l'onglet « Follow up »
     * des projets (ISPAG_Project_Phase_Display::render_internal_tab) : une colonne par famille d'étapes
     * (demande d'offre, commande, autres), compteur + barre de progression, une ligne par étape avec sa date.
     * Les styles viennent de la feuille « ispag-phase-tracker » d'ISPAG Project Manager (#ispag-phase-tracker).
     *
     * Une étape est « Done » dès qu'elle figure dans l'historique de la commande (achats_suivi_phase_commande).
     *
     * @param int $achat_id ID de la commande fournisseur.
     */
    public static function display_achat_suivi($achat_id) {
        global $wpdb;

        wp_enqueue_style('ispag-phase-tracker');

        // 1. Étapes disponibles (triées par ordre)
        $etapes = $wpdb->get_results("
            SELECT Id, steps, Etat, ClassCss, color, is_automatic
            FROM {$wpdb->prefix}achats_etat_commandes_fournisseur
            ORDER BY ordre ASC
        ");

        if (!$etapes) {
            echo '<div class="ispag-notice"><p>' . esc_html__('No steps defined.', 'creation-reservoir') . '</p></div>';
            return;
        }

        // 2. Statuts enregistrés (dernier par étape)
        $suivis = self::get_last_statuses_by_slug($achat_id);

        // 3. Familles : une colonne par groupe d'étapes (colonne « steps »), dans l'ordre d'apparition
        $family_labels = [
            'proposal' => __('Request for quotation', 'creation-reservoir'),
            'purchase' => __('Order', 'creation-reservoir'),
            ''         => __('Other', 'creation-reservoir'),
        ];
        $family_colors = ['proposal' => '#007DB4', 'purchase' => '#00C875', '' => '#9ca3af'];

        $families = [];
        foreach ($etapes as $etape) {
            $key = trim((string) $etape->steps);
            if (!isset($families[$key])) {
                $families[$key] = ['rows' => [], 'done' => 0];
            }
            $suivi = $suivis[$etape->ClassCss] ?? null;
            $families[$key]['rows'][] = ['etape' => $etape, 'suivi' => $suivi];
            if ($suivi) {
                $families[$key]['done']++;
            }
        }

        $done_color    = '#00C875';
        $pending_color = '#E47085';

        ?>
        <div id="ispag-phase-tracker" class="ispag-phase-tracker ispag-phase-tracker--internal ispag-achat-suivi" data-achat-id="<?php echo esc_attr($achat_id); ?>">
            <div class="ispag-phase-board">
            <?php foreach ($families as $key => $family):
                $total = count($family['rows']);
                $pct   = $total ? round(100 * $family['done'] / $total) : 0;
                $label = $family_labels[$key] ?? $key;
                ?>
                <section class="ispag-phase-family" data-family="<?php echo esc_attr($key); ?>" style="--family-color: <?php echo esc_attr($family_colors[$key] ?? '#9ca3af'); ?>">
                    <header class="ispag-phase-family__head">
                        <span class="ispag-phase-family__title"><?php echo esc_html($label); ?></span>
                        <span class="ispag-phase-family__count"><?php echo (int) $family['done']; ?>/<?php echo (int) $total; ?></span>
                        <span class="ispag-phase-family__bar"><span style="width: <?php echo (int) $pct; ?>%"></span></span>
                    </header>
                    <div class="ispag-phase-family__steps">
                    <?php foreach ($family['rows'] as $row):
                        $etape  = $row['etape'];
                        $suivi  = $row['suivi'];
                        $is_done = (bool) $suivi;
                        $color  = $is_done ? $done_color : $pending_color;
                        ?>
                        <div class="ispag-phase-tracker__row<?php echo $is_done ? ' is-done' : ''; ?>" data-slug-phase="<?php echo esc_attr($etape->ClassCss); ?>" style="--status-color: <?php echo esc_attr($color); ?>">
                            <div class="ispag-phase-tracker__label">
                                <span class="ispag-phase-tracker__name"><?php echo esc_html__($etape->Etat, 'creation-reservoir'); ?>
                                    <?php if ((int) $etape->is_automatic): ?>
                                        <span class="dashicons dashicons-controls-repeat" title="<?php echo esc_attr__('Automatic step', 'creation-reservoir'); ?>"></span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($suivi): ?>
                                    <span class="ispag-phase-tracker__date"><?php echo esc_html(mysql2date('d.m.Y H:i', $suivi->date_modification)); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="ispag-phase-tracker__control">
                                <span class="ispag-phase-tracker__status-select" style="--status-color: <?php echo esc_attr($color); ?>">
                                    <?php echo $is_done ? esc_html__('Done', 'creation-reservoir') : esc_html__('Pending', 'creation-reservoir'); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
