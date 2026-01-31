<?php

namespace Core\Database;

/**
 * Cache Class
 *
 * This class handles caching functionality.
 *
 * @category  Cache
 * @package   Core\Database
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      -
 * @version   0.0.1
 */

class DatabaseCache
{
    /**
     * @var string The path
     */
    private $path;

    /**
     * @var string $cacheDir Directory to store cached data.
     */
    protected $cacheDir;

    /**
     * @var bool $zlibEnabled Flag to check if zlib is enabled.
     */
    private $zlibEnabled;

    /**
     * Constructor.
     *
     * @param string $cacheDir Directory to store cached data. Defaults to 'cache'.
     */
    public function __construct($cacheDir = 'cache')
    {
        // Use absolute path from ROOT_DIR constant
        $this->path = defined('ROOT_DIR') ? ROOT_DIR . 'storage' . DIRECTORY_SEPARATOR : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR;
        $this->cacheDir = $this->path . $cacheDir;
        $this->zlibEnabled = extension_loaded('zlib');
    }

    /**
     * Get cached data for a key.
     *
     * @param string $key Cache key.
     * @return mixed Cached data or null if not found or expired.
     */
    public function get($key)
    {
        $filename = $this->getCacheFilename($key);
        if (!file_exists($filename)) {
            return null;
        }

        $data = @file_get_contents($filename);
        if ($data === false) {
            return null;
        }

        $decompressedData = $this->decompressData($data);
        if ($decompressedData === false) {
            return null;
        }

        if (isset($decompressedData['expire']) && $decompressedData['expire'] !== null && time() > $decompressedData['expire']) {
            unlink($filename);
            return null;
        }

        return $decompressedData['data'];
    }

    /**
     * Set cached data for a key with expiration time.
     *
     * @param string $key Cache key.
     * @param mixed $data Data to be cached.
     * @param int|null $expire Expiration time in seconds. Null for no expiration. Defaults to 3600 (1 hour).
     * @return bool True on success, false otherwise.
     */
    public function set($key, $data, $expire = 3600)
    {
        $filename = $this->getCacheFilename($key);

        if ($expire !== null) {
            $expire = time() + $expire;
        }

        $cachedData = [
            'data' => $data,
            'expire' => $expire
        ];

        $compressedData = $this->compressData($cachedData);
        if ($compressedData === false) {
            return false;
        }

        return file_put_contents($filename, $compressedData, LOCK_EX) !== false;
    }

    /**
     * Delete cached data for a key.
     *
     * @param string $key Cache key.
     * @return bool True on success, false otherwise.
     */
    public function delete($key)
    {
        $filename = $this->getCacheFilename($key);
        return file_exists($filename) ? unlink($filename) : true;
    }

    /**
     * Generate cache filename based on key.
     *
     * @param string $key Cache key.
     * @return string Cache filename.
     */
    protected function getCacheFilename($key)
    {
        // Sanitize key and create filename
        $key = preg_replace('/[^a-zA-Z0-9_-]/', '-', $key);
        $filename = $this->cacheDir . DIRECTORY_SEPARATOR . $key . '.cache';

        if (!file_exists($filename)) {
            $directory = dirname($filename);
            if (!file_exists($directory)) {
                mkdir($directory, 0750, true);
            }
            touch($filename);
            chmod($filename, 0640);
        }

        return $filename;
    }

    /**
     * Compresses data using gzip, deflate if available, otherwise using base64.
     *
     * @param mixed $data The data to compress.
     * @return string|false Returns the compressed data as a string, or false on failure.
     */
    protected function compressData($data)
    {
        try {
            $serializedData = serialize($data);
            if ($this->zlibEnabled) {
                $compressedData = @gzcompress($serializedData, 9); // Using maximum compression level (9)
                if ($compressedData === false) {
                    // Fallback to gzdeflate if gzcompress fails
                    $compressedData = @gzdeflate($serializedData, 9);
                }
                return $compressedData;
            } else {
                return base64_encode($serializedData);
            }
        } catch (\Exception $e) {
            // Handle the error (log it, notify, etc.)
            return false;
        }
    }

    /**
     * Decompresses data compressed with gzip or base64.
     *
     * @param string $compressedData The compressed data string.
     * @return mixed|false Returns the original data or false on failure.
     */
    protected function decompressData($compressedData)
    {
        try {
            if ($this->zlibEnabled) {
                $decompressed = @gzuncompress($compressedData);
                if ($decompressed === false) {
                    // Try gzinflate as an alternative
                    $decompressed = @gzinflate($compressedData);
                }
            } else {
                $decompressed = base64_decode($compressedData);
            }
            return unserialize($decompressed);
        } catch (\Exception $e) {
            // Handle the error (log it, notify, etc.)
            return false;
        }
    }
}
