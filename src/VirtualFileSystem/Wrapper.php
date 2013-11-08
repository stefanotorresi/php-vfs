<?php
/*
 * This file is part of the php-vfs package.
 *
 * (c) Michael Donat <michael.donat@me.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VirtualFileSystem;

use VirtualFileSystem\Structure\Directory;
use VirtualFileSystem\Structure\File;
use VirtualFileSystem\Wrapper\FileHandler;
use VirtualFileSystem\Wrapper\DirectoryHandler;
use VirtualFileSystem\Wrapper\PermissionHelper;

/**
 * Stream wrapper class. This is the class that PHP uses as the stream operations handler.
 *
 * @see http://php.net/streamwrapper for informal protocol description
 *
 * @author Michael Donat <michael.donat@me.com>
 * @package php-vfs
 */
class Wrapper
{
    public $context;

    /**
     * @var FileHandler
     */
    protected $currently_opened;

    /**
     * Returns default expectation for stat() function.
     *
     * @see http://php.net/stat
     *
     * @return array
     */
    protected function getStatArray()
    {
        $assoc = array(
            'dev' => 0,
            'ino' => 0,
            'mode' => 0,
            'nlink' => 0,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => 123,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => -1,
            'blocks' => -1
        );

        return array_merge(array_values($assoc), $assoc);
    }

    /**
     * Returns path stripped of url scheme (http://, ftp://, test:// etc.)
     *
     * @param string $path
     *
     * @return string
     */
    public function stripScheme($path)
    {
        $parts = parse_url($path);

        $host = isset($parts['host']) ? $parts['host'] : null;
        $path = isset($parts['path']) ? $parts['path'] : null;

        return sprintf('/%s%s', $host, $path);
    }

    /**
     * Returns Container object fished form default_context_options by scheme.
     *
     * @param $path
     *
     * @return Container
     */
    public function getContainerFromContext($path)
    {
        $scheme = parse_url($path)['scheme'];
        $scheme = is_null($scheme) ? parse_url($path.'.')['scheme'] : $scheme;
        $options = stream_context_get_options(stream_context_get_default());

        return $options[$scheme]['Container'];
    }

    /**
     * @see http://php.net/streamwrapper.stream-tell
     *
     * @return int
     */
    public function stream_tell()
    {
        return $this->currently_opened->position();
    }

    /**
     * @see http://php.net/streamwrapper.stream-close
     *
     * @return void
     */
    public function stream_close()
    {
        $this->currently_opened = null;
    }

