<?php

namespace Psalm\Internal\Provider;

use JsonException;
use PhpParser;
use PhpParser\Node\Stmt;
use Psalm\Config;
use RuntimeException;

use function error_log;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function gettype;
use function igbinary_serialize;
use function igbinary_unserialize;
use function is_array;
use function is_dir;
use function is_readable;
use function is_writable;
use function json_decode;
use function json_encode;
use function md5;
use function mkdir;
use function scandir;
use function serialize;
use function touch;
use function trigger_error;
use function unlink;
use function unserialize;

use const DIRECTORY_SEPARATOR;
use const E_USER_ERROR;
use const JSON_THROW_ON_ERROR;
use const SCANDIR_SORT_NONE;

/**
 * @internal
 */
class ParserCacheProvider
{
    private const FILE_HASHES = 'file_hashes_json';
    private const PARSER_CACHE_DIRECTORY = 'php-parser';
    private const FILE_CONTENTS_CACHE_DIRECTORY = 'file-caches';

    /**
     * A map of filename hashes to contents hashes
     *
     * @var array<string, string>|null
     */
    private $existing_file_content_hashes;

    /**
     * A map of recently-added filename hashes to contents hashes
     *
     * @var array<string, string>
     */
    private $new_file_content_hashes = [];

    /**
     * @var bool
     */
    private $use_file_cache;

    /** @var bool */
    private $use_igbinary;

    public function __construct(Config $config, bool $use_file_cache = true)
    {
        $this->use_igbinary = $config->use_igbinary;
        $this->use_file_cache = $use_file_cache;
    }

    /**
     * @return list<PhpParser\Node\Stmt>|null
     */
    public function loadStatementsFromCache(
        string $file_path,
        int $file_modified_time,
        string $file_content_hash
    ): ?array {
        $root_cache_directory = Config::getInstance()->getCacheDirectory();

        if (!$root_cache_directory) {
            return null;
        }

        $file_cache_key = $this->getParserCacheKey(
            $file_path
        );

        $parser_cache_directory = $root_cache_directory . DIRECTORY_SEPARATOR . self::PARSER_CACHE_DIRECTORY;

        $file_content_hashes = $this->new_file_content_hashes + $this->getExistingFileContentHashes();

        $cache_location = $parser_cache_directory . DIRECTORY_SEPARATOR . $file_cache_key;

        if (isset($file_content_hashes[$file_cache_key])
            && $file_content_hash === $file_content_hashes[$file_cache_key]
            && is_readable($cache_location)
            && filemtime($cache_location) > $file_modified_time
        ) {
            if ($this->use_igbinary) {
                /** @var list<Stmt> */
                $stmts = igbinary_unserialize((string)file_get_contents($cache_location));
            } else {
                /** @var list<Stmt> */
                $stmts = unserialize((string)file_get_contents($cache_location));
            }

            return $stmts;
        }

        return null;
    }

    /**
     * @return list<PhpParser\Node\Stmt>|null
     */
    public function loadExistingStatementsFromCache(string $file_path): ?array
    {
        $root_cache_directory = Config::getInstance()->getCacheDirectory();

        if (!$root_cache_directory) {
            return null;
        }

        $file_cache_key = $this->getParserCacheKey(
            $file_path
        );

        $parser_cache_directory = $root_cache_directory . DIRECTORY_SEPARATOR . self::PARSER_CACHE_DIRECTORY;

        $cache_location = $parser_cache_directory . DIRECTORY_SEPARATOR . $file_cache_key;

        if (is_readable($cache_location)) {
            if ($this->use_igbinary) {
                /** @var list<Stmt> */
                return igbinary_unserialize((string)file_get_contents($cache_location)) ?: null;
            }

            /** @var list<Stmt> */
            return unserialize((string)file_get_contents($cache_location)) ?: null;
        }

        return null;
    }

