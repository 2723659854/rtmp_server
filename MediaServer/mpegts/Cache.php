<?php

namespace MediaServer\mpegts;

class Cache
{

    /** key = string value = ExtInf */
    public static  array $data = [];

    public static function get(string $topic)
    {
        $data = self::$data[$topic]??[];
        return $data;
//        $length = count($data);
//        if ($length>3){
//            return [array_slice($data,-3),$length,'ok'];
//        }else{
//            return [$data,0,'ok'];
//        }
    }

    public static function add(string $topic,ExtInf $extInf)
    {

        if (!isset(self::$data[$topic])){
            self::$data[$topic] = [];
        }
        self::$data[$topic][]=$extInf;
        return true;
    }
}