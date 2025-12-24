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

/**
 * BulkVS Cache Management Class
 * Handles caching of BulkVS numbers and E911 records in PostgreSQL
 */
class bulkvs_cache {

	/**
	 * Database object
	 * @var database
	 */
	private $database;

	/**
	 * Settings object
	 * @var settings
	 */
	private $settings;

	/**
	 * Called when the object is created
	 */
	public function __construct($database = null, $settings = null) {
		if ($database === null) {
			$this->database = database::new();
		} else {
			$this->database = $database;
		}
		
		if ($settings === null) {
			$this->settings = new settings(['database' => $this->database]);
		} else {
			$this->settings = $settings;
		}
	}

	/**
	 * Get numbers from cache filtered by trunk group
	 * @param string $trunk_group Trunk group name
	 * @return array Array of number records
	 */
	public function getNumbers($trunk_group = null) {
		$sql = "SELECT tn, status, activation_date, rate_center, tier, lidb, reference_id, ";
		$sql .= "sms, mms, portout_pin, trunk_group, data_json ";
		$sql .= "FROM v_bulkvs_numbers_cache ";
		
		$parameters = [];
		if (!empty($trunk_group)) {
			$sql .= "WHERE trunk_group = :trunk_group ";
			$parameters['trunk_group'] = $trunk_group;
		}
		
		$sql .= "ORDER BY tn ASC ";
		
		try {
			$results = $this->database->select($sql, $parameters, 'all');
			error_log("BulkVS getNumbers: Query returned " . count($results) . " rows for trunk_group: " . ($trunk_group ?? 'null'));
		} catch (Exception $e) {
			error_log("BulkVS getNumbers error: " . $e->getMessage());
			error_log("SQL: " . $sql);
			$results = [];
		}
		
		// Convert database results to API-like format
		$numbers = [];
		foreach ($results as $row) {
			$number = [];
			
			// If data_json exists, use it as base and merge with individual fields
			if (!empty($row['data_json'])) {
				$number = json_decode($row['data_json'], true);
				if (!is_array($number)) {
					$number = [];
				}
			}
			
			// Override with individual fields (in case data_json is missing or incomplete)
			$number['TN'] = $row['tn'] ?? '';
			$number['Status'] = $row['status'] ?? '';
			$number['Lidb'] = $row['lidb'] ?? '';
			$number['ReferenceID'] = $row['reference_id'] ?? '';
			$number['Portout Pin'] = $row['portout_pin'] ?? '';
			
			// Handle nested TN Details structure
			if (!isset($number['TN Details'])) {
				$number['TN Details'] = [];
			}
			if (!empty($row['activation_date'])) {
				$number['TN Details']['Activation Date'] = $row['activation_date'];
			}
			if (!empty($row['rate_center'])) {
				$number['TN Details']['Rate Center'] = $row['rate_center'];
			}
			if (!empty($row['tier'])) {
				$number['TN Details']['Tier'] = $row['tier'];
			}
			
			// Handle Messaging structure
			if (!isset($number['Messaging'])) {
				$number['Messaging'] = [];
			}
			$number['Messaging']['Sms'] = isset($row['sms']) ? (bool)$row['sms'] : false;
			$number['Messaging']['Mms'] = isset($row['mms']) ? (bool)$row['mms'] : false;
			
			$numbers[] = $number;
		}
		
		return $numbers;
	}

	/**
	 * Get E911 records from cache
	 * @return array Array of E911 records
	 */
	public function getE911Records() {
		$sql = "SELECT tn, caller_name, address_line1, address_line2, city, state, zip, ";
		$sql .= "address_id, sms, data_json ";
		$sql .= "FROM v_bulkvs_e911_cache ";
		$sql .= "ORDER BY tn ASC ";
		
		$results = $this->database->select($sql, null, 'all');
		
		// Convert database results to API-like format
		$records = [];
		foreach ($results as $row) {
			$record = [];
			
			// If data_json exists, use it as base
			if (!empty($row['data_json'])) {
				$record = json_decode($row['data_json'], true);
				if (!is_array($record)) {
					$record = [];
				}
			}
			
			// Override with individual fields
			$record['TN'] = $row['tn'] ?? '';
			$record['Caller Name'] = $row['caller_name'] ?? '';
			$record['Address Line 1'] = $row['address_line1'] ?? '';
			$record['Address Line 2'] = $row['address_line2'] ?? '';
			$record['City'] = $row['city'] ?? '';
			$record['State'] = $row['state'] ?? '';
			$record['Zip'] = $row['zip'] ?? '';
			$record['AddressID'] = $row['address_id'] ?? '';
			
			// Handle SMS array
			if (!empty($row['sms'])) {
				$sms_data = json_decode($row['sms'], true);
				if (is_array($sms_data)) {
					$record['Sms'] = $sms_data;
				}
			}
			
			$records[] = $record;
		}
		
		return $records;
	}

