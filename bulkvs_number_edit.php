<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2025
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (!permission_exists('bulkvs_edit')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//get http variables
	$tn = $_GET['tn'] ?? '';
	$lidb = $_POST['lidb'] ?? '';
	$portout_pin = $_POST['portout_pin'] ?? '';
	$notes = $_POST['notes'] ?? '';
	$sms = isset($_POST['sms']) && $_POST['sms'] == '1' ? true : false;
	$mms = isset($_POST['mms']) && $_POST['mms'] == '1' ? true : false;

//process form submission
	if (!empty($_POST['action']) && $_POST['action'] == 'save' && !empty($tn)) {
		$lidb = isset($_POST['lidb']) ? $_POST['lidb'] : null;
		// Validate and sanitize LIDB: uppercase, alphanumeric only, max 15 characters
		if ($lidb !== null) {
			$lidb = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($lidb)));
			$lidb = substr($lidb, 0, 15);
			if (empty($lidb)) {
				$lidb = null; // Convert empty string back to null
			}
		}
		$portout_pin = isset($_POST['portout_pin']) ? $_POST['portout_pin'] : null;
		$notes = isset($_POST['notes']) ? $_POST['notes'] : null;
		// Always send SMS/MMS values (true/false) when form is submitted so we can enable/disable them
		$sms = isset($_POST['sms']) && $_POST['sms'] == '1';
		$mms = isset($_POST['mms']) && $_POST['mms'] == '1';

		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			// Always pass SMS/MMS (not null) so API can update them
			$bulkvs_api->updateNumber($tn, $lidb, $portout_pin, $notes, $sms, $mms);
			
			message::add($text['message-update']);
			header("Location: bulkvs_numbers.php");
			return;
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
	}

//get current number details from API
	$current_lidb = '';
	$current_portout_pin = '';
	$current_notes = '';
	$current_sms = false;
	$current_mms = false;
	if (!empty($tn)) {
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$number = $bulkvs_api->getNumber($tn);
			
			// Extract fields from API response
			$current_lidb_raw = $number['Lidb'] ?? $number['lidb'] ?? '';
			// Sanitize LIDB from API: uppercase, alphanumeric only, max 15 characters
			$current_lidb = preg_replace('/[^A-Z0-9]/', '', strtoupper($current_lidb_raw));
			$current_lidb = substr($current_lidb, 0, 15);
			$current_portout_pin = $number['Portout Pin'] ?? $number['portoutPin'] ?? '';
			$current_notes = $number['ReferenceID'] ?? $number['referenceID'] ?? '';
			
			// Extract SMS/MMS from nested Messaging object
			if (isset($number['Messaging']) && is_array($number['Messaging'])) {
				$messaging = $number['Messaging'];
				$current_sms = isset($messaging['Sms']) ? (bool)$messaging['Sms'] : false;
				$current_mms = isset($messaging['Mms']) ? (bool)$messaging['Mms'] : false;
			}
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
	}

//set default values (use POST values if set, otherwise use current values)
	if (empty($_POST['action']) || $_POST['action'] != 'save') {
		$lidb = $current_lidb;
		// Ensure LIDB is uppercase and sanitized for display
		if (!empty($lidb)) {
			$lidb = preg_replace('/[^A-Z0-9]/', '', strtoupper($lidb));
			$lidb = substr($lidb, 0, 15);
		}
		$portout_pin = $current_portout_pin;
		$notes = $current_notes;
		$sms = $current_sms;
		$mms = $current_mms;
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-bulkvs-number-edit'];
	require_once "resources/header.php";

//show the content
	echo "<form name='frm' id='frm' method='post' action=''>\n";
	echo "<input type='hidden' name='action' value='save'>\n";
	echo "<input type='hidden' name='tn' value='".escape($tn)."'>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-bulkvs-number-edit']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'bulkvs_numbers.php']);
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<div class='card'>\n";
	echo "	<div class='subheading'>".$text['label-telephone-number'].": ".escape($tn)."</div>\n";
	echo "</div>\n";
	echo "<br />\n";

	echo "<div class='card'>\n";
	echo "	<div class='subheading'>".$text['title-bulkvs-number-edit']."</div>\n";
	echo "	<div class='content'>\n";
	echo "		<table class='no_hover'>\n";
	echo "			<tr>\n";
	echo "				<td class='vncell' style='vertical-align: top;'>".$text['label-lidb']."</td>\n";
	echo "				<td class='vtable'><input type='text' class='formfld' name='lidb' id='lidb' value='".escape($lidb)."' maxlength='15' pattern='[A-Z0-9]{0,15}' title='Up to 15 alphanumeric characters (letters will be converted to uppercase)' oninput=\"this.value = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();\"></td>\n";
	echo "			</tr>\n";
	echo "			<tr>\n";
	echo "				<td class='vncell' style='vertical-align: top;'>".$text['label-portout-pin']."</td>\n";
	echo "				<td class='vtable'><input type='text' class='formfld' name='portout_pin' value='".escape($portout_pin)."' maxlength='10'></td>\n";
	echo "			</tr>\n";
	echo "			<tr>\n";
	echo "				<td class='vncell' style='vertical-align: top;'>".$text['label-notes']."</td>\n";
	echo "				<td class='vtable'><input type='text' class='formfld' name='notes' value='".escape($notes)."' maxlength='255'></td>\n";
	echo "			</tr>\n";
	echo "			<tr>\n";
	echo "				<td class='vncell' style='vertical-align: top;'>".$text['label-sms']."</td>\n";
	echo "				<td class='vtable'>\n";
	echo "					<select class='formfld' name='sms'>\n";
	echo "						<option value='0'".($sms ? '' : " selected").">".$text['label-disabled']."</option>\n";
	echo "						<option value='1'".($sms ? " selected" : '').">".$text['label-enabled']."</option>\n";
	echo "					</select>\n";
	echo "				</td>\n";
	echo "			</tr>\n";
	echo "			<tr>\n";
	echo "				<td class='vncell' style='vertical-align: top;'>".$text['label-mms']."</td>\n";
	echo "				<td class='vtable'>\n";
	echo "					<select class='formfld' name='mms'>\n";
	echo "						<option value='0'".($mms ? '' : " selected").">".$text['label-disabled']."</option>\n";
	echo "						<option value='1'".($mms ? " selected" : '').">".$text['label-enabled']."</option>\n";
	echo "					</select>\n";
	echo "				</td>\n";
	echo "			</tr>\n";
	echo "		</table>\n";
	echo "	</div>\n";
	echo "</div>\n";
	echo "<br />\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>

