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
	if (!permission_exists('bulkvs_search')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//create token (needed early for form validation)
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//get http variables
	$search_query = $_GET['search'] ?? $_POST['search'] ?? '';
	$search_action = $_GET['action'] ?? $_POST['action'] ?? '';
	$purchase_tn = $_POST['purchase_tn'] ?? '';
	$purchase_domain_uuid = $_POST['purchase_domain_uuid'] ?? '';
	
	// Parse search query: 3 digits = NPA, 6 digits = NPANXX
	$npa = '';
	$nxx = '';
	if (!empty($search_query)) {
		$search_query = preg_replace('/[^0-9]/', '', $search_query); // Remove non-numeric
		if (strlen($search_query) == 3) {
			$npa = $search_query;
		} elseif (strlen($search_query) == 6) {
			$npa = substr($search_query, 0, 3);
			$nxx = substr($search_query, 3, 3);
		}
	}

//process purchase
	if ($search_action == 'purchase' && !empty($purchase_tn) && !empty($purchase_domain_uuid)) {
		if (!permission_exists('bulkvs_purchase')) {
			message::add("Access denied", 'negative');
			header("Location: bulkvs_search.php");
			return;
		}
		
		// Validate token
		if (!$object->validate($_SERVER['PHP_SELF'])) {
			message::add("Invalid token", 'negative');
			header("Location: bulkvs_search.php");
			return;
		}

		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$trunk_group = $settings->get('bulkvs', 'trunk_group', '');
			
			if (empty($trunk_group)) {
				throw new Exception("Trunk Group must be configured in default settings");
			}

			// Purchase the number
			$bulkvs_api->purchaseNumber($purchase_tn, $trunk_group);

			// Create destination in FusionPBX
			require_once dirname(__DIR__, 2) . "/app/destinations/resources/classes/destinations.php";
			$destination = new destinations(['database' => $database, 'domain_uuid' => $purchase_domain_uuid]);
			
			$destination_uuid = uuid();
			$destination_number = preg_replace('/[^0-9]/', '', $purchase_tn); // Remove non-numeric characters
			
			// Get domain name for context
			$sql = "select domain_name from v_domains where domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $purchase_domain_uuid;
			$domain_name = $database->select($sql, $parameters, 'column');
			unset($sql, $parameters);

			// Prepare destination array
			$array['destinations'][0]['destination_uuid'] = $destination_uuid;
			$array['destinations'][0]['domain_uuid'] = $purchase_domain_uuid;
			$array['destinations'][0]['destination_type'] = 'inbound';
			$array['destinations'][0]['destination_number'] = $destination_number;
			$array['destinations'][0]['destination_context'] = $domain_name ?? 'public';
			$array['destinations'][0]['destination_enabled'] = 'true';
			$array['destinations'][0]['destination_description'] = 'BulkVS: ' . $purchase_tn;

			// Grant temporary permissions
			$p = permissions::new();
			$p->add('destination_add', 'temp');
			$p->add('dialplan_add', 'temp');
			$p->add('dialplan_detail_add', 'temp');

			// Save the destination
			$database->app_name = 'destinations';
			$database->app_uuid = '5ec89622-b19c-3559-64f0-afde802ab139';
			$database->save($array);

			// Revoke temporary permissions
			$p->delete('destination_add', 'temp');
			$p->delete('dialplan_add', 'temp');
			$p->delete('dialplan_detail_add', 'temp');

			message::add($text['message-purchase-success']);
			// Redirect to destination edit page
			$redirect_url = "../destinations/destination_edit.php?id=".urlencode($destination_uuid);
			header("Location: ".$redirect_url);
			return;
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
	}

//search for numbers
	$search_results = [];
	$error_message = '';
	$num_rows = 0;
	$paging_controls = '';
	$paging_controls_mini = '';
	
	if ($search_action == 'search' && !empty($npa)) {
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$api_response = $bulkvs_api->searchNumbers($npa, $nxx);
			
			// Handle API response - API returns array directly, not wrapped in 'data'
			if (is_array($api_response)) {
				$search_results = $api_response;
			}
		} catch (Exception $e) {
			$error_message = $e->getMessage();
			message::add($text['message-api-error'] . ': ' . $error_message, 'negative');
		}
	}

