<?php
	use Psr\Log\LogLevel;

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
