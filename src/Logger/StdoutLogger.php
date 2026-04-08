<?php
declare(strict_types=1);

namespace App\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class StdoutLogger implements LoggerInterface
{
    private string $loaderName;

    public function __construct()
    {
        $this->loaderName = getenv('LOADER_NAME') ?: 'L?';
    }

    /**
     * Logs with an arbitrary level.
     * @param mixed $level
     */
    public function log($level, $message, array $context = []): void
    {
        $prefix = $this->loaderName . ':';
        $msg = $this->interpolate($message, $context);
        $line = sprintf("%s %s\n", $prefix, $msg);
        // Write all log levels to stdout so docker logs capture them
        echo $line;
    }

    public function emergency($message, array $context = array()): void { $this->log(LogLevel::EMERGENCY, $message, $context); }
    public function alert($message, array $context = array()): void     { $this->log(LogLevel::ALERT, $message, $context); }
    public function critical($message, array $context = array()): void  { $this->log(LogLevel::CRITICAL, $message, $context); }
    public function error($message, array $context = array()): void     { $this->log(LogLevel::ERROR, $message, $context); }
    public function warning($message, array $context = array()): void   { $this->log(LogLevel::WARNING, $message, $context); }
    public function notice($message, array $context = array()): void    { $this->log(LogLevel::NOTICE, $message, $context); }
    public function info($message, array $context = array()): void      { $this->log(LogLevel::INFO, $message, $context); }
    public function debug($message, array $context = array()): void     { $this->log(LogLevel::DEBUG, $message, $context); }

    private function interpolate(string $message, array $context = []): string
    {
        if (strpos($message, '{') === false) {
            return (string)$message;
        }

        $replace = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string)$val;
            } else {
                $replace['{' . $key . '}'] = json_encode($val);
            }
        }

        return strtr($message, $replace);
    }
}
