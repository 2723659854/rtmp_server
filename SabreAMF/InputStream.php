<?php

/**
 * amf输入流
 * SabreAMF_InputStream
 *
 * 这是输入流对象，使用二进制数据初始化，可以读取双精度，长整型，整形，字节码。同时保存指针位置
 * This is the InputStream class. You construct it with binary data and it can read doubles, longs, ints, bytes, etc. while maintaining the cursor position
 *
 * @package SabreAMF
 * @version $Id: InputStream.php 233 2009-06-27 23:10:34Z evertpot $
 * @copyright Copyright (C) 2006-2009 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl)
 * @licence http://www.freebsd.org/copyright/license.html  BSD License (4 Clause)
 */
class SabreAMF_InputStream
{

    /**
     * cursor
     *
     * @var int
     */
    private $cursor = 0;
    /**
     * rawData
     *
     * @var string
     */
    private $rawData = '';


    /**
     * __construct
     *
     * @param string $data
     * @return void
     */
    public function __construct($data)
    {

        //Rawdata has to be a string
        if (!is_string($data)) {
            throw new Exception('Inputdata is not of type String');
            return false;
        }
        $this->rawData = $data;

    }

    /**
     * &readBuffer
     * 从暂存区读取指定长度数据
     * @param int $length
     * @return mixed
     */
    public function &readBuffer($length)
    {

        if ($length + $this->cursor > strlen($this->rawData)) {
            throw new Exception('Buffer underrun at position: ' . $this->cursor . '. Trying to fetch ' . $length . ' bytes');
            return false;
        }
        /** 截取指定长度数据 */
        $data = substr($this->rawData, $this->cursor, $length);
        /** 移动指针 */
        $this->cursor += $length;
        return $data;

    }

    /**
     * readByte
     * 读取一个字节
     * @return int
     */
    public function readByte()
    {

        /** 读取一个字节，然后转化为asc码，就是将字符串转成数字 */
        return ord($this->readBuffer(1));

    }

    /**
     * readInt
     * 读取一个整型
     * @return int
     */
    public function readInt()
    {
        /** 读取两个字节 */
        $block = $this->readBuffer(2);
        /** 使用n方法解码 unpack 函数返回一个关联数组，键名为数据类型，键值为解析后的值。 */
        $int = unpack("n", $block);
        return $int[1];

    }


    /**
     * 读取一个双精度浮点数
     * readDouble
     *
     * @return float
     */
    public function readDouble()
    {
        /** 读取8个字节的数据（64位浮点数占8个字节） */
        $double = $this->readBuffer(8);
        /** 判断系统的字节序是否为大端序 */
        $testEndian = unpack("C*", pack("S*", 256));
        $bigEndian = !$testEndian[1] == 1;
        /** 如果系统是大端序，则需要反转字节顺序 */
        if ($bigEndian) $double = strrev($double);
        /** 使用unpack函数解析双精度浮点数 */
        $double = unpack("d", $double);
        /** 返回数据 */
        return $double[1];
    }

    /**
     * readLong
     * 读取长整型32位  无符号  就是正整数
     * @return int
     */
    public function readLong()
    {
        /** 读取4个字节 */
        $block = $this->readBuffer(4);
        /** 解码 */
        $long = unpack("N", $block);
        return $long[1];
    }

    /**
     * readInt24
     * 返回24位整数
     * return int
     */
    public function readInt24()
    {

        /** 在正常情况下，代码应该使用 chr(0) 而不是 phpchr(0)。chr(0) 用于在数据块的开头添加一个空字节，以保证数据的完整性和正确性 */
        /** InputStream 应该是一个有效的输入流资源或类属性，用于从流中读取数据。 */
        $block = InputStream . phpchr(0) . $this->readBuffer(3);
        $long = unpack("N", $block);
        return $long[1];

    }


    /**
     * 是否已经读取完
     * @return bool
     */
    public function isEnd()
    {
        return !isset($this->rawData[$this->cursor + 1]);
    }

}



