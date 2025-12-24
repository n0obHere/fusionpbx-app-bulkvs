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
		header('Content-Type: application/json');
		echo json_encode(['success' => false, 'message' => 'Access denied']);
		exit;
	}

//set content type to JSON
	header('Content-Type: application/json');

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//get sync type from request
	$sync_type = $_GET['type'] ?? $_POST['type'] ?? '';
	$reset = isset($_GET['reset']) || isset($_POST['reset']);
	$force_reset = isset($_GET['force_reset']) || isset($_POST['force_reset']);
	
	if (empty($sync_type) || !in_array($sync_type, ['numbers', 'e911'])) {
		echo json_encode(['success' => false, 'message' => 'Invalid sync type']);
		exit;
	}

//load cache class
	require_once "resources/classes/bulkvs_cache.php";
	$cache = new bulkvs_cache($database, $settings);

//handle force reset request (reset sync_in_progress flag)
	if ($force_reset) {
		try {
			// Initialize database if not already set
			if (!isset($database) || $database === null) {
				$database = new database;
			}
			
			$sql = "UPDATE v_bulkvs_sync_status SET sync_in_progress = false WHERE sync_type = :sync_type";
			$database->execute($sql, ['sync_type' => $sync_type]);
			echo json_encode(['success' => true, 'message' => 'Force reset successful']);
		} catch (Exception $e) {
			echo json_encode(['success' => false, 'message' => $e->getMessage()]);
		}
		exit;
	}

//handle reset request (reset last_record_count to current_record_count)
	if ($reset) {
		try {
			$cache->resetLastRecordCount($sync_type);
			echo json_encode(['success' => true, 'message' => 'Reset successful']);
		} catch (Exception $e) {
			echo json_encode(['success' => false, 'message' => $e->getMessage()]);
		}
		exit;
	}

//perform sync based on type
	try {
		if ($sync_type === 'numbers') {
			$trunk_group = $settings->get('bulkvs', 'trunk_group', '');
			$result = $cache->syncNumbers($trunk_group);
		} else {
			$result = $cache->syncE911();
		}
		
		// Return result as JSON
		echo json_encode($result);
		
	} catch (Exception $e) {
		echo json_encode([
			'success' => false,
			'message' => $e->getMessage(),
			'new_records' => 0,
			'total_records' => 0
		]);
	}

?>

