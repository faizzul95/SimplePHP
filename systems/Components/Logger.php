<?php

namespace Components;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Logger Class
 *
 * Handles logging with file rotation, error handling, and additional utilities.
 *
 * @category  Components
 * @package   CT
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0.0
 */
class Logger
{
    private $logPath;

    /** @var array<string, self> */
    private static array $instances = [];

    public const LOG_LEVEL_INFO = 'INFO';
    public const LOG_LEVEL_ERROR = 'ERROR';
    public const LOG_LEVEL_WARNING = 'WARNING';
    public const LOG_LEVEL_DEBUG = 'DEBUG';

    private const MAX_LOG_SIZE = 50 * 1024 * 1024; // 50MB
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Constructor - Initializes the logger and sets a default log path if none is provided.
     *
     * @param string|null $logPath Path to the log file.
     */
    public function __construct($logPath = null)
    {
        $this->logPath = $logPath ?: self::defaultLogPath();
        $this->ensureLogDirectoryExists();
    }

    /**
     * Resolve a cached logger instance for a specific path.
     */
    public static function instance($logPath = null): self
    {
        $resolvedPath = is_string($logPath) && trim($logPath) !== ''
            ? $logPath
            : self::defaultLogPath();

        if (!isset(self::$instances[$resolvedPath])) {
            self::$instances[$resolvedPath] = new self($resolvedPath);
        }

        return self::$instances[$resolvedPath];
    }

    public static function defaultLogPath(): string
    {
        $rootDir = defined('ROOT_DIR')
            ? ROOT_DIR
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        return rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'logger.log';
    }

