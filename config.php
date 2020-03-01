<?php
	function getEnvOrDefault($var, $default) {
		$result = getEnv($var);
		return $result === FALSE ? $default : $result;
	}

	$config['key'] = getEnvOrDefault('KEY', '000000000000000000000000000000000000000000000');
	$config['port'] = getEnvOrDefault('PORT', '8010');
	$config['logLevel'] = getEnvOrDefault('LOGLEVEL', 'INFO');
	$config['scripts'] = getEnvOrDefault('SCRIPTS', __DIR__ . '/scripts/');
	$config['jobHistory'] = getEnvOrDefault('JOBHISTORY', 300);

	$localconf = getEnvOrDefault('LOCALCONF', __DIR__ . '/config.local.php');
	if (file_exists($localconf)) { include($localconf); }
