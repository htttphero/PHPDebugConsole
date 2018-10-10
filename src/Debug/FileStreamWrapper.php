<?php

namespace bdk\Debug;

/**
 * Streamwrapper which injects `declare(ticks=1)`
 */
class FileStreamWrapper
{
    /**
     * @var resource
     */
    public $context;

    private $bufferPrepend = '';

    private $declaredTicks = false;

    private $filepath;

    /**
     * @var resource
     */
    private $handle;

    /**
     * @var array paths to exclude from adding tick declaration
     */
    private static $pathsExclude = array();

    /**
     * @var string
     */
    const PROTOCOL = 'file';

    /**
     * Register this stream wrapper
     *
     * @return void
     *
     * @throws UnexpectedValueException
     */
    public static function register($pathsExclude = array())
    {
        $result = \stream_wrapper_unregister(static::PROTOCOL);
        if ($result === false) {
            throw new \UnexpectedValueException('Failed to unregister');
        }
        if ($pathsExclude) {
            self::$pathsExclude = $pathsExclude;
        }
        \stream_wrapper_register(static::PROTOCOL, self::class);
    }

    /**
     * Restore previous wrapper
     *
     * @return void
     *
     * @throws UnexpectedValueException
     */
    private static function restorePrev()
    {
        $result = \stream_wrapper_restore(static::PROTOCOL);
        if ($result === false) {
            throw new \UnexpectedValueException('Failed to restore');
        }
    }

