#!/usr/bin/env php
<?php
	require_once(__DIR__ . '/vendor/autoload.php');
	require_once(__DIR__ . '/config.php');

	use React\EventLoop\Factory as ReactLoopFactory;
	use React\Socket\Server as SocketServer;
	use React\Http\Server as HTTPServer;
	use React\Http\Response;
	use Psr\Http\Message\ServerRequestInterface;
	use Psr\Log\LogLevel;
	use React\ChildProcess\Process;

	if (php_sapi_name() != 'cli') { die('This should only be run from the cli.'); }

	// PSR-3 Compatible Logger.
	class Logger {
		use Psr\Log\LoggerTrait;

		// Log levels as numbers for easy comparison
		protected $levels = [LogLevel::EMERGENCY => 0,
		                     LogLevel::ALERT     => 1,
		                     LogLevel::CRITICAL  => 2,
		                     LogLevel::ERROR     => 3,
		                     LogLevel::WARNING   => 4,
		                     LogLevel::NOTICE    => 5,
		                     LogLevel::INFO      => 6,
		                     LogLevel::DEBUG     => 7
		                    ];

		private $level = LogLevel::DEBUG;

		public function __construct($level = null) {
			if ($level != null) {
				if (isset($this->levels[strtoupper($level)])) {
					$this->level = $level;
				}
			}
		}

		private function validLog($level) {
			return $this->levels[$level] <= $this->levels[$this->level];
		}

		public function log($level, $message, $context = []) {
			if ($this->validLog($level)) {
				echo sprintf('[%s] [%s] %s', date('r'), strtoupper($level), $message), "\n";
			}
		}
	}
	$logger = new Logger($config['logLevel']);

	doLog(LogLevel::INFO, 'Starting...');

	doLog(LogLevel::INFO, 'Creating Loop.');
	$loop = ReactLoopFactory::create();

	doLog(LogLevel::INFO, 'Preparing Shutdown Handler.');
	// This makes sure the shutdown handler is called.
	pcntl_signal(SIGINT, function() { exit(0); });
	pcntl_signal(SIGTERM, function() { exit(0); });
	pcntl_async_signals(true);
	register_shutdown_function(function() {
		global $jobs;
		doLog(LogLevel::INFO, 'Shutting down.');
		foreach ($jobs as $jobID => $job) {
			if ($job['state'] == 'running') {
				doLog(LogLevel::INFO, 'Terminating job: ', $jobID);
				$job['process']->terminate();
			}
		}
		doLog(LogLevel::INFO, 'All jobs terminated.');
	});


	$firstRun = $config['scripts'] . '/firstRun.sh';
	if (file_exists($firstRun) && is_executable($firstRun)) {
		doLog(LogLevel::INFO, 'Running first-run script...');

		// Create the process.
		$process = new Process($firstRun);

		// Start the process
		$process->start($loop);

		$stdout = '';
		$stderr = '';

		// Output data from the process to our logger
		$process->stdout->on('data', function ($chunk) use (&$stdout) {
			$stdout .= $chunk;
			if (strpos($stdout, "\n") !== false) {
				$bits = explode("\n", $stdout);
				$stdout = array_pop($bits);
				foreach ($bits as $b) { doLog(LogLevel::INFO, $b); }
			}
		});
		$process->stderr->on('data', function ($chunk) use (&$stderr) {
			$stderr .= $chunk;
			if (strpos($stderr, "\n") !== false) {
				$bits = explode("\n", $stderr);
				$stderr = array_pop($bits);
				foreach ($bits as $b) { doLog(LogLevel::ERROR, $b); }
			}
		});

		// Handle the process exiting.
		$process->on('exit', function($exitCode, $termSignal) use ($loop, $stdout, $stderr) {
			if (!empty($stdout)) { doLog(LogLevel::INFO, $stdout); }
			if (!empty($stderr)) { doLog(LogLevel::ERROR, $stderr); }
			doLog(LogLevel::INFO, 'first-run exited with exit code: ', $exitCode);

			$loop->stop();
		});

		$loop->run();
	}

	doLog(LogLevel::INFO, 'Preparing HTTP Listener.');
	$server = new HTTPServer(function (ServerRequestInterface $request) {
		try {
			return handleHTTPRequest($request);
		} catch (Throwable $t) {
			doLog(LogLevel::ERROR, 'Error in handleHTTPRequest: ', $t->getMessage());
			foreach (explode("\n", $t->getTraceAsString()) as $s) {
				doLog(LogLevel::ERROR, "\t", $s);
			}
			return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => 'Internal server error.']));
		}
	});
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

	$jobs = [];

	function handleHTTPRequest(ServerRequestInterface $request) {
		global $jobs, $config, $loop;

		$path = $origPath = trim($request->getURI()->getPath(), '/');
		if ($path == 'favicon.ico') { return new Response(404, [], ''); }

		doLog(LogLevel::DEBUG, 'Got Request: ', $path);
		$path = preg_replace('#/+#', '/', str_replace('./', '/', str_replace('../', '/', $path))); // Remove invalid bits from paths.
		if ($path != $origPath) { doLog(LogLevel::DEBUG, 'Handling as: ', $path); }

		$bits = explode('/', $path, 3);

		$token = $bits[0];
		$command = isset($bits[1]) ? $bits[1] : null;
		$param = isset($bits[2]) ? $bits[2] : null;

		if ($token != $config['key']) {
			return new Response(403, ['Content-Type' => 'application/json'], json_encode(['error' => 'Forbidden.']));
		}

		if ($command == 'run' && !empty($param)) {
			// Start a new process.
			$execFile = $config['scripts'] . '/' . $param;

			if ($param != 'firstRun.sh' && file_exists($execFile) && is_executable($execFile)) {
				$jobID = genUUID();
				while (isset($jobs[$jobID])) { $jobID = genUUID(); }
				$jobs[$jobID] = ['process' => null, 'state' => 'running', 'stdout' => '', 'stderr' => '', 'exitCode' => null, 'started' => time(), 'ended' => null];

				doLog(LogLevel::INFO, 'Handling run command: ', $param, ' as ', $jobID);

				// Create the process.
				$process = new Process($execFile);
				$jobs[$jobID]['process'] = $process;

				// Start the process
				$process->start($loop);

				// Capture data from the process.
				$process->stdout->on('data', function ($chunk) use ($jobID) {
					global $jobs;
					$jobs[$jobID]['stdout'] .= $chunk;
				});
				$process->stderr->on('data', function ($chunk) use ($jobID) {
					global $jobs;
					$jobs[$jobID]['stderr'] .= $chunk;
				});

				// Handle the process exiting.
				$process->on('exit', function($exitCode, $termSignal) use ($jobID) {
					global $jobs;
					doLog(LogLevel::INFO, 'Job exited: ', $jobID, ' with exit code: ', $exitCode);

					$jobs[$jobID]['ended'] = time();
					$jobs[$jobID]['process'] = null;
					$jobs[$jobID]['exitCode'] = $exitCode;
					$jobs[$jobID]['state'] = 'ended';
				});

				// Send the process any data we were given over STDIN.
				// TODO: Some data from the request would be nice, eg method,
				// headers, query params etc.
				$data = ['data' => $request->getBody()];
				$process->stdin->write(json_encode($data));
				$process->stdin->end();

				return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => 'ok', 'jobID' => $jobID]));
			}
		} else if ($command == 'info' && !empty($param)) {
			// Show info about a previously run process.
			if (isset($jobs[$param])) {
				$data = $jobs[$param];
				unset($data['process']);
				$data['success'] = 'ok';

				return new Response(200, ['Content-Type' => 'application/json'], json_encode($data));
			}

		} else if ($command == 'signal' && !empty($param)) {
			// Signal a running process.

			$bits = explode('/', $param);
			$jobID = $bits[0];
			$signal = isset($bits[1]) ? $bits[1] : 15;

			if (isset($jobs[$jobID])) {
				if ($jobs[$jobID]['state'] == 'running') {
					doLog(LogLevel::INFO, 'Sending signal ', $signal, ' to job: ', $jobID);

					$jobs[$jobID]['process']->terminate($signal);

					return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => 'ok']));
				} else {
					doLog(LogLevel::INFO, 'Unable to send signal ', $signal, ' to finished job: ', $jobID);
					return new Response(403, ['Content-Type' => 'application/json'], json_encode(['error' => 'Process has ended']));
				}
			}

		} else if ($command == 'ping') {
			return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => 'ok']));
		}

		// None of the command handlers returned a result, so return a default 404.
		return new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => 'File Not Found.']));
	}

	$loop->addPeriodicTimer(10, function () {
		global $jobs, $config;
		// Remove data for processes that finished running more than
		// $config['jobHistory'] seconds ago.

		$remove = [];
		$time = time() - $config['jobHistory'];
		foreach ($jobs as $jobID => $info) {
			if ($info['ended'] != null && $info['ended'] < $time) {
				$remove[] = $jobID;
			}
		}

		foreach ($remove as $jobID) {
			doLog(LogLevel::INFO, 'Job history removed for: ', $jobID);
			unset($jobs[$jobID]);
		}
	});

	function genUUID() {
		return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
	}

	doLog(LogLevel::INFO, 'Starting.');
	$loop->run();

	function doLog(String $level, String ...$message) {
		global $logger;
		$logger->log($level, implode($message));
	}


