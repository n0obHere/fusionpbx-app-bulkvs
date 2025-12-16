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
 * BulkVS API Client
 */
class bulkvs_api {

	/**
	 * Settings object set in the constructor
	 * @var settings Settings Object
	 */
	private $settings;

	/**
	 * API base URL
	 * @var string
	 */
	private $api_url;

	/**
	 * API Key
	 * @var string
	 */
	private $api_key;

	/**
	 * API Secret
	 * @var string
	 */
	private $api_secret;

	/**
	 * Called when the object is created
	 */
	public function __construct($settings = null) {
		//set settings object
		if ($settings === null) {
			$this->settings = new settings(['database' => database::new()]);
		} else {
			$this->settings = $settings;
		}

		//get API credentials from settings
		$this->api_url = $this->settings->get('bulkvs', 'api_url', 'https://portal.bulkvs.com/api/v1.0');
		$this->api_key = $this->settings->get('bulkvs', 'api_key', '');
		$this->api_secret = $this->settings->get('bulkvs', 'api_secret', '');
	}

	/**
	 * Make an API request
	 * @param string $method HTTP method (GET, POST, PUT, DELETE)
	 * @param string $endpoint API endpoint
	 * @param array $data Request data (for POST/PUT)
	 * @return array Response data
	 */
	private function request($method, $endpoint, $data = null) {
		$url = rtrim($this->api_url, '/') . '/' . ltrim($endpoint, '/');

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERPWD, $this->api_key . ':' . $this->api_secret);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

		$headers = [];
		$headers[] = 'Content-Type: application/json';
		$headers[] = 'Accept: application/json';

		if ($method === 'POST' || $method === 'PUT') {
			if ($data !== null) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			}
			if ($method === 'POST') {
				curl_setopt($ch, CURLOPT_POST, true);
			} else {
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
			}
		} elseif ($method === 'GET' && $data !== null) {
			// For GET requests, append data as query parameters
			$query_string = http_build_query($data);
			if ($query_string) {
				$url .= '?' . $query_string;
				curl_setopt($ch, CURLOPT_URL, $url);
			}
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($error) {
			throw new Exception("BulkVS API Error: " . $error);
		}

		// Handle empty responses (some endpoints may return empty body on success)
		if (empty(trim($response))) {
			if ($http_code >= 400) {
				throw new Exception("BulkVS API Error: HTTP Error $http_code");
			}
			return [];
		}

		$result = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception("BulkVS API Error: Invalid JSON response - " . json_last_error_msg());
		}

		if ($http_code >= 400) {
			$error_msg = isset($result['message']) ? $result['message'] : "HTTP Error $http_code";
			throw new Exception("BulkVS API Error: " . $error_msg);
		}

		return $result;
	}

	/**
	 * Get numbers filtered by trunk group
	 * @param string $trunk_group Trunk group name
	 * @return array Array of number records
	 */
	public function getNumbers($trunk_group = null) {
		$params = [];
		if (!empty($trunk_group)) {
			$params['Trunk Group'] = $trunk_group;
		}
		return $this->request('GET', '/tnRecord', $params);
	}

	/**
	 * Get a single number record by telephone number
	 * @param string $tn Telephone number
	 * @return array Number record
	 */
	public function getNumber($tn) {
		$params = ['Number' => $tn];
		$result = $this->request('GET', '/tnRecord', $params);
		// API returns an array, get first element if it exists
		if (is_array($result) && !empty($result)) {
			return $result[0];
		}
		return $result;
	}

	/**
	 * Update a number's fields
	 * @param string $tn Telephone number (required)
	 * @param string $lidb LIDB/CNAM value (optional)
	 * @param string $portout_pin Portout PIN (optional)
	 * @param string $reference_id Notes/ReferenceID (optional)
	 * @param bool $sms SMS enabled (optional)
	 * @param bool $mms MMS enabled (optional)
	 * @return array Response data
	 */
	public function updateNumber($tn, $lidb = null, $portout_pin = null, $reference_id = null, $sms = null, $mms = null) {
		$data = ['TN' => $tn];
		
		if ($lidb !== null) {
			$data['Lidb'] = $lidb;
		}
		if ($portout_pin !== null) {
			$data['Portout Pin'] = $portout_pin;
		}
		if ($reference_id !== null) {
			$data['ReferenceID'] = $reference_id;
		}
		if ($sms !== null) {
			$data['Sms'] = $sms ? true : false;
		}
		if ($mms !== null) {
			$data['Mms'] = $mms ? true : false;
		}
		
		return $this->request('POST', '/tnRecord', $data);
	}

	/**
	 * Search for available numbers
	 * @param string $npa Area code (3 digits)
	 * @param string $nxx Exchange code (3 digits, used with npa for 6-digit search)
	 * @return array Array of available numbers
	 */
	public function searchNumbers($npa = null, $nxx = null) {
		$params = [];
		if (!empty($npa)) {
			$params['Npa'] = $npa; // API uses capital N, lowercase pa
		}
		if (!empty($nxx)) {
			$params['Nxx'] = $nxx; // API uses capital N, lowercase xx
		}
		if (empty($params)) {
			throw new Exception("NPA must be provided");
		}
		return $this->request('GET', '/orderTn', $params);
	}

	/**
	 * Purchase a number
	 * @param string $tn Telephone number to purchase
	 * @param string $trunk_group Trunk group to assign the number to
	 * @param string $lidb LIDB/CNAM value (optional)
	 * @param string $portout_pin Portout PIN (optional)
	 * @param string $reference_id Reference ID/Notes (optional)
	 * @return array Response data
	 */
	public function purchaseNumber($tn, $trunk_group, $lidb = null, $portout_pin = null, $reference_id = null) {
		$data = [
			'TN' => $tn,
			'Trunk Group' => $trunk_group,
			'Sms' => false,
			'Mms' => false
		];
		
		// Only add fields if they are provided (not null and not empty)
		if ($lidb !== null && trim($lidb) !== '') {
			$data['Lidb'] = trim($lidb);
		}
		if ($portout_pin !== null && trim($portout_pin) !== '') {
			$data['Portout Pin'] = trim($portout_pin);
		}
		if ($reference_id !== null && trim($reference_id) !== '') {
			$data['ReferenceID'] = trim($reference_id);
		}
		
		return $this->request('POST', '/orderTn', $data);
	}
}

?>

