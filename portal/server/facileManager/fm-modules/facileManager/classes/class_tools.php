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
 +-------------------------------------------------------------------------+
 | http://www.facilemanager.com/                                           |
 +-------------------------------------------------------------------------+
*/

class fm_tools {
	
	/**
	 * Installs a module
	 *
	 * @since 1.0
	 * @package facileManager
	 */
	function installModule($module_name = null) {
		global $__FM_CONFIG;
		
		if (!$module_name) {
			return sprintf('<p>%s</p>', _('No module was selected to be installed.'));
		}
		
		$install_file = ABSPATH . 'fm-modules' . DIRECTORY_SEPARATOR . $module_name . DIRECTORY_SEPARATOR . 'install.php';
		if (file_exists($install_file)) {
			include($install_file);
			
			/** Include module variables */
			@include(ABSPATH . 'fm-modules/' . $module_name . '/variables.inc.php');
			
			$function = 'install' . $module_name . 'Schema';
			if (function_exists($function)) {
				$output = $function($__FM_CONFIG['db']['name'], $module_name, 'quiet');
			}
			if ($output !== true) {
				$error = (!getOption('show_errors')) ? "<p>$output</p>" : null;
				return sprintf('<p>' . _('%s installation failed!') . '</p>%s', $module_name, $error);
			}
			
			addLogEntry(sprintf(_('%s %s was born.'), $module_name, $__FM_CONFIG[$module_name]['version']), $module_name);
		} else return sprintf('<p>' . _('No installation file found for %s.') . '</p>', $module_name);
		
		return sprintf('<p>' . _('%s was installed successfully!') . '</p>', $module_name);
	}
	
	/**
	 * Upgrades a module
	 *
	 * @since 1.0
	 * @package facileManager
	 */
	function upgradeModule($module_name = null, $process = 'noisy', $running_version = null) {
		global $fmdb, $fm_name;
		
		if (!$module_name) {
			return sprintf('<p>%s</p>', _('No module was selected to be upgraded.'));
		}
		
		$upgrade_file = ABSPATH . 'fm-modules' . DIRECTORY_SEPARATOR . $module_name . DIRECTORY_SEPARATOR . 'upgrade.php';
		if (file_exists($upgrade_file)) {
			include($upgrade_file);
			
			/** Include module variables */
			require(ABSPATH . 'fm-includes/version.php');
			@include(ABSPATH . 'fm-modules/' . $module_name . '/variables.inc.php');
			
			/** Ensure the minimum core version is installed */
			if (version_compare($__FM_CONFIG[$module_name]['required_fm_version'], $fm_version, '<=') === false) {
				$fmdb->last_error = sprintf(_('%s v%s requires %s v%s or later.'), $module_name, $__FM_CONFIG[$module_name]['version'], $fm_name, $__FM_CONFIG[$module_name]['required_fm_version']);
				if ($process == 'quiet') return false;
				return sprintf('<p>' . _('%s upgrade failed!') . '</p>%s', $module_name, $fmdb->last_error);
			}
			
			$function = 'upgrade' . $module_name . 'Schema';
			if (function_exists($function)) {
				$output = $function($running_version);
			}
			if ($output !== true) {
				if ($process == 'quiet') return false;
				$error = (!getOption('show_errors')) ? "<p>$output</p>" : null;
				return sprintf('<p>' . _('%s upgrade failed!') . '</p>%s', $module_name, $error);
			} else {
				setOption('version', $__FM_CONFIG[$module_name]['version'], 'auto', false, 0, $module_name);
				if ($fmdb->last_error) {
					if ($process == 'quiet') return false;
					$error = (!getOption('show_errors')) ? '<p>' . $fmdb->last_error . '</p>' : null;
					return sprintf('<p>' . _('%s upgrade failed!') . '</p>%s', $module_name, $error);
				}
				setOption('version_check', array('timestamp' => date("Y-m-d H:i:s", strtotime("2 days ago")), 'data' => null), 'update', true, 0, $module_name);
			}

			addLogEntry(sprintf(_('%s was upgraded to %s.'), $module_name, $__FM_CONFIG[$module_name]['version']), $module_name);
		}
		
		return ($process == 'quiet') ? true : sprintf('<p>' . _('%s was upgraded successfully! Make sure you upgrade your clients with the updated client files (if applicable).') . '</p>', $module_name);
	}
	