    /**
     * Opens stream in selected mode.
     *
     * @see http://php.net/streamwrapper.stream-open
     *
     * @param string $path
     * @param int $mode
     * @param int $options
     * @param string $opened_path
     *
     * @return bool
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $container = $this->getContainerFromContext($path);
        $path = $this->stripScheme($path);

        $mode = str_split(str_replace('b', '', $mode));

        $appendMode = in_array('a', $mode);
        $readMode = in_array('r', $mode);
        $writeMode = in_array('w', $mode);
        $extended = in_array('+', $mode);

        if (!$container->hasFileAt($path)) {
            if ($readMode or !$container->hasFileAt(dirname($path))) {
                if ($options & STREAM_REPORT_ERRORS) {
                    trigger_error(sprintf('%s: failed to open stream.', $path), E_USER_WARNING);
                }
                return false;
            }
            $parent = $container->fileAt(dirname($path));
            $ph = new PermissionHelper($parent);
            if (!$ph->isWritable()) {
                if ($options & STREAM_REPORT_ERRORS) {
                    trigger_error(sprintf('%s: failed to open stream: Permission denied', $path), E_USER_WARNING);
                }
                return false;
            }
            $parent->addFile($container->factory()->getFile(basename($path)));
        }

        $file = $container->fileAt($path);

        if (($extended || $writeMode || $appendMode) && $file instanceof Directory) {
            if ($options & STREAM_REPORT_ERRORS) {
                trigger_error(sprintf('fopen(%s): failed to open stream: Is a directory', $path), E_USER_WARNING);
            }
            return false;
        }

        if ($file instanceof Directory) {
            $dir = $file;
            $file = $container->factory()->getFile('tmp');
            $file->chmod($dir->mode());
            $file->chown($dir->user());
            $file->chgrp($dir->group());
        }

        $permissionHelper = new PermissionHelper($file);

        $this->currently_opened = new FileHandler();
        $this->currently_opened->setFile($file);
        if ($extended) {
            if (!$permissionHelper->isReadable() or !$permissionHelper->isWritable()) {
                if ($options & STREAM_REPORT_ERRORS) {
                    trigger_error(sprintf('fopen(%s): failed to open stream: Permission denied', $path), E_USER_WARNING);
                }
                return false;
            }
            $this->currently_opened->setReadWriteMode();
        } elseif ($readMode) {
            if (!$permissionHelper->isReadable()) {
                if ($options & STREAM_REPORT_ERRORS) {
                    trigger_error(sprintf('fopen(%s): failed to open stream: Permission denied', $path), E_USER_WARNING);
                }
                return false;
            }
            $this->currently_opened->setReadOnlyMode();
        } else { // a or w are for write only
            if (!$permissionHelper->isWritable()) {
                if ($options & STREAM_REPORT_ERRORS) {
                    trigger_error(sprintf('fopen(%s): failed to open stream: Permission denied', $path), E_USER_WARNING);
                }
                return false;
            }
            $this->currently_opened->setWriteOnlyMode();
        }


        if ($appendMode) {
            $this->currently_opened->seekToEnd();
        } elseif ($writeMode) {
            $this->currently_opened->truncate();
            clearstatcache();
        }

        return true;
    }

    /**
     * Writes data to stream.
     *
     * @see http://php.net/streamwrapper.stream-write
     *
     * @param $data
     *
     * @return int
     */
    public function stream_write($data)
    {
        if (!$this->currently_opened->isOpenedForWriting()) {
            return false;
        }
        //file access time changes so stat cache needs to be cleared
        $written = $this->currently_opened->write($data);
        clearstatcache();
        return $written;
    }

    /**
     * Returns stat data for file inclusion. Currently disabled.
     *
     * @see http://php.net/streamwrapper.stream-stat
     *
     * @return bool
     */
    public function stream_stat()
    {
        return true;
    }

    /**
     * Returns file stat information
     *
     * @see http://php.net/stat
     *
     * @param string $path
     * @param int $flags
     *
     * @return array|bool
     */
    public function url_stat($path, $flags)
    {
        try {
            $file = $this->getContainerFromContext($path)->fileAt($this->stripScheme($path));

            return array_merge($this->getStatArray(), array(
                'mode' => $file->mode(),
                'uid' => $file->user(),
                'gid' => $file->group(),
                'atime' => $file->atime(),
                'mtime' => $file->mtime(),
                'ctime' => $file->ctime()
            ));
        } catch (NotFoundException $e) {
            return false;
        }
    }

    /**
     * Reads and returns $bytes amount of bytes from stream.
     *
     * @see http://php.net/streamwrapper.stream-read
     *
     * @param int $bytes
     *
     * @return string
     */
    public function stream_read($bytes)
    {
        if (!$this->currently_opened->isOpenedForReading()) {
            return null;
        }
        $data = $this->currently_opened->read($bytes);
        //file access time changes so stat cache needs to be cleared
        clearstatcache();
        return $data;
    }

    /**
     * Checks whether pointer has reached EOF.
     *
     * @see http://php.net/streamwrapper.stream-eof
     *
     * @return bool
     */
    public function stream_eof()
    {
        return $this->currently_opened->atEof();
    }

