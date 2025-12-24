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
	if (!permission_exists('bulkvs_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//get HTTP secret from settings
	$http_secret = $settings->get('bulkvs', 'http_secret', '');

//process form submission
	$phone_number = $_POST['phone_number'] ?? $_GET['phone_number'] ?? '';
	$lrn_result = null;
	$error_message = '';

	if (!empty($phone_number)) {
		if (empty($http_secret)) {
			$error_message = "HTTP secret not configured";
		} else {
			try {
				// Clean phone number (remove non-numeric characters except +)
				$phone_clean = preg_replace('/[^0-9+]/', '', $phone_number);
				// Remove leading + if present
				$phone_clean = ltrim($phone_clean, '+');
				// Remove leading 1 if present (US country code)
				if (strlen($phone_clean) == 11 && substr($phone_clean, 0, 1) == '1') {
					$phone_clean = substr($phone_clean, 1);
				}
				
				if (strlen($phone_clean) != 10) {
					throw new Exception("Phone number must be 10 digits");
				}
				
				// Build LRN lookup URL
				$lrn_url = "http://lrn.bulkvs.com/?id=" . urlencode($http_secret) . "&did=" . urlencode($phone_clean) . "&ani=" . urlencode($phone_clean) . "&format=json";
				
				// Make the API request
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $lrn_url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
				curl_setopt($ch, CURLOPT_TIMEOUT, 60);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
				
				$response = curl_exec($ch);
				$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$curl_error = curl_error($ch);
				curl_close($ch);
				
				if ($curl_error) {
					throw new Exception("CURL Error: " . $curl_error);
				}
				
				if ($http_code >= 400) {
					throw new Exception("HTTP Error: " . $http_code);
				}
				
				// Parse JSON response
				$lrn_result = json_decode($response, true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					throw new Exception("Invalid JSON response: " . json_last_error_msg());
				}
				
			} catch (Exception $e) {
				$error_message = $e->getMessage();
				message::add($text['message-lrn-error'] . ': ' . $error_message, 'negative');
			}
		}
	}

//include the header
	$document['title'] = $text['title-bulkvs-lrn'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-bulkvs-lrn']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'bulkvs_numbers.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";
	
	echo "<div class='card'>\n";
	echo "	<div class='subheading'>".$text['description-lrn-lookup']."</div>\n";
	echo "	<form method='post' action=''>\n";
	echo "		<table class='list'>\n";
	echo "			<tr>\n";
	echo "				<td class='vncell' style='width: 200px;'>".$text['label-telephone-number']."</td>\n";
	echo "				<td class='vtable'>\n";
	echo "					<input type='text' name='phone_number' class='formfld' value='".escape($phone_number)."' placeholder='e.g., 7174882203' style='width: 200px;'>\n";
	echo "					<input type='submit' class='btn' value='".$text['button-submit']."'>\n";
	echo "				</td>\n";
	echo "			</tr>\n";
	echo "		</table>\n";
	echo "	</form>\n";
	echo "</div>\n";
	echo "<br />\n";
	
	// Display results
	if (!empty($lrn_result)) {
		echo "<div class='card'>\n";
		echo "	<div class='subheading'>LRN Lookup Results</div>\n";
		echo "	<div style='padding: 15px;'>\n";
		echo "		<pre style='background-color: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto;'>".escape(json_encode($lrn_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))."</pre>\n";
		echo "	</div>\n";
		echo "</div>\n";
		echo "<br />\n";
	}
	
	if (!empty($error_message) && empty($lrn_result)) {
		echo "<div class='alert alert-warning'>".escape($error_message)."</div>\n";
		echo "<br />\n";
	}

//include the footer
	require_once "resources/footer.php";

?>

