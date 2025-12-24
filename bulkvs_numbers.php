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

//process disconnect action
	$action = $_POST['action'] ?? $_GET['action'] ?? '';
	$disconnect_tn = $_POST['disconnect_tn'] ?? $_GET['disconnect_tn'] ?? '';
	
	if ($action == 'disconnect' && !empty($disconnect_tn) && permission_exists('bulkvs_purchase')) {
		// Validate token
		$object = new token;
		if (!$object->validate($_SERVER['PHP_SELF'])) {
			message::add("Invalid token", 'negative');
			header("Location: bulkvs_numbers.php");
			return;
		}
		
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$result = $bulkvs_api->deleteNumber($disconnect_tn);
			
			// Check if delete was successful
			$status = $result['Status'] ?? $result['status'] ?? '';
			if (strtoupper($status) === 'SUCCESS') {
				// Remove from cache
				require_once "resources/classes/bulkvs_cache.php";
				$cache = new bulkvs_cache($database, $settings);
				try {
					$cache->deleteNumber($disconnect_tn);
				} catch (Exception $e) {
					// Ignore cache errors - API delete was successful
					error_log("BulkVS cache delete error: " . $e->getMessage());
				}
				
				message::add($text['message-disconnect-success'], 'positive');
			} else {
				$error_msg = $result['Description'] ?? $result['description'] ?? 'Disconnect failed';
				throw new Exception($error_msg);
			}
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
		
		// Reload the page
		header("Location: bulkvs_numbers.php");
		return;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//get trunk group from settings
	$trunk_group = $settings->get('bulkvs', 'trunk_group', '');

//load cache class
	require_once "resources/classes/bulkvs_cache.php";
	$cache = new bulkvs_cache($database, $settings);

//get numbers from cache (fallback to API if cache is empty)
	$numbers = [];
	$error_message = '';
	$e911_map = []; // Initialize E911 map
	$use_cache = true;
	
	try {
		// Try to load from cache first
		$numbers = $cache->getNumbers($trunk_group);
		
		// If cache is empty, sync from API immediately (synchronous)
		if (empty($numbers)) {
			$use_cache = false;
			// Fetch from API directly for immediate display, then sync in background
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$api_response = $bulkvs_api->getNumbers($trunk_group);
			
			// Handle API response - it may be an array of numbers or an object with a data property
			if (isset($api_response['data']) && is_array($api_response['data'])) {
				$numbers = $api_response['data'];
			} elseif (is_array($api_response)) {
				$numbers = $api_response;
			} else {
				$numbers = [];
			}
			
			// Filter out empty/invalid entries
			$numbers = array_filter($numbers, function($number) {
				$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
				return !empty($tn);
			});
			$numbers = array_values($numbers); // Re-index array
			
			// Now try to sync to cache in background (don't block if it fails)
			try {
				$cache->syncNumbers($trunk_group);
			} catch (Exception $sync_error) {
				error_log("BulkVS background sync failed: " . $sync_error->getMessage());
			}
		}
		
		// Fetch E911 records from cache
		$e911_records = [];
		try {
			$e911_records = $cache->getE911Records();
			
			// If cache is empty, fetch from API directly
			if (empty($e911_records)) {
				require_once "resources/classes/bulkvs_api.php";
				$bulkvs_api = new bulkvs_api($settings);
				$e911_response = $bulkvs_api->getE911Records();
				if (isset($e911_response['data']) && is_array($e911_response['data'])) {
					$e911_records = $e911_response['data'];
				} elseif (is_array($e911_response)) {
					$e911_records = $e911_response;
				}
				
				// Try to sync to cache in background (don't block if it fails)
				try {
					$cache->syncE911();
				} catch (Exception $sync_error) {
					error_log("BulkVS E911 background sync failed: " . $sync_error->getMessage());
				}
			}
			
			// Create a mapping of TN to E911 record
			foreach ($e911_records as $e911_record) {
				$e911_tn = $e911_record['TN'] ?? $e911_record['tn'] ?? '';
				if (!empty($e911_tn)) {
					$e911_map[$e911_tn] = $e911_record;
				}
			}
		} catch (Exception $e) {
			// E911 fetch failed, but don't block the page - just log it
			error_log("BulkVS E911 fetch error: " . $e->getMessage());
		}
	} catch (Exception $e) {
		$error_message = $e->getMessage();
		message::add($text['message-api-error'] . ': ' . $error_message, 'negative');
	}

//check if records have changed (new or deleted)
	$has_changes_numbers = false;
	try {
		$has_changes_numbers = $cache->hasChanges('numbers');
	} catch (Exception $e) {
		// Ignore errors checking for changes
	}

//create token (needed for disconnect action and modals)
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

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
			$sql .= "and destination_type = 'inbound' ";
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
	// Refresh button (hidden by default, shown when new records available)
	$refresh_button_style = $has_changes_numbers ? '' : 'display: none;';
	echo "<span id='refresh_button_container' style='".$refresh_button_style."'>";
	echo button::create(['type'=>'button','label'=>$text['button-refresh'],'icon'=>'refresh','id'=>'btn_refresh','onclick'=>"refreshPage();"]);
	echo "</span>\n";
	if (permission_exists('bulkvs_view')) {
		echo button::create(['type'=>'button','label'=>$text['label-e911'],'icon'=>'phone','link'=>'bulkvs_e911.php']);
	}
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

	// Display description with trunk group name if available
	if (!empty($trunk_group)) {
		echo "View and manage BulkVS phone numbers filtered by trunk group: ".escape($trunk_group)."\n";
	} else {
		echo $text['description-bulkvs-numbers']."\n";
	}
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
		echo "	<th>".$text['label-e911']."</th>\n";
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
			$has_domain_for_display = false;
			if (!empty($destination_uuid) && permission_exists('destination_edit')) {
				$destination_edit_url = "../destinations/destination_edit.php?id=".urlencode($destination_uuid);
				$has_domain_for_display = true;
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
			if ($has_domain_for_display && !empty($domain_name)) {
				echo "	<td class='no-link' style='max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' title='".escape($domain_name)."'>";
				echo button::create(['type'=>'button','class'=>'link','label'=>escape($domain_name),'link'=>$destination_edit_url,'onclick'=>'event.stopPropagation();']);
				echo "&nbsp;</td>\n";
			} else {
				// Show Disconnect button if no domain and user has purchase permission
				if (permission_exists('bulkvs_purchase')) {
					$disconnect_modal_id = 'modal-disconnect-' . preg_replace('/[^0-9]/', '', $tn);
					echo "	<td class='no-link' style='max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'>";
					echo button::create(['type'=>'button','class'=>'link','label'=>$text['label-disconnect'],'onclick'=>"event.stopPropagation(); modal_open('".$disconnect_modal_id."');"]);
					echo "&nbsp;</td>\n";
				} else {
					echo "	<td style='max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'>&nbsp;</td>\n";
				}
			}
			
			// E911 information
			$e911_info = 'None';
			$e911_full_info = '';
			$tn_clean = preg_replace('/[^0-9]/', '', $tn); // Remove non-numeric characters for matching
			if (isset($e911_map[$tn_clean])) {
				$e911_record = $e911_map[$tn_clean];
				$caller_name = $e911_record['Caller Name'] ?? $e911_record['callerName'] ?? '';
				$address_line1 = $e911_record['Address Line 1'] ?? $e911_record['addressLine1'] ?? '';
				$city = $e911_record['City'] ?? $e911_record['city'] ?? '';
				$state = $e911_record['State'] ?? $e911_record['state'] ?? '';
				$zip = $e911_record['Zip'] ?? $e911_record['zip'] ?? '';
				
				// Build E911 display string
				$e911_parts = [];
				if (!empty($caller_name)) {
					$e911_parts[] = $caller_name;
				}
				if (!empty($address_line1)) {
					$e911_parts[] = $address_line1;
				}
				if (!empty($city) || !empty($state) || !empty($zip)) {
					$city_state_zip = trim($city . ', ' . $state . ' ' . $zip, ', ');
					if (!empty($city_state_zip)) {
						$e911_parts[] = $city_state_zip;
					}
				}
				
				if (!empty($e911_parts)) {
					$e911_full_info = implode(', ', $e911_parts);
					$e911_info = $e911_full_info;
				}
			}
			
			// Make E911 cell clickable if permission exists
			$e911_edit_url = '';
			if (permission_exists('bulkvs_edit')) {
				$e911_edit_url = "bulkvs_e911_edit.php?tn=".urlencode($tn);
			}
			
			if (!empty($e911_edit_url)) {
				echo "	<td class='no-link' style='max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' title='".escape($e911_full_info ?: $e911_info)."'>";
				echo button::create(['type'=>'button','class'=>'link','label'=>escape($e911_info),'link'=>$e911_edit_url,'onclick'=>'event.stopPropagation();']);
				echo "&nbsp;</td>\n";
			} else {
				echo "	<td style='max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' title='".escape($e911_full_info ?: $e911_info)."'>".escape($e911_info)."&nbsp;</td>\n";
			}
		
		echo "</tr>\n";
		}

		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
		if ($paging_controls != '') {
			echo "<div align='center'>".$paging_controls."</div>\n";
		}
		
		// Create disconnect modals for numbers without domains
		if (permission_exists('bulkvs_purchase')) {
			$disconnect_modals_created = [];
			foreach ($paginated_numbers as $number) {
				$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
				if (empty($tn)) {
					continue;
				}
				
				// Check if this number has a domain (same logic as in table row)
				$tn_10 = preg_replace('/^1/', '', $tn);
				$has_domain = false;
				if (strlen($tn_10) == 10 && isset($domain_map[$tn_10])) {
					$domain_info = $domain_map[$tn_10];
					$destination_uuid = is_array($domain_info) ? ($domain_info['destination_uuid'] ?? '') : '';
					if (!empty($destination_uuid) && permission_exists('destination_edit')) {
						$has_domain = true;
					}
				}
				
				// Only create modal if no domain (and not already created)
				if (!$has_domain) {
					$disconnect_modal_id = 'modal-disconnect-' . preg_replace('/[^0-9]/', '', $tn);
					if (!isset($disconnect_modals_created[$disconnect_modal_id])) {
						$disconnect_modals_created[$disconnect_modal_id] = true;
						$disconnect_tn_escaped = escape($tn);
						echo modal::create([
							'id'=>$disconnect_modal_id,
							'type'=>'delete',
							'message'=>$text['message-disconnect-confirm'] . ' (' . $disconnect_tn_escaped . ')',
							'actions'=>button::create([
								'type'=>'button',
								'label'=>$text['button-continue'],
								'icon'=>'check',
								'style'=>'float: right; margin-left: 15px;',
								'collapse'=>'never',
								'onclick'=>"modal_close(); window.location.href='bulkvs_numbers.php?action=disconnect&disconnect_tn=".urlencode($tn)."&".$token['name']."=".$token['hash']."';"
							])
						]);
					}
				}
			}
		}
	} else {
		if (empty($error_message)) {
			echo "<div class='card'>\n";
			echo "	<div class='subheading'>".$text['message-no-numbers']."</div>\n";
			echo "</div>\n";
		}
	}

	echo "<br />\n";