	/**
	 * Manages a module
	 *
	 * @since 1.0
	 * @package facileManager
	 */
	function manageModule($module_name = null, $action = null) {
		global $__FM_CONFIG;
		
		if (!$module_name || !in_array($module_name, getAvailableModules())) {
			return false;
		}
		
		$current_active_modules = getOption('fm_active_modules', $_SESSION['user']['account_id']);
		$command = is_array($current_active_modules) ? 'update' : 'insert';
		
		switch($action) {
			case 'activate':
				/** Ensure $module_name is not already active */
				if (in_array($module_name, getActiveModules())) return;
				
				/** Ensure $module_name is installed */
				if (getOption('version', 0, $module_name) === false) return;
				
				$current_active_modules[] = $module_name;
				return setOption('fm_active_modules', $current_active_modules, 'auto', true, $_SESSION['user']['account_id']);

				break;
			case 'deactivate':
				/** Ensure $module_name is not already deactivated */
				if (!in_array($module_name, getActiveModules())) return;
				
				$new_array = array();
				foreach ($current_active_modules as $module) {
					if ($module == $module_name) continue;
					$new_array[] = $module;
				}

				return setOption('fm_active_modules', $new_array, 'update', true, $_SESSION['user']['account_id']);

				break;
			case 'uninstall':
				if (!in_array($module_name, getAvailableModules())) return;
				
				if (function_exists('uninstallModuleSchema')) {
					$output = uninstallModuleSchema($__FM_CONFIG['db']['name'], $module_name);
				}
				if ($output != 'Success') return false;
				
				return true;

				break;
			case 'update':
				if (!in_array($module_name, getAvailableModules())) return;
				
				$fm_new_version_available = getOption('version_check', 0, $module_name);
				if ($fm_new_version_available !== false && isset($fm_new_version_available['data']['link'])) {
					list($message, $local_update_package) = downloadfMFile($fm_new_version_available['data']['link']);
					if ($local_update_package !== false) {
						$message .= extractPackage($local_update_package);
					}
				} else {
					$message = _('No updated packages are found.');
				}
				
				$response = '<strong>' . $module_name . '</strong><br /><pre>' . $message . '</pre>';
				if (strpos($message, "\n")) $response .= sprintf('<p>%s</p>', _('The next step is to upgrade the database.'));
				
				return $response;

				break;
		}
		
		return false;
	}
	
	/**
	 * Cleans up the database
	 *
	 * @since 1.0
	 * @package facileManager
	 */
	function cleanupDatabase() {
		global $fmdb, $__FM_CONFIG, $fm_name;
		
		$record_count = 0;
		
		/** Remove deleted items */
		$raw_table_list = $fmdb->get_results("SHOW TABLES");
		
		foreach ($raw_table_list as $table_object) {
			$table_array = get_object_vars($table_object);
			$array_keys = array_keys($table_array);
			$table = $table_array[$array_keys[0]];
			if (array_key_exists($table, $__FM_CONFIG['clean']['prefixes'])) {
				$query = 'DELETE FROM ' . $table  . ' WHERE ' . $__FM_CONFIG['clean']['prefixes'][$table] . '_status = "deleted"';
				$fmdb->query($query);
				$record_count += $fmdb->rows_affected;
			}
		}
		
		/** Remove old password reset requests */
		$time = date("Y-m-d H:i:s", strtotime($__FM_CONFIG['clean']['time'] . ' ago'));
		$query = 'DELETE FROM `fm_pwd_resets` WHERE `pwd_timestamp`<"' . $time . '"';
		$fmdb->query($query);
		$record_count += $fmdb->rows_affected;
		
		addLogEntry(_('Cleaned up the database.'), $fm_name);
		return sprintf(_('Total number of records purged from the database: <b>%d</b>'), $record_count);
	}

	/**
	 * Backs up the database
	 *
	 * @since 1.0
	 * @package facileManager
	 */
	function backupDatabase() {
		global $__FM_CONFIG, $fm_name;
		
		if (!currentUserCan('run_tools')) return displayResponseClose(_('You are not authorized to run these tools.'));
		
		/** Temporary fix for MySQL 5.6 warnings */
		$exclude_warnings = array('Warning: Using a password on the command line interface can be insecure.' . "\n");
		
		$curdate = date("Y-m-d_H.i.s");
		$sql_file = getOption('fm_temp_directory') . '/' . $__FM_CONFIG['db']['name'] . '_' . $curdate . '.sql';
		$error_log = str_replace('.sql', '.err', $sql_file);
		
		$mysqldump = findProgram('mysqldump');
		if (!$mysqldump) return displayResponseClose(sprintf(_('mysqldump is not found on %s.'), php_uname('n')));
		
		$command_string = "$mysqldump --opt -Q -h {$__FM_CONFIG['db']['host']} -u {$__FM_CONFIG['db']['user']} -p{$__FM_CONFIG['db']['pass']} {$__FM_CONFIG['db']['name']} > " . getOption('fm_temp_directory') . "/{$__FM_CONFIG['db']['name']}_$curdate.sql 2>$error_log";
		@system($command_string, $retval);
		$retarr = @file_get_contents($error_log);
		
		if ($retval) {
			@unlink($error_log);
			@unlink($sql_file);
			return displayResponseClose(nl2br(str_replace($exclude_warnings, '', $retarr)));
		}
		
		compressFile($sql_file, @file_get_contents($sql_file));
		@unlink($error_log);
		@unlink($sql_file);
		
		addLogEntry(_('Backed up the database.'), $fm_name);

		sendFileToBrowser($sql_file . '.gz');
	}
	
	/**
	 * Purges the fM logs table
	 *
	 * @since 2.1
	 * @package facileManager
	 *
	 * @return string
	 */
	function purgeLogs() {
		global $fmdb, $fm_name;
		
		if (!currentUserCan('do_everything')) return displayResponseClose(_('You are not authorized to run these tools.'));
		
		$query = "TRUNCATE fm_logs";
		$fmdb->query($query);
		
		if ($fmdb->sql_errors) {
			return $fmdb->last_error;
		}

		addLogEntry(_('Purged all logs from the database.'), $fm_name);
		return _('Purged all logs from the database.');
	}


}

if (!isset($fm_tools))
	$fm_tools = new fm_tools();

?>