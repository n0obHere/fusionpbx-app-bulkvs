# BulkVS FusionPBX App

A FusionPBX application that integrates with the BulkVS API to manage phone numbers, update Portout PIN and CNAM information, purchase new phone numbers, and manage E911 records directly from the FusionPBX interface.

## Overview

This app provides seamless integration between FusionPBX and BulkVS, allowing administrators to:

- View all phone numbers in their BulkVS account filtered by trunk group
- Update Portout PIN, LIDB/CNAM, SMS/MMS settings, and Notes for individual numbers
- Search for available phone numbers by area code (NPA) or area code + exchange (NPANXX)
- Purchase phone numbers and automatically create destinations in FusionPBX
- View and manage E911 records for phone numbers
- Edit E911 records with address validation

## Features

- **Number Management**: View and manage all BulkVS numbers filtered by configured trunk group
  - Display status, activation date, rate center, tier, LIDB, notes, domain, and E911 information
  - Click on a number row to edit number details
  - Click on domain to edit the destination
  - Click on E911 record to edit E911 information
- **Number Editing**: Update LIDB/CNAM, Portout PIN, SMS/MMS settings, and Notes for individual numbers
- **Number Search**: Search for available numbers by NPA (3-digit area code) or NPANXX (6-digit area code + exchange)
- **Number Purchase**: Purchase numbers directly from the interface with automatic destination creation
  - Configure purchase settings: Domain, LIDB, Portout PIN, Reference ID
  - Automatically creates destination with proper context and prefix
  - Redirects to destination edit page after purchase
- **E911 Management**: View and manage E911 records
  - View E911 information in the numbers table
  - Edit existing E911 records
  - Create new E911 records
  - Address validation before saving
  - SMS number configuration for E911 records
- **Server-Side Pagination**: Efficient pagination for large result sets
- **Server-Side Filtering**: Filter all results, not just the current page
- **Permission-Based Access**: Granular permissions for viewing, editing, searching, and purchasing
- **FusionPBX Standards**: Built using FusionPBX frameworks and follows standard app patterns
- **No External Dependencies**: Uses standard PHP cURL library (no additional packages required)

## Requirements

- FusionPBX installation
- PHP with cURL extension enabled
- BulkVS API account with API credentials
- Valid BulkVS trunk group configured

## Installation

1. Navigate to the FusionPBX app directory:
   ```bash
   cd /var/www/fusionpbx/app
   ```

2. Clone the repository:
   ```bash
   git clone git@github.com:eliweaver732/fusionpbx-app-bulkvs.git
   ```

3. Rename the directory:
   ```bash
   mv fusionpbx-app-bulkvs bulkvs
   ```

4. Navigate to **Advanced > Upgrade** in FusionPBX and run the upgrade to register the app

5. Configure the app settings (see Configuration section below)

## Configuration

Before using the app, you must configure the BulkVS API credentials and trunk group in FusionPBX Default Settings:

1. Navigate to **Advanced > Default Settings**
2. Configure the following settings under the `bulkvs` category:

   - **bulkvs/api_key**: Your BulkVS API Key/Username
   - **bulkvs/api_secret**: Your BulkVS API Secret/Password
   - **bulkvs/trunk_group**: Your BulkVS Trunk Group name (case-sensitive)
   - **bulkvs/api_url**: BulkVS API URL (default: `https://portal.bulkvs.com/api/v1.0`)

## Permissions

The app uses the following permissions:

- **bulkvs_view**: View BulkVS numbers list
- **bulkvs_edit**: Edit number details and E911 records
- **bulkvs_search**: Search for available numbers
- **bulkvs_purchase**: Purchase numbers

Assign these permissions to user groups as needed through **Advanced > Groups**.

## Usage

### Viewing Numbers

1. Navigate to **Apps > BulkVS > Numbers**
2. All numbers filtered by your configured trunk group will be displayed
3. Use the filter box to search through numbers
4. Click on a number row to edit its details
5. Click on the domain to edit the destination
6. Click on the E911 record to edit E911 information

### Editing a Number

1. Click on a number row in the numbers table
2. Update the following fields:
   - **LIDB**: Caller ID Name (up to 15 alphanumeric characters and spaces, auto-uppercased)
   - **Portout PIN**: Port-out PIN for number porting
   - **Notes**: Reference ID or notes
   - **SMS**: Enable/disable SMS
   - **MMS**: Enable/disable MMS
