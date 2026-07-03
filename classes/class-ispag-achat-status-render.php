<?php

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
     * Affiche le suivi des étapes pour une commande fournisseur.
     * Structure inspirée de display_ispag_suivis() pour les projets.
     * 
     * @param int $achat_id ID de la commande fournisseur.
     */
    public static function display_achat_suivi($achat_id) {
        global $wpdb;

        // 1. Récupère les étapes disponibles (triées par ordre)
        $etapes = $wpdb->get_results("
            SELECT Id, Etat, ClassCss, color, is_automatic
            FROM {$wpdb->prefix}achats_etat_commandes_fournisseur
            ORDER BY ordre ASC
        ");

        if (!$etapes) {
            echo '<div class="ispag-notice"><p>' . __('No steps defined.', 'creation-reservoir') . '</p></div>';
            return;
        }

        // 2. Récupère les statuts enregistrés (historique)
        $suivis = self::get_last_statuses_by_slug($achat_id);

        // 3. Vérifie les permissions
        $can_edit = current_user_can('manage_order');
        $can_view_all = current_user_can('manage_order');

        echo '<div class="ispag-suivi-wrapper">';
        echo '<div class="ispag-suivi-steps">';

        foreach ($etapes as $etape) {
            $slug = $etape->ClassCss;
            $etat_nom = __($etape->Etat, 'creation-reservoir');
            $couleur = $etape->color;
            $is_automatic = isset($etape->is_automatic) ? (int) $etape->is_automatic : 0;
            $suivi = isset($suivis[$slug]) ? $suivis[$slug] : null;

            // Détermine si l'étape est complétée
            $row_class = $suivi ? 'is-completed' : 'is-pending';

            echo '<div class="suivi-step-row ' . esc_attr($row_class) . '">';

                // Timeline visuelle (Point + Ligne)
                echo '<div class="step-indicator">';
                    echo '<div class="step-dot" style="background-color:' . esc_attr($couleur) . ';"></div>';
                    echo '<div class="step-line"></div>';
                echo '</div>';

                // Bloc Contenu (Infos à gauche, Statut à droite)
                echo '<div class="step-content-box">';
                    echo '<div class="step-main-info">';
                        echo '<span class="step-title">';
                            echo esc_html($etat_nom);
                            
                            // Icône pour les étapes automatiques (remplace Brevo_id)
                            if ($is_automatic) {
                                echo ' <span class="dashicons dashicons-superhero" title="' . esc_attr__('Automatic step', 'creation-reservoir') . '"></span>';
                            }
                        echo '</span>';
                        
                        // Date de réalisation
                        echo '<span class="step-date">' . __('Realized on: ', 'creation-reservoir') . 
                             ($suivi ? date('d.m.Y', strtotime($suivi->date_modification)) : '--.--.--') . '</span>';
                    echo '</div>';

                    // Zone de statut (badge)
                    echo '<div class="step-status-area">';
                        $status_text = $suivi ? __('Done', 'creation-reservoir') : __('Pending', 'creation-reservoir');
                        
                        // Classes pour le badge
                        $classes = 'suivi-status-badge';
                        if ($can_edit) {
                            $classes .= ' editable-status';
                        } else {
                            $classes .= ' non-editable';
                        }
                        
                        echo '<span class="' . esc_attr($classes) . '" ';
                        if ($can_edit) {
                            echo 'data-achat="' . esc_attr($achat_id) . '" ';
                            echo 'data-phase="' . esc_attr($slug) . '" ';
                            echo 'data-current="' . esc_attr($suivi ? $suivi->status_id : '') . '"';
                        }
                        echo ' style="border-left: 4px solid ' . esc_attr($couleur) . ';">';
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