//get filter parameter
	$filter = $_GET['filter'] ?? $_POST['filter'] ?? '';

//apply server-side filter to all search results
	if ($search_action == 'search' && !empty($filter) && !empty($search_results)) {
		$filter_lower = strtolower($filter);
		$search_results = array_filter($search_results, function($result) use ($filter_lower) {
			$tn = strtolower($result['TN'] ?? $result['tn'] ?? $result['telephoneNumber'] ?? '');
			$rate_center = strtolower($result['Rate Center'] ?? $result['rateCenter'] ?? '');
			$lata = strtolower($result['LATA'] ?? $result['lata'] ?? '');
			$state = strtolower($result['State'] ?? $result['state'] ?? '');
			$tier = strtolower($result['Tier'] ?? $result['tier'] ?? '');
			
			// Check if filter matches any field
			return (
				strpos($tn, $filter_lower) !== false ||
				strpos($rate_center, $filter_lower) !== false ||
				strpos($lata, $filter_lower) !== false ||
				strpos($state, $filter_lower) !== false ||
				strpos($tier, $filter_lower) !== false
			);
		});
		$search_results = array_values($search_results); // Re-index array
	}

//prepare to page the results
	$num_rows = count($search_results);
	$rows_per_page = $settings->get('domain', 'paging', 50);
	$param = "&search=".urlencode($search_query)."&action=search";
	if (!empty($filter)) {
		$param .= "&filter=".urlencode($filter);
	}
	if (!empty($_GET['page'])) {
		$page = $_GET['page'];
	}
	if (!isset($page)) { $page = 0; $_GET['page'] = 0; }
	[$paging_controls, $rows_per_page] = paging($num_rows, $param, $rows_per_page);
	[$paging_controls_mini, $rows_per_page] = paging($num_rows, $param, $rows_per_page, true);
	$offset = $rows_per_page * $page;
	
	// Slice the results array for pagination
	$paginated_results = [];
	if (!empty($search_results)) {
		$paginated_results = array_slice($search_results, $offset, $rows_per_page);
	}

//get list of domains for purchase dropdown
	$domains = [];
	if (permission_exists('domain_all') || permission_exists('domain_select')) {
		$sql = "select domain_uuid, domain_name ";
		$sql .= "from v_domains ";
		$sql .= "where domain_enabled = true ";
		$sql .= "order by domain_name asc ";
		$domains = $database->select($sql, null, 'all');
	} else {
		// Only current domain
		$sql = "select domain_uuid, domain_name ";
		$sql .= "from v_domains ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$parameters['domain_uuid'] = $domain_uuid;
		$domains = $database->select($sql, $parameters, 'all');
	}

