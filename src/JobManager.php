<?php
	use React\ChildProcess\Process;
	use Psr\Log\LogLevel;

	class JobManager {
		private $jobs = [];

		private $config = [];
		private $loop = null;
		private $logger = null;

		public function __construct($config, $loop, $logger) {
			$this->config = $config;
			$this->loop = $loop;
			$this->logger = $logger;

			$this->loop->addPeriodicTimer(10, function () {
				// Remove data for processes that finished running more than
				// $config['jobHistory'] seconds ago.

				$remove = [];
				$time = time() - $this->config['jobHistory'];
				foreach ($this->jobs as $jobID => $info) {
					if ($info['ended'] != null && $info['ended'] < $time) {
						$remove[] = $jobID;
					}
				}

				foreach ($remove as $jobID) {
					$this->doLog(LogLevel::INFO, 'Job history removed for: ', $jobID);
					unset($this->jobs[$jobID]);
				}
			});
		}

		public function shutdown() {
			foreach ($this->jobs as $jobID => $job) {
				if ($job['state'] == 'running') {
					$this->doLog(LogLevel::INFO, 'Terminating job: ', $jobID);
					$this->endJob($jobID);
				}
			}
			$this->doLog(LogLevel::INFO, 'All jobs terminated.');
		}

		public function getJobs() {
			return array_keys($this->jobs);
		}

		public function hasJob($jobID) {
			return isset($this->jobs[$jobID]);
		}

		public function getJob($jobID) {
			return $this->hasJob($jobID) ? $this->jobs[$jobID] : null;
		}

		public function endJob($jobID) {
			if ($this->hasJob($jobID)) {
				$this->jobs[$jobID]['state'] = 'terminating';
				$this->jobs[$jobID]['process']->terminate();
			}
		}

		public function newJob($command, $log = false) {
			$jobID = $this->genUUID();
			while (isset($this->jobs[$jobID])) { $jobID = $this->genUUID(); }
			$this->jobs[$jobID] = ['process' => null, 'state' => 'running', 'stdout' => '', 'stderr' => '', 'exitCode' => null, 'started' => time(), 'ended' => null];

			// Create the process.
			$process = new Process($command);
			$this->jobs[$jobID]['process'] = $process;

			// Start the process
			$process->start($this->loop);

			// Capture data from the process.
			$process->stdout->on('data', function ($chunk) use ($jobID) {
				$this->jobs[$jobID]['stdout'] .= $chunk;
			});
			$process->stderr->on('data', function ($chunk) use ($jobID) {
				$this->jobs[$jobID]['stderr'] .= $chunk;
			});

			if ($log) {
				$stdoutBuffer = '';
				$stderrBuffer = '';

				// Output data from the process to our logger
				$process->stdout->on('data', function ($chunk) use (&$stdoutBuffer) {
					$stdoutBuffer .= $chunk;
					if (strpos($stdoutBuffer, "\n") !== false) {
						$bits = explode("\n", $stdoutBuffer);
						$stdoutBuffer = array_pop($bits);
						foreach ($bits as $b) { $this->doLog(LogLevel::INFO, $b); }
					}
				});
				$process->stderr->on('data', function ($chunk) use (&$stderrBuffer) {
					$stderrBuffer .= $chunk;
					if (strpos($stderrBuffer, "\n") !== false) {
						$bits = explode("\n", $stderrBuffer);
						$stderrBuffer = array_pop($bits);
						foreach ($bits as $b) { $this->doLog(LogLevel::ERROR, $b); }
					}
				});
			}

			// Handle the process exiting.
			$process->on('exit', function($exitCode, $termSignal) use ($jobID) {
				$this->doLog(LogLevel::INFO, 'Job exited: ', $jobID, ' with exit code: ', $exitCode);

				$this->jobs[$jobID]['ended'] = time();
				$this->jobs[$jobID]['process'] = null;
				$this->jobs[$jobID]['exitCode'] = $exitCode;
				$this->jobs[$jobID]['state'] = 'ended';
			});

			return $jobID;
		}

		private function genUUID() {
			return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
		}

		private function doLog(String $level, String ...$message) {
			$this->logger->log($level, implode($message));
		}

	}