    /**
     * Close the directory
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.dir-closedir.php
     */
    public function dir_closedir()
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        \closedir($this->handle);
        self::register();
        $this->handle = null;
        return true;
    }

    /**
     * Opens a directory for reading
     *
     * @param string  $path    Specifies the URL that was passed to opendir().
     * @param integer $options Whether or not to enforce safe_mode (0x04).
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.dir-opendir.php
     */
    public function dir_opendir($path, $options = 0)
    {
        if ($this->handle) {
            return false;
        }
        // "use" our function params so things don't complain
        array($options);
        self::restorePrev();
        $this->handle = \opendir($path);
        self::register();
        return $this->handle !== false;
    }

    /**
     * Read a single filename of a directory
     *
     * @return string|boolean
     *
     * @see http://php.net/manual/en/streamwrapper.dir-readdir.php
     */
    public function dir_readdir()
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        $success = \readdir($this->handle);
        self::register();
        return $success;
    }

    /**
     * Reset directory name pointer
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.dir-rewinddir.php
     */
    public function dir_rewinddir()
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        \rewinddir($this->handle);
        self::register();
        return true;
    }

    /**
     * Create a directory
     *
     * @param string  $path    Directory which should be created.
     * @param integer $mode    The value passed to mkdir().
     * @param integer $options A bitwise mask of values, such as STREAM_MKDIR_RECURSIVE.
     *
     * @return boolean
     */
    public function mkdir($path, $mode, $options = 0)
    {
        self::restorePrev();
        $success = \mkdir($path, $mode, (bool) ($options & STREAM_MKDIR_RECURSIVE));
        self::register();
        return $success;
    }

    /**
     * Rename a file
     *
     * @param string $pathFrom existing path
     * @param string $pathTo   The URL which the path_from should be renamed to.
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.rename.php
     */
    public function rename($pathFrom, $pathTo)
    {
        self::restorePrev();
        $pathFrom = self::overlayPath($pathFrom);
        $pathTo = self::overlayPath($pathTo);
        $success = rename($pathFrom, $pathTo);
        self::register();
        return $success;
    }

    /**
     * Remove a directory
     *
     * @param string  $path    directory to remove
     * @param integer $options bitwise mask of values
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.rmdir.php
     */
    public function rmdir($path, $options)
    {
        // "use" our function params so things don't complain
        array($options);
        self::restorePrev();
        $success = \rmdir($path);
        self::register();
        return $success;
    }

    /**
     * Retrieve the underlying resource
     *
     * @param integer $castAs STREAM_CAST_FOR_SELECT when stream_select() is calling stream_cast()
     *                        STREAM_CAST_AS_STREAM when stream_cast() is called for other uses
     *
     * @return resource|boolean
     *
     * @see http://php.net/manual/en/streamwrapper.stream-cast.php
     */
    public function stream_cast($castAs)
    {
        if ($this->handle && $castAs & STREAM_CAST_AS_STREAM) {
            return $this->handle;
        }
        return false;
    }

    /**
     * Close a file
     *
     * @see http://php.net/manual/en/streamwrapper.stream-close.php
     *
     * @return void
     */
    public function stream_close()
    {
        if (!$this->handle) {
            return;
        }
        self::restorePrev();
        \fclose($this->handle);
        $this->handle = null;
        self::register();
    }

    /**
     * Tests for end-of-file on a file pointer
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.stream-eof.php
     */
    public function stream_eof()
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        $result = \feof($this->handle);
        self::register();
        return $result;
    }

    /**
     * Flush the output
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.stream-flush.php
     */
    public function stream_flush()
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        $success = \fflush($this->handle);
        self::register();
        return $success;
    }

    /**
     * Advisory file locking
     *
     * @param integer $operation
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.stream-lock.php
     */
    public function stream_lock($operation)
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        $success = \flock($this->handle, $operation);
        self::register();
        return $success;
    }

    /**
     * Change file options
     *
     * @param string  $path   filepath or URL
     * @param integer $option What meta value is being set
     * @param mixed   $value  Meta value
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.stream-metadata.php
     */
    public function stream_metadata($path, $option, $value)
    {
        self::restorePrev();
        switch ($option) {
            case STREAM_META_TOUCH:
                if (!empty($value)) {
                    $success = \touch($path, $value[0], $value[1]);
                } else {
                    $success = \touch($path);
                }
                break;
            case STREAM_META_OWNER_NAME:
                // Fall through
            case STREAM_META_OWNER:
                $success = \chown($path, $value);
                break;
            case STREAM_META_GROUP_NAME:
                // Fall through
            case STREAM_META_GROUP:
                $success = \chgrp($path, $value);
                break;
            case STREAM_META_ACCESS:
                $success = \chmod($path, $value);
                break;
            default:
                $success = false;
        }
        self::register();
        return $success;
    }

    /**
     * Opens file or URL
     *
     * @param string   $path       Specifies the file/URL that was passed to the original function.
     * @param string   $mode       The mode used to open the file, as detailed for fopen().
     * @param integers $options    Holds additional flags set by the streams API. I
     * @param string   $openedPath the full path of the file/resource that was actually opened
     *
     * @return boolean
     *
     * @see    http://php.net/manual/en/streamwrapper.stream-open.php
     * @throws UnexpectedValueException
     */
    public function stream_open($path, $mode, $options, &$openedPath)
    {
        if ($this->handle) {
            return false;
        }
        $useIncludePath = (bool) $options & STREAM_USE_PATH;
        $context = $this->context;
        if ($context === null) {
            $context = \stream_context_get_default();
        }
        self::restorePrev();
        $handle = \fopen($path, $mode, $useIncludePath, $context);
        self::register();
        if ($handle === false) {
            return false;
        }
        /*
            Determine opened path
        */
        $meta = \stream_get_meta_data($handle);
        if (!isset($meta['uri'])) {
            throw new \UnexpectedValueException('Uri not in meta data');
        }
        $openedPath = $meta['uri'];
        $this->filepath = $openedPath;
        $this->handle = $handle;
        return true;
    }

    /**
     * Read from stream
     *
     * @param integer $count How many bytes of data from the current position should be returned.
     *
     * @return string
     *
     * @see http://php.net/manual/en/streamwrapper.stream-read.php
     */
    public function stream_read($count)
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        $buffer = \fread($this->handle, $count);
        $bufferLen = \strlen($buffer);
        $backtrace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $isRequire = !in_array($backtrace[1]['function'], array('file_get_contents'));
        if (!$this->declaredTicks && $isRequire) {
            foreach (self::$pathsExclude as $excludePath) {
                if (\strpos($this->filepath, $excludePath.DIRECTORY_SEPARATOR) === 0) {
                    $this->declaredTicks = true;
                }
            }
        }
        if (!$this->declaredTicks && $isRequire) {
            $buffer = \preg_replace(
                '/^(<\?php\s*)$/m',
                "\\0\ndeclare(ticks=1);\n",
                $buffer,
                1
            );
            $this->declaredTicks = true;
        }
        $buffer = $this->bufferPrepend.$buffer;
        $bufferLenAfter = \strlen($buffer);
        $diff = $bufferLenAfter - $bufferLen;
        $this->bufferPrepend = '';
        if ($diff) {
            $this->bufferPrepend = \substr($buffer, $count);
            $buffer = \substr($buffer, 0, $count);
        }
        self::register();
        return $buffer;
    }

    /**
     * Seek to specific location in a stream
     *
     * @param integer $offset The stream offset to seek to
     * @param integer $whence [SEEK_SET] | SEEK_CUR | SEEK_END
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.stream-seek.php
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePref();
        $success = \fseek($this->hand, $offset, $whence);
        self::register();
        return $success;
    }

    /**
     * Retrieve information about a file resource
     *
     * @return array
     *
     * @see http://php.net/manual/en/streamwrapper.stream-stat.php
     */
    public function stream_stat()
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        $array = \fstat($this->handle);
        self::register();
        return $array;
    }

    /**
     * Retrieve the current position of a stream
     *
     * @return integer
     *
     * @see http://php.net/manual/en/streamwrapper.stream-tell.php
     */
    public function stream_tell()
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        $position = \ftell($this->handle);
        self::register();
        return $position;
    }

    /**
     * Truncates a file to the given size
     *
     * @param integer $size Truncate to this size
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.stream-truncate.php
     */
    public function stream_truncate($size)
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        $success = \ftruncate($this->handle, $size);
        self::register();
        return $success;
    }

    /**
     * Write to stream
     *
     * @param string $data data to write
     *
     * @return integer
     *
     * @see http://php.net/manual/en/streamwrapper.stream-write.php
     */
    public function stream_write($data)
    {
        if (!$this->handle) {
            return false;
        }
        self::restorePrev();
        $length = \fwrite($this->handle, $data);
        self::register();
        return $length;
    }

    /**
     * Unlink a file
     *
     * @param string $path filepath
     *
     * @return boolean
     *
     * @see http://php.net/manual/en/streamwrapper.unlink.php
     */
    public function unlink($path)
    {
        self::restorePrev();
        $path = self::overlayPath($path);
        $success = \unlink($path);
        self::register();
        return $success;
    }

    /**
     * Retrieve information about a file
     *
     * @param string  $path  The file path or URL to stat
     * @param integer $flags Holds additional flags set by the streams API.
     *
     * @return array
     *
     * @see http://php.net/manual/en/streamwrapper.url-stat.php
     */
    public function url_stat($path, $flags)
    {
        self::restorePrev();
        if (!\file_exists($path)) {
            $info = false;
        } elseif ($flags & STREAM_URL_STAT_LINK) {
            $info = $flags & STREAM_URL_STAT_QUIET
                ? @lstat($path)
                : lstat($path);
        } else {
            $info = $flags & STREAM_URL_STAT_QUIET
                ? @stat($path)
                : stat($path);
        }
        self::register();
        return $info;
    }
}