	/**
	 * Sync numbers from API to cache
	 * @param string $trunk_group Trunk group name
	 * @return array Sync result with success status and record counts
	 */
	public function syncNumbers($trunk_group = null) {
		// Check if sync is already in progress (but allow if it's been more than 5 minutes - might be stale)
		$sync_status = $this->getSyncStatus('numbers');
		if ($sync_status && isset($sync_status['sync_in_progress']) && $sync_status['sync_in_progress']) {
			// Check if sync is stale (more than 5 minutes old)
			$last_sync_start = isset($sync_status['last_sync_start']) ? strtotime($sync_status['last_sync_start']) : 0;
			$now = time();
			if (($now - $last_sync_start) < 300) { // 5 minutes
				return [
					'success' => false,
					'message' => 'Sync already in progress',
					'new_records' => 0,
					'total_records' => $sync_status['current_record_count'] ?? 0
				];
			} else {
				// Stale sync - reset it
				error_log("BulkVS: Resetting stale sync_in_progress flag");
			}
		}
		
		// Mark sync as in progress
		$this->updateSyncStatus('numbers', [
			'sync_in_progress' => true,
			'sync_status' => 'in_progress',
			'last_sync_start' => date('Y-m-d H:i:s')
		]);
		
		try {
			require_once __DIR__ . "/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($this->settings);
			
			// Fetch from API
			error_log("BulkVS: Fetching numbers from API for trunk_group: " . ($trunk_group ?? 'null'));
			$api_response = $bulkvs_api->getNumbers($trunk_group);
			error_log("BulkVS: API response type: " . gettype($api_response));
			error_log("BulkVS: API response keys: " . (is_array($api_response) ? implode(', ', array_keys($api_response)) : 'not array'));
			
			// Handle API response format
			if (isset($api_response['data']) && is_array($api_response['data'])) {
				$numbers = $api_response['data'];
				error_log("BulkVS: Found " . count($numbers) . " numbers in api_response['data']");
			} elseif (is_array($api_response)) {
				$numbers = $api_response;
				error_log("BulkVS: Found " . count($numbers) . " numbers in api_response array");
			} else {
				$numbers = [];
				error_log("BulkVS: API response is not an array, setting numbers to empty");
			}
			
			// Filter out empty/invalid entries
			$numbers = array_filter($numbers, function($number) {
				$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
				return !empty($tn);
			});
			$numbers = array_values($numbers);
			error_log("BulkVS: After filtering, have " . count($numbers) . " valid numbers");
			
			$new_count = 0;
			$updated_count = 0;
			
			// Get current count from cache
			$sql_count = "SELECT COUNT(*) as count FROM v_bulkvs_numbers_cache ";
			if (!empty($trunk_group)) {
				$sql_count .= "WHERE trunk_group = :trunk_group ";
				$parameters_count = ['trunk_group' => $trunk_group];
			} else {
				$parameters_count = [];
			}
			$current_count_result = $this->database->select($sql_count, $parameters_count, 'row');
			$current_count = isset($current_count_result['count']) ? (int)$current_count_result['count'] : 0;
			
			// Upsert each number
			error_log("BulkVS: Starting to upsert " . count($numbers) . " numbers");
			$inserted_count = 0;
			foreach ($numbers as $number) {
				$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
				if (empty($tn)) {
					continue;
				}
				
				// Extract fields
				$status = $number['Status'] ?? $number['status'] ?? '';
				$lidb = $number['Lidb'] ?? $number['lidb'] ?? '';
				$reference_id = $number['ReferenceID'] ?? $number['referenceID'] ?? '';
				$portout_pin = $number['Portout Pin'] ?? $number['portoutPin'] ?? '';
				
				$activation_date = '';
				$rate_center = '';
				$tier = '';
				if (isset($number['TN Details']) && is_array($number['TN Details'])) {
					$tn_details = $number['TN Details'];
					$activation_date = $tn_details['Activation Date'] ?? $tn_details['activation_date'] ?? '';
					$rate_center = $tn_details['Rate Center'] ?? $tn_details['rate_center'] ?? '';
					$tier = $tn_details['Tier'] ?? $tn_details['tier'] ?? '';
				}
				
				$sms = false;
				$mms = false;
				if (isset($number['Messaging']) && is_array($number['Messaging'])) {
					$messaging = $number['Messaging'];
					$sms = isset($messaging['Sms']) ? (bool)$messaging['Sms'] : false;
					$mms = isset($messaging['Mms']) ? (bool)$messaging['Mms'] : false;
				}
				
				// Check if record exists
				$sql_check = "SELECT cache_uuid FROM v_bulkvs_numbers_cache WHERE tn = :tn ";
				if (!empty($trunk_group)) {
					$sql_check .= "AND trunk_group = :trunk_group ";
				}
				$check_params = ['tn' => $tn];
				if (!empty($trunk_group)) {
					$check_params['trunk_group'] = $trunk_group;
				}
				$existing = $this->database->select($sql_check, $check_params, 'row');
				
				if (empty($existing)) {
					$new_count++;
				} else {
					$updated_count++;
				}
				
				// Prepare data for insert/update
				$data_json = json_encode($number);
				
				$sql = "INSERT INTO v_bulkvs_numbers_cache ";
				$sql .= "(cache_uuid, tn, status, activation_date, rate_center, tier, lidb, reference_id, ";
				$sql .= "sms, mms, portout_pin, trunk_group, data_json, last_updated, created) ";
				$sql .= "VALUES ";
				$sql .= "(gen_random_uuid(), :tn, :status, :activation_date, :rate_center, :tier, :lidb, :reference_id, ";
				$sql .= ":sms, :mms, :portout_pin, :trunk_group, :data_json::jsonb, CURRENT_TIMESTAMP, ";
				$sql .= "COALESCE((SELECT created FROM v_bulkvs_numbers_cache WHERE tn = :tn ";
				if (!empty($trunk_group)) {
					$sql .= "AND trunk_group = :trunk_group ";
				}
				$sql .= "LIMIT 1), CURRENT_TIMESTAMP)) ";
				$sql .= "ON CONFLICT (tn) DO UPDATE SET ";
				$sql .= "status = EXCLUDED.status, ";
				$sql .= "activation_date = EXCLUDED.activation_date, ";
				$sql .= "rate_center = EXCLUDED.rate_center, ";
				$sql .= "tier = EXCLUDED.tier, ";
				$sql .= "lidb = EXCLUDED.lidb, ";
				$sql .= "reference_id = EXCLUDED.reference_id, ";
				$sql .= "sms = EXCLUDED.sms, ";
				$sql .= "mms = EXCLUDED.mms, ";
				$sql .= "portout_pin = EXCLUDED.portout_pin, ";
				$sql .= "trunk_group = EXCLUDED.trunk_group, ";
				$sql .= "data_json = EXCLUDED.data_json, ";
				$sql .= "last_updated = CURRENT_TIMESTAMP ";
				
				$parameters = [
					'tn' => $tn,
					'status' => $status,
					'activation_date' => !empty($activation_date) ? $activation_date : null,
					'rate_center' => $rate_center,
					'tier' => $tier,
					'lidb' => $lidb,
					'reference_id' => $reference_id,
					'sms' => $sms,
					'mms' => $mms,
					'portout_pin' => $portout_pin,
					'trunk_group' => $trunk_group,
					'data_json' => $data_json
				];
				
				try {
					$this->database->execute($sql, $parameters);
					$inserted_count++;
					if ($inserted_count % 10 == 0) {
						error_log("BulkVS: Inserted $inserted_count numbers so far...");
					}
				} catch (Exception $e) {
					error_log("BulkVS cache insert error for TN $tn: " . $e->getMessage());
					error_log("SQL: " . substr($sql, 0, 500));
					error_log("Parameters: " . json_encode($parameters));
					// Don't throw - continue with other records
					error_log("Continuing with other records...");
				}
			}
			error_log("BulkVS: Finished upserting. Total inserted/updated: $inserted_count");
			
			// Remove numbers from cache that are no longer in API response (if trunk_group is specified)
			if (!empty($trunk_group)) {
				$tn_list = array_map(function($n) {
					return $n['TN'] ?? $n['tn'] ?? $n['telephoneNumber'] ?? '';
				}, $numbers);
				$tn_list = array_filter($tn_list);
				
				if (!empty($tn_list)) {
					$placeholders = [];
					$delete_params = ['trunk_group' => $trunk_group];
					foreach ($tn_list as $index => $tn) {
						$placeholders[] = ':tn_' . $index;
						$delete_params['tn_' . $index] = $tn;
					}
					
					$sql_delete = "DELETE FROM v_bulkvs_numbers_cache ";
					$sql_delete .= "WHERE trunk_group = :trunk_group ";
					$sql_delete .= "AND tn NOT IN (" . implode(', ', $placeholders) . ") ";
					
					$this->database->execute($sql_delete, $delete_params);
				}
			}
			
			$total_records = count($numbers);
			$last_record_count = $current_count;
			
			// Update sync status
			$this->updateSyncStatus('numbers', [
				'sync_in_progress' => false,
				'sync_status' => 'success',
				'last_sync_end' => date('Y-m-d H:i:s'),
				'last_record_count' => $last_record_count,
				'current_record_count' => $total_records,
				'error_message' => null
			]);
			
			return [
				'success' => true,
				'new_records' => $total_records - $last_record_count,
				'total_records' => $total_records,
				'updated_count' => $updated_count
			];
			
		} catch (Exception $e) {
			// Update sync status with error
			$this->updateSyncStatus('numbers', [
				'sync_in_progress' => false,
				'sync_status' => 'error',
				'last_sync_end' => date('Y-m-d H:i:s'),
				'error_message' => $e->getMessage()
			]);
			
			return [
				'success' => false,
				'message' => $e->getMessage(),
				'new_records' => 0,
				'total_records' => 0
			];
		}
	}

