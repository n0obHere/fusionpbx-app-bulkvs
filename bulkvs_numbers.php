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
			$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
			return !empty($tn);
		});
		$numbers = array_values($numbers); // Re-index array
	} catch (Exception $e) {
		$error_message = $e->getMessage();
		message::add($text['message-api-error'] . ': ' . $error_message, 'negative');
	}

//get filter parameter
	$filter = $_GET['filter'] ?? $_POST['filter'] ?? '';

//apply server-side filter to all numbers
	if (!empty($filter)) {
		$filter_lower = strtolower($filter);
		$numbers = array_filter($numbers, function($number) use ($filter_lower) {
			$tn = strtolower($number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '');
			$status = strtolower($number['Status'] ?? $number['status'] ?? '');
			$rate_center = '';
			$tier = '';
			$lidb = strtolower($number['Lidb'] ?? $number['lidb'] ?? '');
			$notes = strtolower($number['ReferenceID'] ?? $number['referenceID'] ?? '');
			
			// Extract nested fields
			if (isset($number['TN Details']) && is_array($number['TN Details'])) {
				$tn_details = $number['TN Details'];
				$rate_center = strtolower($tn_details['Rate Center'] ?? $tn_details['rate_center'] ?? '');
				$tier = strtolower($tn_details['Tier'] ?? $tn_details['tier'] ?? '');
			}
			
			// Check if filter matches any field
			return (
				strpos($tn, $filter_lower) !== false ||
				strpos($status, $filter_lower) !== false ||
				strpos($rate_center, $filter_lower) !== false ||
				strpos($tier, $filter_lower) !== false ||
				strpos($lidb, $filter_lower) !== false ||
				strpos($notes, $filter_lower) !== false
			);
		});
		$numbers = array_values($numbers); // Re-index array
	}

//prepare to page the results
	$num_rows = count($numbers);
	$rows_per_page = $settings->get('domain', 'paging', 50);
	$param = "";
	if (!empty($filter)) {
		$param = "&filter=".urlencode($filter);
	}
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

