<?php

	use React\Http\Server as HTTPServer;
	use React\Http\Response;
	use Psr\Http\Message\ServerRequestInterface;
	use Psr\Log\LogLevel;

	class HTTPHandler {
		private $config = [];
		private $jobs = null;
		private $logger = null;

		private $shutdown = false;
		private $server = null;

		public function __construct($config, $jobs, $logger) {
			$this->config = $config;
			$this->jobs = $jobs;
			$this->logger = $logger;

			$this->server = new HTTPServer([$this, 'handleHTTPRequest']);
		}

		public function shutdown() {
			$this->shutdown = true;
		}

		public function getHTTPServer() {
			return $this->server;
		}

		public function handleHTTPRequest(ServerRequestInterface $request) {
			try {
				return $this->doHandleHTTPRequest($request);
			} catch (Throwable $t) {
				$this->doLog(LogLevel::ERROR, 'Error in handleHTTPRequest: ', $t->getMessage());
				foreach (explode("\n", $t->getTraceAsString()) as $s) {
					$this->doLog(LogLevel::ERROR, "\t", $s);
				}
				return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => 'Internal server error.']));
			}
		}

		private function doHandleHTTPRequest(ServerRequestInterface $request) {
			if ($this->shutdown) {
				return new Response(503, ['Content-Type' => 'application/json'], json_encode(['error' => 'Server shutting down.']));
			}

			$path = $origPath = trim($request->getURI()->getPath(), '/');
			if ($path == 'favicon.ico') { return new Response(404, [], ''); }

			$this->doLog(LogLevel::DEBUG, 'Got Request: ', $path);
			$path = preg_replace('#/+#', '/', str_replace('./', '/', str_replace('../', '/', $path))); // Remove invalid bits from paths.
			if ($path != $origPath) { $this->doLog(LogLevel::DEBUG, 'Handling as: ', $path); }

			$bits = explode('/', $path, 3);

			$token = $bits[0];
			$command = isset($bits[1]) ? $bits[1] : null;
			$param = isset($bits[2]) ? $bits[2] : null;

			if ($token != $this->config['key']) {
				return new Response(403, ['Content-Type' => 'application/json'], json_encode(['error' => 'Forbidden.']));
			}

			if ($command == 'run' && !empty($param)) {
				// Start a new process.
				$execFile = $this->config['scripts'] . '/' . $param;

				if ($param != 'firstRun.sh' && file_exists($execFile) && is_executable($execFile)) {
					$jobID = $this->jobs->newJob($execFile);
					$job = $this->jobs->getJob($jobID);

					// Send the process any data we were given over STDIN.
					$data = ['jobID' => $jobID,
					         'method' => $request->getMethod(),
					         'headers' => $request->getHeaders(),
					         'body' => (String)$request->getBody(),
					         'serverParams' => $request->getServerParams(),
					         'cookies' => $request->getCookieParams(),
					         'queryParams' => $request->getQueryParams(),
					         'attributes' => $request->getAttributes(),
					        ];
					print_r(json_encode($data, JSON_PRETTY_PRINT));
					$job['process']->stdin->write(json_encode($data));
					$job['process']->stdin->end();

					return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => 'ok', 'jobID' => $jobID]));
				}
			} else if ($command == 'info' && !empty($param)) {
				// Show info about a previously run process.
				if ($this->jobs->hasJob($param)) {
					$job = $this->jobs->getJob($param);
					unset($job['process']);
					$job['success'] = 'ok';

					return new Response(200, ['Content-Type' => 'application/json'], json_encode($job));
				}

			} else if ($command == 'signal' && !empty($param)) {
				// Signal a running process.

				$bits = explode('/', $param);
				$jobID = $bits[0];
				$signal = isset($bits[1]) ? $bits[1] : 15;

				if ($this->jobs->hasJob($jobID)) {
					$job = $this->jobs->getJob($jobID);
					if ($job['state'] == 'running') {
						$this->doLog(LogLevel::INFO, 'Sending signal ', $signal, ' to job: ', $jobID);

						$job['process']->terminate($signal);

						return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => 'ok']));
					} else {
						$this->doLog(LogLevel::INFO, 'Unable to send signal ', $signal, ' to finished job: ', $jobID);
						return new Response(403, ['Content-Type' => 'application/json'], json_encode(['error' => 'Process has ended']));
					}
				}

			} else if ($command == 'ping') {
				return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => 'ok']));
			}

			// None of the command handlers returned a result, so return a default 404.
			return new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => 'File Not Found.']));
		}

		private function doLog(String $level, String ...$message) {
			$this->logger->log($level, implode($message));
		}
	}
