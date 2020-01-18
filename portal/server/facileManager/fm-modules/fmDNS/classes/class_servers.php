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
*/

require_once(ABSPATH . 'fm-modules/shared/classes/class_servers.php');

class fm_module_servers extends fm_shared_module_servers {
	
	/**
	 * Displays the server list
	 */
	function rows($result, $type, $page, $total_pages) {
		global $fmdb;
		
		$num_rows = $fmdb->num_rows;
		$results = $fmdb->last_result;

		$bulk_actions_list = null;
//		if (currentUserCan('manage_servers', $_SESSION['module'])) $bulk_actions_list = array('Enable', 'Disable', 'Delete', 'Upgrade');
		if (currentUserCan('manage_servers', $_SESSION['module'])) {
			$bulk_actions_list[] = __('Upgrade');
		}
		if (currentUserCan('build_server_configs', $_SESSION['module'])) {
			$bulk_actions_list[] = __('Build Config');
		}
		if (is_array($bulk_actions_list)) {
			$title_array[] = array(
								'title' => '<input type="checkbox" class="tickall" onClick="toggle(this, \'' . rtrim($type, 's') . '_list[]\')" />',
								'class' => 'header-tiny header-nosort'
							);
		}

		$start = $_SESSION['user']['record_count'] * ($page - 1);
		$fmdb->num_rows = $num_rows;
		echo displayPagination($page, $total_pages, @buildBulkActionMenu($bulk_actions_list, 'server_id_list'));
			
		if (!$result) {
			$message = $type == 'servers' ? __('There are no servers.') : __('There are no groups.');
			printf('<p id="table_edits" class="noresult" name="servers">%s</p>', $message);
		} else {
			$table_info = array(
							'class' => 'display_results sortable',
							'id' => 'table_edits',
							'name' => 'servers'
						);

			if ($type == 'servers') {
				$title_array[] = array('class' => 'header-tiny header-nosort');
				$title_array = array_merge($title_array, array(array('title' => __('Hostname'), 'rel' => 'server_name'),
					array('title' => __('Method'), 'rel' => 'server_update_method'),
					array('title' => __('Keys'), 'class' => 'header-nosort'),
					array('title' => __('Server Type'), 'class' => 'header-nosort'),
					array('title' => __('Version'), 'class' => 'header-nosort'),
					array('title' => __('Run-as'), 'rel' => 'server_run_as_predefined'),
					array('title' => __('Config File'), 'rel' => 'server_config_file'),
					array('title' => __('Application Name'), 'rel' => 'server_application_name'),
					array('title' => __('Server Root'), 'rel' => 'server_root_dir'),
					array('title' => __('Zones Directory'), 'rel' => 'server_zones_dir'),
					));
			} elseif ($type == 'groups') {
				$title_array = array_merge((array)$title_array, array(array('title' => __('Group Name'), 'rel' => 'group_name'),
					array('title' => __('Master Servers'), 'class' => 'header-nosort'),
					array('title' => __('Slave Servers'), 'class' => 'header-nosort'),
					));
			}
			$title_array[] = array(
								'title' => __('Actions'),
								'class' => 'header-actions header-nosort'
							);

			echo displayTableHeader($table_info, $title_array);
			
			$y = 0;
			for ($x=$start; $x<$num_rows; $x++) {
				if ($y == $_SESSION['user']['record_count']) break;
				$this->displayRow($results[$x], $type);
				$y++;
			}
			
			echo "</tbody>\n</table>\n";
		}
	}

