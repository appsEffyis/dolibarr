<?php
/**
 * Modèle PDF LodinPay
 * 📁 custom/lodinpay/core/modules/facture/doc/pdf_lodinpay.modules.php
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

class pdf_lodinpay extends ModelePDFFactures
{
    public $type        = 'pdf';
    public $name        = 'lodinpay';
    public $description = 'Facture avec QR code LodinPay';
    public $update_main_doc_field = 1;
    public $phpmin      = [7, 0];
    public $version     = 'dolibarr';

    public $marge_gauche  = 10;
    public $marge_droite  = 10;
    public $marge_haute   = 10;
    public $marge_basse   = 10;
    public $page_largeur  = 210;
    public $page_hauteur  = 297;

    public function __construct($db)
    {
        global $langs;
        $this->db = $db;
        $langs->loadLangs(['main', 'bills', 'dict', 'companies']);
        $this->format = [$this->page_largeur, $this->page_hauteur];
        $this->option_logo            = 1;
        $this->option_tva             = 1;
        $this->option_modereg         = 1;
        $this->option_multilang       = 1;
        $this->option_freetext        = 1;
        $this->option_draft_watermark = 1;
    }

    // ======================================================
    // GÉNÉRATION DU PDF
    // ======================================================
    public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $conf, $mysoc;

        $outputlangs->loadLangs(['main', 'bills', 'dict', 'companies']);

        // Charger le tiers
        if (empty($object->thirdparty) || empty($object->thirdparty->name)) {
            $object->fetch_thirdparty();
        }

        // Créer le dossier
        $fileDir = $conf->facture->dir_output.'/'.$object->ref.'/';
        dol_mkdir($fileDir);

        // Récupérer le lien LodinPay
        $lodinpayLink = '';
        $sql = "SELECT lodinpay_payment_link FROM ".MAIN_DB_PREFIX."facture WHERE rowid = ".((int)$object->id);
        $res = $this->db->query($sql);
        if ($res && ($row = $this->db->fetch_object($res))) {
            $lodinpayLink = $row->lodinpay_payment_link ?? '';
        }

        // Télécharger QR via cURL
        $qrTmpPath = '';
        if (!empty($lodinpayLink)) {
            $qrApiUrl  = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data='.urlencode($lodinpayLink);
            $qrTmpPath = $fileDir.$object->ref.'_qr.png';

            if (function_exists('curl_init')) {
                $ch = curl_init($qrApiUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $qrData   = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($qrData && $httpCode === 200) {
                    file_put_contents($qrTmpPath, $qrData);
                } else {
                    $qrTmpPath = '';
                }
            } elseif (ini_get('allow_url_fopen')) {
                $qrData = @file_get_contents($qrApiUrl);
                if ($qrData) file_put_contents($qrTmpPath, $qrData);
                else $qrTmpPath = '';
            } else {
                $qrTmpPath = '';
            }
        }

        // Init TCPDF
        $pdf = pdf_getInstance($this->format);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetFont(pdf_getPDFFont($outputlangs));
        $pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->Open();
        $pdf->AddPage();

        $this->_drawHeader($pdf, $object, $outputlangs, $mysoc, $conf);
        $nexY = $this->_drawLines($pdf, $object, $outputlangs);
        $nexY = $this->_drawTotals($pdf, $object, $outputlangs, $nexY);

        // Bandeau paiement bas de page
        if (!empty($lodinpayLink)) {
            $this->_drawPaymentBanner($pdf, $lodinpayLink, $qrTmpPath);
        }

        if (!empty($qrTmpPath) && file_exists($qrTmpPath)) {
            @unlink($qrTmpPath);
        }

        $this->_drawFooter($pdf, $object, $outputlangs, $conf);

        $file = $fileDir.$object->ref.'.pdf';
        $pdf->Close();
        $pdf->Output($file, 'F');

        $this->result = ['fullpath' => $file];
        dol_syslog("LODINPAY PDF generated: ".$file, LOG_INFO);

        return 1;
    }

    // ======================================================
    // HEADER
    // ======================================================
    private function _drawHeader(&$pdf, $object, $outputlangs, $mysoc, $conf)
    {
        pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

        $logo = $conf->mycompany->dir_output.'/logos/'.$mysoc->logo;
        if (!empty($mysoc->logo) && file_exists($logo)) {
            $pdf->Image($logo, $this->marge_gauche, $this->marge_haute, 40, 0, '', '', '', false, 300);
        } else {
            $pdf->SetFont('', 'B', 11);
            $pdf->SetTextColor(0, 0, 60);
            $pdf->SetXY($this->marge_gauche, $this->marge_haute);
            $pdf->Cell(80, 6, $outputlangs->convToOutputCharset($mysoc->name), 0, 1, 'L');
        }

        $pdf->SetFont('', 'B', 14);
        $pdf->SetTextColor(0, 0, 60);
        $pdf->SetXY(120, $this->marge_haute);
        $pdf->Cell(80, 7, $outputlangs->transnoentities('Invoice').' '.$object->ref, 0, 1, 'R');

        $pdf->SetFont('', '', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetXY(120, $this->marge_haute + 8);
        $pdf->MultiCell(80, 4,
            $outputlangs->transnoentities('DateInvoice').' : '.dol_print_date($object->date, 'day', false, $outputlangs)."\n".
            $outputlangs->transnoentities('DateEcheance').' : '.dol_print_date($object->date_lim_reglement, 'day', false, $outputlangs)."\n".
            $outputlangs->transnoentities('CustomerCode').' : '.($object->thirdparty->code_client ?? ''),
        0, 'R');

        $pdf->SetFont('', 'B', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($this->marge_gauche, 48);
        $pdf->Cell(80, 5, $outputlangs->transnoentities('Issuer'), 0, 1, 'L');
        $pdf->SetFont('', '', 8);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetXY($this->marge_gauche, 53);
        $pdf->MultiCell(80, 4,
            $mysoc->name."\n".($mysoc->address ?? '')."\n".($mysoc->zip ?? '').' '.($mysoc->town ?? ''),
        1, 'L', true);

        $pdf->SetFont('', 'B', 8);
        $pdf->SetXY(110, 48);
        $pdf->Cell(90, 5, $outputlangs->transnoentities('BillTo'), 0, 1, 'L');
        $pdf->SetFont('', '', 8);
        $pdf->SetXY(110, 53);
        $pdf->MultiCell(90, 4,
            ($object->thirdparty->name ?? '')."\n".
            ($object->thirdparty->address ?? '')."\n".
            ($object->thirdparty->zip ?? '').' '.($object->thirdparty->town ?? ''),
        1, 'L');

        $pdf->SetFont('', 'B', 8);
        $pdf->SetFillColor(40, 60, 120);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($this->marge_gauche, 80);
        $pdf->Cell(80, 6, $outputlangs->transnoentities('Designation'), 1, 0, 'L', true);
        $pdf->Cell(20, 6, $outputlangs->transnoentities('VAT'), 1, 0, 'C', true);
        $pdf->Cell(25, 6, $outputlangs->transnoentities('PriceUHT'), 1, 0, 'R', true);
        $pdf->Cell(15, 6, $outputlangs->transnoentities('Qty'), 1, 0, 'C', true);
        $pdf->Cell(0,  6, $outputlangs->transnoentities('TotalHT'), 1, 1, 'R', true);
        $pdf->SetTextColor(0, 0, 0);
    }

    // ======================================================
    // LIGNES
    // ======================================================
    private function _drawLines(&$pdf, $object, $outputlangs)
    {
        $nexY = 88;
        foreach ($object->lines as $line) {
            $pdf->SetFont('', '', 8);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($this->marge_gauche, $nexY);
            $pdf->MultiCell(80, 5, $outputlangs->convToOutputCharset($line->desc ?? ''), 'LR', 'L');
            $realH = max($pdf->GetY() - $nexY, 5);

            $pdf->SetXY(90, $nexY);
            $pdf->Cell(20, $realH, number_format($line->tva_tx, 0).'%', 'LR', 0, 'C');
            $pdf->SetXY(110, $nexY);
            $pdf->Cell(25, $realH, price($line->subprice), 'LR', 0, 'R');
            $pdf->SetXY(135, $nexY);
            $pdf->Cell(15, $realH, $line->qty, 'LR', 0, 'C');
            $pdf->SetXY(150, $nexY);
            $pdf->Cell(0, $realH, price($line->total_ht), 'LR', 0, 'R');

            $nexY += $realH;
            $pdf->SetDrawColor(220, 220, 220);
            $pdf->Line($this->marge_gauche, $nexY, $this->page_largeur - $this->marge_droite, $nexY);
        }

        $pdf->SetDrawColor(40, 60, 120);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($this->marge_gauche, 86, $this->marge_gauche, $nexY);
        $pdf->Line($this->page_largeur - $this->marge_droite, 86, $this->page_largeur - $this->marge_droite, $nexY);
        $pdf->Line($this->marge_gauche, $nexY, $this->page_largeur - $this->marge_droite, $nexY);

        return $nexY;
    }

    // ======================================================
    // TOTAUX
    // ======================================================
    private function _drawTotals(&$pdf, $object, $outputlangs, $nexY)
    {
        $nexY += 4;
        $pdf->SetFont('', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY(110, $nexY);
        $pdf->Cell(40, 5, $outputlangs->transnoentities('TotalHT'), 0, 0, 'R');
        $pdf->Cell(0, 5, price($object->total_ht), 0, 1, 'R');
        $nexY += 5;

        if ($object->total_tva != 0) {
            $pdf->SetXY(110, $nexY);
            $pdf->Cell(40, 5, $outputlangs->transnoentities('VAT'), 0, 0, 'R');
            $pdf->Cell(0, 5, price($object->total_tva), 0, 1, 'R');
            $nexY += 5;
        }

        $pdf->SetFont('', 'B', 9);
        $pdf->SetFillColor(40, 60, 120);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(110, $nexY);
        $pdf->Cell(40, 7, $outputlangs->transnoentities('TotalTTC'), 1, 0, 'R', true);
        $pdf->Cell(0, 7, price($object->total_ttc), 1, 1, 'R', true);
        $pdf->SetTextColor(0, 0, 0);

        return $nexY + 7;
    }

    // ======================================================
    // ✅ BANDEAU PAIEMENT PROFESSIONNEL — bas de page
    //
    //  ┌──────────────────────────────────────────────────────────────┐
    //  │  💳  Payez votre facture en ligne       [ Payer maintenant ] │  [QR]
    //  │      Rapide, sécurisé, 20+ banques                           │
    //  └──────────────────────────────────────────────────────────────┘
    // ======================================================
    private function _drawPaymentBanner(&$pdf, $lodinpayLink, $qrTmpPath)
    {
        $bannerX = $this->marge_gauche;
        $bannerY = $this->page_hauteur - $this->marge_basse - 52;
        $bannerW = $this->page_largeur - $this->marge_gauche - $this->marge_droite; // 190mm
        $bannerH = 30;

        $qrSize  = 28; // QR dans le bandeau

        // ── Fond du bandeau ──────────────────────────────────────────
        $pdf->SetFillColor(245, 247, 255);
        $pdf->SetDrawColor(180, 190, 230);
        $pdf->SetLineWidth(0.4);
        $pdf->RoundedRect($bannerX, $bannerY, $bannerW, $bannerH, 3, '1111', 'DF');

        // ── Barre colorée gauche ──────────────────────────────────────
        $pdf->SetFillColor(40, 60, 120);
        $pdf->SetDrawColor(40, 60, 120);
        $pdf->RoundedRect($bannerX, $bannerY, 3, $bannerH, 1.5, '1100', 'F');

        // ── Texte principal ───────────────────────────────────────────
        $pdf->SetFont('', 'B', 9);
        $pdf->SetTextColor(40, 60, 120);
        $pdf->SetXY($bannerX + 7, $bannerY + 5);
        $pdf->Cell(90, 5, 'Payez votre facture en ligne', 0, 1, 'L');

        $pdf->SetFont('', '', 7.5);
        $pdf->SetTextColor(100, 110, 140);
        $pdf->SetXY($bannerX + 7, $bannerY + 11);
        $pdf->Cell(90, 4, 'Rapide · Sécurisé · 20+ banques européennes', 0, 1, 'L');

        // ── Bouton "Payer maintenant" (cliquable) ─────────────────────
        $btnW = 42;
        $btnH = 9;
        $btnX = $bannerX + 7;
        $btnY = $bannerY + 18;

        // Ombre légère
        $pdf->SetFillColor(20, 40, 100);
        $pdf->SetDrawColor(20, 40, 100);
        $pdf->RoundedRect($btnX + 0.5, $btnY + 0.5, $btnW, $btnH, 2, '1111', 'F');

        // Bouton principal
        $pdf->SetFillColor(40, 60, 180);
        $pdf->SetDrawColor(40, 60, 180);
        $pdf->RoundedRect($btnX, $btnY, $btnW, $btnH, 2, '1111', 'F');

        // Texte du bouton — cliquable
        $pdf->SetFont('', 'B', 8);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($btnX, $btnY + 1.5);
        $pdf->Cell($btnW, $btnH - 3, '  Payer maintenant  →', 0, 0, 'C', false, $lodinpayLink);

        // ── Séparateur vertical ───────────────────────────────────────
        $sepX = $bannerX + $bannerW - $qrSize - 18;
        $pdf->SetDrawColor(200, 210, 235);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($sepX, $bannerY + 3, $sepX, $bannerY + $bannerH - 3);

        // ── QR Code ───────────────────────────────────────────────────
        if (!empty($qrTmpPath) && file_exists($qrTmpPath)) {
            $qrX = $bannerX + $bannerW - $qrSize - 5;
            $qrY = $bannerY + ($bannerH - $qrSize) / 2;

            // Fond blanc propre derrière le QR
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetDrawColor(220, 225, 240);
            $pdf->SetLineWidth(0.2);
            $pdf->RoundedRect($qrX - 1.5, $qrY - 1.5, $qrSize + 3, $qrSize + 3, 1.5, '1111', 'DF');

            $pdf->Image($qrTmpPath, $qrX, $qrY, $qrSize, $qrSize, 'PNG');
        }

        // ── Texte sous le QR ─────────────────────────────────────────
        $qrX = $bannerX + $bannerW - $qrSize - 5;
        $pdf->SetFont('', '', 5.5);
        $pdf->SetTextColor(140, 150, 175);
        $pdf->SetXY($qrX - 1.5, $bannerY + $bannerH - 6);
        $pdf->Cell($qrSize + 3, 4, 'Scanner pour payer', 0, 0, 'C');

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
    }

    // ======================================================
    // PIED DE PAGE
    // ======================================================
    private function _drawFooter(&$pdf, $object, $outputlangs, $conf)
    {
        $yFoot = $this->page_hauteur - 18;

        $pdf->SetDrawColor(40, 60, 120);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($this->marge_gauche, $yFoot, $this->page_largeur - $this->marge_droite, $yFoot);

        $pdf->SetFont('', 'I', 7);
        $pdf->SetTextColor(150, 150, 150);

        if (!empty($conf->global->MAIN_PDF_FREETEXT)) {
            $pdf->SetXY($this->marge_gauche, $yFoot + 2);
            $pdf->MultiCell(140, 3, $outputlangs->convToOutputCharset($conf->global->MAIN_PDF_FREETEXT), 0, 'L');
        }

        $pdf->SetXY($this->marge_gauche, $yFoot + 2);
        $pdf->Cell(0, 4, $pdf->PageNo().' / '.$pdf->getAliasNbPages(), 0, 0, 'R');
    }
}