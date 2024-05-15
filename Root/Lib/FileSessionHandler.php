<?php

namespace Root\Lib;

use Root\Lib\Session;

class FileSessionHandler
{

    protected static $_sessionSavePath = null;


    protected static $_sessionFilePrefix = 'session_';


    public static function init() {
        $save_path = @\session_save_path();
        if (!$save_path || \strpos($save_path, 'tcp://') === 0) {
            $save_path = \sys_get_temp_dir();
        }
        static::sessionSavePath($save_path);
    }


    public function __construct($config = array()) {
        if (isset($config['save_path'])) {
            static::sessionSavePath($config['save_path']);
        }
    }

    public function open($save_path, $name)
    {
        return true;
    }


    public function read($session_id)
    {
        $session_file = static::sessionFile($session_id);
        \clearstatcache();
        if (\is_file($session_file)) {
            if (\time() - \filemtime($session_file) > Session::$lifetime) {
                \unlink($session_file);
                return '';
            }
            $data = \file_get_contents($session_file);
            return $data ? $data : '';
        }
        return '';
    }

    public function write($session_id, $session_data)
    {
        $temp_file = static::$_sessionSavePath . uniqid(bin2hex(random_bytes(8)), true);
        if (!\file_put_contents($temp_file, $session_data)) {
            return false;
        }
        return \rename($temp_file, static::sessionFile($session_id));
    }


    public function updateTimestamp($id, $data = "")
    {
        $session_file = static::sessionFile($id);
        if (!file_exists($session_file)) {
            return false;
        }
        // set file modify time to current time
        $set_modify_time = \touch($session_file);
        // clear file stat cache
        \clearstatcache();
        return $set_modify_time;
    }


    public function close()
    {
        return true;
    }


    public function destroy($session_id)
    {
        $session_file = static::sessionFile($session_id);
        if (\is_file($session_file)) {
            \unlink($session_file);
        }
        return true;
    }


    public function gc($maxlifetime) {
        $time_now = \time();
        foreach (\glob(static::$_sessionSavePath . static::$_sessionFilePrefix . '*') as $file) {
            if(\is_file($file) && $time_now - \filemtime($file) > $maxlifetime) {
                \unlink($file);
            }
        }
    }


    protected static function sessionFile($session_id) {
        return static::$_sessionSavePath.static::$_sessionFilePrefix.$session_id;
    }


    public static function sessionSavePath($path) {
        if ($path) {
            if ($path[\strlen($path)-1] !== DIRECTORY_SEPARATOR) {
                $path .= DIRECTORY_SEPARATOR;
            }
            static::$_sessionSavePath = $path;
            if (!\is_dir($path)) {
                \mkdir($path, 0777, true);
            }
        }
        return $path;
    }
}

FileSessionHandler::init();