	/**
	 * Adds the new server
	 */
	function addServer($post) {
		global $fmdb, $__FM_CONFIG;
		
		$module = ($post['module_name']) ? $post['module_name'] : $_SESSION['module'];
		
		/** Get a valid and unique serial number */
		$post['server_serial_no'] = (isset($post['server_serial_no'])) ? $post['server_serial_no'] : generateSerialNo($module);

		/** Validate entries */
		$post = $this->validatePost($post);
		if (!is_array($post)) return $post;
		
		$sql_insert = "REPLACE INTO `fm_{$__FM_CONFIG['fmDNS']['prefix']}servers`";
		$sql_fields = '(';
		$sql_values = null;
		
		$post['account_id'] = $_SESSION['user']['account_id'];
		
		$config_opts = array('keys', 'bogus', 'edns', 'provide-ixfr', 'request-ixfr',
			'transfers', 'transfer-format');
		
		$exclude = array_merge(array('submit', 'action', 'server_id', 'compress', 'AUTHKEY',
			'module_name', 'module_type', 'config', 'sub_type', 'update_from_client',
			'dryrun'), $config_opts);

		foreach ($post as $key => $data) {
			$clean_data = sanitize($data);
			if (($key == 'server_name') && empty($clean_data)) return __('No server name defined.');
			if (!in_array($key, $exclude)) {
				$sql_fields .= $key . ', ';
				$sql_values .= "'$clean_data', ";
			}
		}
		$sql_fields = rtrim($sql_fields, ', ') . ')';
		$sql_values = rtrim($sql_values, ', ');
		
		$query = "$sql_insert $sql_fields VALUES ($sql_values)";
		$result = $fmdb->query($query);
		
		if ($fmdb->sql_errors) {
			//return formatError(__('Could not add the server because a database error occurred.'), 'sql');
			return formatError(sprintf(__('Could not add the server because a database error occurred. Sql_fields: %s. $Sql_values: %s'), $sql_fields, $sql_values), 'sql');
		}
		
		$new_server_id = $fmdb->insert_id;
		
		/** Process config options */
		$sql_insert = "INSERT INTO `fm_{$__FM_CONFIG['fmDNS']['prefix']}config`";
		$sql_fields = '(`server_id`, `cfg_type`, `cfg_name`, `cfg_data`)';
		$sql_values = null;
		foreach ($config_opts as $option) {
			$clean_data = sanitize($post[$option]);
			$sql_values .= "('$new_server_id', 'global', '$option', '$clean_data'), ";
		}

		$sql_values = rtrim($sql_values, ', ');

		$query = "$sql_insert $sql_fields VALUES $sql_values";
		$result = $fmdb->query($query);

		if ($fmdb->sql_errors) {
			return formatError(__('Could not add the server options because a database error occurred.'), 'sql');
		}
		
		$tmp_key = $post['server_key'] ? getNameFromID($post['server_key'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'keys', 'key_', 'key_id', 'key_name') : 'None';
		$tmp_runas = $post['server_run_as_predefined'] ? $post['server_run_as_predefined'] : $post['server_run_as'];
		addLogEntry("Added server:\nName: {$post['server_name']} ({$post['server_serial_no']})\nKey: {$tmp_key}\nType: {$post['server_type']}\n" .
				"Run-as: {$tmp_runas}\nUpdate Method: {$post['server_update_method']}\nConfig File: {$post['server_config_file']}\n" .
				"Application Name: {$post['server_application_name']}\nServer Root: {$post['server_root_dir']}\nServer Chroot: {$post['server_chroot_dir']}\n" .
				"Zone file directory: {$post['server_zones_dir']}");
		return true;
	}

	/**
	 * Adds the new server group
	 */
	function addGroup($post) {
		global $fmdb, $__FM_CONFIG;
		
		if (empty($post['group_name'])) return __('No group name defined.');
		
		/** Check name field length */
		$field_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'groups', 'group_name');
		if ($field_length !== false && strlen($post['group_name']) > $field_length) return sprintf(dngettext($_SESSION['module'], 'Group name is too long (maximum %d character).', 'Group name is too long (maximum %d characters).', $field_length), $field_length);
		
		/** Does the record already exist for this account? */
		basicGet('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'server_groups', $post['group_name'], 'group_', 'group_name');
		if ($fmdb->num_rows) return __('This group name already exists.');
		
		/** Process group masters */
		$log_message_master_servers = null;
		foreach ($post['group_masters'] as $val) {
			if ($val == 0) {
				$group_masters = 0;
				break;
			}
			$group_masters .= $val . ';';
			$server_name = getNameFromID($val, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_', 'server_id', 'server_name');
			$log_message_master_servers .= $val ? "$server_name; " : null;
		}
		$log_message_master_servers = rtrim ($log_message_master_servers, '; ');
		$post['group_masters'] = rtrim($group_masters, ';');
		if (!isset($post['group_masters'])) $post['group_masters'] = 0;

		/** Process group slaves */
		$log_message_slave_servers = null;
		foreach ($post['group_slaves'] as $val) {
			if ($val == 0) {
				$group_slaves = 0;
				break;
			}
			$group_slaves .= $val . ';';
			$server_name = getNameFromID($val, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_', 'server_id', 'server_name');
			$log_message_slave_servers .= $val ? "$server_name; " : null;
		}
		$log_message_slave_servers = rtrim ($log_message_slave_servers, '; ');
		$post['group_slaves'] = rtrim($group_slaves, ';');
		if (!isset($post['group_slaves'])) $post['group_slaves'] = 0;

		$sql_insert = "REPLACE INTO `fm_{$__FM_CONFIG['fmDNS']['prefix']}server_groups`";
		$sql_fields = '(';
		$sql_values = null;
		
		$post['account_id'] = $_SESSION['user']['account_id'];
		
		$exclude = array('submit', 'action', 'server_id', 'group_id', 'compress', 'AUTHKEY', 'module_name', 'module_type', 'config', 'sub_type');

		foreach ($post as $key => $data) {
			$clean_data = sanitize($data);
			if (($key == 'group_name') && empty($clean_data)) return __('No group name defined.');
			if (!in_array($key, $exclude)) {
				$sql_fields .= $key . ', ';
				$sql_values .= "'$clean_data', ";
			}
		}
		$sql_fields = rtrim($sql_fields, ', ') . ')';
		$sql_values = rtrim($sql_values, ', ');
		
		$query = "$sql_insert $sql_fields VALUES ($sql_values)";
		$result = $fmdb->query($query);
		
		if ($fmdb->sql_errors) {
			return formatError(_('Could not add the group because a database error occurred.'), 'sql');
		}

		$tmp_key = $post['server_key'] ? getNameFromID($post['server_key'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'keys', 'key_', 'key_id', 'key_name') : 'None';
		addLogEntry(__('Added server group') . ":\n" . __('Name') . ": {$post['group_name']}\n" .
				__('Masters') . ": {$log_message_master_servers}\n" .
				__('Slaves') . ": {$log_message_slave_servers}\n");
		return true;
	}

	/**
	 * Updates the selected server
	 */
	function updateServer($post) {
		global $fmdb, $__FM_CONFIG;
		
		$return = null;
		
		/** Validate entries */
		$post = $this->validatePost($post);
		if (!is_array($post)) return $post;
		
		$config_opts = array('keys', 'bogus', 'edns', 'provide-ixfr', 'request-ixfr',
			'transfers', 'transfer-format');
		
		$exclude = array_merge(array('submit', 'action', 'server_id', 'compress', 'AUTHKEY',
			'module_name', 'module_type', 'config', 'SERIALNO', 'sub_type',
			'update_from_client', 'dryrun'), $config_opts);
		
		$post['server_run_as'] = $post['server_run_as_predefined'] == 'as defined:' ? $post['server_run_as'] : null;
		if (!in_array($post['server_run_as_predefined'], enumMYSQLSelect('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_run_as_predefined'))) {
			$post['server_run_as'] = $post['server_run_as_predefined'];
			$post['server_run_as_predefined'] = 'as defined:';
		}

		$sql_edit = null;
		
		foreach ($post as $key => $data) {
			if (!in_array($key, $exclude)) {
				$sql_edit .= $key . "='" . sanitize($data) . "', ";
			}
		}
		$sql = rtrim($sql_edit, ', ');
		
		/** Update the server */
		$old_name = getNameFromID($post['server_id'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_', 'server_id', 'server_name');
		$query = "UPDATE `fm_{$__FM_CONFIG['fmDNS']['prefix']}servers` SET $sql WHERE `server_id`={$post['server_id']} AND `account_id`='{$_SESSION['user']['account_id']}'";
		$result = $fmdb->query($query);
		
		if ($fmdb->sql_errors) {
			return formatError(__('Could not update the server because a database error occurred.'), 'sql');
		}

		$return[] = (!$fmdb->rows_affected) ? true : false;
		
		/** Process config options */
		$sql_insert = "UPDATE `fm_{$__FM_CONFIG['fmDNS']['prefix']}config` SET ";
		foreach ($config_opts as $option) {
			$clean_data = sanitize($post[$option]);

			$query = "$sql_insert cfg_name='$option', cfg_data='$clean_data' WHERE server_id='{$post['server_id']}' AND cfg_name='$option' LIMIT 1";
			$result = $fmdb->query($query);

			if ($fmdb->sql_errors) {
				return formatError(__('Could not update the server options because a database error occurred.'), 'sql');
			}
			
			$return[] = (!$fmdb->rows_affected) ? true : false;
		}
		
		/** Return if there are no changes */
		if (!$fmdb->rows_affected && array_search(false, $return) === false) return true;

		setBuildUpdateConfigFlag(getServerSerial($post['server_id'], $_SESSION['module']), 'yes', 'build');
		
		$tmp_key = $post['server_key'] ? getNameFromID($post['server_key'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'keys', 'key_', 'key_id', 'key_name') : 'None';
		$tmp_runas = $post['server_run_as_predefined'] == 'as defined:' ? $post['server_run_as'] : $post['server_run_as_predefined'];
		addLogEntry("Updated server '$old_name' to:\nName: {$post['server_name']}\nKey: {$tmp_key}\nType: {$post['server_type']}\n" .
					"Run-as: {$tmp_runas}\nUpdate Method: {$post['server_update_method']}\nConfig File: {$post['server_config_file']}\n" .
					"Application Name: {$post['server_application_name']}\nServer Root: {$post['server_root_dir']}\nServer Chroot: {$post['server_chroot_dir']}\n" .
					"Zone file directory: {$post['server_zones_dir']}");
		return true;
	}
	
	/**
	 * Updates the selected server group
	 */
	function updateGroup($post) {
		global $fmdb, $__FM_CONFIG;
		
		if (empty($post['group_name'])) return __('No group name defined.');
		
		/** Check name field length */
		$field_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'groups', 'group_name');
		if ($field_length !== false && strlen($post['group_name']) > $field_length) return sprintf(dngettext($_SESSION['module'], 'Group name is too long (maximum %d character).', 'Group name is too long (maximum %d characters).', $field_length), $field_length);
		
		/** Does the record already exist for this account? */
		basicGet('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'server_groups', $post['group_name'], 'group_', 'group_name', "AND group_id!='{$post['server_id']}'");
		if ($fmdb->num_rows) return __('This group name already exists.');
		
		/** Process group masters */
		$log_message_master_servers = null;
		foreach ((array) $post['group_masters'] as $val) {
			if ($val == 0) {
				$group_masters = 0;
				break;
			}
			$group_masters .= $val . ';';
			$server_name = getNameFromID($val, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_', 'server_id', 'server_name');
			$log_message_master_servers .= $val ? "$server_name; " : null;
		}
		$log_message_master_servers = rtrim ($log_message_master_servers, '; ');
		$post['group_masters'] = rtrim($group_masters, ';');
		if (!isset($post['group_masters'])) $post['group_masters'] = 0;

		/** Process group slaves */
		$log_message_slave_servers = null;
		foreach ((array) $post['group_slaves'] as $val) {
			if ($val == 0) {
				$group_slaves = 0;
				break;
			}
			$group_slaves .= $val . ';';
			$server_name = getNameFromID($val, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_', 'server_id', 'server_name');
			$log_message_slave_servers .= $val ? "$server_name; " : null;
		}
		$log_message_slave_servers = rtrim ($log_message_slave_servers, '; ');
		$post['group_slaves'] = rtrim($group_slaves, ';');
		if (!isset($post['group_slaves'])) $post['group_slaves'] = 0;
		
		$post['account_id'] = $_SESSION['user']['account_id'];

		$sql_edit = null;
		$exclude = array('submit', 'action', 'server_id', 'group_id', 'compress', 'AUTHKEY', 'module_name', 'module_type', 'config', 'sub_type');

		foreach ($post as $key => $data) {
			if (!in_array($key, $exclude)) {
				$sql_edit .= $key . "='" . sanitize($data) . "', ";
			}
		}
		$sql = rtrim($sql_edit, ', ');

		/** Update the server */
		$old_name = getNameFromID($post['server_id'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'server_groups', 'group_', 'group_id', 'group_name');
		$query = "UPDATE `fm_{$__FM_CONFIG['fmDNS']['prefix']}server_groups` SET $sql WHERE `group_id`={$post['server_id']} AND `account_id`='{$_SESSION['user']['account_id']}'";
		$result = $fmdb->query($query);
		
		if ($fmdb->sql_errors) {
			return formatError(_('Could not update the group because a database error occurred.'), 'sql');
		}

		/** Return if there are no changes */
		if (!$fmdb->rows_affected) return true;

		addLogEntry(sprintf(__("Updated server group '%s' to"), $old_name) . ":\n" . __('Name') . ": {$post['group_name']}\n" .
				__('Masters') . ": {$log_message_master_servers}\n" .
				__('Slaves') . ": {$log_message_slave_servers}\n");
		
		return true;
	}
	
	/**
	 * Deletes the selected server/group
	 */
	function delete($server_id, $type) {
		global $fmdb, $__FM_CONFIG;
		
		/** Does the server_id exist for this account? */
		if ($type == 'servers') {
			$server_serial_no = getNameFromID($server_id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_', 'server_id', 'server_serial_no');
			basicGet('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', $server_serial_no, 'server_', 'server_serial_no');
			if ($fmdb->num_rows) {
				/** Delete associated config options */
				$delete_status = $this->deleteServerSpecificConfigs($server_serial_no, 's_' . $server_id);
				if ($delete_status !== true) return $delete_status;

				/** Delete associated records from fm_{$__FM_CONFIG['fmDNS']['prefix']}track_builds */
				if (basicDelete('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'track_builds', $server_serial_no, 'server_serial_no', false) === false) {
					return formatError(sprintf(__('The server could not be removed from the %s table because a database error occurred.'), 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'track_builds'), 'sql');
				}

				/** Delete server */
				$tmp_name = getNameFromID($server_id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_', 'server_id', 'server_name');
				if (updateStatus('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', $server_id, 'server_', 'deleted', 'server_id')) {
					addLogEntry(sprintf(__("Server '%s' (%s) was deleted"), $tmp_name, $server_serial_no));
					return true;
				}
			}

			return formatError(__('This server could not be deleted.'), 'sql');
		} else {
			basicGet('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'server_groups', $server_id, 'group_', 'group_id');
			if ($fmdb->num_rows) {
				/** Delete associated config options */
				$delete_status = $this->deleteServerSpecificConfigs('g_' . $server_id, 'g_' . $server_id);
				if ($delete_status !== true) return $delete_status;

				/** Delete group */
				$tmp_name = getNameFromID($server_id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'server_groups', 'group_', 'group_id', 'group_name');
				if (updateStatus('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'server_groups', $server_id, 'group_', 'deleted', 'group_id')) {
					addLogEntry(sprintf(__("Server group '%s' was deleted."), $tmp_name));
					return true;
				}
			}

			return formatError(__('This server group could not be deleted.'), 'sql');
		}

		return __('There is something wrong with your request.');
	}


	function displayRow($row, $type) {
		global $fmdb, $__FM_CONFIG, $fm_dns_acls;
		
		$class = ($row->server_status == 'disabled' || $row->group_status == 'disabled') ? 'disabled' : null;
		
		$edit_status = $edit_actions = $runas = $update_method = null;
		
		if ($type == 'servers') {
			$os_image = ($row->server_type == 'remote') ? '<i class="fa fa-globe fa-2x grey" style="font-size: 1.5em" title="' . __('Remote server') . '" aria-hidden="true"></i>' : setOSIcon($row->server_os_distro);

			$edit_actions = ($row->server_type != 'remote') ? '<a href="preview.php" onclick="javascript:void window.open(\'preview.php?server_serial_no=' . $row->server_serial_no . '\',\'1356124444538\',\'width=700,height=500,toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1,left=0,top=0\');return false;">' . $__FM_CONFIG['icons']['preview'] . '</a>' : null;
			$icons = sprintf('<a href="config-options.php?server_id=%d" class="mini-icon"><i class="mini-icon fa fa-sliders" title="%s"></i></a>', $row->server_id, __('Configure Additional Options'));
			$checkbox = '<td></td>';

			if (currentUserCan('build_server_configs', $_SESSION['module']) && $row->server_installed == 'yes') {
				if ($row->server_build_config == 'yes' && $row->server_status == 'active' && $row->server_installed == 'yes') {
					$edit_actions .= $__FM_CONFIG['icons']['build'];
					$class = 'build';
				}
			}
			if (currentUserCan('manage_servers', $_SESSION['module'])) {
				$edit_status = '<a class="edit_form_link" name="' . $type . '" href="#">' . $__FM_CONFIG['icons']['edit'] . '</a>';
				if ($row->server_installed == 'yes' || $row->server_type == 'remote') {
					$edit_status .= '<a class="status_form_link" href="#" rel="';
					$edit_status .= ($row->server_status == 'active') ? 'disabled' : 'active';
					$edit_status .= '">';
					$edit_status .= ($row->server_status == 'active') ? $__FM_CONFIG['icons']['disable'] : $__FM_CONFIG['icons']['enable'];
					$edit_status .= '</a>';
				}
				$query = "SELECT group_id FROM fm_{$__FM_CONFIG['fmDNS']['prefix']}server_groups WHERE account_id='{$_SESSION['user']['account_id']}' AND group_status!='deleted' AND 
					(group_masters='{$row->server_id}' OR group_masters LIKE '{$row->server_id};%' OR group_masters LIKE '%;{$row->server_id};%' OR group_masters LIKE '%;{$row->server_id}'
					OR group_slaves='{$row->server_id}' OR group_slaves LIKE '{$row->server_id};%' OR group_slaves LIKE '%;{$row->server_id};%' OR group_slaves LIKE '%;{$row->server_id}')";
				$result = $fmdb->get_results($query);
				if (!$fmdb->num_rows) {
					$edit_status .= '<a href="#" class="delete" name="' . $type . '">' . $__FM_CONFIG['icons']['delete'] . '</a>';
				}
			}
			if (isset($row->server_client_version) && version_compare($row->server_client_version, getOption('client_version', 0, $_SESSION['module']), '<')) {
				$edit_actions = __('Client Upgrade Available') . '<br />';
				$class = 'attention';
			}
			if ($row->server_type != 'remote' && $row->server_installed != 'yes') {
				$edit_actions = __('Client Install Required') . '<br />';
			}
			$edit_status = $edit_actions . $edit_status;

			$edit_name = $row->server_name;
			if ($row->server_type != 'remote') {
				$checkbox = (currentUserCan(array('manage_servers', 'build_server_configs'), $_SESSION['module'])) ? '<td><input type="checkbox" name="server_list[]" value="' . $row->server_serial_no .'" /></td>' : null;

				$runas = ($row->server_run_as_predefined == 'as defined:') ? $row->server_run_as : $row->server_run_as_predefined;

				$port = ($row->server_update_method != 'cron') ? ' (tcp/' . $row->server_update_port . ')' : null;
				$update_method = $row->server_update_method . $port;
			}

			if (! class_exists('fm_dns_acls')) {
				include_once(ABSPATH . 'fm-modules/' . $_SESSION['module'] . '/classes/class_acls.php');
			}
			$keys = $fm_dns_acls->parseACL($this->getConfig($row->server_id, 'keys'));
			
			if ($class) $class = 'class="' . $class . '"';

			echo <<<HTML
		<tr id="$row->server_id" name="$row->server_name" $class>
			$checkbox
			<td>$os_image</td>
			<td title="$row->server_serial_no" nowrap>$edit_name $icons</td>
			<td>$update_method</td>
			<td>$keys</td>
			<td>$row->server_type</td>
			<td>$row->server_version</td>
			<td>$runas</td>
			<td>$row->server_config_file</td>
			<td>$row->server_application_name</td>
			<td>$row->server_root_dir</td>
			<td>$row->server_zones_dir</td>
			<td id="row_actions">$edit_status</td>
		</tr>

HTML;
		} elseif ($type == 'groups') {
			$checkbox = (currentUserCan(array('manage_servers', 'build_server_configs'), $_SESSION['module'])) ? '<td><input type="checkbox" name="group_list[]" value="g' . $row->group_id .'" /></td>' : null;

			if (currentUserCan('manage_servers', $_SESSION['module'])) {
				$edit_status = '<a class="edit_form_link" name="' . $type . '" href="#">' . $__FM_CONFIG['icons']['edit'] . '</a>';
				$edit_status .= '<a class="status_form_link" href="#" rel="';
				$edit_status .= ($row->group_status == 'active') ? 'disabled' : 'active';
				$edit_status .= '">';
				$edit_status .= ($row->group_status == 'active') ? $__FM_CONFIG['icons']['disable'] : $__FM_CONFIG['icons']['enable'];
				$edit_status .= '</a>';
				$query = "SELECT domain_id FROM fm_{$__FM_CONFIG['fmDNS']['prefix']}domains WHERE account_id='{$_SESSION['user']['account_id']}' AND domain_status!='deleted' AND 
					(domain_name_servers='g_{$row->group_id}' OR domain_name_servers LIKE 'g_{$row->group_id};%' OR domain_name_servers LIKE '%;g_{$row->group_id};%' OR domain_name_servers LIKE '%;g_{$row->group_id}')";
				$result = $fmdb->get_results($query);
				if (!$fmdb->num_rows) {
					$edit_status .= '<a href="#" class="delete" name="' . $type . '">' . $__FM_CONFIG['icons']['delete'] . '</a>';
				}
			}
			
			/** Process group masters */
			foreach (explode(';', $row->group_masters) as $server_id) {
				$masters[] = getNameFromID($server_id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_', 'server_id', 'server_name');
			}
			
			$group_masters = implode('; ', array_map(function($value) {
				return $value == null ? sprintf('<i>%s</i>', __('missing')) : $value;
			}, $masters));
			if (empty($group_masters) || !count($masters) || (count($masters) == 1 && empty($masters[0]))) $group_masters = __('None');
			
			/** Process group slaves */
			foreach (explode(';', $row->group_slaves) as $server_id) {
				$slaves[] = getNameFromID($server_id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_', 'server_id', 'server_name');
			}
			$group_slaves = implode('; ', $slaves);
			if (empty($group_slaves)) $group_slaves = __('None');
			
			if ($class) $class = 'class="' . $class . '"';
			
			$group_masters = wordwrap($group_masters);
			$group_slaves = wordwrap($group_slaves);

			echo <<<HTML
		<tr id="$row->group_id" name="$row->group_name" $class>
			$checkbox
			<td>$row->group_name</td>
			<td>$group_masters</td>
			<td>$group_slaves</td>
			<td id="row_actions">$edit_status</td>
		</tr>

HTML;
		}
	}

	/**
	 * Displays the form to add new server
	 */
	function printForm($data = '', $action = 'add', $type = 'servers') {
		global $__FM_CONFIG;
		
		$server_id = $group_id = 0;
		$server_name = $server_root_dir = $server_zones_dir = $runas = $server_type = $server_update_port = null;
		$server_update_method = $server_key = $server_run_as = $server_config_file = $server_application_name = $server_run_as_predefined = null;
		$server_chroot_dir = $group_name = null;
		$server_installed = false;
		
		if (!empty($_POST) && !array_key_exists('is_ajax', $_POST)) {
			if (is_array($_POST))
				extract($_POST);
		} elseif (@is_object($data[0])) {
			extract(get_object_vars($data[0]));
		}
		
		if ($type == 'groups') $server_id = $group_id;

		if ($action == 'add') {
			$popup_title = $type == 'servers' ? __('Add Server') : __('Add Group');
		} else {
			$popup_title = $type == 'servers' ? __('Edit Server') : __('Edit Group');
		}
		$popup_header = buildPopup('header', $popup_title);
		$popup_footer = buildPopup('footer');
		
		$return_form = <<<FORM
			<form name="manage" id="manage" method="post" action="?type=$type">
			$popup_header
				<input type="hidden" name="action" value="$action" />
				<input type="hidden" name="server_id" value="$server_id" />
				<input type="hidden" name="sub_type" value="$type" />
FORM;

		if ($type == 'servers') {
			/** Show/hide divs */
			if (isset($server_run_as_predefined) && $server_run_as_predefined == 'as defined:') {
				$runashow = 'block';
			} else {
				$runashow = 'none';
				$server_run_as = null;
			}
			$server_update_port_style = ($server_update_method == 'cron') ? 'style="display: none;"' : 'style="display: block;"';

			$disabled = ($server_installed == 'yes') ? 'disabled' : null;

			if ($server_installed == 'yes') {
				if (strpos($server_update_method, 'http') === false) {
					$server_update_method_choices = array($server_update_method);
				} else {
					$server_update_method_choices = array('http', 'https');
				}
			} else {
				$server_update_method_choices = enumMYSQLSelect('fm_' . $__FM_CONFIG[$_SESSION['module']]['prefix'] . 'servers', 'server_update_method');
			}

			/** Check name field length */
			$server_name_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_name');
			$server_config_file_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_config_file');
			$server_application_name_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_application_name');
			$server_root_dir_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_root_dir');
			$server_chroot_dir_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_chroot_dir');
			$server_zones_dir_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_zones_dir');

			$server_type = buildSelect('server_type', 'server_type', enumMYSQLSelect('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_type'), $server_type, 1);
			$server_update_method = buildSelect('server_update_method', 'server_update_method', $server_update_method_choices, $server_update_method, 1);
			$server_run_as_predefined = buildSelect('server_run_as_predefined', 'server_run_as_predefined', enumMYSQLSelect('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_run_as_predefined'), $server_run_as_predefined, 1, '', false, "showHideBox('run_as', 'server_run_as_predefined', 'as defined:')");

			$alternative_help = ($action == 'add' && getOption('client_auto_register')) ? sprintf('<p id="alternative_help"><b>%s</b> %s</p>', __('Note:'), __('The client installer can automatically generate this entry.')) : null;

			/** Advanced tab */
			$keys = $this->getConfig($server_id, 'keys');
			$keys = ($keys) ? explode(',', $keys) : null;
			$keys = buildSelect('keys', 'keys', $this->availableItems('key', 'blank', 'AND `key_type`="tsig"', 'key_'), $keys, 1, '', true);
			$transfers = str_replace(array('"', "'"), '', $this->getConfig($server_id, 'transfers'));
			$bogus = $this->buildConfigOptions('bogus', $this->getConfig($server_id, 'bogus'));
			$edns = $this->buildConfigOptions('edns', $this->getConfig($server_id, 'edns'));
			$transfer_format = $this->buildConfigOptions('transfer-format', $this->getConfig($server_id, 'transfer-format'));
			
			$return_form .= sprintf('
	<div id="tabs">
		<div id="tab">
			<input type="radio" name="tab-group-1" id="tab-1" checked />
			<label for="tab-1">%s</label>
			<div id="tab-content">
			%s
			<table class="form-table">
				<tr>
					<th width="33&#37;" scope="row"><label for="server_name">%s</label></th>
					<td width="67&#37;"><input name="server_name" id="server_name" type="text" value="%s" size="40" placeholder="dns1.local" maxlength="%d" /></td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="server_type">%s</label> <a href="#" class="tooltip-top" data-tooltip="%s"><i class="fa fa-question-circle"></i></a></th>
					<td width="67&#37;">%s</td>
				</tr>
				<tr class="local_server_options">
					<th width="33&#37;" scope="row"><label for="server_run_as_predefined">%s</label></th>
					<td width="67&#37;">%s
					<div id="run_as" style="display: %s"><input name="server_run_as" id="server_run_as" type="text" placeholder="%s" value="%s" /></div></td>
				</tr>
				<tr class="local_server_options">
					<th width="33&#37;" scope="row"><label for="server_update_method">%s</label></th>
					<td width="67&#37;">%s<div id="server_update_port_option" %s><input type="number" name="server_update_port" value="%s" placeholder="80" onkeydown="return validateNumber(event)" maxlength="5" max="65535" /></div></td>
				</tr>
				<tr class="local_server_options">
					<th width="33&#37;" scope="row"><label for="server_config_file">%s</label></th>
					<td width="67&#37;"><input name="server_config_file" id="server_config_file" type="text" value="%s" size="40" placeholder="%s" maxlength="%s" /></td>
				</tr>
				<tr class="local_server_options">
					<th width="33&#37;" scope="row"><label for="server_application_name">%s</label></th>
					<td width="67&#37;"><input name="server_application_name" id="server_application_name" type="text" value="%s" size="40" placeholder="%s" maxlength="%s" /></td>
				</tr>
				<tr class="local_server_options">
					<th width="33&#37;" scope="row"><label for="server_root_dir">%s</label></th>
					<td width="67&#37;"><input name="server_root_dir" id="server_root_dir" type="text" value="%s" size="40" placeholder="%s" maxlength="%s" /></td>
				</tr>
				<tr class="local_server_options">
					<th width="33&#37;" scope="row"><label for="server_chroot_dir">%s</label></th>
					<td width="67&#37;"><input name="server_chroot_dir" id="server_chroot_dir" type="text" value="%s" size="40" placeholder="%s" maxlength="%s" /></td>
				</tr>
				<tr class="local_server_options">
					<th width="33&#37;" scope="row"><label for="server_zones_dir">%s</label></th>
					<td width="67&#37;"><input name="server_zones_dir" id="server_zones_dir" type="text" value="%s" size="40" placeholder="%s" maxlength="%s" /></td>
				</tr>
			</table>
			</div>
		</div>
		<div id="tab">
			<input type="radio" name="tab-group-1" id="tab-3" />
			<label for="tab-3">%s</label>
			<div id="tab-content">
			<table class="form-table">
				<tr>
					<th width="33&#37;" scope="row"><label for="keys">%s</label></th>
					<td width="67&#37;">%s</td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="bogus">%s</label></th>
					<td width="67&#37;">%s</td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="edns">%s</label></th>
					<td width="67&#37;">%s</td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="transfers">%s</label></th>
					<td width="67&#37;"><input name="transfers" id="transfers" type="text" value="%s" style="width: 5em;" onkeydown="return validateNumber(event)" /></td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="transfer-format">%s</label></th>
					<td width="67&#37;">%s</td>
				</tr>
			</table>
			</div>
		</div>
	</div>
		%s
		</form>
		<script>
			$(document).ready(function() {
				$("#manage select").select2({
					minimumResultsForSearch: 10,
					allowClear: true,
					width: "200px"
				});
				if ($("#server_type").val() == "remote") {
					$(".local_server_options").slideUp();
				} else {
					$(".local_server_options").show("slow");
				}
			});
		</script>',
				__('Basic'), $alternative_help,
				__('Server Name'), $server_name, $server_name_length,
				__('Server Type'), sprintf(__('A remote server is not managed by %s.'), $_SESSION['module']), $server_type,
				__('Run-as Account'), $server_run_as_predefined, $runashow, __('Other run-as account'), $server_run_as,
				__('Update Method'), $server_update_method, $server_update_port_style, $server_update_port,
				__('Config File'), $server_config_file, $__FM_CONFIG['ns']['named_config_file'], $server_config_file_length,
				__('Application Name'), $server_application_name, $__FM_CONFIG['ns']['named_application_name'], $server_application_name_length,
				__('Server Root'), $server_root_dir, $__FM_CONFIG['ns']['named_root_dir'], $server_root_dir_length,
				__('Server Chroot'), $server_chroot_dir, $__FM_CONFIG['ns']['named_chroot_dir'], $server_chroot_dir_length,
				__('Zone File Directory'), $server_zones_dir, $__FM_CONFIG['ns']['named_zones_dir'], $server_zones_dir_length,
				__('Advanced'),
				__('Keys'), $keys,
				__('Bogus'), $bogus,
				__('EDNS'), $edns,
				__('Transfers'), $transfers,
				__('Transfer format'), $transfer_format,
				$popup_footer
				);
		} elseif ($type == 'groups') {
			$group_masters = (isset($group_masters)) ? explode(';', $group_masters) : null;
			$group_slaves  = (isset($group_slaves)) ? explode(';', $group_slaves) : null;
			
			$group_name_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'server_groups', 'group_name');
			$group_masters = buildSelect('group_masters', 'group_masters', $this->availableItems('server'), $group_masters, 1, null, true, null, null, __('Select master servers'));
			$group_slaves = buildSelect('group_slaves', 'group_slaves', $this->availableItems('server'), $group_slaves, 1, null, true, null, null, __('Select slave servers'));

			$return_form .= sprintf('
			<table class="form-table">
				<tr>
					<th width="33&#37;" scope="row"><label for="group_name">%s</label></th>
					<td width="67&#37;"><input name="group_name" id="group_name" type="text" value="%s" size="40" maxlength="%d" /></td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="group_masters">%s</label></th>
					<td width="67&#37;">%s</td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="group_slaves">%s</label></th>
					<td width="67&#37;">%s</td>
				</tr>
			</table>
		%s
		</form>
		<script>
			$(document).ready(function() {
				$("#manage select").select2({
					minimumResultsForSearch: 10,
					allowClear: true,
					width: "230px"
				});
			});
		</script>', __('Group Name'), $group_name, $group_name_length, __('Master Servers'), $group_masters, __('Slave Servers'), $group_slaves, $popup_footer);
		} else {
			$return_form = buildPopup('header', _('Error'));
			$return_form .= sprintf('<h3>%s</h3><p>%s</p>', __('Oops!'), __('Invalid request.'));
			$return_form .= buildPopup('footer', _('OK'), array('cancel'));
		}

		return $return_form;
	}
	
	function availableItems($type, $default = 'blank', $addl_sql = null, $prefix = null) {
		global $fmdb, $__FM_CONFIG;
		
		$return = null;
		
		$j = 0;
		if ($default == 'blank') {
			$return[$j][] = '';
			$return[$j][] = '';
			$j++;
		}
		
		$query = "SELECT * FROM fm_{$__FM_CONFIG['fmDNS']['prefix']}{$type}s WHERE account_id='{$_SESSION['user']['account_id']}' AND {$type}_status='active' $addl_sql ORDER BY {$type}_name ASC";
		$result = $fmdb->get_results($query);
		if ($fmdb->num_rows) {
			$results = $fmdb->last_result;
			foreach ($fmdb->last_result as $results) {
				if (property_exists($results, 'server_type') && $results->server_type == 'remote') continue;
				$type_name = $type . '_name';
				$type_id   = $type . '_id';
				$return[$j][] = $results->$type_name;
				$return[$j][] = $prefix . $results->$type_id;
				$j++;
			}
		}
		
		return $return;
	}

	function manageCache($server_id, $action) {
		global $fmdb, $__FM_CONFIG;
		
		/** Check serial number */
		basicGet('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', sanitize($server_id), 'server_', 'server_id');
		if (!$fmdb->num_rows) return __('This server is not found.');

		$server_details = $fmdb->last_result;
		extract(get_object_vars($server_details[0]), EXTR_SKIP);
		$response[] = $server_name;
		
		if ($server_installed != 'yes') {
			$response[] = ' --> ' . __('Failed: Client is not installed.');
		}
		
		if (count($response) == 1 && $server_status != 'active') {
			$response[] = ' --> ' . sprintf(__('Failed: Server is %s.'), $server_status);
		}
		
		if (count($response) == 1) {
			foreach (makePlainText($this->buildServerConfig($server_serial_no, $action, ucfirst(str_replace('-', ' ', $action))), true) as $line) {
				$response[] = ' --> ' . $line;
			}
		}
		
		return implode("\n", $response);
	}
	
	/**
	 * Updates the name server assignments
	 *
	 * @since 2.0
	 * @package facileManager
	 * @subpackage fmDNS
	 *
	 * @param array $result Database results
	 * @param integer $count Number of db results
	 * @param integer $server_id Server ID to remove from assignments
	 * @return boolean
	 */
	function updateNameServerAssignments($result, $count, $server_id) {
		global $fmdb, $__FM_CONFIG;
		
		for ($i=0; $i < $count; $i++) {
			$serverids = null;
			foreach (explode(';', $result[$i]->domain_name_servers) as $server) {
				if ($server == $server_id) continue;
				$serverids .= $server . ';';
			}
			$serverids = rtrim($serverids, ';');

			/** Set new domain_name_servers list */
			$query = "UPDATE `fm_{$__FM_CONFIG['fmDNS']['prefix']}domains` SET `domain_name_servers`='$serverids' WHERE `domain_id`={$result[$i]->domain_id} AND `account_id`='{$_SESSION['user']['account_id']}'";
			$result2 = $fmdb->query($query);
			if (!$fmdb->rows_affected) {
				return formatError(__('The associated zones for this server or group could not be updated because a database error occurred.'), 'sql');
			}
		}
		
		return true;
	}

	/**
	 * Looks up the server group IDs
	 *
	 * @since 2.0
	 * @package facileManager
	 * @subpackage fmDNS
	 *
	 * @param integer $server_id Server ID to lookup
	 * @return array
	 */
	function getServerGroupIDs($server_id) {
		global $fmdb, $__FM_CONFIG;
		
		$query = "SELECT group_id FROM `fm_{$__FM_CONFIG['fmDNS']['prefix']}server_groups` WHERE `account_id`='{$_SESSION['user']['account_id']}' AND `group_status`='active'
			AND (group_masters='$server_id' OR group_masters LIKE '$server_id;%' OR group_masters LIKE '%;$server_id;%' OR group_masters LIKE '%;$server_id' OR
			group_slaves='$server_id' OR group_slaves LIKE '$server_id;%' OR group_slaves LIKE '%;$server_id;%' OR group_slaves LIKE '%;$server_id')";
		$fmdb->get_results($query);
		if ($fmdb->num_rows) {
			for ($i=0; $i<$fmdb->num_rows; $i++) {
				$array[] = $fmdb->last_result[$i]->group_id;
			}
			return $array;
		}
		
		return false;
	}

	/**
	 * Builds an array of all servers in a group
	 *
	 * @since 2.2
	 * @package facileManager
	 * @subpackage fmDNS
	 *
	 * @param integer $group_id Group ID to lookup
	 * @return array
	 */
	function getGroupServerIDs($group_id) {
		global $fmdb, $__FM_CONFIG;
		
		$query = "SELECT group_masters, group_slaves FROM `fm_{$__FM_CONFIG['fmDNS']['prefix']}server_groups` WHERE 
			`account_id`='{$_SESSION['user']['account_id']}' AND `group_status`='active' AND `group_id`=$group_id";
		$fmdb->get_results($query);
		if ($fmdb->num_rows) {
			$servers = array_merge((array) @explode(';', $fmdb->last_result[0]->group_masters), (array) @explode(';', $fmdb->last_result[0]->group_slaves));
			return array_unique($servers);
		}
		
		return false;
	}

	/**
	 * Deletes associated configs during server/group deletion
	 *
	 * @since 2.2
	 * @package facileManager
	 * @subpackage fmDNS
	 *
	 * @param integer $server_serial_no Server serial number to query by
	 * @param string $server_id Server/Group ID to query by
	 * @return array
	 */
	function deleteServerSpecificConfigs($server_serial_no, $server_id) {
		global $fmdb, $__FM_CONFIG;
		
		/** Update all associated domains */
		$query = "SELECT domain_id,domain_name_servers FROM `fm_{$__FM_CONFIG['fmDNS']['prefix']}domains` WHERE (`domain_name_servers` LIKE '%;{$server_id};%' OR `domain_name_servers` LIKE '%;{$server_id}' OR `domain_name_servers` LIKE '{$server_id};%' OR `domain_name_servers`='{$server_id}') AND `account_id`='{$_SESSION['user']['account_id']}'";
		$fmdb->query($query);
		if ($fmdb->num_rows) {
			$result = $this->updateNameServerAssignments($fmdb->last_result, $fmdb->num_rows, $server_id);
			if ($result !== true) {
				return $result;
			}
		}

		/** Delete associated config options */
		if (updateStatus('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'config', $server_serial_no, 'cfg_', 'deleted', 'server_serial_no') === false) {
			return formatError(__('The associated server configs could not be deleted because a database error occurred.'), 'sql');
		}
		if (updateStatus('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'config', str_replace('s_', '', $server_id), 'cfg_', 'deleted', 'server_id') === false) {
			return formatError(__('The associated server configs could not be deleted because a database error occurred.'), 'sql');
		}

		/** Delete associated views */
		if (updateStatus('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'views', $server_serial_no, 'view_', 'deleted', 'server_serial_no') === false) {
			return formatError(__('The associated views could not be deleted because a database error occurred.'), 'sql');
		}

		/** Delete associated ACLs */
		if (updateStatus('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'acls', $server_serial_no, 'acl_', 'deleted', 'server_serial_no') === false) {
			return formatError(__('The associated ACLs could not be deleted because a database error occurred.'), 'sql');
		}
		
		/** Delete associated controls */
		if (updateStatus('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'controls', $server_serial_no, 'control_', 'deleted', 'server_serial_no') === false) {
			return formatError(__('The associated controls could not be deleted because a database error occurred.'), 'sql');
		}
		
		return true;
	}
	
	/**
	 * Gets config item data from key
	 *
	 * @since 3.3
	 * @package facileManager
	 * @subpackage fmDNS
	 *
	 * @param integer $server_id Server ID
	 * @param string $config_opt Config option to retrieve
	 * @return string
	 */
	function getConfig($server_id, $config_opt = null) {
		global $fmdb, $__FM_CONFIG;
		
		$return = null;
		
		/** Get the data from $config_opt */
		$query = "SELECT cfg_id,cfg_data FROM fm_{$__FM_CONFIG[$_SESSION['module']]['prefix']}config WHERE account_id='{$_SESSION['user']['account_id']}' AND cfg_status!='deleted' AND server_id='{$server_id}' AND cfg_type='global' AND cfg_name='$config_opt' ORDER BY cfg_id ASC";
		$result = $fmdb->get_results($query);
		if (!$fmdb->sql_errors && $fmdb->num_rows) {
			return $fmdb->last_result[0]->cfg_data;
		}
		
		return $return;
	}
	
	/**
	 * Gets config item data from key
	 *
	 * @since 3.3
	 * @package facileManager
	 * @subpackage fmDNS
	 *
	 * @param string $config_name Config name to get options for
	 * @param string $config_data Current config data for selection
	 * @return string
	 */
	private function buildConfigOptions($config_name, $config_data) {
		global $__FM_CONFIG, $fmdb, $fm_module_options;
		
		$query = "SELECT def_type,def_dropdown,def_minimum_version FROM fm_{$__FM_CONFIG[$_SESSION['module']]['prefix']}functions WHERE def_option = '$config_name'";
		$fmdb->get_results($query);
		if ($fmdb->num_rows) {
			/** Build array of possible values */
			if (!class_exists('fm_module_options')) {
				include(ABSPATH . 'fm-modules/' . $_SESSION['module'] . '/classes/class_options.php');
			}
			return $fm_module_options->populateDefTypeDropdown($fmdb->last_result[0]->def_type, $config_data, $config_name, 'include-blank');
		}
		
		return null;
	}
	
	/**
	 * Validates the user-submitted data (for add and edit)
	 *
	 * @since 3.3
	 * @package facileManager
	 * @subpackage fmDNS
	 *
	 * @param array $post Posted data to validate
	 * @return array
	 */
	private function validatePost($post) {
		global $fmdb, $__FM_CONFIG;
		
		if (empty($post['server_name'])) return __('No server name defined.');
		
		/** Check name field length */
		$field_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_name');
		if ($field_length !== false && strlen($post['server_name']) > $field_length) return sprintf(dngettext($_SESSION['module'], 'Server name is too long (maximum %d character).', 'Server name is too long (maximum %d characters).', $field_length), $field_length);
		
		/** Does the record already exist for this account? */
		basicGet('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', $post['server_name'], 'server_', 'server_name');
		if ($fmdb->num_rows) {
			if ($fmdb->last_result[0]->server_id != $post['server_id']) return __('This server name already exists.');
		}
		
		unset($post['tab-group-1']);
		
		if ($post['server_type'] == 'remote') {
			unset($post['server_run_as_predefined'], $post['server_update_method'], $post['server_update_port']);
			$post['server_status'] = 'active';
		} else {
			if (empty($post['server_root_dir'])) $post['server_root_dir'] = $__FM_CONFIG['ns']['named_root_dir'];
			if (empty($post['server_zones_dir'])) $post['server_zones_dir'] = $__FM_CONFIG['ns']['named_zones_dir'];
			if (empty($post['server_config_file'])) $post['server_config_file'] = $__FM_CONFIG['ns']['named_config_file'];
			if (empty($post['server_application_name'])) $post['server_application_name'] = $__FM_CONFIG['ns']['named_application_name'];

			$post['server_root_dir'] = rtrim($post['server_root_dir'], '/');
			$post['server_chroot_dir'] = rtrim($post['server_chroot_dir'], '/');
			$post['server_zones_dir'] = rtrim($post['server_zones_dir'], '/');

			/** Process server_run_as */
			$server_run_as_options = enumMYSQLSelect('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'servers', 'server_run_as_predefined');
			if (!in_array($post['server_run_as_predefined'], $server_run_as_options)) {
				$post['server_run_as'] = $post['server_run_as_predefined'];
				$post['server_run_as_predefined'] = 'as defined:';
			}

			/** Set default ports */
			if (!isset($post['server_update_method'])) $post['server_update_method'] = 'http';
			if ($post['server_update_method'] == 'cron') {
				$post['server_update_port'] = 0;
			}
			if (!empty($post['server_update_port']) && !verifyNumber($post['server_update_port'], 1, 65535, false)) return __('Server update port must be a valid TCP port.');
			if (empty($post['server_update_port'])) {
				if ($post['server_update_method'] == 'http') $post['server_update_port'] = 80;
				elseif ($post['server_update_method'] == 'https') $post['server_update_port'] = 443;
				elseif ($post['server_update_method'] == 'ssh') $post['server_update_port'] = 22;
			}
		}
		
		/** Process server_key */
		if (isset($post['keys'])) {
			$post['keys'] = join(',', $post['keys']);
		} else {
			$post['keys'] = null;
		}
		
		/** Clean up arrays */
		foreach ($post as $key => $val) {
			if (is_array($val)) {
				$post[$key] = $val[0];
			}
		}
		
		return $post;
	}
	
}

if (!isset($fm_module_servers))
	$fm_module_servers = new fm_module_servers();

?>