3. Click **Save**

### Searching for Numbers

1. Navigate to **Apps > BulkVS > Search & Purchase Numbers**
2. Enter 3 digits (area code) or 6 digits (area code + exchange) in the search field
3. Configure purchase settings (if you have purchase permission):
   - **Domain**: Select the domain for the destination
   - **LIDB**: Caller ID Name (optional)
   - **Portout PIN**: 8-digit PIN (auto-generated if empty)
   - **Reference ID**: Notes or reference (optional)
4. Click **Search**
5. Review the search results
6. Click **Purchase** next to a number to purchase it

### Purchasing a Number

1. Search for available numbers (see Searching for Numbers above)
2. Configure purchase settings in the form
3. Click **Purchase** next to the desired number
4. The number will be:
   - Purchased in BulkVS and assigned to your trunk group
   - Automatically created as a destination in the selected FusionPBX domain
   - Created with context set to 'public' and prefix set to '1'
   - Destination description set to the Reference ID
   - Ready to use for routing calls
5. You will be redirected to the destination edit page

### Managing E911 Records

1. Navigate to **Apps > BulkVS > Numbers**
2. Click on the E911 record (or "None" if no record exists) for a number
3. Fill in the E911 information:
   - **Caller Name**: Name associated with the E911 record
   - **Street Number**: Street number
   - **Street Name**: Street name
   - **Location**: Suite, unit, etc. (optional)
   - **City**: City name
   - **State**: State abbreviation (2 letters)
   - **Zip**: ZIP code
   - **SMS Numbers**: Comma-separated list of SMS numbers (optional)
4. Click **Save**
5. The address will be validated first, then the E911 record will be saved

## File Structure

```
bulkvs/
├── app_config.php              # App configuration, permissions, and default settings
├── app_menu.php                # Menu items for the app
├── app_languages.php           # Language strings for UI elements
├── bulkvs_numbers.php          # Main numbers list page
├── bulkvs_number_edit.php     # Number edit page
├── bulkvs_e911_edit.php        # E911 record edit page
├── bulkvs_search.php           # Search and purchase page
└── resources/
    └── classes/
        └── bulkvs_api.php      # BulkVS API client class
```

## API Client

The `bulkvs_api` class provides methods for interacting with the BulkVS API:

- `getNumbers($trunk_group)`: Retrieve numbers filtered by trunk group
- `getNumber($tn)`: Get details for a specific number
- `updateNumber($tn, $lidb, $portout_pin, $reference_id, $sms, $mms)`: Update number details
- `searchNumbers($npa, $nxx)`: Search for available numbers
- `purchaseNumber($tn, $trunk_group, $lidb, $portout_pin, $reference_id)`: Purchase a number
- `getE911Records()`: Get all E911 records
- `getE911Record($tn)`: Get E911 record for a specific number
- `validateAddress($address_data)`: Validate an address and get AddressID
- `saveE911Record($tn, $caller_name, $address_id, $sms)`: Save/update E911 record

## API Documentation

For detailed information about the BulkVS API, refer to the official documentation:
https://portal.bulkvs.com/api/v1.0/openapi

## Troubleshooting

### Numbers Not Appearing

- Verify your API credentials are correct in Default Settings
- Ensure the trunk group matches exactly (case-sensitive)
- Check that your BulkVS account has numbers assigned to the specified trunk group

### API Errors

- Verify your API credentials are valid
- Check that your BulkVS account has sufficient permissions
- Ensure the API URL is correct (default should work)
- Check FusionPBX error logs for detailed error messages

### Purchase Failures

- Ensure the trunk group is configured in Default Settings
- Verify you have permissions to purchase numbers in BulkVS
- Check that you have sufficient account balance (if required by BulkVS)
- Verify domain permissions if purchasing to a different domain
- Ensure a domain is selected before clicking Purchase

### E911 Record Issues

- Verify address information is complete and accurate
- Check that the address validation returns "GEOCODED" status
- Ensure state is a valid 2-letter abbreviation
- Check that ZIP code is valid

## License

This project is licensed under the Mozilla Public License Version 1.1 (MPL 1.1).
