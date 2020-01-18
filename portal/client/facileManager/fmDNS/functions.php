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

/**
 * fmDNS Functions
 *
 * @package fmDNS
 * @subpackage Client
 *
 */


/**
 * Prints the module help file
 *
 * @since 1.0
 * @package facileManager
 *
 * @return null
 */
function printModuleHelp () {
	global $argv;
	
	echo <<<HELP
   -D            Name of zone to dump (required by dump-zone)
   -f            Filename hosting the zone data (required by dump-zone)
   -z|zones      Build all associated zone files
     dump-cache  Dump the DNS cache
     dump-zone   Dump the specified zone data to STDOUT
     clear-cache Clear the DNS cache
     id=XX       Specify the individual DomainID to build and reload
  
HELP;
}


function installFMModule($module_name, $proto, $compress, $data, $server_location, $url) {
	global $argv;
	
	extract($server_location);

	echo fM('  --> Running version tests...');
	$app = detectDaemonVersion(true);
	if ($app === null) {
		echo "failed\n\n";
		echo fM("Cannot find a supported DNS server - please check the README document for supported DNS servers.  Aborting.\n");
		exit(1);
	}
	extract($app);
	$data['server_type'] = $server['type'];
	if (versionCheck($app_version, $proto . '://' . $hostname . '/' . $path, $compress) == true) {
		echo "ok\n";
	} else {
		echo "failed\n\n";
		echo "$app_version is not supported.\n";
		exit(1);
	}
	$data['server_version'] = $app_version;
	
	echo fM("\n  --> Tests complete.  Continuing installation.\n\n");
	
	/** Handle the update method */
	$data['server_update_method'] = processUpdateMethod($module_name, $update_method, $data, $url);

	$raw_data = getPostData(str_replace('genserial', 'addserial', $url), $data);
	$raw_data = $data['compress'] ? @unserialize(gzuncompress($raw_data)) : @unserialize($raw_data);
	
	return $data;
}


