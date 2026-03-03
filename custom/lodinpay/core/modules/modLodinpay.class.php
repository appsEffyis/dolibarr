<?php

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modLodinpay extends DolibarrModules
{
    public function __construct($db)
    {
        global $conf;

        $this->db = $db;

        // Unique ID from Dolibarr marketplace range
        $this->numero        = 500001;

        $this->rights_class  = 'lodinpay';
        $this->family        = 'financial';
        $this->module_position = 500;

        $this->name          = 'LodinPay';
        $this->description   = 'Generate instant payment links on invoices using LodinPay. Customers can pay instantly with 20+ European banks.';
        $this->version       = '1.0.0';
        $this->const_name    = 'MAIN_MODULE_LODINPAY';

        $this->editor_name   = 'LodinPay';
        $this->editor_url    = 'https://www.lodinpay.io';

        $this->depends = array('modFacture');
       // $this->dirs    = array('/lodinpay');

        $this->phpmin  = array(7, 4);
        $this->need_dolibarr_version = array(17, 0);

        $this->config_page_url = array("setup.php@lodinpay");

        $this->dirs = [
            '/lodinpay',              // pour SQL (init)
            ['/facture/doc', 0, 1, '', '', 1],  // pour modèles PDF
        ];

        $this->module_parts = [
            'hooks' => [
                'invoicecard',
                'beforePDFCreation',
                'afterPDFCreation',
            ],
            'models' => 1,   
        ];



        $this->const = [
            [
                'FACTURE_ADDON_PDF',   // nom de la constante
                'chaine',              // type
                'lodinpay',            // valeur
                'Default PDF model set by LodinPay',  // note
                1,                     // visible
                'allentities',         // entity
                1,                     // deleteOnUninstall (remet à vide à la désactivation)
            ],
        ];  


        $this->picto = 'payment';

        // Rights
        $this->rights = array();
        $r = 0;
        $this->rights[$r][0] = 500001;
        $this->rights[$r][1] = 'Use LodinPay';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'use';
        $r++;

        $this->menu = array();
    }

    public function init($options = '')
    {
        // ✅ Chemin correct vers le SQL
        $result = $this->_load_tables('/lodinpay/sql/');
        if ($result < 0) return -1;

        // ✅ Insérer le modèle dans llx_document_model si absent
        $this->_registerPDFModel();

        return parent::init($options);
    }

    public function remove($options = '')
    {
        // Restaurer sponge comme modèle par défaut à la désactivation
        $this->_restoreDefaultPDFModel();
        return parent::remove($options);
    }

    // ======================================================
    // Enregistre le modèle lodinpay dans llx_document_model
    // ======================================================
    private function _registerPDFModel()
    {
        global $conf;

        $entity = (int)($conf->entity ?? 1);

        // Vérifier si déjà enregistré
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."document_model
                WHERE nom = 'lodinpay'
                AND type = 'invoice'
                AND entity = ".$entity;

        $res = $this->db->query($sql);

        if ($res && $this->db->num_rows($res) === 0) {
            $sqlInsert = "INSERT INTO ".MAIN_DB_PREFIX."document_model
                          (nom, type, entity)
                          VALUES ('lodinpay', 'invoice', ".$entity.")";
            $this->db->query($sqlInsert);
            dol_syslog("LODINPAY: model registered in document_model", LOG_INFO);
        }
    }

    // ======================================================
    // Restaure sponge à la désactivation
    // ======================================================
    private function _restoreDefaultPDFModel()
    {
        global $conf;

        $entity = (int)($conf->entity ?? 1);

        // Remettre sponge comme défaut
        $this->db->query(
            "DELETE FROM ".MAIN_DB_PREFIX."const
             WHERE name = 'FACTURE_ADDON_PDF'
             AND entity = ".$entity
        );

        $this->db->query(
            "INSERT INTO ".MAIN_DB_PREFIX."const
             (name, value, type, visible, note, entity)
             VALUES ('FACTURE_ADDON_PDF', 'sponge', 'chaine', 0, 'Restored by LodinPay uninstall', ".$entity.")"
        );

        dol_syslog("LODINPAY: PDF model restored to sponge", LOG_INFO);
    }
}