//include the header
	$document['title'] = $text['title-bulkvs-search'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-bulkvs-search']."</b>";
	if ($num_rows > 0) {
		echo "<div class='count'>".number_format($num_rows)."</div>";
	}
	echo "</div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'bulkvs_numbers.php']);
	if ($search_action == 'search' && !empty($search_results)) {
		echo "		<form method='get' action='' style='display: inline; margin-left: 15px;'>\n";
		echo "			<input type='hidden' name='action' value='search'>\n";
		echo "			<input type='hidden' name='search' value='".escape($search_query)."'>\n";
		echo "			<input type='text' name='filter' class='txt list-search' placeholder='Filter results...' value='".escape($filter)."' style='width: 200px;'>\n";
		echo "			<input type='submit' class='btn' value='Filter' style='margin-left: 5px;'>\n";
		if (!empty($filter)) {
			echo "			<a href='?action=search&search=".urlencode($search_query)."' class='btn' style='margin-left: 5px;'>Clear</a>\n";
		}
		echo "		</form>\n";
	}
	if ($paging_controls_mini != '') {
		echo "<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-bulkvs-search']."\n";
	echo "<br /><br />\n";

	// Search form
	echo "<div class='card'>\n";
	echo "	<div class='subheading'>".$text['button-search']."</div>\n";
	echo "	<div class='content'>\n";
	echo "		<form method='get' action=''>\n";
	echo "			<input type='hidden' name='action' value='search'>\n";
	echo "			<table class='no_hover'>\n";
	echo "				<tr>\n";
	echo "					<td class='vncell'>Search</td>\n";
	echo "					<td class='vtable'><input type='text' class='formfld' name='search' value='".escape($search_query)."' maxlength='6' placeholder='3 digits (area code) or 6 digits (area code + exchange)'></td>\n";
	echo "				</tr>\n";
	echo "				<tr>\n";
	echo "					<td colspan='2'><input type='submit' class='btn' value='".$text['button-search']."'></td>\n";
	echo "				</tr>\n";
	echo "			</table>\n";
	echo "		</form>\n";
	echo "	</div>\n";
	echo "</div>\n";
	echo "<br />\n";

	// Search results
	if ($search_action == 'search') {
		if (!empty($error_message)) {
			echo "<div class='alert alert-warning'>".escape($error_message)."</div>\n";
			echo "<br />\n";
		}

		if (!empty($paginated_results)) {
			echo "<div class='card'>\n";
			echo "<table class='list' id='results_table'>\n";
			echo "<tr class='list-header'>\n";
			echo "	<th>".$text['label-telephone-number']."</th>\n";
			echo "	<th>".$text['label-rate-center']."</th>\n";
			echo "	<th>".$text['label-lata']."</th>\n";
			if (permission_exists('bulkvs_purchase')) {
				echo "	<td class='action-button'>&nbsp;</td>\n";
			}
			echo "</tr>\n";

			foreach ($paginated_results as $result) {
				// API returns fields with spaces: "TN", "Rate Center", "LATA", etc.
				$tn = $result['TN'] ?? $result['tn'] ?? $result['telephoneNumber'] ?? '';
				$rate_center = $result['Rate Center'] ?? $result['rateCenter'] ?? '';
				$lata = $result['LATA'] ?? $result['lata'] ?? '';

				echo "<tr class='list-row'>\n";
				echo "	<td>".escape($tn)."</td>\n";
				echo "	<td>".escape($rate_center)."&nbsp;</td>\n";
				echo "	<td>".escape($lata)."&nbsp;</td>\n";
				if (permission_exists('bulkvs_purchase')) {
					echo "	<td class='action-button'>\n";
					echo "		<form method='post' action='' style='display: inline;'>\n";
					echo "			<input type='hidden' name='action' value='purchase'>\n";
					echo "			<input type='hidden' name='purchase_tn' value='".escape($tn)."'>\n";
					echo "			<input type='hidden' name='search' value='".escape($search_query)."'>\n";
					if (isset($_GET['page'])) {
						echo "			<input type='hidden' name='page' value='".escape($_GET['page'])."'>\n";
					}
					echo "			<select name='purchase_domain_uuid' class='formfld' style='width: auto; margin-right: 5px;'>\n";
					foreach ($domains as $domain) {
						$selected = ($domain['domain_uuid'] == $domain_uuid) ? 'selected' : '';
						echo "				<option value='".escape($domain['domain_uuid'])."' ".$selected.">".escape($domain['domain_name'])."</option>\n";
					}
					echo "			</select>\n";
					echo "			<input type='submit' class='btn' value='".$text['button-purchase']."'>\n";
					echo "			<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
					echo "		</form>\n";
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
				echo "	<div class='subheading'>".$text['message-no-results']."</div>\n";
				echo "</div>\n";
			}
		}
	}

	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>