function buildConf($url, $data) {
	global $proto, $debug, $purge;
	
	$raw_data = getPostData($url, $data);
	$raw_data = $data['compress'] ? @unserialize(gzuncompress($raw_data)) : @unserialize($raw_data);
	if (!is_array($raw_data)) {
		if ($debug) echo fM($raw_data);
		addLogEntry($raw_data);
		exit(1);
	}
	extract($raw_data, EXTR_SKIP);
	$chroot_environment = false;
	
	if (dirname($server_chroot_dir)) {
		$server_root_dir = $server_chroot_dir . $server_root_dir;
		$server_zones_dir = $server_chroot_dir . $server_zones_dir;
		$server_config_file = $server_chroot_dir . $server_config_file;
		foreach ($files as $filename => $contents) {
			$new_files[$server_chroot_dir . $filename] = $contents;
		}
		$files = $new_files;
		unset($new_files);
		$chroot_environment = true;
		
		/** Add key file to chroot list */
		addChrootFiles();
	}
	
	if ($debug) {
		foreach ($files as $filename => $fileinfo) {
			if (is_array($fileinfo)) {
				extract($fileinfo, EXTR_OVERWRITE);
			} else {
				$contents = $fileinfo;
			}
			echo str_repeat('=', 50) . "\n";
			echo $filename . ":\n";
			echo str_repeat('=', 50) . "\n";
			echo $contents . "\n\n";
		}
	}
	
	$runas = ($server_run_as_predefined == 'as defined:') ? $server_run_as : $server_run_as_predefined;
	$chown_dirs = array($server_zones_dir);
	
	/** Freeze zones */
	if (isDaemonRunning('fDNS')) {
		/** Handle dynamic zones to support reloading */
		runRndcActions('freeze');
	}
		
	/** Remove previous files so there are no stale files */
	if ($purge || ($purge_config_files == 'yes' && $server_update_config == 'conf')) {
		/** Server config files */
		$path_parts = pathinfo($server_config_file);
		if (version_compare(PHP_VERSION, '5.2.0', '<')) {
			$path_parts['filename'] = str_replace('.' . $path_parts['extension'], '', $path_parts['basename']);
		}
		$config_file_pattern = $path_parts['dirname'] . DIRECTORY_SEPARATOR . $path_parts['filename'] . '.*';
		exec('ls ' . $config_file_pattern, $config_file_match);
		foreach ($config_file_match as $config_file) {
			deleteFile($config_file, $debug, $data['dryrun']);
		}
		
		/** Zone files */
		deleteFile($server_zones_dir, $debug, $data['dryrun']);
	}
	
	/** Install the new files */
	installFiles($files, $data['dryrun'], $chown_dirs, $runas);
	
	/** Reload the server */
	$message = "Reloading the server\n";
	$named_rc_script = getStartupScript($chroot_environment);
	addLogEntry($named_rc_script);
	if ($debug) echo fM($message);
	if (!$data['dryrun']) {
		addLogEntry($message);
		if (isDaemonRunning('fDNS')) {
			
			$rndc_actions = array('reload', 'thaw');
			
			/** Handle dynamic zones to support reloading */
			runRndcActions($rndc_actions);
		} else {
			$message = "The server is not running - attempting to start it\n";
			if ($debug) echo fM($message);
			addLogEntry($message);
			$named_rc_script = getStartupScript($chroot_environment);
			if ($named_rc_script === false) {
				$last_line = "Cannot locate the start script\n";
				$retval = true;
			} else {
				$last_line = system($named_rc_script . ' 2>&1', $retval);
			}
		}
		if ($retval) {
			return processReloadFailure($last_line);
		} else {
			/** Only update reloaded zones */
			$data['reload_domain_ids'] = $reload_domain_ids;
			if (!isset($server_build_all)) {
				$data['zone'] = 'update';
			}
			
			/** Update the server with a successful reload */
			$data['action'] = 'update';
			$raw_update = getPostData($url, $data);
			$raw_update = $data['compress'] ? @unserialize(gzuncompress($raw_update)) : @unserialize($raw_update);
			addLogEntry("END OF WRITNG\n");
			if ($debug) echo $raw_update;
		}
	}
	return true;
}


function detectServerType() {
	$supported_servers = array('bind9'=>'named');
	
	foreach($supported_servers as $type => $app) {
		if (findProgram($app)) return array('type'=>$type, 'app'=>$app);
	}
	
	return null;
}


function moduleAddServer() {
	/** Attempt to determine default variables */
	$named_conf = findFile('named.conf', array('/etc/named', '/etc/namedb', '/etc/bind'));
	$data['server_run_as_predefined'] = 'named';
	if ($named_conf) {
		if (function_exists('posix_getgrgid')) {
			if ($run_as = posix_getgrgid(filegroup($named_conf))) {
				$data['server_run_as_predefined'] = $run_as['name'];
			}
		}
		$data['server_config_file'] = $named_conf;
		$server_root = getParameterValue('directory', $named_conf, '"');
		
		if ($server_root === false) {
			if (file_exists($named_conf . '.options')) {
				$server_root = getParameterValue('directory', $named_conf . '.options', '"');
			}
		}
		$data['server_root_dir'] = $server_root;
		
		$data['server_zones_dir'] = (dirname($named_conf) == '/etc') ? null : dirname($named_conf) . '/zones';
	}
	$data['server_chroot_dir'] = detectChrootDir();
	
	return $data;
}


function detectDaemonVersion($return_array = false) {
	$dns_server = detectServerType();
	$dns_flags = array('named'=>'-v | sed "s/BIND //"');
	
	if ($dns_server) {
		$version = trim(shell_exec(findProgram($dns_server['app']) . ' ' . $dns_flags[$dns_server['app']]));
		if ($return_array) {
			return array('server' => $dns_server, 'app_version' => $version);
		} else return trim($version);
	}
	
	return null;
}


