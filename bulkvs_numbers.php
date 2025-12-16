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
	require_once dirname(__DIR__, 2) . "/resources/paging.php";

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

//get trunk group from settings
	$trunk_group = $settings->get('bulkvs', 'trunk_group', '');

//get numbers from BulkVS API
	$numbers = [];
	$error_message = '';
	try {
		require_once "resources/classes/bulkvs_api.php";
		$bulkvs_api = new bulkvs_api($settings);
		$api_response = $bulkvs_api->getNumbers($trunk_group);
		
		// Handle API response - it may be an array of numbers or an object with a data property
		if (isset($api_response['data']) && is_array($api_response['data'])) {
			$numbers = $api_response['data'];
		} elseif (is_array($api_response)) {
			$numbers = $api_response;
		}
		
		// Filter out empty/invalid entries
		$numbers = array_filter($numbers, function($number) {
			$tn = $number['tn'] ?? $number['TN'] ?? $number['telephoneNumber'] ?? '';
			return !empty($tn);
		});
		$numbers = array_values($numbers); // Re-index array
	} catch (Exception $e) {
		$error_message = $e->getMessage();
		message::add($text['message-api-error'] . ': ' . $error_message, 'negative');
	}

//prepare to page the results
	$num_rows = count($numbers);
	$rows_per_page = $settings->get('domain', 'paging', 50);
	$param = "";
	if (!empty($_GET['page'])) {
		$page = $_GET['page'];
	}
	if (!isset($page)) { $page = 0; $_GET['page'] = 0; }
	[$paging_controls, $rows_per_page] = paging($num_rows, $param, $rows_per_page);
	[$paging_controls_mini, $rows_per_page] = paging($num_rows, $param, $rows_per_page, true);
	$offset = $rows_per_page * $page;
	
	// Slice the results array for pagination
	$paginated_numbers = [];
	if (!empty($numbers)) {
		$paginated_numbers = array_slice($numbers, $offset, $rows_per_page);
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-bulkvs-numbers'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-bulkvs-numbers']."</b>";
	if ($num_rows > 0) {
		echo "<div class='count'>".number_format($num_rows)."</div>";
	}
	echo "</div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('bulkvs_search')) {
		echo button::create(['type'=>'button','label'=>$text['title-bulkvs-search'],'icon'=>'search','link'=>'bulkvs_search.php']);
	}
	if (!empty($paginated_numbers)) {
		echo "		<input type='text' id='table_filter' class='txt list-search' placeholder='Filter results...' style='margin-left: 15px; width: 200px;' onkeyup='filterTable()'>";
		echo "		<span id='filter_count' style='margin-left: 5px; color: #666; font-size: 12px;'></span>";
	}
	if ($paging_controls_mini != '') {
		echo "<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if (!empty($trunk_group)) {
		echo "<div class='card'>\n";
		echo "	<div class='subheading'>".$text['label-trunk-group'].": ".escape($trunk_group)."</div>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

	echo $text['description-bulkvs-numbers']."\n";
	echo "<br /><br />\n";

	if (!empty($error_message)) {
		echo "<div class='alert alert-warning'>".escape($error_message)."</div>\n";
		echo "<br />\n";
	}

	if (!empty($paginated_numbers)) {
		echo "<div class='card'>\n";
		echo "<table class='list' id='numbers_table'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".$text['label-telephone-number']."</th>\n";
		echo "	<th>".$text['label-trunk-group']."</th>\n";
		echo "	<th>".$text['label-portout-pin']."</th>\n";
		echo "	<th>".$text['label-cnam']."</th>\n";
		if (permission_exists('bulkvs_edit')) {
			echo "	<td class='action-button'>&nbsp;</td>\n";
		}
		echo "</tr>\n";

		foreach ($paginated_numbers as $number) {
			// Try multiple possible field name variations from the API
			$tn = $number['tn'] ?? $number['TN'] ?? $number['telephoneNumber'] ?? '';
			$tg = $number['trunkGroup'] ?? $number['Trunk Group'] ?? '';
			$portout_pin = $number['portoutPin'] ?? $number['Portout PIN'] ?? '';
			$cnam = $number['cnam'] ?? $number['CNAM'] ?? '';
			
			// Skip rows with no telephone number
			if (empty($tn)) {
				continue;
			}

			//create the row link
			$list_row_url = '';
			if (permission_exists('bulkvs_edit')) {
				$list_row_url = "bulkvs_number_edit.php?tn=".urlencode($tn);
			}

			//show the data
			echo "<tr class='list-row'".(!empty($list_row_url) ? " href='".$list_row_url."'" : "").">\n";
			echo "	<td class='no-wrap'>";
			if (permission_exists('bulkvs_edit')) {
				echo "		<a href='".$list_row_url."'>".escape($tn)."</a>\n";
			} else {
				echo "		".escape($tn);
			}
			echo "	</td>\n";
			echo "	<td>".escape($tg)."&nbsp;</td>\n";
			echo "	<td>".escape($portout_pin)."&nbsp;</td>\n";
			echo "	<td>".escape($cnam)."&nbsp;</td>\n";
			if (permission_exists('bulkvs_edit')) {
				echo "	<td class='action-button'>";
				echo button::create(['type'=>'button','title'=>$text['button-edit'],'icon'=>$settings->get('theme', 'button_icon_edit'),'link'=>$list_row_url]);
				echo "	</td>\n";
			}
			echo "</tr>\n";
		}

		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
		if ($paging_controls != '') {
			echo "<div align='center'>".$paging_controls."</div>\n";
		}
	} else {
		if (empty($error_message)) {
			echo "<div class='card'>\n";
			echo "	<div class='subheading'>".$text['message-no-numbers']."</div>\n";
			echo "</div>\n";
		}
	}

	echo "<br />\n";

//add client-side table filtering script
	if (!empty($paginated_numbers)) {
		$total_on_page = count($paginated_numbers);
		echo "<script>\n";
		echo "var totalRows = ".$total_on_page.";\n";
		echo "function filterTable() {\n";
		echo "	var input = document.getElementById('table_filter');\n";
		echo "	var filter = input.value.toLowerCase();\n";
		echo "	var table = document.getElementById('numbers_table');\n";
		echo "	var tr = table.getElementsByTagName('tr');\n";
		echo "	var visibleCount = 0;\n";
		echo "	\n";
		echo "	// Start from index 1 to skip header row\n";
		echo "	for (var i = 1; i < tr.length; i++) {\n";
		echo "		var td = tr[i].getElementsByTagName('td');\n";
		echo "		var found = false;\n";
		echo "		\n";
		echo "		// Check each cell in the row (skip last column if it's action button)\n";
		echo "		for (var j = 0; j < td.length - 1; j++) {\n";
		echo "			if (td[j]) {\n";
		echo "				var txtValue = td[j].textContent || td[j].innerText;\n";
		echo "				if (txtValue.toLowerCase().indexOf(filter) > -1) {\n";
		echo "					found = true;\n";
		echo "					break;\n";
		echo "				}\n";
		echo "			}\n";
		echo "		}\n";
		echo "		\n";
		echo "		if (found) {\n";
		echo "			tr[i].style.display = '';\n";
		echo "			visibleCount++;\n";
		echo "		} else {\n";
		echo "			tr[i].style.display = 'none';\n";
		echo "		}\n";
		echo "	}\n";
		echo "	\n";
		echo "	// Update filter count\n";
		echo "	var countElement = document.getElementById('filter_count');\n";
		echo "	if (filter === '') {\n";
		echo "		countElement.textContent = '';\n";
		echo "	} else {\n";
		echo "		countElement.textContent = '(' + visibleCount + '/' + totalRows + ')';\n";
		echo "	}\n";
		echo "}\n";
		echo "// Initialize count on page load\n";
		echo "document.addEventListener('DOMContentLoaded', function() {\n";
		echo "	filterTable();\n";
		echo "});\n";
		echo "</script>\n";
	}

//include the footer
	require_once "resources/footer.php";

?>