	/**
	 * Sync E911 records from API to cache
	 * @return array Sync result with success status and record counts
	 */
	public function syncE911() {
		// Check if sync is already in progress (but allow if it's been more than 5 minutes - might be stale)
		$sync_status = $this->getSyncStatus('e911');
		if ($sync_status && isset($sync_status['sync_in_progress']) && $sync_status['sync_in_progress']) {
			// Check if sync is stale (more than 5 minutes old)
			$last_sync_start = isset($sync_status['last_sync_start']) ? strtotime($sync_status['last_sync_start']) : 0;
			$now = time();
			if (($now - $last_sync_start) < 300) { // 5 minutes
				return [
					'success' => false,
					'message' => 'Sync already in progress',
					'new_records' => 0,
					'total_records' => $sync_status['current_record_count'] ?? 0
				];
			} else {
				// Stale sync - reset it
				error_log("BulkVS: Resetting stale sync_in_progress flag");
			}
		}
		
		// Mark sync as in progress
		$this->updateSyncStatus('e911', [
			'sync_in_progress' => true,
			'sync_status' => 'in_progress',
			'last_sync_start' => date('Y-m-d H:i:s')
		]);
		
		try {
			require_once __DIR__ . "/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($this->settings);
			
			// Fetch from API
			$api_response = $bulkvs_api->getE911Records();
			
			// Handle API response format
			if (isset($api_response['data']) && is_array($api_response['data'])) {
				$records = $api_response['data'];
			} elseif (is_array($api_response)) {
				$records = $api_response;
			} else {
				$records = [];
			}
			
			// Filter out empty/invalid entries
			$records = array_filter($records, function($record) {
				$tn = $record['TN'] ?? $record['tn'] ?? '';
				return !empty($tn);
			});
			$records = array_values($records);
			
			// Get current count from cache
			$sql_count = "SELECT COUNT(*) as count FROM v_bulkvs_e911_cache ";
			$current_count_result = $this->database->select($sql_count, null, 'row');
			$current_count = isset($current_count_result['count']) ? (int)$current_count_result['count'] : 0;
			
			$new_count = 0;
			$updated_count = 0;
			
			// Upsert each record
			foreach ($records as $record) {
				$tn = $record['TN'] ?? $record['tn'] ?? '';
				if (empty($tn)) {
					continue;
				}
				
				// Extract fields
				$caller_name = $record['Caller Name'] ?? $record['callerName'] ?? '';
				$address_line1 = $record['Address Line 1'] ?? $record['addressLine1'] ?? '';
				$address_line2 = $record['Address Line 2'] ?? $record['addressLine2'] ?? '';
				$city = $record['City'] ?? $record['city'] ?? '';
				$state = $record['State'] ?? $record['state'] ?? '';
				$zip = $record['Zip'] ?? $record['zip'] ?? '';
				$address_id = $record['AddressID'] ?? $record['addressID'] ?? '';
				
				$sms_array = [];
				if (isset($record['Sms']) && is_array($record['Sms'])) {
					$sms_array = $record['Sms'];
				}
				
				// Check if record exists
				$sql_check = "SELECT cache_uuid FROM v_bulkvs_e911_cache WHERE tn = :tn ";
				$existing = $this->database->select($sql_check, ['tn' => $tn], 'row');
				
				if (empty($existing)) {
					$new_count++;
				} else {
					$updated_count++;
				}
				
				// Prepare data for insert/update
				$data_json = json_encode($record);
				$sms_json = json_encode($sms_array);
				
				$sql = "INSERT INTO v_bulkvs_e911_cache ";
				$sql .= "(cache_uuid, tn, caller_name, address_line1, address_line2, city, state, zip, ";
				$sql .= "address_id, sms, data_json, last_updated, created) ";
				$sql .= "VALUES ";
				$sql .= "(gen_random_uuid(), :tn, :caller_name, :address_line1, :address_line2, :city, :state, :zip, ";
				$sql .= ":address_id, :sms::jsonb, :data_json::jsonb, CURRENT_TIMESTAMP, ";
				$sql .= "COALESCE((SELECT created FROM v_bulkvs_e911_cache WHERE tn = :tn LIMIT 1), CURRENT_TIMESTAMP)) ";
				$sql .= "ON CONFLICT (tn) DO UPDATE SET ";
				$sql .= "caller_name = EXCLUDED.caller_name, ";
				$sql .= "address_line1 = EXCLUDED.address_line1, ";
				$sql .= "address_line2 = EXCLUDED.address_line2, ";
				$sql .= "city = EXCLUDED.city, ";
				$sql .= "state = EXCLUDED.state, ";
				$sql .= "zip = EXCLUDED.zip, ";
				$sql .= "address_id = EXCLUDED.address_id, ";
				$sql .= "sms = EXCLUDED.sms, ";
				$sql .= "data_json = EXCLUDED.data_json, ";
				$sql .= "last_updated = CURRENT_TIMESTAMP ";
				
				$parameters = [
					'tn' => $tn,
					'caller_name' => $caller_name,
					'address_line1' => $address_line1,
					'address_line2' => $address_line2,
					'city' => $city,
					'state' => $state,
					'zip' => $zip,
					'address_id' => $address_id,
					'sms' => $sms_json,
					'data_json' => $data_json
				];
				
				try {
					$this->database->execute($sql, $parameters);
				} catch (Exception $e) {
					error_log("BulkVS E911 cache insert error for TN $tn: " . $e->getMessage());
					error_log("SQL: " . substr($sql, 0, 500));
					// Don't throw - continue with other records
					error_log("Continuing with other records...");
				}
			}
			
			// Remove records from cache that are no longer in API response
			$tn_list = array_map(function($r) {
				return $r['TN'] ?? $r['tn'] ?? '';
			}, $records);
			$tn_list = array_filter($tn_list);
			
			if (!empty($tn_list)) {
				$placeholders = [];
				$delete_params = [];
				foreach ($tn_list as $index => $tn) {
					$placeholders[] = ':tn_' . $index;
					$delete_params['tn_' . $index] = $tn;
				}
				
				$sql_delete = "DELETE FROM v_bulkvs_e911_cache ";
				$sql_delete .= "WHERE tn NOT IN (" . implode(', ', $placeholders) . ") ";
				
				$this->database->execute($sql_delete, $delete_params);
			}
			
			$total_records = count($records);
			$last_record_count = $current_count;
			
			// Update sync status
			$this->updateSyncStatus('e911', [
				'sync_in_progress' => false,
				'sync_status' => 'success',
				'last_sync_end' => date('Y-m-d H:i:s'),
				'last_record_count' => $last_record_count,
				'current_record_count' => $total_records,
				'error_message' => null
			]);
			
			return [
				'success' => true,
				'new_records' => $total_records - $last_record_count,
				'total_records' => $total_records,
				'updated_count' => $updated_count
			];
			
		} catch (Exception $e) {
			// Update sync status with error
			$this->updateSyncStatus('e911', [
				'sync_in_progress' => false,
				'sync_status' => 'error',
				'last_sync_end' => date('Y-m-d H:i:s'),
				'error_message' => $e->getMessage()
			]);
			
			return [
				'success' => false,
				'message' => $e->getMessage(),
				'new_records' => 0,
				'total_records' => 0
			];
		}
	}