function getStartupScript($chroot_environment = false) {
	$distros = array(
		'Arch'      => array('/etc/rc.d/named start', findProgram('systemctl') . ' start named.service'),
		'Debian'    => array('/etc/init.d/bind9 start', findProgram('systemctl') . ' start bind9.service'),
		'Redhat'    => array('/etc/init.d/named start', findProgram('systemctl') . ' start named.service', findProgram('systemctl') . ' start named-chroot.service'),
		'SUSE'      => array('/etc/init.d/named start', findProgram('systemctl') . ' start named.service'),
		'Gentoo'    => array('/etc/init.d/named start', findProgram('systemctl') . ' start named.service'),
		'Slackware' => array('/etc/rc.d/rc.bind start', findProgram('systemctl') . ' start bind.service'),
		'FreeBSD'   => array('/usr/local/etc/rc.d/named start' , '/etc/rc.d/named start'),
		'OpenBSD'   => array('/usr/local/etc/rc.d/named start' , '/etc/rc.d/named start'),
		'Apple'     => findProgram('launchctl') . ' start org.isc.named'
		);
	
	/** Debian-based distros */
	$distros['Raspbian'] = $distros['Ubuntu'] = $distros['Fubuntu'] = $distros['Debian'];
	
	/** Redhat-based distros */
	$distros['Fedora'] = $distros['CentOS'] = $distros['ClearOS'] = $distros['Oracle'] = $distros['Scientific'] = $distros['Redhat'];

	$os = detectOSDistro();
	
	if (array_key_exists($os, $distros)) {
		if (is_array($distros[$os])) {
			foreach ($distros[$os] as $rcscript) {
				$script = preg_split('/\s+/', $rcscript);
				if (file_exists($script[0])) {
					if ($chroot_environment) {
						if (strpos($distros[$os][count($distros[$os])-1], $script[0]) !== false) {
							return $distros[$os][count($distros[$os])-1];
						}
					}
					
					return $rcscript;
				}
			}
		} else {
			return $distros[$os];
		}
	}
	
	return false;
}


function detectChrootDir() {
	switch (PHP_OS) {
		case 'Linux':
			$os = detectOSDistro();
			if (in_array($os, array('Redhat', 'CentOS', 'ClearOS', 'Oracle', 'Scientific'))) {
				if ($chroot_dir = getParameterValue('^ROOTDIR', '/etc/sysconfig/named')) return $chroot_dir;
				/** systemd unit file */
				addChrootFiles();
				if ($chroot_dir = getParameterValue('ExecStart=/usr/libexec/setup-named-chroot.sh', '/usr/lib/systemd/system/named-chroot-setup.service', ' ')) return $chroot_dir;
			}
			if (in_array($os, array('Debian', 'Ubuntu', 'Fubuntu'))) {
				if ($flags = getParameterValue('^OPTIONS', '/etc/default/bind9')) {
					$flags = explode(' ', $flags);
					if (in_array('-t', $flags)) return $flags[array_search('-t', $flags) + 1];
				}
			}
			break;
		case 'OpenBSD':
			$chroot_dir = '/var/named';
			foreach (array('/etc/rc.conf.local', '/etc/rc.conf') as $rcfile) {
				if ($chroot_dir = getParameterValue('^named_chroot', $rcfile)) break;
			}
			return $chroot_dir;
		case 'FreeBSD':
			if ($chroot_dir = getParameterValue('^named_chroot', '/etc/rc.conf')) return $chroot_dir;
			
			if ($flags = getParameterValue('^named_flags', '/etc/rc.conf')) {
				$flags = explode(' ', $flags);
				if (in_array('-t', $flags)) return $flags[array_search('-t', $flags) + 1];
			}
	}
	
	return null;
}


