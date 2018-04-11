<?php declare(strict_types=1);

namespace Compolomus\Cache;

use DateInterval;
use DateTime;
use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileObject;

#use Psr\SimpleCache\CacheInterface;

class FileCache// implements CacheInterface
{

    private $cachePath;

    /**
     * FileCache constructor.
     * @param null|string $cachePath
     */
    public function __construct(?string $cachePath = null)
    {
        $this->cachePath = $cachePath ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cache';
        is_dir($this->cachePath) ?: mkdir($this->cachePath, 0775, true);
    }

    /**
     * Clear cache directory
     *
     * @return bool
     */
    public function clear(): bool
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->cachePath,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        if (iterator_count($iterator)) {
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }

        return rmdir($this->cachePath);
    }

    /**
     * @param array $keys
     * @param mixed $default
     * @return array
     * @throws LogicException
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public function getMultiple(array $keys, $default = null): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key) ?: $default;
        }

        return $values;
    }

    /**
     * @param string $key
     * @param null $default
     * @return mixed
     * @throws LogicException
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public function get(string $key, $default = null)
    {
        $keyFile = $this->getFilename($key);
        $file = file_exists($keyFile) && $this->has($key) ? realpath($keyFile) : null;

        return $file ? unserialize((new SplFileObject($file))->fread(filesize($file)),
            ['allowed_classes' => false]) : $default;
    }

    /**
     * @param string $key
     * @return string
     * @throws InvalidArgumentException
     */
    private function getFilename(string $key): string
    {
        $this->validateKey($key);
        $sha1 = sha1($key);

        return $this->cachePath . DIRECTORY_SEPARATOR
            . substr($sha1, 0, 2) . DIRECTORY_SEPARATOR
            . substr($sha1, 2, 2) . DIRECTORY_SEPARATOR
            . $sha1 . '.cache';
    }

    /**
     * @param $key
     * @return bool
     * @throws InvalidArgumentException
     */
    private function validateKey(string $key): bool
    {
        if (preg_match('#[{}()/\\\@:]#', $key)) {
            throw new InvalidArgumentException('Can\'t validate the specified key');
        }

        return true;
    }

    /**
     * Isset and life item
     *
     * @param string $key
     * @return bool
     * @throws InvalidArgumentException
     */
    public function has(string $key): bool
    {
        $filename = $this->getFilename($key);
        if (!file_exists($filename)) {
            return false;
        }
        if ($this->isLife(filemtime($filename))) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * @param $ttl int
     * @return bool
     */
    private function isLife($ttl): bool
    {
        return $ttl < time();
    }

    /**
     * @param string $key
     * @return bool
     * @throws InvalidArgumentException
     */
    public function delete($key): bool
    {
        return unlink($this->getFilename($key));
    }

    /**
     * @param array $values
     * @param int $ttl
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     * @return bool
     */
    public function setMultiple(array $values, $ttl = null): bool
    {
        $status = [];
        foreach ($values as $key => $value) {
            $status[$key] = $this->set($key, $value, $ttl);
        }

        return \in_array(true, $status, true) ? false : true;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param null|int|\DateInterval $ttl
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     * @return bool
     */
    public function set(string $key, $value, $ttl = null): bool
    {
        $file = $this->getFilename($key);
        $dir = \dirname($file);
        is_dir($dir) ?: mkdir($dir, 0775, true);

        switch ($ttl) {
            case \is_int($ttl):
                $ttl += time();
                break;
            case ($ttl instanceof DateInterval):
                $ttl = (new DateTime())->add($ttl)->getTimestamp();
                break;
            case (null === $ttl):
            default:
                $ttl = time() + 15;
        }

        $data = new SplFileObject($file, 'wb');
        $data->fwrite(serialize($value));
        return touch($file, $ttl);
    }

    /**
     * @param array $keys
     * @return bool
     * @throws InvalidArgumentException
     */
    public function deleteMultiple(array $keys): bool
    {
        $status = [];
        foreach ($keys as $key) {
            $status[] = $this->delete($key);
        }

        return \in_array(true, $status, true) ? false : true;
    }
}