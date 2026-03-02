<?php
/**
 * Hook LodinPay — déclenché à la validation d'une facture
 * Équivalent du on_submit() ERPNext / action_post() Odoo
 */

class InterfaceLodinPay
{
    public $results;
    public $db;

    public function __construct()
    {
        $this->results = array();
    }

    /**
     * Se déclenche après la validation d'une facture (statut = 1)
     * Équivalent : on_submit(doc, method)
     */
    public function runTrigger($action, &$object, $user, $langs, $conf)
    {
        // On s'intéresse uniquement à la validation des factures clients
        if (!($action === 'BILL_VALIDATE' && get_class($object) === 'Facture')) {
            return 0;
        }

        require_once DOL_DOCUMENT_ROOT.'/custom/lodinpay/class/lodinpay.class.php';

        // ── Vérification devise (équivalent : if doc.currency != "EUR") ────────
        $currency = $object->multicurrency_code ?: $conf->currency;
        if ($currency !== 'EUR') {
            setEventMessages(
                "LodinPay ignoré pour la facture {$object->ref} (devise: {$currency}, LodinPay accepte uniquement EUR)",
                null,
                'warnings'
            );
            return 0;
        }

        // ── Vérification credentials ──────────────────────────────────────────
        $lodinpay = new LodinPay($this->db, $user);

        if (!$lodinpay->isConfigured()) {
            dol_syslog("LodinPay: Credentials manquants pour user {$user->login}", LOG_ERR);
            setEventMessages("LodinPay: Credentials non configurés. Allez dans Configuration LodinPay.", null, 'warnings');
            return 0;
        }

        // ── Déjà traité ? (équivalent : if not doc.lodinpay_order_id) ─────────
        // On lit le champ lodinpay_order_id depuis la table llx_facture
        $orderId = $this->getLodinpayOrderId($object->id);

        if (!empty($orderId)) {
            dol_syslog("LodinPay: Facture {$object->ref} déjà traitée (orderId: {$orderId})", LOG_INFO);
            return 0;
        }

        try {
            dol_syslog("===== LODINPAY PROCESS START invoice={$object->ref} =====", LOG_INFO);

            // ── 1. GÉNÉRATION DU RTP ──────────────────────────────────────────
            $rtpData = $lodinpay->generateRtp($object);

            $paymentLink = $rtpData['url']         ?? '';
            $newOrderId  = (string)($rtpData['orderId']    ?? '');
            $accessLogId = $rtpData['accessLogId'] ?? '';

            // Sauvegarde IMMÉDIATE en base (même si la suite plante)
            // Équivalent : doc.db_set(...) + frappe.db.commit()
            $this->saveRtpData($object->id, $paymentLink, $newOrderId);

            dol_syslog("LodinPay: RTP généré orderId={$newOrderId} link={$paymentLink}", LOG_INFO);

            // ── 2. ENVOI DES DONNÉES JSON ─────────────────────────────────────
            $invoiceResponse = $lodinpay->sendInvoiceToBackend($object, $accessLogId);

            if (empty($invoiceResponse['id'])) {
                throw new Exception("LodinPay n'a pas retourné d'invoice id");
            }

            $backendInvoiceId = $invoiceResponse['id'];

            // ── 3. ENVOI DU PDF ───────────────────────────────────────────────
            $pdfPath = $this->getInvoicePdfPath($object, $conf);

            if (!empty($pdfPath)) {
                $lodinpay->sendInvoicePdf($pdfPath, $object->ref, $object->total_ttc, $backendInvoiceId);
            } else {
                dol_syslog("LodinPay: PDF introuvable pour {$object->ref}", LOG_WARNING);
            }

            dol_syslog("✅ LODINPAY FULL SYNC DONE invoice={$object->ref}", LOG_INFO);

            // Message de succès dans l'interface Dolibarr
            setEventMessages(
                "✅ LodinPay : Lien de paiement généré pour {$object->ref}",
                null,
                'mesgs'
            );

        } catch (Exception $e) {
            dol_syslog("LodinPay ERREUR facture {$object->ref} : " . $e->getMessage(), LOG_ERR);
            setEventMessages("Erreur LodinPay : " . $e->getMessage(), null, 'errors');
            // On ne bloque PAS la validation de la facture (comme dans ERPNext)
        }

        return 0;
    }

    // =========================================================================
    // UTILITAIRES PRIVÉS
    // =========================================================================

    /**
     * Lit le lodinpay_order_id depuis la base Dolibarr
     * Équivalent : doc.lodinpay_order_id dans ERPNext
     */
    private function getLodinpayOrderId($factureId)
    {
        $sql = "SELECT lodinpay_order_id FROM ".MAIN_DB_PREFIX."facture";
        $sql .= " WHERE rowid = ".((int) $factureId);

        $res = $this->db->query($sql);
        if ($res) {
            $obj = $this->db->fetch_object($res);
            return $obj->lodinpay_order_id ?? '';
        }
        return '';
    }

    /**
     * Sauvegarde le RTP en base immédiatement
     * Équivalent : doc.db_set(...) + frappe.db.commit()
     */
    private function saveRtpData($factureId, $paymentLink, $orderId)
    {
        $sql  = "UPDATE ".MAIN_DB_PREFIX."facture SET";
        $sql .= " lodinpay_payment_link = '".$this->db->escape($paymentLink)."'";
        $sql .= ", lodinpay_order_id    = '".$this->db->escape($orderId)."'";
        $sql .= " WHERE rowid = ".((int) $factureId);

        $this->db->query($sql);
        // Pas besoin de commit explicite, Dolibarr gère les transactions
    }

    /**
     * Retrouve le chemin du PDF de la facture
     */
    private function getInvoicePdfPath($object, $conf)
    {
        // Dolibarr stocke les PDFs dans : documents/facture/[year]/[ref]/[ref].pdf
        $dir = $conf->facture->dir_output
             . '/' . dol_sanitizeFileName($object->ref);

        $path = $dir . '/' . dol_sanitizeFileName($object->ref) . '.pdf';

        if (file_exists($path)) {
            return $path;
        }

        // Si le PDF n'existe pas encore, on le génère
        require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
        require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

        // Génération du PDF via le modèle par défaut
        $modelpath = "core/modules/facture/doc/";
        $result    = $object->generateDocument(
            $object->model_pdf ?: 'crabe',
            $langs ?? new Translate('', $conf),
            0, 0, 0
        );

        if ($result > 0 && file_exists($path)) {
            return $path;
        }

        return null;
    }
}