//add JavaScript for background sync
	echo "<script type='text/javascript'>\n";
	echo "	// Function to refresh page and reset sync status\n";
	echo "	function refreshPage() {\n";
	echo "		// Reset last_record_count before reloading\n";
	echo "		var xhr = new XMLHttpRequest();\n";
	echo "		xhr.open('POST', 'bulkvs_sync.php', true);\n";
	echo "		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');\n";
	echo "		xhr.send('type=numbers&reset=1');\n";
	echo "		// Reload page immediately (don't wait for reset)\n";
	echo "		window.location.reload();\n";
	echo "	}\n";
	echo "	\n";
	echo "	// Trigger background sync on page load\n";
	echo "	(function() {\n";
	echo "		var syncType = 'numbers';\n";
	echo "		var xhr = new XMLHttpRequest();\n";
	echo "		xhr.open('GET', 'bulkvs_sync.php?type=' + syncType, true);\n";
	echo "		xhr.onreadystatechange = function() {\n";
	echo "			if (xhr.readyState === 4) {\n";
	echo "				if (xhr.status === 200) {\n";
	echo "					try {\n";
	echo "						var response = JSON.parse(xhr.responseText);\n";
	echo "						if (response.success && response.new_records !== 0) {\n";
	echo "							// Show refresh button\n";
	echo "							var refreshBtn = document.getElementById('refresh_button_container');\n";
	echo "							if (refreshBtn) {\n";
	echo "								refreshBtn.style.display = '';\n";
	echo "							}\n";
	echo "						}\n";
	echo "					} catch (e) {\n";
	echo "						// Ignore JSON parse errors\n";
	echo "					}\n";
	echo "				}\n";
	echo "			}\n";
	echo "		};\n";
	echo "		xhr.send();\n";
	echo "	})();\n";
	echo "</script>\n";

//include the footer
	require_once "resources/footer.php";

?>