    /**
     * Called in response to mkdir to create directory.
     *
     * @see http://php.net/streamwrapper.mkdir
     *
     * @param string $path
     * @param int $mode
     * @param int $options
     *
     * @return bool
     */
    public function mkdir($path, $mode, $options)
    {
        $container = $this->getContainerFromContext($path);
        $path = $this->stripScheme($path);
        $recursive = (bool) ($options & STREAM_MKDIR_RECURSIVE);

        try {
            $container->createDir($path, $recursive, $mode);
        } catch (FileExistsException $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
            return false;
        }

        return true;
    }

    /**
     * Called in response to chown/chgrp/touch/chmod etc.
     *
     * @see http://php.net/streamwrapper.stream-metadata
     *
     * @param string $path
     * @param int $option
     * @param mixed $value
     *
     * @return bool
     */
    public function stream_metadata($path, $option, $value)
    {
        try {
            switch ($option) {
                case STREAM_META_ACCESS:
                    $this->getContainerFromContext($path)->fileAt($this->stripScheme($path))->chmod($value);
                    $this->getContainerFromContext($path)->fileAt($this->stripScheme($path))->setChangeTime(time());
                    break;

                case STREAM_META_OWNER_NAME:
                    $uid = posix_getpwnam($value)['uid'];
                    $this->getContainerFromContext($path)->fileAt($this->stripScheme($path))->chown($uid);
                    $this->getContainerFromContext($path)->fileAt($this->stripScheme($path))->setChangeTime(time());
                    break;

                case STREAM_META_OWNER:
                    $this->getContainerFromContext($path)->fileAt($this->stripScheme($path))->chown($value);
                    $this->getContainerFromContext($path)->fileAt($this->stripScheme($path))->setChangeTime(time());
                    break;

                case STREAM_META_GROUP_NAME:
                    $gid = posix_getgrnam($value)['gid'];
                    $this->getContainerFromContext($path)->fileAt($this->stripScheme($path))->chgrp($gid);
                    $this->getContainerFromContext($path)->fileAt($this->stripScheme($path))->setChangeTime(time());
                    break;

                case STREAM_META_GROUP:
                    $this->getContainerFromContext($path)->fileAt($this->stripScheme($path))->chgrp($value);
                    $this->getContainerFromContext($path)->fileAt($this->stripScheme($path))->setChangeTime(time());
                    break;

                case STREAM_META_TOUCH:
                    if (!$this->getContainerFromContext($path)->hasFileAt($this->stripScheme($path))) {
                        $strippedPath = $this->stripScheme($path);
                        try {
                            $this->getContainerFromContext($path)->createFile($strippedPath);
                        } catch (NotFoundException $e) {
                            trigger_error(
                                sprintf('touch: %s: No such file or directory.', $strippedPath),
                                E_USER_WARNING
                            );
                            return false;
                        }
                    }
                    $file = $this->getContainerFromContext($path)->fileAt($this->stripScheme($path));
                    $file->setAccessTime(time());
                    $file->setModificationTime(time());
                    $file->setChangeTime(time());
                    break;

            }
        } catch (NotFoundException $e) {
            return false;
        }

        clearstatcache(true, $path);

        return true;
    }

    /**
     * Sets file pointer to specified position
     *
     * @param int $offset
     * @param int $whence
     *
     * @return bool
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        switch($whence) {
            case SEEK_SET:
                $this->currently_opened->position($offset);
                break;
            case SEEK_CUR:
                $this->currently_opened->offsetPosition($offset);
                break;
            case SEEK_END:
                $this->currently_opened->seekToEnd();
                $this->currently_opened->offsetPosition($offset);
        }
        return true;
    }

    /**
     * Truncates file to given size
     *
     * @param int $new_size
     *
     * @return bool
     */
    public function stream_truncate($new_size)
    {
        $this->currently_opened->truncate($new_size);
        clearstatcache();
        return true;
    }