	/**
	 * Check if there are new records since last sync
	 * @param string $sync_type 'numbers' or 'e911'
	 * @return bool True if new records exist
	 */
	public function hasNewRecords($sync_type) {
		$sync_status = $this->getSyncStatus($sync_type);
		if (!$sync_status) {
			return false;
		}
		
		$current_count = isset($sync_status['current_record_count']) ? (int)$sync_status['current_record_count'] : 0;
		$last_count = isset($sync_status['last_record_count']) ? (int)$sync_status['last_record_count'] : 0;
		
		return $current_count > $last_count;
	}

	/**
	 * Get sync status for a sync type
	 * @param string $sync_type 'numbers' or 'e911'
	 * @return array|null Sync status array or null if not found
	 */
	public function getSyncStatus($sync_type) {
		$sql = "SELECT sync_type, last_sync_start, last_sync_end, last_record_count, ";
		$sql .= "current_record_count, sync_in_progress, sync_status, error_message ";
		$sql .= "FROM v_bulkvs_sync_status ";
		$sql .= "WHERE sync_type = :sync_type ";
		$sql .= "LIMIT 1 ";
		
		$result = $this->database->select($sql, ['sync_type' => $sync_type], 'row');
		return $result ?: null;
	}

	/**
	 * Update sync status
	 * @param string $sync_type 'numbers' or 'e911'
	 * @param array $data Status data to update
	 */
	private function updateSyncStatus($sync_type, $data) {
		// Check if record exists
		$existing = $this->getSyncStatus($sync_type);
		
		if (empty($existing)) {
			// Insert new record
			$sql = "INSERT INTO v_bulkvs_sync_status ";
			$sql .= "(sync_uuid, sync_type, last_sync_start, last_sync_end, last_record_count, ";
			$sql .= "current_record_count, sync_in_progress, sync_status, error_message) ";
			$sql .= "VALUES ";
			$sql .= "(gen_random_uuid(), :sync_type, :last_sync_start, :last_sync_end, :last_record_count, ";
			$sql .= ":current_record_count, :sync_in_progress, :sync_status, :error_message) ";
			
			$parameters = [
				'sync_type' => $sync_type,
				'last_sync_start' => $data['last_sync_start'] ?? null,
				'last_sync_end' => $data['last_sync_end'] ?? null,
				'last_record_count' => $data['last_record_count'] ?? 0,
				'current_record_count' => $data['current_record_count'] ?? 0,
				'sync_in_progress' => isset($data['sync_in_progress']) ? ($data['sync_in_progress'] ? true : false) : false,
				'sync_status' => $data['sync_status'] ?? 'success',
				'error_message' => $data['error_message'] ?? null
			];
			
			try {
				$this->database->execute($sql, $parameters);
			} catch (Exception $e) {
				error_log("BulkVS sync status insert error: " . $e->getMessage());
				throw $e;
			}
		} else {
			// Update existing record
			$sql = "UPDATE v_bulkvs_sync_status SET ";
			$updates = [];
			$parameters = ['sync_type' => $sync_type];
			
			if (isset($data['last_sync_start'])) {
				$updates[] = "last_sync_start = :last_sync_start";
				$parameters['last_sync_start'] = $data['last_sync_start'];
			}
			if (isset($data['last_sync_end'])) {
				$updates[] = "last_sync_end = :last_sync_end";
				$parameters['last_sync_end'] = $data['last_sync_end'];
			}
			if (isset($data['last_record_count'])) {
				$updates[] = "last_record_count = :last_record_count";
				$parameters['last_record_count'] = $data['last_record_count'];
			}
			if (isset($data['current_record_count'])) {
				$updates[] = "current_record_count = :current_record_count";
				$parameters['current_record_count'] = $data['current_record_count'];
			}
			if (isset($data['sync_in_progress'])) {
				$updates[] = "sync_in_progress = :sync_in_progress";
				$parameters['sync_in_progress'] = $data['sync_in_progress'] ? true : false;
			}
			if (isset($data['sync_status'])) {
				$updates[] = "sync_status = :sync_status";
				$parameters['sync_status'] = $data['sync_status'];
			}
			if (isset($data['error_message'])) {
				$updates[] = "error_message = :error_message";
				$parameters['error_message'] = $data['error_message'];
			}
			
			if (!empty($updates)) {
				$sql .= implode(', ', $updates);
				$sql .= " WHERE sync_type = :sync_type ";
				$this->database->execute($sql, $parameters);
			}
		}
	}

