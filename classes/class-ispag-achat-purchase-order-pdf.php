<?php
defined('ABSPATH') || exit;

/**
 * Bon de commande fournisseur (PDF) : mise en page moderne, indépendante du bulletin de livraison.
 * Instancié à la demande (voir ISPAG_Achat_Generate_Purchase_Order_PDF::build_pdf) : la classe parente
 * ISPAG_PDF_Generator vient du plugin ispag-project-manager et doit être chargée avant l'autoload de celle-ci.
 */
class ISPAG_Achat_Purchase_Order_PDF extends ISPAG_PDF_Generator {

    const MARGIN   = 15;
    const CONTENT  = 180; // largeur utile (A4 − 2 × marges)
    const RED      = [200, 0, 0];
    const INK      = [33, 37, 41];
    const MUTED    = [120, 126, 134];
    const LINE     = [225, 228, 232];
    const ZEBRA    = [247, 248, 250];
    const CARD     = [243, 244, 246];

    protected $currency = '';

    /**
     * Texte brut propre pour le PDF : retire les balises HTML, décode les entités (même doublement encodées,
     * ex. « &amp;lt; ») et normalise les retours à la ligne. Un « < » isolé (ex. « <2500mm ») est conservé.
     */
    public static function plain_text($text) {
        $text = stripslashes((string) $text);
        for ($i = 0; $i < 3; $i++) {
            $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
            $text = preg_replace('#</\s*(p|div|li|tr|h[1-6])\s*>#i', "\n", $text);
            $text = preg_replace('#<\s*/?\s*[a-z][a-z0-9:-]*\b[^>]*>#i', '', $text);
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) break;
            $text = $decoded;
        }
        $text = str_replace(["\r", "\xC2\xA0"], ['', ' '], $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/ ?\n ?/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    protected function color(array $c, string $what = 'text') {
        if ($what === 'fill') $this->SetFillColor($c[0], $c[1], $c[2]);
        elseif ($what === 'draw') $this->SetDrawColor($c[0], $c[1], $c[2]);
        else $this->SetTextColor($c[0], $c[1], $c[2]);
    }

    /** Colonnes : largeurs proportionnelles ramenées à la largeur utile. */
    protected function scaleColumns(array $columns): array {
        $sum = array_sum(array_column($columns, 'width')) ?: 1;
        foreach ($columns as &$col) {
            $col['w'] = $col['width'] * self::CONTENT / $sum;
            $col['align'] = $col['align'] ?? 'L';
        }
        return $columns;
    }

    public function generate_purchase_order($project_header, $achat, $infos, $table_header, $articles, $title = 'Purchase order') {
        $this->title          = $title;
        $this->project        = $achat;
        $this->infos          = $infos;
        $this->project_header = $project_header;
        $this->currency       = strtoupper(trim((string) ($achat->Devise ?? '')));

        $this->SetCreator('ISPAG');
        $this->SetTitle($this->cleanStr($title), true);
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetAutoPageBreak(true, 25);
        $this->AliasNbPages();
        $this->AddPage();

        $this->drawHeader();
        $bottom = $this->drawInfoCards();
        $this->SetY($bottom + 10);
        $this->drawTable($this->scaleColumns($table_header), $articles);
    }

    protected function drawHeader() {
        $this->drawLogo(self::MARGIN, 12, 42, 16);

        $this->SetXY(self::MARGIN, 12);
        $this->SetFont('Arial', 'B', 22);
        $this->color(self::RED);
        $this->Cell(self::CONTENT, 10, $this->cleanStr($this->title), 0, 1, 'R');

        // Filet rouge sous l'en-tête
        $this->color(self::RED, 'draw');
        $this->SetLineWidth(0.6);
        $this->Line(self::MARGIN, 32, self::MARGIN + self::CONTENT, 32);
        $this->SetLineWidth(0.2);
    }

    /** Deux cartes côte à côte : fournisseur (gauche) et références de la commande (droite). Retourne le bas des cartes. */
    protected function drawInfoCards(): float {
        $top = 40;
        $w   = 87;
        $gap = self::CONTENT - 2 * $w;
        $xL  = self::MARGIN;
        $xR  = self::MARGIN + $w + $gap;

        // --- Fournisseur ---
        $lines = [];
        foreach (['nom_entreprise', 'contact_name', 'AdresseDeLivraison', 'PersonneContact', 'DeliveryAdresse2', 'DeliveryAdresse3'] as $k) {
            $v = trim(self::plain_text($this->infos->$k ?? ''));
            if ($v !== '') $lines[] = $v;
        }
        $zip_city = trim(($this->infos->{'Postal code'} ?? $this->infos->NIP ?? '') . ' ' . ($this->infos->City ?? ''));
        if ($zip_city !== '') $lines[] = $zip_city;
        if (!empty($this->infos->country)) $lines[] = $this->infos->country;

        $supplierH = 10 + max(1, count($lines)) * 5.2 + 4;

        // --- Références ---
        $meta  = (array) $this->project_header;
        $metaH = 10 + count($meta) * 6.2 + 2;

        $h = max($supplierH, $metaH);

        foreach ([$xL, $xR] as $x) {
            $this->color(self::CARD, 'fill');
            $this->Rect($x, $top, $w, $h, 'F');
            $this->color(self::RED, 'fill');
            $this->Rect($x, $top, 1.2, $h, 'F');
        }

        // Carte fournisseur
        $this->SetXY($xL + 6, $top + 3);
        $this->SetFont('Arial', 'B', 8);
        $this->color(self::MUTED);
        $this->Cell($w - 8, 4, $this->cleanStr(mb_strtoupper(__('Supplier', 'creation-reservoir'))), 0, 1);
        $y = $top + 9;
        foreach ($lines as $i => $line) {
            $this->SetXY($xL + 6, $y);
            $this->SetFont('Arial', $i === 0 ? 'B' : '', $i === 0 ? 11 : 10);
            $this->color(self::INK);
            $this->Cell($w - 8, 5.2, $this->cleanStr($line), 0, 1);
            $y += 5.2;
        }

        // Carte références
        $this->SetXY($xR + 6, $top + 3);
        $this->SetFont('Arial', 'B', 8);
        $this->color(self::MUTED);
        $this->Cell($w - 8, 4, $this->cleanStr(mb_strtoupper(__('Reference', 'creation-reservoir'))), 0, 1);
        $y = $top + 9;
        foreach ($meta as $label => $value) {
            $this->SetXY($xR + 6, $y);
            $this->SetFont('Arial', '', 9);
            $this->color(self::MUTED);
            $this->Cell(30, 6.2, $this->cleanStr($label), 0, 0);
            $this->SetFont('Arial', 'B', 10);
            $this->color(self::INK);
            $this->MultiCell($w - 38, 6.2, $this->cleanStr(self::plain_text($value)), 0, 'L');
            $y = max($y + 6.2, $this->GetY());
        }

        return $top + max($h, $y - $top + 2);
    }

    protected function drawTableHeader(array $columns) {
        $this->SetFont('Arial', 'B', 9);
        $this->color(self::RED, 'fill');
        $this->color([255, 255, 255]);
        foreach ($columns as $col) {
            $this->Cell($col['w'], 8, $this->cleanStr($col['label'] ?? ''), 0, 0, $col['align'] === 'L' ? 'L' : $col['align'], true);
        }
        $this->Ln();
    }

    protected function drawTable(array $columns, array $rows) {
        $lh  = 5;
        $pad = 2;

        $this->drawTableHeader($columns);
        $this->SetFont('Arial', '', 9);
        $this->color(self::LINE, 'draw');

        $total = 0.0;
        foreach ($rows as $n => $row) {
            $cells = [];
            $maxLines = 1;
            foreach ($columns as $col) {
                $text = $this->cleanStr(self::plain_text($row[$col['key']] ?? ''));
                $cells[] = $text;
                $maxLines = max($maxLines, $this->NbLines($col['w'], $text));
            }
            $rowH = $maxLines * $lh + 2 * $pad;

            if ($this->GetY() + $rowH > $this->PageBreakTrigger) {
                $this->AddPage();
                $this->drawTableHeader($columns);
                $this->SetFont('Arial', '', 9);
            }

            $x = self::MARGIN;
            $y = $this->GetY();
            if ($n % 2 === 1) {
                $this->color(self::ZEBRA, 'fill');
                $this->Rect($x, $y, self::CONTENT, $rowH, 'F');
            }
            $this->color(self::INK);
            foreach ($columns as $i => $col) {
                $this->SetXY($x, $y + $pad);
                $this->SetFont('Arial', $col['key'] === 'total' ? 'B' : '', 9);
                $this->MultiCell($col['w'], $lh, $cells[$i], 0, $col['align']);
                $x += $col['w'];
            }
            $this->color(self::LINE, 'draw');
            $this->Line(self::MARGIN, $y + $rowH, self::MARGIN + self::CONTENT, $y + $rowH);
            $this->SetY($y + $rowH);

            $total += floatval(str_replace([' ', "'"], '', $row['total'] ?? 0));
        }

        $this->drawTotal($total);
    }

    protected function drawTotal(float $total) {
        if ($this->GetY() + 14 > $this->PageBreakTrigger) {
            $this->AddPage();
        }
        $w = 70;
        $x = self::MARGIN + self::CONTENT - $w;
        $y = $this->GetY() + 5;

        $this->color(self::CARD, 'fill');
        $this->Rect($x, $y, $w, 11, 'F');
        $this->color(self::RED, 'fill');
        $this->Rect($x, $y, 1.2, 11, 'F');

        $label = __('Total', 'creation-reservoir') . ($this->currency !== '' ? ' ' . $this->currency : '');
        $this->SetXY($x + 5, $y);
        $this->SetFont('Arial', 'B', 10);
        $this->color(self::MUTED);
        $this->Cell(25, 11, $this->cleanStr($label), 0, 0, 'L');
        $this->SetFont('Arial', 'B', 13);
        $this->color(self::INK);
        $this->Cell($w - 30, 11, $this->cleanStr(number_format($total, 2, '.', "'")), 0, 1, 'R');
    }

    function Footer() {
        $this->SetY(-20);
        $this->color(self::LINE, 'draw');
        $this->Line(self::MARGIN, $this->GetY(), self::MARGIN + self::CONTENT, $this->GetY());
        $this->Ln(2);

        $this->SetFont('Arial', '', 8);
        $this->color(self::MUTED);
        $line1 = implode(' - ', array_filter([
            get_option('wpcb_companyName'),
            get_option('wpcb_companyAdress'),
            trim(get_option('wpcb_companyNIP') . ' ' . get_option('wpcb_companyCity')),
            get_option('wpcb_companyCountry'),
        ]));
        $line2 = implode(' - ', array_filter([
            get_option('wpcb_companyMail'),
            get_option('wpcb_companyPhone'),
            get_option('wpcb_companyWebsite'),
        ]));
        $this->Cell(0, 4, $this->cleanStr($line1), 0, 1, 'C');
        $this->Cell(0, 4, $this->cleanStr($line2), 0, 1, 'C');
        $this->Cell(0, 4, $this->cleanStr('Page ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
    }
}
