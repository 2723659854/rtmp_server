<?php

    require_once dirname(__FILE__) . '/AMF0/Serializer.php';
    require_once dirname(__FILE__) . '/AMF0/Deserializer.php';
    require_once dirname(__FILE__) . '/Const.php';
    require_once dirname(__FILE__) . '/AMF3/Wrapper.php';

    /**
     * 在实时音视频流传输的过程中，RTMP 通常用于传输视频和音频数据流，而 AMF 则用于传输控制信息和元数据。例如，在实时直播中，
     * 视频和音频数据通过 RTMP 协议传输，而实时聊天消息、用户状态等控制信息则可以使用 AMF 格式传输。
     * 设置amf数据，被用作request
     * SabreAMF_Message
     *
     * The Message class encapsulates either an entire request package or an entire result package; including an AMF enveloppe
     * Message类封装了整个请求包或整个结果包；包括AMF信封
     *
     * @package SabreAMF
     * @version $Id: Message.php 233 2009-06-27 23:10:34Z evertpot $
     * @copyright Copyright (C) 2006-2009 Rooftop Solutions. All rights reserved.
     * @author Evert Pot (http://www.rooftopsolutions.nl/)
     * @licence http://www.freebsd.org/copyright/license.html  BSD License (4 Clause)
     * @uses SabreAMF_AMF0_Serializer
     * @uses SabreAMF_AMF0_Deserializer
     */
    class SabreAMF_Message {

        /**
         * clientType
         *
         * @var int
         */
        private $clientType=0;
        /**
         * bodies
         *
         * @var array
         */
        private $bodies=array();
        /**
         * headers
         *
         * @var array
         */
        private $headers=array();

        /**
         * encoding
         *
         * @var int
         */
        private $encoding = SabreAMF_Const::AMF0;

        /**
         * 数据序列化
         * serialize
         *
         * This method serializes a request. It requires an SabreAMF_OutputStream as an argument to read
         * the AMF Data from. After serialization the Outputstream will contain the encoded AMF data.
         *
         * @param SabreAMF_OutputStream $stream
         * @return void
         */
        public function serialize(SabreAMF_OutputStream $stream) {
            /** 设置输出流 */
            $this->outputStream = $stream;
            /** 设置amf头 */
            $stream->writeByte(0x00);
            $stream->writeByte($this->encoding);
            $stream->writeInt(count($this->headers));
            /** 写入header头部数据 */
            foreach($this->headers as $header) {

                $serializer = new SabreAMF_AMF0_Serializer($stream);
                $serializer->writeString($header['name']);
                $stream->writeByte($header['required']==true);
                $stream->writeLong(-1);
                $serializer->writeAMFData($header['data']);
            }
            /** 写入body数据条数 */
            $stream->writeInt(count($this->bodies));

            /** 循环写入body数据 */
            foreach($this->bodies as $body) {
                $serializer = new SabreAMF_AMF0_Serializer($stream);
                $serializer->writeString($body['target']);
                $serializer->writeString($body['response']);
                $stream->writeLong(-1);
                /** 根据版本进行编码 */
                switch($this->encoding) {

                    case SabreAMF_Const::AMF0 :
                        $serializer->writeAMFData($body['data']);
                        break;
                    case SabreAMF_Const::AMF3 :
                        $serializer->writeAMFData(new SabreAMF_AMF3_Wrapper($body['data']));
                        break;

                }

            }

        }

        /**
         * 数据反序列化
         * deserialize
         *
         * This method deserializes a request. It requires an SabreAMF_InputStream with valid AMF data. After
         * deserialization the contents of the request can be found through the getBodies and getHeaders methods
         *
         * 这个方法解析请求，他需要sabreamf输入流携带amf数据，解析后，可以使用getBodies 和 getHeaders 获取数据。
         *
         * @param SabreAMF_InputStream $stream
         * @return void
         */
        public function deserialize(SabreAMF_InputStream $stream) {
            /** 初始化请求头，内容 */
            $this->headers = array();
            $this->bodies = array();

            $this->InputStream = $stream;
            /** 忽略消息头 */
            $stream->readByte();
            /** 客户端类型 */
            $this->clientType = $stream->readByte();
            /** 解码器 */
            $deserializer = new SabreAMF_AMF0_Deserializer($stream);
            /** 获取头部总数 */
            $totalHeaders = $stream->readInt();

            /** 解析消息头header */
            for($i=0;$i<$totalHeaders;$i++) {

                $header = array(
                    'name'     => $deserializer->readString(),
                    'required' => $stream->readByte()==true
                );
                $stream->readLong();
                $header['data']  = $deserializer->readAMFData(null,true);
                $this->headers[] = $header;

            }
            /** 获取body数据总数 */
            $totalBodies = $stream->readInt();
            /** 循环解析body数据 */
            for($i=0;$i<$totalBodies;$i++) {

                try {
                    $target = $deserializer->readString();
                } catch (Exception $e) {
                    // Could not fetch next body.. this happens with some versions of AMFPHP where the body
                    // count isn't properly set. If this happens we simply stop decoding
                    //无法获取下一个正文。。这种情况发生在AMFPHP的某些版本中，其中
                    //计数设置不正确。如果发生这种情况，我们只需停止解码
                    break;
                }
                /** 解码 */
                $body = array(
                    'target'   => $target,
                    'response' => $deserializer->readString(),
                    'length'   => $stream->readLong(),
                    'data'     => $deserializer->readAMFData(null,true)
                );
                /** 如果是对象消息 */
                if (is_object($body['data']) && $body['data'] instanceof SabreAMF_AMF3_Wrapper) {
                     $body['data'] = $body['data']->getData();
                     $this->encoding = SabreAMF_Const::AMF3;
                } else if (is_array($body['data']) && isset($body['data'][0]) && is_object($body['data'][0]) && $body['data'][0] instanceof SabreAMF_AMF3_Wrapper) {
                    /** 如果是数组 */
                     $body['data'] = $body['data'][0]->getData();
                     $this->encoding = SabreAMF_Const::AMF3;
                }

                $this->bodies[] = $body;

            }


        }

        /**
         * getClientType
         * 获取客户端类型
         * Returns the ClientType for the request. Check SabreAMF_Const for possible (known) values
         *
         * @return int
         */
        public function getClientType() {

            return $this->clientType;

        }

        /**
         * getBodies
         * 获取消息内容
         * Returns the bodies int the message
         *
         * @return array
         */
        public function getBodies() {

            return $this->bodies;

        }

        /**
         * getHeaders
         * 获取消息头
         * Returns the headers in the message
         *
         * @return array
         */
        public function getHeaders() {

            return $this->headers;

        }

        /**
         * addBody
         * 设置body
         * Adds a body to the message
         *
         * @param mixed $body
         * @return void
         */
        public function addBody($body) {

            $this->bodies[] = $body;

        }

        /**
         * addHeader
         * 设置header
         * Adds a message header
         *
         * @param mixed $header
         * @return void
         */
        public function addHeader($header) {

            $this->headers[] = $header;

        }

        /**
         * setEncoding
         * 设置编码方式
         * @param int $encoding
         * @return void
         */
        public function setEncoding($encoding) {

            $this->encoding = $encoding;

        }

        /**
         * getEncoding
         * 获取编码方式
         * @return int
         */
        public function getEncoding() {

            return $this->encoding;

        }

    }


