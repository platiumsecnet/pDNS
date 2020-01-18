<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2013-2019 The facileManager Team                          |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | facileManager: Easy System Administration                               |
 | fmDNS: Easily manage one or more ISC BIND servers                       |
 +-------------------------------------------------------------------------+
 | http://www.facilemanager.com/modules/fmdns/                             |
 +-------------------------------------------------------------------------+
 | Processes zone record updates                                           |
 +-------------------------------------------------------------------------+
*/

require_once('fm-init.php');

include(ABSPATH . 'fm-modules/' . $_SESSION['module'] . '/classes/class_records.php');

if (empty($_POST)) {
	header('Location: ' . $GLOBALS['RELPATH']);
	exit;
}

/** Make sure we can handle all of the variables */
checkMaxInputVars();

extract($_POST);

/** Should the user be here? */
if (!currentUserCan('manage_records', $_SESSION['module'])) unAuth();
if (!zoneAccessIsAllowed(array($domain_id))) unAuth();
if (in_array($record_type, $__FM_CONFIG['records']['require_zone_rights']) && !currentUserCan('manage_zones', $_SESSION['module'])) unAuth();

if (isset($update) && is_array($update)) {
	foreach ($update as $id => $data) {
		$old_record = null;
		
		if (isset($data['soa_serial_no'])) {
			if (!class_exists('fm_dns_zones')) {
				include_once(ABSPATH . 'fm-modules/' . $_SESSION['module'] . '/classes/class_zones.php');
			}
			$fm_dns_zones->updateSOASerialNo($domain_id, $data['soa_serial_no'], 'no-increment');

			continue;
		}
		
		/** Auto-detect IPv4 vs IPv6 A records */
		if ($record_type == 'A' && strrpos($data['record_value'], ':')) $record_type = 'AAAA';
		elseif ($record_type == 'AAAA' && !strrpos($data['record_value'], ':')) $record_type = 'A';
		
		if ($record_type != 'PTR' && $data['record_status'] == 'deleted') {
			$data['PTR'] = $domain_id;
		}
		
		/** Get current record information */
		if (isset($data['PTR'])) {
			basicGet('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'records', $id, 'record_', 'record_id');
			if ($fmdb->num_rows) $old_record = $fmdb->last_result[0];
		}

		$fm_dns_records->update($domain_id, $id, $record_type, $data);
		
		/** Are we auto-creating a PTR record? */
		autoManagePTR($domain_id, $record_type, $data, 'update', $old_record);
	}
}

if (isset($skip) && is_array($skip)) {
	foreach ($skip as $id => $data) {
		$fm_dns_records->update($domain_id, $id, $record_type, $data, true);
	}
}

if (isset($create) && is_array($create)) {
	$record_count = 0;
	foreach ($create as $new_id => $data) {
		if (!isset($data['record_skip'])) {
			if (isset($import_records)) $record_type = $data['record_type'];
			
			/** Auto-detect IPv4 vs IPv6 A records */
			if ($record_type == 'A' && strrpos($data['record_value'], ':')) $record_type = 'AAAA';
			elseif ($record_type == 'AAAA' && !strrpos($data['record_value'], ':')) $record_type = 'A';
			
			if (!isset($record_type)) $record_type = null;
			if (!isset($data['record_comment']) || strtolower($data['record_comment']) == __('none')) $data['record_comment'] = null;
			if ($record_type != 'SOA' && (!isset($data['record_ttl']) || strtolower($data['record_ttl']) == __('default'))) $data['record_ttl'] = null;
			
			/** Remove double quotes */
			if (isset($data['record_value'])) $data['record_value'] = str_replace('"', '', $data['record_value']);
			
			// Handle bulk import
			if ($submit == 'Import' && isset($data['PTR'])) {
				list($data['PTR'], $error_msg) = checkPTRZone($data['record_value'], $domain_id);
			}
			
			$fm_dns_records->add($domain_id, $record_type, $data);

			/** Are we auto-creating a PTR record? */
			autoManagePTR($domain_id, $record_type, $data);
			
			$record_count++;
		}
	}
	
	if (isset($import_records)) {
		$domain_name = displayFriendlyDomainName(getNameFromID($_POST['domain_id'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'domains', 'domain_', 'domain_id', 'domain_name'));
		addLogEntry(sprintf(dngettext($_SESSION['module'], 'Imported %d record from \'%s\' into %s.', 'Imported %d records from \'%s\' into %s.', $record_count), $record_count, $import_file, $domain_name));
	}
}

if (isset($record_type) && $domain_id && !isset($import_records)) {
	header('Location: zone-records.php?map=' . $map . '&domain_id=' . $domain_id . '&record_type=' . $record_type);
	exit;
} else {
	if ($domain_id) {
		header('Location: zone-records.php?map=' . $map . '&domain_id=' . $domain_id);
		exit;
	} else {
		header('Location: ' . $menu[getParentMenuKey(__('SOA'))][4]);
		exit;
	}
}


/**
 * Manages the PTR record
 *
 * @since 3.0
 * @package facileManager
 * @subpackage fmDNS
 *
 * @param id $domain_id domain_id
 * @param string $record_type Type of RR
 * @param array $data RR data to process
 * @param string $operation Add or Update
 * @param object $old_record Old RR information
 * @return null
 */
function autoManagePTR($domain_id, $record_type, $data, $operation = 'add', $old_record = null) {
	global $__FM_CONFIG, $fmdb;

	$forward_record_id = ($old_record) ? $old_record->record_id : $fmdb->insert_id;
	
	if ($record_type == 'A' && isset($data['PTR']) && zoneAccessIsAllowed(array($data['PTR']))) {
		$domain = '.' . trimFullStop(getNameFromID($domain_id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'domains', 'domain_', 'domain_id', 'domain_name')) . '.';
		if ($data['record_name'][0] == '@') {
			$data['record_name'] = null;
			$domain = substr($domain, 1);
		}

		/** Get reverse zone */
		if (!strrpos($data['record_value'], ':')) {
			$rev_domain = trimFullStop(getNameFromID($data['PTR'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'domains', 'domain_', 'domain_id', 'domain_name'));
			$domain_pieces = array_reverse(explode('.', $rev_domain));
			$domain_parts = count($domain_pieces);

			$subnet_ips = null;
			for ($i=2; $i<$domain_parts; $i++) {
				if (strpos($domain_pieces[$i], '-')) break;
				$subnet_ips .= $domain_pieces[$i] . '.';
			}
			$record_octets = array_reverse(explode('.', substr($data['record_value'], strlen($subnet_ips))));
			$temp_record_value = null;
			for ($j=0; $j<count($record_octets); $j++) {
				$temp_record_value .= $record_octets[$j] . '.';
			}
			$data['record_value'] = rtrim($temp_record_value, '.');
		} else {
			/** IPv6 not yet supported */
			return;
		}

		if (isset($data['record_status'])) {
			$array['record_status'] = $data['record_status'];
		}
		if (!isset($data['record_status']) || $data['record_status'] != 'deleted') {
			$array = array(
					'record_name' => $data['record_value'],
					'record_ttl' => $data['record_ttl'],
					'record_class' => $data['record_class'],
					'record_value' => $data['record_name'] . $domain,
					'record_comment' => $data['record_comment']
					);
		}

		global $fm_dns_records;
		if ($operation == 'update') {
			$array['domain_id'] = $data['PTR'];
			$fm_dns_records->update($data['PTR'], $old_record->record_ptr_id, 'PTR', $array);
			
			if ($fmdb->rows_affected) return;
			array_pop($array);
		}
		
		if (!isset($data['record_status']) || $data['record_status'] != 'deleted') {
			$fm_dns_records->add($data['PTR'], 'PTR', $array, 'replace');
			if ($fmdb->insert_id != $forward_record_id) {
				basicUpdate('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'records', $forward_record_id, 'record_ptr_id', $fmdb->insert_id, 'record_id');
			}
		}
	}
}
?>
