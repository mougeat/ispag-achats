<?php

class ISPAG_Achat_Details_Repository {
    private $table;
    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'achats_info_commande';
        $this->wpdb = $wpdb;
    }

    public function get_infos_livraison($deal_id) {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE purchase_order = %d", $deal_id)
        );

        if ($row) {
            return $row;
        }

        // Retourne un objet par défaut si rien trouvé
        return (object)[
            'purchase_order' => $deal_id,
            'AdresseDeLivraison' => '',
            'DeliveryAdresse2' => '',
            'Postal code' => '',
            'City' => '',
            'Comment' => '',
            'PersonneContact' => '',
            'num_tel_contact' => '',
            'ConfCommande' => '',
            'unloadingFacilities' => 0,
        ];
    }

}
 