//build domain lookup map for paginated numbers
	$domain_map = [];
	if (!empty($paginated_numbers)) {
		// Extract 10-digit numbers from BulkVS 11-digit numbers
		$tn_10_digit = [];
		foreach ($paginated_numbers as $number) {
			$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
			if (!empty($tn)) {
				// Convert 11-digit to 10-digit (remove leading "1")
				$tn_10 = preg_replace('/^1/', '', $tn);
				if (strlen($tn_10) == 10) {
					$tn_10_digit[] = $tn_10;
				}
			}
		}
		
		// Query destinations for these numbers
		if (!empty($tn_10_digit)) {
			$placeholders = [];
			$parameters = [];
			foreach ($tn_10_digit as $index => $tn_10) {
				$placeholders[] = ':tn_' . $index;
				$parameters['tn_' . $index] = $tn_10;
			}
			
			$sql = "select distinct destination_number, domain_uuid, destination_uuid ";
			$sql .= "from v_destinations ";
			$sql .= "where destination_number in (" . implode(', ', $placeholders) . ") ";
			$sql .= "and destination_enabled = 'true' ";
			$destinations = $database->select($sql, $parameters, 'all');
			unset($sql, $parameters);
			
			// Build map of destination_number -> domain_uuid and destination_uuid
			$domain_uuids = [];
			$destination_uuids = [];
			foreach ($destinations as $dest) {
				$dest_number = $dest['destination_number'] ?? '';
				$dest_domain_uuid = $dest['domain_uuid'] ?? '';
				$dest_uuid = $dest['destination_uuid'] ?? '';
				if (!empty($dest_number) && !empty($dest_domain_uuid)) {
					// Store domain_uuid and destination_uuid for this number (use first match if multiple)
					if (!isset($domain_uuids[$dest_number])) {
						$domain_uuids[$dest_number] = $dest_domain_uuid;
						if (!empty($dest_uuid)) {
							$destination_uuids[$dest_number] = $dest_uuid;
						}
					}
				}
			}
			
			// Query domain names for unique domain_uuids
			if (!empty($domain_uuids)) {
				$unique_domain_uuids = array_unique(array_values($domain_uuids));
				$placeholders = [];
				$parameters = [];
				foreach ($unique_domain_uuids as $index => $domain_uuid) {
					$placeholders[] = ':domain_uuid_' . $index;
					$parameters['domain_uuid_' . $index] = $domain_uuid;
				}
				
				$sql = "select domain_uuid, domain_name ";
				$sql .= "from v_domains ";
				$sql .= "where domain_uuid in (" . implode(', ', $placeholders) . ") ";
				$domains = $database->select($sql, $parameters, 'all');
				unset($sql, $parameters);
				
				// Build map of domain_uuid -> domain_name
				$domain_names = [];
				foreach ($domains as $domain) {
					$domain_uuid = $domain['domain_uuid'] ?? '';
					$domain_name = $domain['domain_name'] ?? '';
					if (!empty($domain_uuid)) {
						$domain_names[$domain_uuid] = $domain_name;
					}
				}
				
				// Build final map: destination_number -> ['domain_name' => ..., 'destination_uuid' => ...]
				foreach ($domain_uuids as $dest_number => $domain_uuid) {
					if (isset($domain_names[$domain_uuid])) {
						$domain_map[$dest_number] = [
							'domain_name' => $domain_names[$domain_uuid],
							'destination_uuid' => $destination_uuids[$dest_number] ?? ''
						];
					}
				}
			}
		}
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
	if (!empty($numbers)) {
		echo "		<form method='get' action='' style='display: inline; margin-left: 15px;'>\n";
		echo "			<input type='text' name='filter' class='txt list-search' placeholder='Filter results...' value='".escape($filter)."' style='width: 200px;'>\n";
		echo "			<input type='submit' class='btn' value='Filter' style='margin-left: 5px;'>\n";
		if (!empty($filter)) {
			echo "			<a href='bulkvs_numbers.php' class='btn' style='margin-left: 5px;'>Clear</a>\n";
		}
		echo "		</form>\n";
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
		echo "	<th>".$text['label-status']."</th>\n";
		echo "	<th>".$text['label-activation-date']."</th>\n";
		echo "	<th>".$text['label-rate-center']."</th>\n";
		echo "	<th>".$text['label-tier']."</th>\n";
		echo "	<th>".$text['label-lidb']."</th>\n";
		echo "	<th>".$text['label-notes']."</th>\n";
		echo "	<th>".$text['label-domain']."</th>\n";
		echo "</tr>\n";

		foreach ($paginated_numbers as $number) {
			// Extract fields from API response (handling nested structures)
			$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
			$status = $number['Status'] ?? $number['status'] ?? '';
			$activation_date = '';
			$rate_center = '';
			$tier = '';
			$lidb = '';
			$notes = '';
			
			// Extract nested fields
			if (isset($number['TN Details']) && is_array($number['TN Details'])) {
				$tn_details = $number['TN Details'];
				$activation_date = $tn_details['Activation Date'] ?? $tn_details['activation_date'] ?? '';
				$rate_center = $tn_details['Rate Center'] ?? $tn_details['rate_center'] ?? '';
				$tier = $tn_details['Tier'] ?? $tn_details['tier'] ?? '';
			}
			
			// LIDB is at top level
			$lidb = $number['Lidb'] ?? $number['lidb'] ?? '';
			
			// Notes (ReferenceID)
			$notes = $number['ReferenceID'] ?? $number['referenceID'] ?? '';
			
			// Skip rows with no telephone number
			if (empty($tn)) {
				continue;
			}
			
			// Format activation date if present
			if (!empty($activation_date)) {
				// Try to format the date nicely
				$date_timestamp = strtotime($activation_date);
				if ($date_timestamp !== false) {
					$activation_date = date('Y-m-d H:i', $date_timestamp);
				}
			}
			
			// Look up domain for this number
			$domain_name = '';
			$destination_uuid = '';
			$domain_info = null;
			if (!empty($tn)) {
				// Convert 11-digit to 10-digit (remove leading "1")
				$tn_10 = preg_replace('/^1/', '', $tn);
				if (strlen($tn_10) == 10 && isset($domain_map[$tn_10])) {
					$domain_info = $domain_map[$tn_10];
					$domain_name = is_array($domain_info) ? ($domain_info['domain_name'] ?? '') : $domain_info;
					$destination_uuid = is_array($domain_info) ? ($domain_info['destination_uuid'] ?? '') : '';
				}
			}
			
			// Create edit URL for the row
			$edit_url = '';
			if (permission_exists('bulkvs_edit')) {
				$edit_url = "bulkvs_number_edit.php?tn=".urlencode($tn);
			}
			
			// Create destination edit URL
			$destination_edit_url = '';
			if (!empty($destination_uuid) && permission_exists('destination_edit')) {
				$destination_edit_url = "../destinations/destination_edit.php?id=".urlencode($destination_uuid);
			}

			//show the data
			echo "<tr class='list-row'".(!empty($edit_url) ? " href='".$edit_url."'" : "").">\n";
			echo "	<td class='no-wrap'>".escape($tn)."</td>\n";
			echo "	<td>".escape($status)."&nbsp;</td>\n";
			echo "	<td class='no-wrap'>".escape($activation_date)."&nbsp;</td>\n";
			echo "	<td>".escape($rate_center)."&nbsp;</td>\n";
			echo "	<td>".escape($tier)."&nbsp;</td>\n";
			echo "	<td>".escape($lidb)."&nbsp;</td>\n";
			echo "	<td>".escape($notes)."&nbsp;</td>\n";
			if (!empty($destination_edit_url)) {
				echo "	<td><a href='".$destination_edit_url."' onclick='event.stopPropagation();'>".escape($domain_name)."</a>&nbsp;</td>\n";
			} else {
				echo "	<td>".escape($domain_name)."&nbsp;</td>\n";
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

//include the footer
	require_once "resources/footer.php";

?>

