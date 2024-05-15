<?php

    require_once dirname(__FILE__) . '/OutputStream.php';
    require_once dirname(__FILE__) . '/InputStream.php';
    require_once dirname(__FILE__) . '/Message.php';
    require_once dirname(__FILE__) . '/Const.php';
    require_once dirname(__FILE__) . '/InvalidAMFException.php';


    /**
     * amf服务器，网关
     * AMF Server
     * 这是AMF0/AMF3服务器类。使用此类为客户端构造要连接到的网关
     * This is the AMF0/AMF3 Server class. Use this class to construct a gateway for clients to connect to
     * 在实时音视频流传输的过程中，RTMP 通常用于传输视频和音频数据流，而 AMF 则用于传输控制信息和元数据。例如，在实时直播中，
     * 视频和音频数据通过 RTMP 协议传输，而实时聊天消息、用户状态等控制信息则可以使用 AMF 格式传输。
     * @package SabreAMF
     * @version $Id: Server.php 233 2009-06-27 23:10:34Z evertpot $
     * @copyright Copyright (C) 2006-2009 Rooftop Solutions. All rights reserved.
     * @author Evert Pot (http://www.rooftopsolutions.nl/)
     * @licence http://www.freebsd.org/copyright/license.html  BSD License (4 Clause)
     * @uses SabreAMF_OutputStream
     * @uses SabreAMF_InputStream
     * @uses SabreAMF_Message
     * @uses SabreAMF_Const
     * @example ../examples/MediaServer.php
     */
    class SabreAMF_Server {

        /**
         * 输入数据流
         * amfInputStream
         *
         * @var SabreAMF_InputStream
         */
        protected $amfInputStream;
        /**
         * 输出数据流
         * amfOutputStream
         *
         * @var SabreAMF_OutputStream
         */
        protected $amfOutputStream;

        /**
         * amf请求
         * The representation of the AMF request
         *
         * @var SabreAMF_Message
         */
        protected $amfRequest;

        /**
         * amf响应
         * The representation of the AMF response
         *
         * @var SabreAMF_Message
         */
        protected $amfResponse;

        /**
         * 读取afm的原始数据流
         * Input stream to read the AMF from
         *
         * @var SabreAMF_Message
         */
        static protected $dataInputStream = 'php://input';

        /**
         * 读取的amf数据
         * Input string to read the AMF from
         *
         * @var SabreAMF_Message
         */
        static protected $dataInputData = '';

        /**
         * __construct
         *
         * @return void
         */
        public function __construct() {
            /** 读取php数据流 */
            $data = $this->readInput();

            //file_put_contents($dump.'/' . md5($data),$data);
            /** 设置amf input 流 ，用来读取数据的 */
            $this->amfInputStream = new SabreAMF_InputStream($data);
            /** 设置afm request */
            $this->amfRequest = new SabreAMF_Message();
            /** 设置输出流  */
            $this->amfOutputStream = new SabreAMF_OutputStream();
            /** 设置响应 */
            $this->amfResponse = new SabreAMF_Message();
            /** 将接收到的数据解码 */
            $this->amfRequest->deserialize($this->amfInputStream);

        }

        /**
         * 获取request的body
         * getRequests
         *
         * Returns the requests that are made to the gateway.
         *
         * @return array
         */
        public function getRequests() {

            return $this->amfRequest->getBodies();

        }

        /**
         * 设置响应体
         * setResponse
         * 这里是给客户端发送响应
         * Send a response back to the client (based on a request you got through getRequests)
         *
         * @param string $target This parameter should contain the same as the 'response' item you got through getRequests. This connects the request to the response
         * @param int $responsetype Set as either SabreAMF_Const::R_RESULT or SabreAMF_Const::R_STATUS, depending on if the call succeeded or an error was produced
         * @param mixed $data The result data
         * @return void
         */
        public function setResponse($target,$responsetype,$data) {


            switch($responsetype) {

                 case SabreAMF_Const::R_RESULT :
                        $target = $target.='/onResult';
                        break;
                 case SabreAMF_Const::R_STATUS :
                        $target = $target.='/onStatus';
                        break;
                 case SabreAMF_Const::R_DEBUG :
                        $target = '/onDebugEvents';
                        break;
            }
            return $this->amfResponse->addBody(array('target'=>$target,'response'=>'','data'=>$data));

        }

        /**
         * sendResponse
         *
         * Sends the responses back to the client. Call this after you answered all the requests with setResponse
         *
         * @return void
         */
        public function sendResponse() {
            /** 设置header头 数据类型为amf */
            header('Content-Type: ' . SabreAMF_Const::MIMETYPE);
            /** 设置编码格式 */
            $this->amfResponse->setEncoding($this->amfRequest->getEncoding());
            /** 将数据编码 */
            $this->amfResponse->serialize($this->amfOutputStream);
            /** 返回数据 逆天了，返回数据使用的echo */
            echo($this->amfOutputStream->getRawData());

        }

        /**
         * 设置amf头部信息
         * addHeader
         *
         * Add a header to the MediaServer response
         *
         * @param string $name
         * @param bool $required
         * @param mixed $data
         * @return void
         */
        public function addHeader($name,$required,$data) {

            $this->amfResponse->addHeader(array('name'=>$name,'required'=>$required==true,'data'=>$data));

        }

        /**
         * 获取request的header
         * getRequestHeaders
         *
         * returns the request headers
         *
         * @return void
         */
        public function getRequestHeaders() {

            return $this->amfRequest->getHeaders();

        }

        /**
         * 设置要传输的文件
         * setInputFile
         *
         * returns the true/false depended on wheater the stream is readable
         *
         * @param string $stream New input stream
         *
         * @author Asbjørn Sloth Tønnesen <asbjorn@lila.io>
         * @return bool
         */
        static public function setInputFile($stream) {

            if (!is_readable($stream)) return false;

            self::$dataInputStream = $stream;
            return true;

        }

        /**
         * 设置传输的字符串
         * setInputString
         *
         * Returns the true/false depended on wheater the string was accepted.
         * That a string is accepted by this method, does NOT mean that it is a valid AMF request.
         *
         * @param string $string New input string
         *
         * @author Asbjørn Sloth Tønnesen <asbjorn@lila.io>
         * @return bool
         */
        static public function setInputString($string) {

            if (!(is_string($string) && strlen($string) > 0))
                throw new SabreAMF_InvalidAMFException();

            self::$dataInputStream = null;
            self::$dataInputData = $string;
            return true;

        }

        /**
         * 读取输入
         * readInput
         *
         * Reads the input from stdin unless it has been overwritten
         * with setInputFile or setInputString.
         *
         * @author Asbjørn Sloth Tønnesen <asbjorn@lila.io>
         * @return string Binary string containing the AMF data
         */
        protected function readInput() {

            if (is_null(self::$dataInputStream)) return self::$dataInputData;

            $data = file_get_contents(self::$dataInputStream);
            if (!$data) throw new SabreAMF_InvalidAMFException();

            return $data;

        }

    }


