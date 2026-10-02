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
        'address_2'      => 'ispag_company_address_2',
        'postal_code'    => 'ispag_company_postal_code',
        'city'           => 'ispag_company_city',
        'country'        => 'ispag_company_country',
        'delivery_days'  => 'ispag_supplier_delivery_days',
        'transport_time' => 'ispag_supplier_transport_time',
        'image'          => 'ispag_supplier_image',
        'contact_order'  => 'ispag_supplier_contact_order',
        'contact_plan'   => 'ispag_supplier_contact_plan',
        'contact_billing'  => 'ispag_supplier_contact_billing',
        'contact_delivery' => 'ispag_supplier_contact_delivery',
    ];

    /**
     * Ancien nom de colonne de achats_fournisseurs => clé logique de SUPPLIER_META_KEYS
     * (les champs propres aux fournisseurs vivent dans wor9711_ispag_companies_meta).
     */
    const LEGACY_FIELDS = [
        'NumTel'               => 'phone',
        'Langue'               => 'lang',
        'Monnaie'              => 'currency',
        'TVA'                  => 'tva',
        'SupplierAdresse'      => 'address',
        'CodePostal'           => 'postal_code',
        'Ville'                => 'city',
        'Pays'                 => 'country',
        'deliveryDays'         => 'delivery_days',
        'TransportTime'        => 'transport_time',
        'Image'                => 'image',
        'IdContactCommande'    => 'contact_order',
        'IdContactPlan'        => 'contact_plan',
        'IdContactFacturation' => 'contact_billing',
        'IdContactLivraison'   => 'contact_delivery',
    ];

    /**
     * Fiche fournisseur (ligne de ispag_companies enrichie de ses metas) avec les anciens noms de champs :
     * Id, Fournisseur, Mail, compagnyDomain, NumTel, Langue, Monnaie, TVA, SupplierAdresse, CodePostal, Ville, Pays,
     * deliveryDays, TransportTime, Image, IdContactCommande/Plan/Facturation/Livraison.
     */
    public static function get_supplier_row($supplier_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ispag_companies WHERE Id = %d", (int) $supplier_id));
        if (!$row) {
            return null;
        }
        $repo  = new self();
        $metas = $repo->get_supplier_metas($row->Id);
        $row->Fournisseur     = $row->company_name;
        $row->Mail            = $row->email;
        $row->compagnyDomain  = $row->compagny_domain;
        foreach (self::LEGACY_FIELDS as $legacy => $logical) {
            $row->$legacy = $metas[$logical];
        }
        if ($row->NumTel === '' && !empty($row->phone)) {
            $row->NumTel = $row->phone;
        }
        if ($row->Ville === '' && !empty($row->city)) {
            $row->Ville = $row->city;
        }
        return $row;
    }

    /** Écrit (ou supprime si vide) une meta fournisseur. */
    public static function set_supplier_meta($supplier_id, $logical_key, $value) {
        global $wpdb;
        $table    = $wpdb->prefix . 'ispag_companies_meta';
        $meta_key = self::SUPPLIER_META_KEYS[$logical_key];
        $wpdb->delete($table, ['company_id' => (int) $supplier_id, 'meta_key' => $meta_key], ['%d', '%s']);
        if ($value === '' || $value === null || $value === 0 || $value === '0') {
            return true;
        }
        return $wpdb->insert($table, ['company_id' => (int) $supplier_id, 'meta_key' => $meta_key, 'meta_value' => (string) $value], ['%d', '%s', '%s']) !== false;
    }

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
            // la dernière occurrence gagne, sans qu'un doublon vide n'écrase une valeur renseignée
            if (!isset($by_key[$row->meta_key]) || trim((string) $row->meta_value) !== '') {
                $by_key[$row->meta_key] = $row->meta_value;
            }
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

        // La colonne s'appelle « Id » (majuscule) : $supplier['id'] n'existait pas, les metas (adresse, code postal, pays…) étaient donc cherchées pour la société 0
        $company_id = (int) ($supplier['Id'] ?? $supplier['id'] ?? $supplier_id);
        $meta = $this->get_supplier_metas($company_id);

        return [
            'id' => $company_id,
            'name' => $supplier['company_name'],
            'email' => $supplier['email'],
            'phone' => $supplier['phone'] ?: $meta['phone'],
            'lang' => $meta['lang'],
            'currency' => $meta['currency'],
            'tva' => $meta['tva'],
            'address' => $meta['address'],
            'address_2' => $meta['address_2'],
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
