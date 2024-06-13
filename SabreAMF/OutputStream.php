<?php

    /**
     * amf输出流 将数据转码成二进制
     * SabreAMF_OutputStream 
     *
     * This class provides methods to encode bytes, longs, strings, int's etc. to a binary format
     * 本对象提供方法将字节码，长整型，字符串编码成二进制
     *
     * @package SabreAMF 
     * @version $Id: OutputStream.php 233 2009-06-27 23:10:34Z evertpot $
     * @copyright Copyright (C) 2006-2009 Rooftop Solutions. All rights reserved.
     * @author Evert Pot (http://www.rooftopsolutions.nl/) 
     * @licence http://www.freebsd.org/copyright/license.html  BSD License (4 Clause) 
     */
    class SabreAMF_OutputStream {

        /**
         * rawData 
         * 
         * @var string
         */
        private $rawData = '';

        /**
         * writeBuffer 
         * 写入暂存区
         *
         * @param string $str 
         * @return void
         */
        public function writeBuffer($str) {
            $this->rawData.=$str;
        }

        /**
         * writeByte
         * 写入二进制
         * 
         * @param int $byte 
         * @return void
         */
        public function writeByte($byte) {

            $this->rawData.=pack('c',$byte);

        }

        /**
         * writeInt 
         * 写入整型
         *
         * @param int $int 
         * @return void
         */
        public function writeInt($int) {

            $this->rawData.=pack('n',$int);

        }
        
        /**
         * writeDouble 
         * 
         * @param float $double 
         * @return void
         */
        public function writeDouble($double) {

            $bin = pack("d",$double);
            $testEndian = unpack("C*",pack("S*",256));
            $bigEndian = !$testEndian[1]==1;
            if ($bigEndian) $bin = strrev($bin);
            $this->rawData.=$bin;

        }

        /**
         * writeLong 
         * 写入长整型
         *
         * @param int $long 
         * @return void
         */
        public function writeLong($long) {

            $this->rawData.=pack("N",$long);


        }

        /**
         * getRawData
         * 获取编码后的数据
         * 
         * @return string 
         */
        public function getRawData() {

            return $this->rawData;

        }


    }



