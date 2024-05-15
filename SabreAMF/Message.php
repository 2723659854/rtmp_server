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
            /** 写入body */
            $stream->writeInt(count($this->bodies));


            foreach($this->bodies as $body) {
                $serializer = new SabreAMF_AMF0_Serializer($stream);
                $serializer->writeString($body['target']);
                $serializer->writeString($body['response']);
                $stream->writeLong(-1);

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
         * @param SabreAMF_InputStream $stream
         * @return void
         */
        public function deserialize(SabreAMF_InputStream $stream) {

            $this->headers = array();
            $this->bodies = array();

            $this->InputStream = $stream;

            $stream->readByte();

            $this->clientType = $stream->readByte();

            $deserializer = new SabreAMF_AMF0_Deserializer($stream);

            $totalHeaders = $stream->readInt();

            for($i=0;$i<$totalHeaders;$i++) {

                $header = array(
                    'name'     => $deserializer->readString(),
                    'required' => $stream->readByte()==true
                );
                $stream->readLong();
                $header['data']  = $deserializer->readAMFData(null,true);
                $this->headers[] = $header;

            }

            $totalBodies = $stream->readInt();

            for($i=0;$i<$totalBodies;$i++) {

                try {
                    $target = $deserializer->readString();
                } catch (Exception $e) {
                    // Could not fetch next body.. this happens with some versions of AMFPHP where the body
                    // count isn't properly set. If this happens we simply stop decoding
                    break;
                }

                $body = array(
                    'target'   => $target,
                    'response' => $deserializer->readString(),
                    'length'   => $stream->readLong(),
                    'data'     => $deserializer->readAMFData(null,true)
                );

                if (is_object($body['data']) && $body['data'] instanceof SabreAMF_AMF3_Wrapper) {
                     $body['data'] = $body['data']->getData();
                     $this->encoding = SabreAMF_Const::AMF3;
                } else if (is_array($body['data']) && isset($body['data'][0]) && is_object($body['data'][0]) && $body['data'][0] instanceof SabreAMF_AMF3_Wrapper) {
                     $body['data'] = $body['data'][0]->getData();
                     $this->encoding = SabreAMF_Const::AMF3;
                }

                $this->bodies[] = $body;

            }


        }

        /**
         * getClientType
         *
         * Returns the ClientType for the request. Check SabreAMF_Const for possible (known) values
         *
         * @return int
         */
        public function getClientType() {

            return $this->clientType;

        }

        /**
         * getBodies
         *
         * Returns the bodies int the message
         *
         * @return array
         */
        public function getBodies() {

            return $this->bodies;

        }

        /**
         * getHeaders
         *
         * Returns the headers in the message
         *
         * @return array
         */
        public function getHeaders() {

            return $this->headers;

        }

        /**
         * addBody
         *
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
         *
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
         *
         * @param int $encoding
         * @return void
         */
        public function setEncoding($encoding) {

            $this->encoding = $encoding;

        }

        /**
         * getEncoding
         *
         * @return int
         */
        public function getEncoding() {

            return $this->encoding;

        }

    }