    /**
     * Logs a message to the default log file.
     *
     * @param string $message Log message.
     * @param string $level Log level (INFO, ERROR, WARNING, DEBUG).
     */
    public function log($message, $level = self::LOG_LEVEL_INFO)
    {
        $this->rotateLogIfNeeded();

        $logMessage = $this->formatLogMessage($message, $level);
        if (file_put_contents($this->logPath, $logMessage, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Failed to write to log file: {$this->logPath}");
        }
    }

    /**
     * Append a preformatted single-line record without the default timestamp/level prefix.
     * Useful for structured logs that are parsed line-by-line as JSON or custom trace formats.
     */
    public function appendRawLine($message)
    {
        $this->rotateLogIfNeeded();

        $line = $this->sanitizeLogValue((string) $message) . PHP_EOL;
        if (file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Failed to write to log file: {$this->logPath}");
        }
    }

    /**
     * Logs info message to the default log file.
     *
     * @param string $message Log message.
     */
    public function log_info($message)
    {
        $this->log($message);
    }

    /**
     * Logs debug message to the default log file.
     *
     * @param string $message Log message.
     */
    public function log_debug($message)
    {
        $this->log($message, self::LOG_LEVEL_DEBUG);
    }

    /**
     * Logs error message to the default log file.
     *
     * @param string $message Log message.
     */
    public function log_error($message)
    {
        $this->log($message, self::LOG_LEVEL_ERROR);
    }

    /**
     * Logs warning message to the default log file.
     *
     * @param string $message Log message.
     */
    public function log_warning($message)
    {
        $this->log($message, self::LOG_LEVEL_WARNING);
    }

    public function info($message, array $context = []): void
    {
        $context === []
            ? $this->log($message, self::LOG_LEVEL_INFO)
            : $this->logWithContext($message, $context, self::LOG_LEVEL_INFO);
    }

    public function debug($message, array $context = []): void
    {
        $context === []
            ? $this->log($message, self::LOG_LEVEL_DEBUG)
            : $this->logWithContext($message, $context, self::LOG_LEVEL_DEBUG);
    }

    public function warning($message, array $context = []): void
    {
        $context === []
            ? $this->log($message, self::LOG_LEVEL_WARNING)
            : $this->logWithContext($message, $context, self::LOG_LEVEL_WARNING);
    }

    public function error($message, array $context = []): void
    {
        $context === []
            ? $this->log($message, self::LOG_LEVEL_ERROR)
            : $this->logWithContext($message, $context, self::LOG_LEVEL_ERROR);
    }

    /**
     * Logs an exception with its message and stack trace.
     *
     * @param Throwable $exception The exception to log.
     */
    public function logException($exception)
    {
        $message = sprintf(
            "Exception: %s in %s on line %d\nStack trace:\n%s",
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        $this->log($message, self::LOG_LEVEL_ERROR);
    }

    /**
     * Logs a message with additional context.
     *
     * @param string $message Log message.
     * @param array $context Additional context data.
     * @param string $level Log level.
     */
    public function logWithContext($message, $context = [], $level = self::LOG_LEVEL_INFO)
    {
        $contextString = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($contextString === false) {
            $contextString = '{"error":"context_encoding_failed"}';
        }

        $this->log("{$message} | Context: {$contextString}", $level);
    }

    /**
     * Checks if the log file is writable.
     *
     * @return bool True if writable, false otherwise.
     */
    public function isLogWritable()
    {
        return is_writable($this->logPath);
    }

    /**
     * Gets the log file size in bytes.
     *
     * @return int Log file size.
     */
    public function getLogSize()
    {
        return file_exists($this->logPath) ? filesize($this->logPath) : 0;
    }

    /**
     * Gets the log file's creation date.
     *
     * @return string File creation date.
     */
    public function getLogFileCreationDate()
    {
        return file_exists($this->logPath) ? date(self::DATE_FORMAT, filectime($this->logPath)) : 'File does not exist';
    }

    /**
     * Gets the last modification date of the log file.
     *
     * @return string Last modified date.
     */
    public function getLogFileModificationDate()
    {
        return file_exists($this->logPath) ? date(self::DATE_FORMAT, filemtime($this->logPath)) : 'File does not exist';
    }

    /**
     * Counts the total number of log entries in the log file.
     *
     * This function reads the log file and counts the number of lines,
     * ignoring empty lines to provide an accurate count of log entries.
     *
     * @return int The total number of log entries. Returns 0 if the file does not exist.
     */
    public function countLogEntries()
    {
        if (!file_exists($this->logPath)) {
            return 0;
        }

        // Use SplFileObject to count lines without loading entire file into memory
        $count = 0;
        $file = new \SplFileObject($this->logPath, 'r');
        $file->setFlags(\SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        while (!$file->eof()) {
            $file->current();
            $file->next();
            $count++;
        }
        return $count;
    }

    /**
     * Archives old logs into a zip file.
     *
     * @param string $archivePath Path where the zip file will be stored.
     */
    public function archiveLogs($archivePath)
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Failed to create archive: {$archivePath}");
        }

        $zip->addFile($this->logPath, basename($this->logPath));
        $zip->close();
    }

    /**
     * Provides a summary of log entries.
     *
     * @return array Log summary.
     */
    public function getLogSummary()
    {
        $summary = [
            'file_path' => $this->logPath,
            'file_size' => $this->getLogSize(),
            'total_entries' => $this->countLogEntries(),
            'last_modified' => $this->getLogFileModificationDate()
        ];

        return $summary;
    }

    /**
     * Sets custom permissions on the log file.
     *
     * @param int $permissions File permissions in octal (e.g., 0644).
     */
    public function setLogPermissions($permissions)
    {
        if (!chmod($this->logPath, $permissions)) {
            throw new RuntimeException("Failed to set permissions on log file.");
        }
    }

    /**
     * Formats the log message with a timestamp and log level.
     *
     * @param string $message The log message.
     * @param string $level The log level.
     * @return string The formatted log message.
     */
    private function formatLogMessage($message, $level)
    {
        $timestamp = date(self::DATE_FORMAT);
        return "[{$timestamp}] [{$this->sanitizeLogValue((string) $level)}] {$this->sanitizeLogValue((string) $message)}" . PHP_EOL;
    }

    /**
     * Keep log records single-line and remove low ASCII control characters.
     */
    private function sanitizeLogValue(string $value): string
    {
        $value = str_replace(["\r", "\n", "\t"], ['[CR]', '[NL]', '[TAB]'], $value);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    }

    /**
     * Ensures the log directory exists and is writable.
     *
     * @throws RuntimeException If the directory cannot be created or is not writable.
     */
    private function ensureLogDirectoryExists()
    {
        $directory = dirname($this->logPath);

        if (!file_exists($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Unable to create log directory: {$directory}");
            }
        }

        if (!is_writable($directory)) {
            throw new RuntimeException("Log directory is not writable: {$directory}");
        }
    }

    /**
     * Rotates the log file if it exceeds the maximum allowed size.
     *
     * @throws RuntimeException If log rotation fails.
     */
    private function rotateLogIfNeeded()
    {
        if (file_exists($this->logPath) && filesize($this->logPath) > self::MAX_LOG_SIZE) {
            $newLogPath = sprintf('%s.%s', $this->logPath, date('Y-m-d-His'));
            if (!rename($this->logPath, $newLogPath)) {
                throw new RuntimeException("Failed to rotate log file: {$this->logPath}");
            }
        }
    }
}