	/**
	 * Clear cache for a sync type
	 * @param string $sync_type 'numbers' or 'e911'
	 */
	public function clearCache($sync_type) {
		if ($sync_type === 'numbers') {
			$sql = "DELETE FROM v_bulkvs_numbers_cache ";
			$this->database->execute($sql, null);
		} elseif ($sync_type === 'e911') {
			$sql = "DELETE FROM v_bulkvs_e911_cache ";
			$this->database->execute($sql, null);
		}
	}

	/**
	 * Update a single number in cache
	 * @param string $tn Telephone number
	 * @param array $number_data Number data
	 */
	public function updateNumber($tn, $number_data) {
		// This will be called after editing a number
		// For now, trigger a full sync or update the specific record
		// For simplicity, we'll just mark that a sync is needed
		// In a production system, you might want to update just this record
	}

	/**
	 * Delete a number from cache
	 * @param string $tn Telephone number
	 */
	public function deleteNumber($tn) {
		$sql = "DELETE FROM v_bulkvs_numbers_cache WHERE tn = :tn ";
		$this->database->execute($sql, ['tn' => $tn]);
	}

	/**
	 * Update a single E911 record in cache
	 * @param string $tn Telephone number
	 * @param array $e911_data E911 record data
	 */
	public function updateE911($tn, $e911_data) {
		// This will be called after editing an E911 record
		// For now, trigger a full sync or update the specific record
	}

	/**
	 * Delete an E911 record from cache
	 * @param string $tn Telephone number
	 */
	public function deleteE911($tn) {
		$sql = "DELETE FROM v_bulkvs_e911_cache WHERE tn = :tn ";
		$this->database->execute($sql, ['tn' => $tn]);
	}

	/**
	 * Reset last_record_count to current_record_count (after refresh)
	 * @param string $sync_type 'numbers' or 'e911'
	 */
	public function resetLastRecordCount($sync_type) {
		$sync_status = $this->getSyncStatus($sync_type);
		if ($sync_status && isset($sync_status['current_record_count'])) {
			$this->updateSyncStatus($sync_type, [
				'last_record_count' => $sync_status['current_record_count']
			]);
		}
	}
}

?>