    /**
     * Renames/Moves file
     *
     * @param string $oldname
     * @param string $newname
     *
     * @return bool
     */
    public function rename($oldname, $newname)
    {
        $container = $this->getContainerFromContext($newname);
        $oldname = $this->stripScheme($oldname);
        $newname = $this->stripScheme($newname);

        try {
            $container->move($oldname, $newname);
        } catch (NotFoundException $e) {
            trigger_error(
                sprintf('mv: rename %s to %s: No such file or directory', $oldname, $newname),
                E_USER_WARNING
            );
            return false;
        } catch (\RuntimeException $e) {
            trigger_error(
                sprintf('mv: rename %s to %s: Not a directory', $oldname, $newname),
                E_USER_WARNING
            );
            return false;
        }

        return true;
    }

    /**
     * Deletes file at given path
     *
     * @param int $path
     *
     * @return bool
     */
    public function unlink($path)
    {
        $container = $this->getContainerFromContext($path);

        try {
            $container->remove($path = $this->stripScheme($path));
        } catch (NotFoundException $e) {
            trigger_error(
                sprintf('rm: %s: No such file or directory', $path),
                E_USER_WARNING
            );
            return false;
        } catch (\RuntimeException $e) {
            trigger_error(
                sprintf('rm: %s: is a directory', $path),
                E_USER_WARNING
            );
            return false;
        }

        return true;
    }

    /**
     * Removes directory
     *
     * @param string $path
     *
     * @return bool
     */
    public function rmdir($path)
    {
        $container = $this->getContainerFromContext($path);
        $path = $this->stripScheme($path);

        try {
            $directory = $container->fileAt($path);

            if ($directory instanceof File) {
                trigger_error(
                    sprintf('Warning: rmdir(%s): Not a directory', $path),
                    E_USER_WARNING
                );
                return false;
            }

        } catch (NotFoundException $e) {
            trigger_error(
                sprintf('Warning: rmdir(%s): No such file or directory', $path),
                E_USER_WARNING
            );
            return false;
        }

        if ($directory->size()) {
            trigger_error(
                sprintf('Warning: rmdir(%s): Directory not empty', $path),
                E_USER_WARNING
            );
            return false;
        }

        $container->remove($path, true);

        return true;
    }

    /**
     * Opens directory for iteration
     *
     * @param string $path
     * @param int $options
     *
     * @return bool
     */
    public function dir_opendir($path, $options)
    {
        $container = $this->getContainerFromContext($path);
        $path = $this->stripScheme($path);

        if (!$container->hasFileAt($path)) {
            trigger_error(sprintf('opendir(%s): failed to open dir: No such file or directory', $path), E_USER_WARNING);
            return false;
        }

        $dir = $container->fileAt($path);

        if ($dir instanceof File) {
            trigger_error(sprintf('opendir(%s): failed to open dir: Not a directory', $path), E_USER_WARNING);
            return false;
        }

        $permissionHelper = new PermissionHelper($dir);

        if (!$permissionHelper->isReadable()) {
            trigger_error(sprintf('opendir(%s): failed to open dir: Permission denied', $path), E_USER_WARNING);
            return false;
        }

        $this->currently_opened = new DirectoryHandler();
        $this->currently_opened->setDirectory($dir);

        return true;
    }

    /**
     * Closes opened dir
     *
     * @return bool
     */
    public function dir_closedir()
    {
        if ($this->currently_opened) {
            $this->currently_opened = null;
            return true;
        }

        return false;
    }

    /**
     * Returns next file url in directory
     *
     * @return null
     */
    public function dir_readdir()
    {
        $node = $this->currently_opened->iterator()->current();
        if (!$node) {
            return false;
        }
        $this->currently_opened->iterator()->next();
        return $node->basename();
    }

    /**
     * Resets directory iterator
     */
    public function dir_rewinddir()
    {
        $this->currently_opened->iterator()->rewind();
    }
}
