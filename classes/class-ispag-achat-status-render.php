<?php


class ISPAG_Achat_status_render {
    private $wpdb;
    private $table_historique;
    private $table_etat_commande;
    protected static $instance = null;

    
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_action('ispag_display_achat_suivi', [self::$instance, 'display_achat_suivi'], 10, 1);

    }


    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_historique = $wpdb->prefix . 'achats_historique';
        $this->table_etat_commande = $wpdb->prefix . 'achats_etat_commandes_fournisseur';

    }

    public function get_all_state_liste() {
        $results = $this->wpdb->get_results("
            SELECT Id, Etat, ordre, ClassCss, color 
            FROM {$this->table_etat_commande} 
            ORDER BY ordre ASC
        ");

        $states = [];

        foreach ($results as $row) {
            $states[] = [
                'id'     => (int) $row->Id,
                'label'  => $row->Etat,
                'ordre'  => (int) $row->ordre,
                'class'  => $row->ClassCss,
                'color'  => $row->color,
            ];
        }

        return $states;
    }
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
            OBJECT  // indexé par slug_phase directement
        );

        $by_slug = [];
        foreach ($results as $row) {
            $by_slug[$row->slug_phase] = $row;
        }
        return $by_slug;

        
    }

    public static function display_achat_suivi($achat_id) {
        global $wpdb;

        // 1. Récupère les étapes disponibles
        $etapes = $wpdb->get_results("SELECT Id, Etat, ClassCss, color FROM {$wpdb->prefix}achats_etat_commandes_fournisseur ORDER BY ordre ASC");

        if (!$etapes) {
            echo '<div class="ispag-notice"><p>' . __('No steps defined.', 'creation-reservoir') . '</p></div>';
            return;
        }

        // 2. Récupère les statuts enregistrés (historique)
        $suivis = self::get_last_statuses_by_slug($achat_id);

        echo '<div class="ispag-suivi-wrapper">'; // Centrage identique au projet
        echo '<div class="ispag-suivi-steps">';

        foreach ($etapes as $etape) {
            $slug = $etape->ClassCss;
            $etat_nom = __($etape->Etat, 'creation-reservoir');
            $couleur = $etape->color;
            $suivi = isset($suivis[$slug]) ? $suivis[$slug] : null;

            // Définition des classes identiques au projet pour la cohérence
            $row_class = $suivi ? 'is-completed' : 'is-pending';
            
            echo '<div class="suivi-step-row ' . $row_class . '">';
                
                // Timeline visuelle (Point + Ligne)
                echo '<div class="step-indicator">';
                    echo '<div class="step-dot" style="background-color:' . esc_attr($couleur) . ';"></div>';
                    echo '<div class="step-line"></div>';
                echo '</div>';

                // Bloc Contenu (Infos à gauche, Statut à droite)
                echo '<div class="step-content-box">';
                    echo '<div class="step-main-info">';
                        echo '<span class="step-title">' . esc_html($etat_nom) . '</span>';
                        echo '<span class="step-date">';
                        echo $suivi ? date('d.m.Y', strtotime($suivi->date_modification)) : '--.--.--';
                        echo '</span>';
                    echo '</div>';

                    echo '<div class="step-status-area">';
                        // On garde la classe suivi-status-badge pour le design interactif
                        $status_text = $suivi ? __('Done', 'creation-reservoir') : __('Pending', 'creation-reservoir');
                        echo '<span class="suivi-status-badge non-editable" style="border-left: 4px solid ' . esc_attr($couleur) . ';">';
                        echo esc_html($status_text);
                        echo '</span>';
                    echo '</div>';
                echo '</div>';

            echo '</div>'; // .suivi-step-row
        }

        echo '</div>'; // .ispag-suivi-steps
        echo '</div>'; // .ispag-suivi-wrapper
    }




}