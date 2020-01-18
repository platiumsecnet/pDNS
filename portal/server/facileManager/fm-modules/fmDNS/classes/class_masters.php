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

class fm_dns_masters {
	
	/**
	 * Displays the master list
	 */
	function rows($result, $page, $total_pages) {
		global $fmdb;
		
		$num_rows = $fmdb->num_rows;
		$results = $fmdb->last_result;

		$start = $_SESSION['user']['record_count'] * ($page - 1);
		echo displayPagination($page, $total_pages);

		if (!$result) {
			printf('<p id="table_edits" class="noresult" name="masters">%s</p>', __('There are no masters.'));
		} else {
			$table_info = array(
							'class' => 'display_results sortable',
							'id' => 'table_edits',
							'name' => 'masters'
						);

			$title_array = array(array('title' => __('Name'), 'rel' => 'master_name'), 
				array('title' => _('Comment'), 'class' => 'header-nosort'));
			if (currentUserCan('manage_servers', $_SESSION['module'])) $title_array[] = array('title' => __('Actions'), 'class' => 'header-actions header-nosort');

			echo displayTableHeader($table_info, $title_array, 'masters');
			
			$y = 0;
			for ($x=$start; $x<$num_rows; $x++) {
				if ($y == $_SESSION['user']['record_count']) break;
				$this->displayRow($results[$x]);
				$y++;
			}
			
			echo "</tbody>\n</table>\n";
		}
	}

