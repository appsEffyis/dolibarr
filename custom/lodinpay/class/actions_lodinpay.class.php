<?php

class ActionsLodinpay
{
    const RTP_API = "https://api-preprod.lodinpay.com/merchant-service/extensions/pay/rtp";
    const EXTENSION_CODE = "DOLIBARR";

    // 🔽 ADDITION: NEW APIS (NO IMPACT)
    const INVOICE_API    = "https://api-preprod.lodinpay.com/merchant-service/extensions/invoices";
    const RTP_STATUS_API = "https://api-preprod.lodinpay.com/merchant-service/extensions/pay/rtp/check-status";
    // 🔼 ADDITION END

    // ======================================================
    // CALLED ON EVERY ACTION (INVOICE CARD CONTEXT)
    // ======================================================
public function doActions($parameters, &$object, &$action, $hookmanager)
{
    global $conf, $db, $user, $langs;

    dol_syslog("LODINGPAY doActions action=".$action, LOG_INFO);

    if (!is_object($object) || $object->element !== 'facture') {
        return 0;
    }

    $clientId = $conf->global->LODINPAY_CLIENT_ID ?? '';
    $secret   = $conf->global->LODINPAY_CLIENT_SECRET ?? '';

    if (!$clientId || !$secret) {
        dol_syslog("LODINGPAY missing credentials", LOG_WARNING);
        return 0;
    }

    // ======================================================
    // 🔁 MANUAL SYNC BUTTON
    // ======================================================
    if ($action === 'lodinpay_sync') {
        try {
            dol_syslog("LODINGPAY sync status for ".$object->ref, LOG_INFO);

            $statusResponse = $this->syncPaymentStatus($object, $clientId, $secret);
            $status = $statusResponse['status'] ?? null;

            dol_syslog("LODINGPAY STATUS=".$status, LOG_INFO);

            if ($status === 'Completed') {
                $this->markInvoicePaid($object);
                setEventMessage("Invoice marked as PAID (LodinPay)");
            } else {
                setEventMessage("LodinPay status: ".$status);
            }

        } catch (Exception $e) {
            dol_syslog("LODINGPAY SYNC ERROR ".$e->getMessage(), LOG_ERR);
            setEventMessage($e->getMessage(), 'errors');
        }

        return 0;
    }

    // ======================================================
    // ✅ ON INVOICE VALIDATION ONLY
    // ======================================================
    if ($action !== 'confirm_valid') {
        return 0;
    }

    // ✅ FIX 1 — Recharger l'objet pour avoir le vrai numéro (pas PROV...)
    $object->fetch($object->id);
    dol_syslog("LODINGPAY ref after fetch: ".$object->ref, LOG_INFO);

    // ✅ FIX 2 — Vérifier en DB si déjà traité
    $sqlCheck = "SELECT lodinpay_order_id FROM ".MAIN_DB_PREFIX."facture 
                 WHERE rowid = ".((int) $object->id)."
                 AND lodinpay_order_id IS NOT NULL 
                 AND lodinpay_order_id != ''";
    $resCheck = $db->query($sqlCheck);
    if ($resCheck && $db->num_rows($resCheck) > 0) {
        dol_syslog("LODINGPAY already processed ".$object->ref.", skipping", LOG_INFO);
        return 0;
    }

    try {
        // ===============================
        // 1️⃣ GENERATE RTP
        // ===============================
        dol_syslog("LODINGPAY generating RTP for ".$object->ref, LOG_INFO);

        $rtp = $this->generateRTP($object, $clientId, $secret);

        dol_syslog("LODINGPAY RTP RESPONSE: ".json_encode($rtp), LOG_DEBUG);

        // ===============================
        // 2️⃣ CREATE INVOICE IN LODINPAY
        // ===============================
        try {
            $invoiceResponse = $this->createInvoice(
                $object,
                $clientId,
                $secret,
                $rtp['accessLogId'] ?? null
            );

            dol_syslog("LODINGPAY INVOICE RESPONSE: ".json_encode($invoiceResponse), LOG_DEBUG);

            if (empty($invoiceResponse['id'])) {
                throw new Exception("LodinPay invoice creation failed");
            }

            $backendInvoiceId = $invoiceResponse['id'];

            // ===============================
            // 3️⃣ ATTACH PDF
            // ===============================
            $this->attachInvoicePdf(
                $object,
                $backendInvoiceId,
                $clientId,
                $secret
            );

            dol_syslog("LODINGPAY PDF attached for ".$object->ref, LOG_INFO);

        } catch (Exception $e) {
            // ✅ "already exists" n'est pas bloquant — le lien RTP est déjà créé
            if (strpos($e->getMessage(), 'already exists') !== false) {
                dol_syslog("LODINGPAY invoice already exists in backend, continuing", LOG_WARNING);
            } else {
                throw $e; // Relancer les vraies erreurs
            }
        }

        // ===============================
        // 4️⃣ SAVE DATA IN DOLIBARR (toujours exécuté)
        // ===============================
        $sql = "UPDATE ".MAIN_DB_PREFIX."facture SET
                    lodinpay_payment_link = '".$db->escape($rtp['url'])."',
                    lodinpay_order_id     = '".$db->escape($rtp['orderId'])."'
                WHERE rowid = ".((int) $object->id);

        $db->query($sql);

        dol_syslog("LODINGPAY saved orderId=".$rtp['orderId']." for ".$object->ref, LOG_INFO);

    } catch (Exception $e) {
        dol_syslog("LODINGPAY ERROR ".$e->getMessage(), LOG_ERR);
        setEventMessage("LodinPay error: ".$e->getMessage(), 'errors');
    }

    return 0;
}


