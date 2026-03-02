<?php
$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = @include '../../main.inc.php';
if (!$res && file_exists("../../../main.inc.php")) $res = @include '../../../main.inc.php';
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$langs->load("admin");

if (!$user->admin) accessforbidden();

// =======================
// SAVE CONFIG
// =======================
if (GETPOST('action', 'alpha') == 'save') {

    // 🔐 CSRF check
    if (!GETPOST('token')) accessforbidden('Missing token');

    dolibarr_set_const($db, "LODINPAY_CLIENT_ID", GETPOST('LODINPAY_CLIENT_ID', 'alpha'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, "LODINPAY_CLIENT_SECRET", GETPOST('LODINPAY_CLIENT_SECRET', 'alpha'), 'chaine', 0, '', $conf->entity);

    setEventMessage("Configuration saved");
}

// =======================
// READ CONFIG SAFELY
// =======================
$clientId = !empty($conf->global->LODINPAY_CLIENT_ID) ? $conf->global->LODINPAY_CLIENT_ID : '';
$clientSecret = !empty($conf->global->LODINPAY_CLIENT_SECRET) ? $conf->global->LODINPAY_CLIENT_SECRET : '';

// =======================
// UI
// =======================
llxHeader('', 'LodinPay Setup');

print load_fiche_titre("LodinPay Configuration");

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="action" value="save">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print '<div class="fichecenter">';
print '<div class="fichethirdleft">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">LodinPay Settings</td>';
print '</tr>';

// Client ID
print '<tr>';
print '<td class="titlefield">Client ID</td>';
print '<td>';
print '<input type="text" class="minwidth300" name="LODINPAY_CLIENT_ID" value="'.dol_escape_htmltag($clientId).'">';
print '</td>';
print '</tr>';

// Client Secret
print '<tr>';
print '<td>Client Secret</td>';
print '<td>';
print '<input type="password" class="minwidth300" name="LODINPAY_CLIENT_SECRET" value="'.dol_escape_htmltag($clientSecret).'">';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';
print '</div>';

print '<div class="center">';
print '<input type="submit" class="button button-save" value="Save">';
print '</div>';

print '</form>';

llxFooter();
$db->close();
