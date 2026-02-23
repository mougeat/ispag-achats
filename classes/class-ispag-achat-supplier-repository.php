
<?php


class ISPAG_Achat_Supplier_Repository {
    private $wpdb;
    private $table_fournisseurs ;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_fournisseurs  = $wpdb->prefix . 'achats_fournisseurs';

    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_action('ispag_get_supplier_info', [self::$instance, 'get_supplier_info_by_id'], 10, 2);

    }

    public function get_supplier_info_by_id($html, $supplier_id){
        // $table_fournisseurs = $this->wpdb->prefix . 'achats_fournisseurs';

        $supplier = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM $this->table_fournisseurs WHERE Id = %d", $supplier_id),
            ARRAY_A
        );

        if (!$supplier) {
            return null; // ou [] si tu préfères
        }

        return [
            'id' => $supplier['Id'],
            'name' => $supplier['Fournisseur'],
            'email' => $supplier['Mail'],
            'phone' => $supplier['NumTel'],
            'lang' => $supplier['Langue'],
            'currency' => $supplier['Monnaie'],
            'tva' => $supplier['TVA'],
            'address' => $supplier['SupplierAdresse'],
            'Postal code' => $supplier['CodePostal'],
            'city' => $supplier['Ville'],
            'country' => $supplier['Pays'],
            'delivery_days' => $supplier['deliveryDays'],
            'transport_time' => $supplier['TransportTime'],
            'domain' => $supplier['compagnyDomain'],
            'image' => $supplier['Image']
        ];
    }
}