<?php

	//application details
		$apps[$x]['name'] = "BulkVS";
		$apps[$x]['uuid'] = "5557cc3b-2956-4636-8581-f77a126f67f4";
		$apps[$x]['category'] = "Switch";
		$apps[$x]['subcategory'] = "";
		$apps[$x]['version'] = "1.0";
		$apps[$x]['license'] = "Mozilla Public License 1.1";
		$apps[$x]['url'] = "http://www.fusionpbx.com";
		$apps[$x]['description']['en-us'] = "Integrate with BulkVS API to manage phone numbers, update Portout PIN and CNAM, and purchase new numbers.";
		$apps[$x]['description']['en-gb'] = "Integrate with BulkVS API to manage phone numbers, update Portout PIN and CNAM, and purchase new numbers.";
		$apps[$x]['description']['ar-eg'] = "";
		$apps[$x]['description']['de-at'] = "";
		$apps[$x]['description']['de-ch'] = "";
		$apps[$x]['description']['de-de'] = "";
		$apps[$x]['description']['el-gr'] = "";
		$apps[$x]['description']['es-cl'] = "";
		$apps[$x]['description']['es-mx'] = "";
		$apps[$x]['description']['fr-ca'] = "";
		$apps[$x]['description']['fr-fr'] = "";
		$apps[$x]['description']['he-il'] = "";
		$apps[$x]['description']['it-it'] = "";
		$apps[$x]['description']['ka-ge'] = "";
		$apps[$x]['description']['nl-nl'] = "";
		$apps[$x]['description']['pl-pl'] = "";
		$apps[$x]['description']['pt-br'] = "";
		$apps[$x]['description']['pt-pt'] = "";
		$apps[$x]['description']['ro-ro'] = "";
		$apps[$x]['description']['ru-ru'] = "";
		$apps[$x]['description']['sv-se'] = "";
		$apps[$x]['description']['uk-ua'] = "";

	//default settings
		$y=0;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "d4e8275b-5dba-4d07-a5be-2e10fe13f74d";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "bulkvs";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "api_key";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "BulkVS API Key/Username";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "2baa6433-08df-466e-b1b1-f3a14c082dd5";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "bulkvs";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "api_secret";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "BulkVS API Secret";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "2d25170d-03cd-4998-81d2-f7da041acdcf";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "bulkvs";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "trunk_group";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Trunk Group to filter numbers";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "96c1b551-7ff4-48e4-8e10-da513aae84b3";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "bulkvs";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "api_url";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "https://portal.bulkvs.com/api/v1.0";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "BulkVS API Base URL";

	//permission details
		$y=0;
		$apps[$x]['permissions'][$y]['name'] = "bulkvs_view";
		$apps[$x]['permissions'][$y]['menu']['uuid'] = "48e8090a-7e28-44fc-9239-b3a6511c477f";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "bulkvs_edit";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "bulkvs_search";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "bulkvs_purchase";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";

?>