    public function loadExistingFileContentsFromCache(string $file_path): ?string
    {
        if (!$this->use_file_cache) {
            return null;
        }

        $root_cache_directory = Config::getInstance()->getCacheDirectory();

        if (!$root_cache_directory) {
            return null;
        }

        $file_cache_key = $this->getParserCacheKey(
            $file_path
        );

        $parser_cache_directory = $root_cache_directory . DIRECTORY_SEPARATOR . self::FILE_CONTENTS_CACHE_DIRECTORY;

        $cache_location = $parser_cache_directory . DIRECTORY_SEPARATOR . $file_cache_key;

        if (is_readable($cache_location)) {
            return file_get_contents($cache_location);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function getExistingFileContentHashes(): array
    {
        $config = Config::getInstance();
        $root_cache_directory = $config->getCacheDirectory();

        if ($this->existing_file_content_hashes === null) {
            $file_hashes_path = $root_cache_directory . DIRECTORY_SEPARATOR . self::FILE_HASHES;

            if ($root_cache_directory && is_readable($file_hashes_path)) {
                $hashes_encoded = (string) file_get_contents($file_hashes_path);

                if (!$hashes_encoded) {
                    error_log('Unexpected value when loading from file content hashes');
                    $this->existing_file_content_hashes = [];

                    return [];
                }

                try {
                    $hashes_decoded = json_decode($hashes_encoded, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    error_log('Failed to parse hashes: ' . $e->getMessage());
                    $this->existing_file_content_hashes = [];

                    return [];
                }

                if (!is_array($hashes_decoded)) {
                    error_log('Unexpected value ' . gettype($hashes_decoded));
                    $this->existing_file_content_hashes = [];

                    return [];
                }

                /** @var array<string, string> $hashes_decoded */
                $this->existing_file_content_hashes = $hashes_decoded;
            } else {
                $this->existing_file_content_hashes = [];
            }
        }

        return $this->existing_file_content_hashes;
    }

    /**
     * @param  list<PhpParser\Node\Stmt>        $stmts
     */
    public function saveStatementsToCache(
        string $file_path,
        string $file_content_hash,
        array $stmts,
        bool $touch_only
    ): void {
        $root_cache_directory = Config::getInstance()->getCacheDirectory();

        if (!$root_cache_directory) {
            return;
        }

        $file_cache_key = $this->getParserCacheKey(
            $file_path
        );

        $parser_cache_directory = $root_cache_directory . DIRECTORY_SEPARATOR . self::PARSER_CACHE_DIRECTORY;

        $cache_location = $parser_cache_directory . DIRECTORY_SEPARATOR . $file_cache_key;

        if ($touch_only) {
            touch($cache_location);
        } else {
            $this->createCacheDirectory($parser_cache_directory);

            if ($this->use_igbinary) {
                file_put_contents($cache_location, igbinary_serialize($stmts));
            } else {
                file_put_contents($cache_location, serialize($stmts));
            }

            $this->new_file_content_hashes[$file_cache_key] = $file_content_hash;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getNewFileContentHashes(): array
    {
        return $this->new_file_content_hashes;
    }

    /**
     * @param array<string, string> $file_content_hashes
     *
     */
    public function addNewFileContentHashes(array $file_content_hashes): void
    {
        $this->new_file_content_hashes = $file_content_hashes + $this->new_file_content_hashes;
    }

    public function saveFileContentHashes(): void
    {
        $root_cache_directory = Config::getInstance()->getCacheDirectory();

        if (!$root_cache_directory) {
            return;
        }

        $file_content_hashes = $this->new_file_content_hashes + $this->getExistingFileContentHashes();

        $file_hashes_path = $root_cache_directory . DIRECTORY_SEPARATOR . self::FILE_HASHES;

        file_put_contents(
            $file_hashes_path,
            json_encode($file_content_hashes, JSON_THROW_ON_ERROR)
        );
    }

    public function cacheFileContents(string $file_path, string $file_contents): void
    {
        if (!$this->use_file_cache) {
            return;
        }

        $root_cache_directory = Config::getInstance()->getCacheDirectory();

        if (!$root_cache_directory) {
            return;
        }

        $file_cache_key = $this->getParserCacheKey(
            $file_path
        );

        $parser_cache_directory = $root_cache_directory . DIRECTORY_SEPARATOR . self::FILE_CONTENTS_CACHE_DIRECTORY;

        $cache_location = $parser_cache_directory . DIRECTORY_SEPARATOR . $file_cache_key;

        $this->createCacheDirectory($parser_cache_directory);

        file_put_contents($cache_location, $file_contents);
    }

    public function deleteOldParserCaches(float $time_before): int
    {
        $cache_directory = Config::getInstance()->getCacheDirectory();

        if (!$cache_directory) {
            return 0;
        }

        $removed_count = 0;

        $cache_directory .= DIRECTORY_SEPARATOR . self::PARSER_CACHE_DIRECTORY;

        if (is_dir($cache_directory)) {
            $directory_files = scandir($cache_directory, SCANDIR_SORT_NONE);

            foreach ($directory_files as $directory_file) {
                $full_path = $cache_directory . DIRECTORY_SEPARATOR . $directory_file;

                if ($directory_file[0] === '.') {
                    continue;
                }

                if (filemtime($full_path) < $time_before && is_writable($full_path)) {
                    unlink($full_path);
                    ++$removed_count;
                }
            }
        }

        return $removed_count;
    }

    private function getParserCacheKey(string $file_name): string
    {
        return md5($file_name) . ($this->use_igbinary ? '-igbinary' : '') . '-r';
    }

    private function createCacheDirectory(string $parser_cache_directory): void
    {
        if (!is_dir($parser_cache_directory)) {
            try {
                mkdir($parser_cache_directory, 0777, true);
            } catch (RuntimeException $e) {
                // Race condition (#4483)
                if (!is_dir($parser_cache_directory)) {
                    trigger_error('Could not create parser cache directory: ' . $parser_cache_directory, E_USER_ERROR);
                }
            }
        }
    }
}