function manageCache($action, $message) {
	addLogEntry($message);
	if (shell_exec('ps -A | grep named | grep -vc grep') > 0) {
		$last_line = system(findProgram('rndc') . ' ' . $action . ' 2>&1', $retval);
		if ($last_line) addLogEntry($last_line);

		if ($action == 'dumpdb -cache') {
			/** Get dump-file location */
			$dump_file = system('grep dump-file /etc/named.conf* | awk \'{print $NF}\'', $retval);
			$dump_file = str_replace(array('"', ';'), '', $dump_file);

			if (file_exists($dump_file)) {
				echo file_get_contents($dump_file);
			}
		}
		
		$message = $retval ? $message . ' failed' : $message . ' completed successfully';
		echo fM($message);
		addLogEntry($message);
	} else {
		$error_msg = "The server is not running\n";
		if ($debug) echo fM($error_msg);
		addLogEntry($error_msg);
	}
	if ($retval) {
		addLogEntry($last_line);
		$message = "There was an error " . strtolower($message) . " - please check the logs for details\n";
		if ($debug) echo fM($message);
		addLogEntry($message);
		exit(1);
	}
	
	exit;
}


/**
 * Logs and outputs error messages
 *
 * @since 2.0
 * @package fmDNS
 *
 * @param string $last_line Output from previously run command
 * @return boolean
 */
function processReloadFailure($last_line) {
	if ($debug) echo fM($last_line);
	addLogEntry($last_line);
	$message = "There was an error reloading the server - please check the logs for details\n";
	if ($debug) echo fM($message);
	addLogEntry($message);
	return false;
}


/**
 * Processes module-specific web action requests
 *
 * @since 2.2
 * @package fmDNS
 *
 * @return array
 */
function moduleInitWebRequest() {
	$output = array();
	
	switch ($_POST['action']) {
		case 'reload':
			if (!isset($_POST['domain_id']) || !is_numeric($_POST['domain_id'])) {
				exit(serialize('Zone ID is not found.'));
			}

			exec(findProgram('sudo') . ' ' . findProgram('php') . ' ' . dirname(__FILE__) . '/client.php zones id=' . $_POST['domain_id'] . ' 2>&1', $rawoutput, $rc);
			if ($rc) {
				/** Something went wrong */
				$output[] = 'Zone reload failed.';
				$output = array_merge($output, $rawoutput);
			}
			break;
		case 'get_zone_contents':
			if (!isset($_POST['domain_id']) || !is_numeric($_POST['domain_id'])) {
				exit(serialize('Zone ID is not found.'));
			}
			$output['failures'] = false;
			$output['output'] = array();

			exec(findProgram('sudo') . ' ' . findProgram('php') . ' ' . dirname(__FILE__) . '/client.php ' . $_POST['command_args'], $output['output'], $rc);
			if ($rc) {
				/** Something went wrong */
				$output['failures'] = true;
			}
			break;
	}
	
	return $output;
}


/**
 * Dumps the specified zone data to STDOUT
 *
 * @since 3.0
 * @package fmDNS
 *
 * @param string $domain Domain name
 * @param string $zonefile Filename of zone file
 * @return boolean
 */
function dumpZone($domain, $zonefile) {
	passthru(findProgram('named-checkzone') . " -j -D $domain $zonefile");
	
	exit;
}


/**
 * Runs a rndc action
 *
 * @since 3.0
 * @package fmDNS
 *
 * @param array $rndc_actions rndc actions to run
 * @return boolean
 */
function runRndcActions($rndc_actions = array()) {
	if (!is_array($rndc_actions)) $rndc_actions = array($rndc_actions);
	
	$rndc = findProgram('rndc');
	
	foreach ($rndc_actions as $action) {
		addLogEntry("rndc_actions = $rndc $action\n");
		$last_line = system("$rndc $action 2>&1", $retval);
		if ($retval) {
			return processReloadFailure($last_line);
		}
	}
	
	return false;
}


/**
 * Ensures chroot files are added
 *
 * @since 3.0
 * @package fmDNS
 *
 * @return boolean
 */
function addChrootFiles() {
	if (file_exists('/usr/libexec/setup-named-chroot.sh') && !exec('grep -c ' . escapeshellarg('named.conf.keys') . ' /usr/libexec/setup-named-chroot.sh')) {
		file_put_contents('/usr/libexec/setup-named-chroot.sh', str_replace('rndc.key', 'rndc.key /etc/named.conf.keys', file_get_contents('/usr/libexec/setup-named-chroot.sh')));
	}
}


?>