    // ======================================================
    // SHOW LINK ON INVOICE CARD
    // ======================================================
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $db;

        if (!is_object($object) || $object->element !== 'facture') {
            return 0;
        }

        $sql = "SELECT lodinpay_payment_link, lodinpay_order_id
                FROM ".MAIN_DB_PREFIX."facture
                WHERE rowid = ".((int) $object->id);

        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql)) {
            $row = $db->fetch_object($resql);

            if (!empty($row->lodinpay_payment_link)) {

                // PAYMENT LINK
                print '<tr>
                    <td>LodinPay Payment</td>
                    <td>
                        <a class="badge badge-status4"
                        target="_blank"
                        href="'.dol_escape_htmltag($row->lodinpay_payment_link).'">
                        Pay via LodinPay
                        </a>
                    </td>
                </tr>';

                // ORDER ID
                print '<tr>
                    <td>LodinPay Order ID</td>
                    <td>'.dol_escape_htmltag($row->lodinpay_order_id).'</td>
                </tr>';

                // SYNC BUTTON
                print '<tr>
                    <td>LodinPay Status</td>
                    <td>
                        <a class="badge badge-status4"
                        href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=lodinpay_sync">
                        Sync payment status
                        </a>
                    </td>
                </tr>';
            }
        }

        return 0;
    }

    // ======================================================
    // RTP API
    // ======================================================
    private function generateRTP($invoice, $clientId, $secret)
    {
        $amount = number_format($invoice->total_ttc, 2, '.', '');
        $timestamp = gmdate("Y-m-d\TH:i:s.v\Z");

        $payload = $clientId.$timestamp.$amount.$invoice->ref;
        $signature = $this->sign($payload, $secret);

        $headers = [
            "Content-Type: application/json",
            "X-Client-Id: $clientId",
            "X-Timestamp: $timestamp",
            "X-Signature: $signature",
            "X-Extension-Code: ".self::EXTENSION_CODE
        ];

        return $this->call(self::RTP_API, $headers, json_encode([
            "amount" => (float)$amount,
            "invoiceId" => $invoice->ref,
            "paymentType" => "INST",
            "description" => "Invoice ".$invoice->ref
        ]));
    }

    // ======================================================
    // 🔽 ADDITION START — CREATE INVOICE (ODOO EQUIVALENT)
    // ======================================================
    private function createInvoice($invoice, $clientId, $secret, $accessLogId)
    {
        $items = [];
        foreach ($invoice->lines as $line) {
            $items[] = [
                "name" => $line->desc,
                "description" => $line->desc,
                "unitPrice" => (float)$line->subprice,
                "quantity" => (float)$line->qty,
                "totalPrice" => (float)$line->total_ht,
            ];
        }

        $amount = number_format($invoice->total_ttc, 2, '.', '');
        $timestamp = gmdate("Y-m-d\TH:i:s.v\Z");

        $payload = $clientId.$timestamp.$amount.$invoice->ref;
        $signature = $this->sign($payload, $secret);

        $headers = [
            "Content-Type: application/json",
            "X-Client-Id: $clientId",
            "X-Timestamp: $timestamp",
            "X-Signature: $signature",
            "X-Extension-Code: ".self::EXTENSION_CODE
        ];

        return $this->call(self::INVOICE_API, $headers, json_encode([
            "externalInvoiceId" => $invoice->ref,
            "invoiceNumber" => $invoice->ref,
            "totalAmount" => (float)$invoice->total_ttc,
            "taxAmount" => (float)$invoice->total_tva,
            "feeAmount" => 0,
            "currency" => $invoice->multicurrency_code ?: "EUR",
            "description" => "Dolibarr invoice ".$invoice->ref,
            "invoiceDate" => $timestamp,
            "accessLogId" => $accessLogId,
            "items" => $items
        ]));
    }
    // ======================================================
    // 🔼 ADDITION END
    // ======================================================

    // ======================================================
    // 🔽 ADDITION — ATTACH PDF
    // ======================================================
    private function attachInvoicePdf($invoice, $backendInvoiceId, $clientId, $secret)
    {
        $pdfPath = DOL_DATA_ROOT."/facture/".$invoice->ref."/".$invoice->ref.".pdf";
        if (!file_exists($pdfPath)) {
            throw new Exception("Invoice PDF not found");
        }

        $pdfBase64 = base64_encode(file_get_contents($pdfPath));
        $amount = number_format($invoice->total_ttc, 2, '.', '');
        $timestamp = gmdate("Y-m-d\TH:i:s.v\Z");

        $payload = $clientId.$timestamp.$amount.$invoice->ref;
        $signature = $this->sign($payload, $secret);

        $headers = [
            "Content-Type: application/json",
            "X-Client-Id: $clientId",
            "X-Timestamp: $timestamp",
            "X-Signature: $signature",
            "X-Extension-Code: ".self::EXTENSION_CODE
        ];

        $this->call(
            self::INVOICE_API."/".$backendInvoiceId."/pdf",
            $headers,
            json_encode([
                "fileName" => $invoice->ref.".pdf",
                "base64Pdf" => $pdfBase64
            ])
        );
    }

    // ======================================================
    // 🔽 ADDITION — SYNC STATUS
    // ======================================================
   private function syncPaymentStatus($invoice, $clientId, $secret)
    {
        global $db;

        // FORCE LOAD orderId from DB
        $sql = "SELECT lodinpay_order_id
                FROM ".MAIN_DB_PREFIX."facture
                WHERE rowid = ".((int)$invoice->id);

        $res = $db->query($sql);
        $row = $db->fetch_object($res);

        if (empty($row->lodinpay_order_id)) {
            throw new Exception("Missing LodinPay orderId");
        }

        $orderId = $row->lodinpay_order_id;

        // ODOO-COMPATIBLE TIMESTAMP (REAL milliseconds)
        $micro = microtime(true);
        $dt = DateTime::createFromFormat('U.u', sprintf('%.6f', $micro));
        $dt->setTimezone(new DateTimeZone('UTC'));
        $timestamp = $dt->format("Y-m-d\TH:i:s.v\Z");

        // EXACT payload expected by backend
        $payload = $clientId.$timestamp."0.00".$orderId;
        $signature = $this->sign($payload, $secret);

        dol_syslog("LODINGPAY SYNC PAYLOAD=".$payload, LOG_DEBUG);
        dol_syslog("LODINGPAY SYNC SIGNATURE=".$signature, LOG_DEBUG);

        return $this->call(
            self::RTP_STATUS_API,
            [
                "Content-Type: application/json",
                "X-Client-Id: $clientId",
                "X-Timestamp: $timestamp",
                "X-Signature: $signature",
                "X-Extension-Code: ".self::EXTENSION_CODE
            ],
            json_encode([
                "orderId" => $orderId
            ])
        );
    }

    // ======================================================
    // SHARED
    // ======================================================
    private function call($url, $headers, $body)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body
        ]);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!in_array($code, [200, 201])) {
            throw new Exception($res);
        }

        return json_decode($res, true);
    }

    private function sign($payload, $secret)
    {
        return rtrim(strtr(
            base64_encode(hash_hmac("sha256", $payload, $secret, true)),
            "+/", "-_"
        ), "=");
    }
    
    private function markInvoicePaid($invoice)
    {
        global $user;

        // Already paid → do nothing
        if ((int)$invoice->paye === 1) {
            dol_syslog("LODINGPAY invoice already PAID ".$invoice->ref, LOG_INFO);
            return;
        }

        // ✅ OFFICIAL Dolibarr API (19.x compatible)
        $res = $invoice->setPaid($user);

        if ($res <= 0) {
            throw new Exception(
                "Failed to mark invoice as paid: ".$invoice->error
            );
        }

        dol_syslog(
            "LODINGPAY invoice ".$invoice->ref." successfully marked as PAID",
            LOG_INFO
        );
    }
public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
    {
        global $db;

        // Only invoices
        if (!is_object($object) || $object->element !== 'facture') {
            return 0;
        }

        // Read RTP link DIRECTLY from llx_facture
        $sql = "SELECT lodinpay_payment_link
                FROM ".MAIN_DB_PREFIX."facture
                WHERE rowid = ".((int) $object->id);

        $resql = $db->query($sql);
        if ($resql && ($row = $db->fetch_object($resql))) {

            if (!empty($row->lodinpay_payment_link)) {

                // Inject into public note (PDF-safe)
                $object->note_public .= "\n\n"
                    ."LodinPay payment link:\n"
                    .$row->lodinpay_payment_link;
            }
        }

        return 0;
    }
   
}