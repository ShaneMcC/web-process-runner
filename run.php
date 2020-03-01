#!/usr/bin/env php
<?php
	require_once(__DIR__ . '/vendor/autoload.php');
	require_once(__DIR__ . '/config.php');

	use React\EventLoop\Factory as ReactLoopFactory;
	use React\Socket\Server as SocketServer;
	use Psr\Log\LogLevel;

	if (php_sapi_name() != 'cli') { die('This should only be run from the cli.'); }

	$logger = new Logger($config['logLevel']);

	doLog(LogLevel::INFO, 'Starting...');

	doLog(LogLevel::INFO, 'Creating Loop.');
	$loop = ReactLoopFactory::create();

	doLog(LogLevel::INFO, 'Creating JobManager.');
	$jobs = new JobManager($config, $loop, $logger);

	doLog(LogLevel::INFO, 'Creating HTTP Handler.');
	$httpHandler = new HTTPHandler($config, $jobs, $logger);

	doLog(LogLevel::INFO, 'Preparing Shutdown Handler.');
	// This makes sure the shutdown handler is called.
	pcntl_signal(SIGINT, function() { exit(0); });
	pcntl_signal(SIGTERM, function() { exit(0); });
	pcntl_async_signals(true);
	register_shutdown_function(function() use ($jobs, $httpHandler) {
		doLog(LogLevel::INFO, 'Shutting down.');
		$jobs->shutdown();
		$httpHandler->shutdown();
	});

	$firstRun = $config['scripts'] . '/firstRun.sh';
	if (file_exists($firstRun) && is_executable($firstRun)) {
		doLog(LogLevel::INFO, 'Running first-run script...');

		$jobID = $jobs->newJob($firstRun, true);
		$job = $jobs->getJob($jobID);

		// Handle the process exiting.
		$job['process']->on('exit', function($exitCode, $termSignal) use ($loop) {
			// Stop the loop to let startup continue.
			$loop->stop();

			if ($exitCode != 0) {
				doLog(LogLevel::ERROR, 'First-run did not complete successfully, exiting.');
				die();
			}
		});

		// Start the loop to let the job run.
		$loop->run();
	}

	doLog(LogLevel::INFO, 'Preparing HTTP Server Listener.');
	$server = $httpHandler->getHTTPServer();
	$listenPort = preg_match('#^[0-9]+$#', $config['port']) ? '0.0.0.0:' . $config['port'] : $config['port'];
	$socket = new SocketServer($listenPort, $loop);
	$server->listen($socket);
	doLog(LogLevel::INFO, 'Server Listening on: ', $listenPort);

	$server->on('error', function (Throwable $t) {
		doLog(LogLevel::ERROR, 'Error: ', $t->getMessage());
		foreach (explode("\n", $t->getTraceAsString()) as $s) {
			doLog(LogLevel::ERROR, "\t", $s);
		}
	});

	doLog(LogLevel::INFO, 'Starting.');
	$loop->run();

	function doLog(String $level, String ...$message) {
		global $logger;
		$logger->log($level, implode($message));
	}