	/**
	 * Adds the new master
	 */
	function add($post) {
		global $fmdb, $__FM_CONFIG;
		
		/** Validate post */
		$post = $this->validatePost($post);
		if (!is_array($post)) return $post;
		
		$sql_insert = "INSERT INTO `fm_{$__FM_CONFIG['fmDNS']['prefix']}masters`";
		$sql_fields = '(';
		$sql_values = null;
		
		$exclude = array('submit', 'action', 'server_id');

		foreach ($post as $key => $data) {
			$clean_data = sanitize($data);
			if ($key == 'master_name' && empty($clean_data)) return __('No master name defined.');
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
			return formatError(__('Could not add the master because a database error occurred.'), 'sql');
		}

		$log_message = sprintf(__("Added master:\nName: %s\nPort: %s\nDSCP: %s\nComment: %s"), $post['master_name'], $post['master_port'], $post['master_dscp'], $post['master_comment']);
		if (isset($post['master_parent_id'])) {
			include_once(ABSPATH . 'fm-modules/' . $_SESSION['module'] . '/classes/class_acls.php');
			$log_message = sprintf(__("An address list was added to the %s master with the following:\nAddress: %s\nPort: %s\nKey: %s\nComment: %s"), getNameFromID($post['master_parent_id'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_', 'master_id', 'master_name'), $fm_dns_acls->parseACL($post['master_addresses']), $post['master_port'], $fm_dns_acls->parseACL('key_' . $post['master_key_id']), $post['master_comment']);
		}
		addLogEntry($log_message);
		return true;
	}

	/**
	 * Updates the selected master
	 */
	function update($post) {
		global $fmdb, $__FM_CONFIG;
		
		/** Validate post */
		$post = $this->validatePost($post);
		if (!is_array($post)) return $post;
		
		$exclude = array('submit', 'action', 'server_id');

		$sql_edit = 'master_port=null, master_dscp=null, master_key_id=0, ';
		foreach ($post as $key => $data) {
			if (!in_array($key, $exclude)) {
				$sql_edit .= $key . "='" . sanitize($data) . "', ";
			}
		}
		$sql = rtrim($sql_edit, ', ');
		
		// Update the master
		include_once(ABSPATH . 'fm-modules/' . $_SESSION['module'] . '/classes/class_acls.php');
		$old_name = getNameFromID($post['master_id'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_', 'master_id', 'master_name');
		$old_address = $fm_dns_acls->parseACL(getNameFromID($post['master_id'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_', 'master_id', 'master_addresses'));
		$query = "UPDATE `fm_{$__FM_CONFIG['fmDNS']['prefix']}masters` SET $sql WHERE `master_id`={$post['master_id']}";
		$result = $fmdb->query($query);
		
		if ($fmdb->sql_errors) {
			return formatError(__('Could not update the master because a database error occurred.'), 'sql');
		}

		/** Return if there are no changes */
		if (!$fmdb->rows_affected) return true;

		$log_message = sprintf(__("Updated master '%s' to the following:\nName: %s\nPort: %s\nDSCP: %s\nComment: %s"), $old_name, $post['master_name'], $post['master_port'], $post['master_dscp'], $post['master_comment']);
		if (!$old_name) {
			$tmp_parent_id = getNameFromID($post['master_id'], 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_', 'master_id', 'master_parent_id');
			$tmp_name = getNameFromID($tmp_parent_id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_', 'master_id', 'master_name');
			$log_message = sprintf(__("Updated %s on master '%s' to the following:\nAddress: %s\nPort: %s\nKey: %s\nComment: %s"), $old_address, $tmp_name, $fm_dns_acls->parseACL($post['master_addresses']), $post['master_port'], $fm_dns_acls->parseACL('key_' . $post['master_key_id']), $post['master_comment']);
		}
		addLogEntry($log_message);
		return true;
	}
	
	
	/**
	 * Deletes the selected master
	 */
	function delete($id, $server_serial_no = 0) {
		global $fmdb, $__FM_CONFIG;
		
		$tmp_name = getNameFromID($id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_', 'master_id', 'master_name');
		$log_message = sprintf(__("master '%s' was deleted"), $tmp_name);
		if (!$tmp_name) {
			$tmp_parent_id = getNameFromID($id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_', 'master_id', 'master_parent_id');
			$tmp_address = getNameFromID($id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_', 'master_id', 'master_addresses');
			$tmp_name = getNameFromID($tmp_parent_id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_', 'master_id', 'master_name');
			$log_message = sprintf(__("%s was deleted from the %s master"), $tmp_address, $tmp_name);
		} else {
			$query = "UPDATE `fm_{$__FM_CONFIG['fmDNS']['prefix']}masters` SET `master_status`='deleted' WHERE account_id='{$_SESSION['user']['account_id']}' AND `master_parent_id`='" . sanitize($id) . "'";
			if (!$fmdb->query($query) && $fmdb->sql_errors) {
				return formatError(__('The associated master elements could not be deleted because a database error occurred.'), 'sql');
			}
		}
		if (updateStatus('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', $id, 'master_', 'deleted', 'master_id') === false) {
			return formatError(__('This master could not be deleted because a database error occurred.'), 'sql');
		} else {
			setBuildUpdateConfigFlag($server_serial_no, 'yes', 'build');
			addLogEntry($log_message);
			return true;
		}
	}


	function displayRow($row) {
		global $__FM_CONFIG;
		
		if ($row->master_status == 'disabled') $classes[] = 'disabled';
		
		if (currentUserCan('manage_servers', $_SESSION['module'])) {
			$edit_status = '<td id="row_actions">';
			$edit_status .= '<a class="edit_form_link" href="#">' . $__FM_CONFIG['icons']['edit'] . '</a>';
			if (!getConfigAssoc($row->master_id, 'master')) {
				$edit_status .= '<a class="status_form_link" href="#" rel="';
				$edit_status .= ($row->master_status == 'active') ? 'disabled' : 'active';
				$edit_status .= '">';
				$edit_status .= ($row->master_status == 'active') ? $__FM_CONFIG['icons']['disable'] : $__FM_CONFIG['icons']['enable'];
				$edit_status .= '</a>';
				$edit_status .= '<a href="#" class="delete">' . $__FM_CONFIG['icons']['delete'] . '</a>';
			}
			$edit_status .= '</td>';
		} else {
			$edit_status = null;
		}
		
		$edit_name = '<b>' . $row->master_name . '</b>';
		if (currentUserCan('manage_servers', $_SESSION['module'])) {
			$edit_name .= displayAddNew('masters', $row->master_id, null, 'fa fa-plus-square-o');
		}
		$edit_addresses = nl2br(str_replace(',', "\n", $row->master_addresses));
		$edit_addresses = $this->getMasterElements($row->master_id);
		$element_names = $element_comment = null;
		foreach ($edit_addresses as $element_id => $element_array) {
			$comment = $element_array['element_comment'] ? $element_array['element_comment'] : '&nbsp;';
			$element_names .= '<p class="subelement' . $element_id . '"><span>' . $element_array['element_addresses'] . 
					'</span>' . $element_array['element_edit'] . $element_array['element_delete'] . "</p>\n";
			$element_comment .= '<p class="subelement' . $element_id . '">' . $comment . '</p>' . "\n";
		}
		if ($element_names) $classes[] = 'subelements';
		
		$comments = nl2br($row->master_comment) . '&nbsp;';

		$class = 'class="' . implode(' ', $classes) . '"';

		echo <<<HTML
		<tr id="$row->master_id" name="$row->master_name" $class>
			<td>$edit_name $element_names</td>
			<td>$comments $element_comment</td>
			$edit_status
		</tr>
HTML;
	}

	/**
	 * Displays the form to add new master
	 */
	function printForm($data = '', $action = 'add') {
		global $__FM_CONFIG;
		
		$master_id = $parent_id = $master_key_id = 0;
		$master_name = $master_addresses = $master_comment = $master_port = $master_dscp = null;
		$ucaction = ucfirst($action);
		$server_serial_no = (isset($_REQUEST['request_uri']['server_serial_no']) && ((is_int($_REQUEST['request_uri']['server_serial_no']) && $_REQUEST['request_uri']['server_serial_no'] > 0) || $_REQUEST['request_uri']['server_serial_no'][0] == 'g')) ? sanitize($_REQUEST['request_uri']['server_serial_no']) : 0;
		
		if (!empty($_POST) && array_key_exists('add_form', $_POST)) {
			if (is_array($_POST))
				extract($_POST);
		} elseif (@is_object($data[0])) {
			extract(get_object_vars($data[0]));
			if (isset($master_parent_id)) $parent_id = $_POST['parent_id'] = $master_parent_id;
		}
		
		$master_addresses = str_replace(',', "\n", rtrim(str_replace(' ', '', $master_addresses), ';'));

		/** Get field length */
		$master_name_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_name');

		if ($parent_id) {
			$popup_title = $action == 'add' ? __('Add Master Element') : __('Edit Master Element');
		} else {
			$popup_title = $action == 'add' ? __('Add Master') : __('Edit Master');
		}
		$popup_header = buildPopup('header', $popup_title);
		$popup_footer = buildPopup('footer');
		
		if (!$parent_id) {
			$return_form = sprintf('<form name="manage" id="manage" method="post" action="">
		%s
			<input type="hidden" name="action" value="%s" />
			<input type="hidden" name="master_id" value="%d" />
			<input type="hidden" name="server_serial_no" value="%s" />
			<table class="form-table">
				<tr>
					<th width="33&#37;" scope="row"><label for="master_name">%s</label></th>
					<td width="67&#37;"><input name="master_name" id="master_name" type="text" value="%s" size="40" placeholder="%s" maxlength="%d" /></td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="master_port">%s</label></th>
					<td width="67&#37;"><input name="master_port" id="master_port" type="text" value="%s" maxlength="5" style="width: 5em;" onkeydown="return validateNumber(event)" /></td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="master_dscp">%s</label> <a href="#" class="tooltip-top" data-tooltip="%s"><i class="fa fa-question-circle"></i></a></th>
					<td width="67&#37;"><input name="master_dscp" id="master_dscp" type="text" value="%s" maxlength="2" style="width: 5em;" onkeydown="return validateNumber(event)" /></td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="master_comment">%s</label></th>
					<td width="67&#37;"><textarea id="master_comment" name="master_comment" rows="4" cols="26">%s</textarea></td>
				</tr>
			</table>
		%s
		</form>
		<script>
			$(document).ready(function() {
				$("#manage select").select2({
					width: "200px",
					minimumResultsForSearch: 10
				});
			});
		</script>',
				$popup_header,
				$action, $master_id, $server_serial_no,
				__('Master Name'), $master_name, __('master-ips-name'), $master_name_length,
				__('Port'), $master_port, __('DSCP'), __('This option requires BIND 9.9 or greater.'), $master_dscp,
				_('Comment'), $master_comment,
				$popup_footer
			);
		} else {
			global $fm_module_servers;
			
			$disabled = (strpos($master_addresses, 'master_') !== false) ? 'disabled' : null;
			$master_keys = buildSelect('master_key_id', 'master_key_id', $fm_module_servers->availableItems('key', 'blank', 'AND key_type="tsig"'), explode(';', $master_key_id), 1, $disabled, false, null, null, __('Select a key'));
			
			$return_form = sprintf('<form name="manage" id="manage" method="post" action="">
		%s
			<input type="hidden" name="action" value="%s" />
			<input type="hidden" name="master_id" value="%d" />
			<input type="hidden" name="master_parent_id" value="%d" />
			<input type="hidden" name="server_serial_no" value="%s" />
			<table class="form-table">
				<tr>
					<th width="33&#37;" scope="row">%s</th>
					<td width="67&#37;">%s</td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="master_addresses">%s</label> <a href="#" class="tooltip-top" data-tooltip="%s"><i class="fa fa-question-circle"></i></a></th>
					<td width="67&#37;"><input type="hidden" id="master_addresses" name="master_addresses" class="address_match_element" value="%s" /></td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="master_port">%s</label></th>
					<td width="67&#37;"><input name="master_port" id="master_port" type="text" value="%s" maxlength="5" style="width: 5em;" onkeydown="return validateNumber(event)" %s /></td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="master_key_id">%s</label></th>
					<td width="67&#37;">%s</td>
				</tr>
				<tr>
					<th width="33&#37;" scope="row"><label for="master_comment">%s</label></th>
					<td width="67&#37;"><textarea id="master_comment" name="master_comment" rows="4" cols="26">%s</textarea></td>
				</tr>
			</table>
		%s
		</form>
		<script>
			$(document).ready(function() {
				$("#manage select").select2({
					width: "200px",
					minimumResultsForSearch: 10
				});
				$(".address_match_element").select2({
					createSearchChoice:function(term, data) { 
						if ($(data).filter(function() { 
							return this.text.localeCompare(term)===0; 
						}).length===0) 
						{return {id:term, text:term};} 
					},
					multiple: true,
					maximumSelectionSize: 1,
					width: "200px",
					tokenSeparators: [",", " ", ";"],
					data: %s
				});
			});
		</script>',
				$popup_header,
				$action, $master_id, $parent_id, $server_serial_no,
				__('master Name'), getNameFromID($parent_id, 'fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_', 'master_id', 'master_name'),
				__('Matched Address List'), __('Choose an existing master or type a new one.'), $master_addresses, __('Port'), $master_port, $disabled,
				__('Key'), $master_keys, _('Comment'), $master_comment,
				$popup_footer,
				$this->getPredefinedMasters('JSON', $master_addresses)
			);
		}

		return $return_form;
	}

	/**
	 * Gets the master listing
	 */
	function getMasterList($server_serial_no = 0, $include = 'available') {
		global $__FM_CONFIG, $fmdb;
		
		if ($include == 'none') return array();
		
		$master_list = array();
		$i = 0;
		$serial_sql = $server_serial_no ? "AND server_serial_no IN ('0','$server_serial_no')" : "AND server_serial_no='0' ";
		$parent_sql = ($include == 'all') ? null : "AND master_id!={$_POST['parent_id']} ";
		
		basicGetList('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_id', 'master_', $serial_sql . $parent_sql . " AND master_parent_id=0 AND master_status='active'");
		if ($fmdb->num_rows) {
			$last_result = $fmdb->last_result;
			for ($j=0; $j<$fmdb->num_rows; $j++) {
				$master_list[$i]['id'] = 'master_' . $last_result[$j]->master_id;
				$master_list[$i]['text'] = $last_result[$j]->master_name;
				$i++;
			}
		}
		
		return $master_list;
	}

	/**
	 * Builds the master listing JSON
	 */
	function buildMasterJSON($saved_masters, $server_serial_no = 0, $include = 'all') {
		$available_masters = $this->getMasterList($server_serial_no, $include);
		$temp_masters = array();
		foreach ($available_masters as $temp_master_array) {
			$temp_masters[] = $temp_master_array['id'];
		}
		$i = count($available_masters);
		foreach (explode(',', $saved_masters) as $saved_master) {
			if (!$saved_master) continue;
			if (array_search($saved_master, $temp_masters) === false) {
				$available_masters[$i]['id'] = $saved_master;
				$available_masters[$i]['text'] = $saved_master;
				$i++;
			}
		}
		$available_masters = json_encode($available_masters);
		unset($temp_master_array, $temp_masters);
		
		return $available_masters;
	}
	
	
	/**
	 * Build array of predefined masters
	 *
	 * @since 3.2
	 * @package facileManager
	 * @subpackage fmDNS
	 *
	 * @param string $encode Whether return is encoded (JSON)
	 * @param string $addl_address Add any additional addresses to the array
	 * @return array
	 */
	function getPredefinedMasters($encode = null, $addl_address = null) {
		global $fm_dns_acls;
		
		$i = 0;
		$list = array(array('id' => null, 'text' => null));
		$list = $this->getMasterList();
		$i = count($list);
		
		if ($addl_address) {
			$list[$i]['id'] = $addl_address;
			$list[$i]['text'] = $fm_dns_acls->parseACL($addl_address);
		}
		
		return ($encode == 'JSON') ? json_encode($list) : $list;
	}

	/**
	 * Build array of masters
	 *
	 * @since 3.2
	 * @package facileManager
	 * @subpackage fmDNS
	 *
	 * @param integer $master_id Master ID to query
	 * @return array
	 */
	function getMasterElements($master_parent_id) {
		global $fmdb, $__FM_CONFIG, $fm_dns_acls;
		
		$return = null;
		basicGet('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', $master_parent_id, 'master_', 'master_parent_id', 'ORDER BY master_id');
		if ($fmdb->num_rows) {
			if (!class_exists('fm_dns_acls')) {
				include_once(ABSPATH . 'fm-modules/' . $_SESSION['module'] . '/classes/class_acls.php');
			}
			$count = $fmdb->num_rows;
			$element_array = $fmdb->last_result;
			for ($i=0; $i<$count; $i++) {
				$element_id = $element_array[$i]->master_id;
				$return[$element_id]['element_addresses'] = $fm_dns_acls->parseACL($element_array[$i]->master_addresses);
				
				/** Delete permitted? */
				if (currentUserCan(array('manage_servers'), $_SESSION['module'])) {
					$return[$element_id]['element_edit'] = '<a class="subelement_edit" name="master" href="#" id="' . $element_id . '">' . $__FM_CONFIG['icons']['edit'] . '</a>';
					$return[$element_id]['element_delete'] = ' ' . str_replace('__ID__', $element_id, $__FM_CONFIG['module']['icons']['sub_delete']);
				} else {
					$return[$element_id]['element_delete'] = $return[$element_id]['element_edit'] = null;
				}
				
				/** Element Comment */
				$return[$element_id]['element_comment'] = $element_array[$i]->master_comment;
			}
		}
		return $return;
	}
	
	
	/**
	 * Validates the post
	 *
	 * @since 3.2
	 * @package facileManager
	 * @subpackage fmDNS
	 *
	 * @param array $post Array to validate
	 * @return array
	 */
	function validatePost($post) {
		global $fmdb, $__FM_CONFIG;
		
		/** Check name field length */
		$field_length = getColumnLength('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', 'master_name');
		if ($field_length !== false && strlen($post['master_name']) > $field_length) return sprintf(dngettext($_SESSION['module'], 'Master name is too long (maximum %d character).', 'Master name is too long (maximum %d characters).', $field_length), $field_length);
		
		/** Does the record already exist for this account? */
		if (array_key_exists('master_name', $post)) {
			basicGet('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'masters', sanitize($post['master_name']), 'master_', 'master_name', 'AND server_serial_no=' . $post['server_serial_no']);
			if ($fmdb->num_rows) {
				if ($fmdb->last_result[0]->master_id != $post['master_id']) return __('This master already exists.');
			}
		}
		
		if (!$post['master_port']) unset($post['master_port']);
		if (!$post['master_dscp']) unset($post['master_dscp']);
		if (!$post['master_key_id']) unset($post['master_key_id']);
		
		$post['account_id'] = $_SESSION['user']['account_id'];
		$post['master_comment'] = trim($post['master_comment']);
		
		if (array_key_exists('master_parent_id', $post)) {
			if (empty($post['master_addresses'])) {
				return __('Allowed addresses not defined.');
			}
			/** Cleans up master_addresses for future parsing **/
			if (strpos($post['master_addresses'], 'master_') === false) {
				if (!verifyIPAddress($post['master_addresses'])) return sprintf(__('%s is not a valid IP address.'), $post['master_addresses']);
			} else {
				unset($post['master_port'], $post['master_key_id']);
			}
		}
		
		if (!array_key_exists('master_parent_id', $post)  && $post['master_dscp']) {
			if (!verifyNumber($post['master_dscp'], 0, 95)) return sprintf(__('%d is not a valid port number.'), $post['master_dscp']);
		}
		
		if (!empty($post['master_port'])) {
			if (!verifyNumber($post['master_port'], 0, 65535)) return sprintf(__('%d is not a valid port number.'), $post['master_port']);
		}
		
		return $post;
	}
	
}

if (!isset($fm_dns_masters))
	$fm_dns_masters = new fm_dns_masters();

?>
