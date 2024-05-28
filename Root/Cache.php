<?php

namespace Root;

/**
 * @purpose 缓存类
 * @comment 本缓存仅供保存媒体数据，仅适用于常住内存。
 */
class Cache
{

    /** 数据队列 */
    private static $_list = [];
    /** 普通数据 */
    private static $_temp = [];

    /**
     * 设置缓存
     * @param string $name
     * @param $value
     * @return bool
     */
    public static function set(string $name,$value):bool
    {
        self::$_temp[$name]=$value;
        return true;
    }

    /**
     * 获取缓存
     * @param string $name
     * @return mixed|null
     */
    public static function get(string $name)
    {
        if (isset(self::$_temp[$name])){
            return self::$_temp[$name];
        }else{
            return null;
        }
    }

    /**
     * 判断是否有设置缓存
     * @param string $name
     * @return bool
     */
    public static function has(string $name)
    {
        return isset(self::$_temp[$name]);
    }

    /**
     * 存入数据到队列
     * @param string $name key值
     * @param mixed $value value 值
     * @return bool
     */
    public static function push(string $name, $value):bool
    {

        self::$_list[$name][]=$value;
        return true;
    }

    /**
     * 获取一个数据
     * @param string $name
     * @return mixed|null
     */
    public static function pob(string $name)
    {

        if (isset(self::$_list[$name])){
           return array_shift(self::$_list[$name]);
        }else{
            return null;
        }
    }

    /**
     * 获取数据，并清空缓存
     * @param string $name
     * @return array|mixed
     */
    public static function flush(string $name)
    {
        $array = [];
        if (isset(self::$_list[$name])){
            $array = self::$_list[$name];
            unset(self::$_list[$name]);
        }
        return $array;
    }
}