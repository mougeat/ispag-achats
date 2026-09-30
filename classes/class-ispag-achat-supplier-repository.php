<?php


class ISPAG_Achat_Supplier_Repository {
    private $wpdb;
    private $table_fournisseurs ;
    private $table_meta;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_fournisseurs  = $wpdb->prefix . 'ispag_companies';
        $this->table_meta          = $wpdb->prefix . 'ispag_companies_meta';

    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_action('ispag_get_supplier_info', [self::$instance, 'get_supplier_info_by_id'], 10, 2);

    }

    /**
     * Clés meta (wor9711_ispag_companies_meta) des champs propres aux fournisseurs.
     */
    const SUPPLIER_META_KEYS = [
        'phone'          => 'ispag_company_phone',
        'lang'           => 'ispag_supplier_lang',
        'currency'       => 'ispag_supplier_currency',
        'tva'            => 'ispag_supplier_tva',
        'address'        => 'ispag_company_adress',
        'postal_code'    => 'ispag_company_postal_code',
        'city'           => 'ispag_company_city',
        'country'        => 'ispag_company_country',
        'delivery_days'  => 'ispag_supplier_delivery_days',
        'transport_time' => 'ispag_supplier_transport_time',
        'image'          => 'ispag_supplier_image',
        'contact_order'  => 'ispag_supplier_contact_order',
        'contact_plan'   => 'ispag_supplier_contact_plan',
    ];

    /**
     * Retourne les metas fournisseur (clé logique => valeur) d'une entreprise.
     */
    public function get_supplier_metas($company_id) {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$this->table_meta} WHERE company_id = %d ORDER BY meta_id ASC",
            $company_id
        ));

        $by_key = [];
        foreach ($rows as $row) {
            $by_key[$row->meta_key] = $row->meta_value; // la dernière occurrence gagne
        }

        $metas = [];
        foreach (self::SUPPLIER_META_KEYS as $logical => $meta_key) {
            $metas[$logical] = $by_key[$meta_key] ?? '';
        }
        return $metas;
    }

    public function get_supplier_info_by_id($html, $supplier_id){
        $supplier = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table_fournisseurs} WHERE id = %d", $supplier_id),
            ARRAY_A
        );

        if (!$supplier) {
            return null; // ou [] si tu préfères
        }

        $meta = $this->get_supplier_metas($supplier['id']);

        return [
            'id' => $supplier['id'],
            'name' => $supplier['company_name'],
            'email' => $supplier['email'],
            'phone' => $supplier['phone'] ?: $meta['phone'],
            'lang' => $meta['lang'],
            'currency' => $meta['currency'],
            'tva' => $meta['tva'],
            'address' => $meta['address'],
            'Postal code' => $meta['postal_code'],
            'city' => $meta['city'] ?: $supplier['city'],
            'country' => $meta['country'],
            'delivery_days' => $meta['delivery_days'],
            'transport_time' => $meta['transport_time'],
            'domain' => $supplier['compagny_domain'],
            'image' => $meta['image']
        ];
    }
}
