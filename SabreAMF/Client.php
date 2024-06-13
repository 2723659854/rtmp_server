<?php


    require_once dirname(__FILE__) . '/Message.php';
    require_once dirname(__FILE__) . '/OutputStream.php';
    require_once dirname(__FILE__) . '/InputStream.php';
    require_once dirname(__FILE__) . '/Const.php';
    require_once dirname(__FILE__) . '/AMF3/Wrapper.php';

    /**
     * 这里定义了amf客户端：用来发送amf控制命令
     * AMF Client
     *
     * 使用此类可以调用AMF0/AMF3服务。该类使用curlhttp库，因此请确保已安装该库。
     * Use this class to make a calls to AMF0/AMF3 services. The class makes use of the curl http library, so make sure you have this installed.
     * 默认使用amf0编码，你可以更改为amf3编码
     * It sends AMF0 encoded data by default. Change the encoding to AMF3 with setEncoding. sendRequest calls the actual service
     *
     * @package SabreAMF
     * @version $Id: Client.php 233 2009-06-27 23:10:34Z evertpot $
     * @copyright Copyright (C) 2006-2009 Rooftop Solutions. All rights reserved.
     * @author Evert Pot (http://www.rooftopsolutions.nl/)
     * @licence http://www.freebsd.org/copyright/license.html  BSD License
     * @example ../examples/client.php
     * @uses SabreAMF_Message
     * @uses SabreAMF_OutputStream
     * @uses SabreAMF_InputStream
     */
    class SabreAMF_Client {

        /**
         * 网络节点
         * endPoint
         *
         * @var string
         */
        private $endPoint;
        /**
         * 端口号
         * httpProxy
         *
         * @var mixed
         */
        private $httpProxy;
        /**
         * 输入流
         * amfInputStream
         *
         * @var SabreAMF_InputStream
         */
        private $amfInputStream;
        /**
         * 输出流
         * amfOutputStream
         *
         * @var SabreAMF_OutputStream
         */
        private $amfOutputStream;

        /**
         * request请求内容
         * amfRequest
         *
         * @var SabreAMF_Message
         */
        private $amfRequest;

        /**
         * response响应内容
         * amfResponse
         *
         * @var SabreAMF_Message
         */
        private $amfResponse;

        /**
         * 默认的编码格式amf0
         * encoding
         *
         * @var int
         */
        private $encoding = SabreAMF_Const::AMF0;

        /**
         * 初始化
         * __construct
         * 传入节点
         * @param string $endPoint The url to the AMF gateway
         * @return void
         */
        public function __construct($endPoint) {
            /** 初始化节点 */
            $this->endPoint = $endPoint;
            /** 初始化请求体 amf命令消息 */
            $this->amfRequest = new SabreAMF_Message();
            /** 初始化编码工具 */
            $this->amfOutputStream = new SabreAMF_OutputStream();

        }


        /**
         * 发送请求
         * sendRequest
         * 向服务端发送请求 需要请求地址，方法名，其他参数
         * sendRequest sends the request to the MediaServer. It expects the servicepath and methodname, and the parameters of the methodcall
         *
         * @param string $servicePath The servicepath (e.g.: myservice.mymethod) 服务路径
         * @param array $data The parameters you want to send 你想发送的任何参数
         * @return mixed
         */
        public function sendRequest($servicePath,$data) {

            /** 发送flex命令 */
            // We're using the FLEX Messaging framework
            if($this->encoding & SabreAMF_Const::FLEXMSG) {


                // Setting up the message
                $message = new SabreAMF_AMF3_RemotingMessage();
                $message->body = $data;

                /** 解码请求路径和请求方法 */
                // We need to split serviceName.methodName into separate variables
                $service = explode('.',$servicePath);
                /** 数组第一个元素是请求方法 get,post,head,option,delete... */
                $method = array_pop($service);
                /** 剩下的是路由重新组装成字符串 */
                $service = implode('.',$service);
                $message->operation = $method;
                $message->source = $service;
                /** 用message替换data */
                $data = $message;
            }
            /** 添加请求数据到body */
            $this->amfRequest->addBody(array(

                // If we're using the flex messaging framework, target is specified as the string 'null'
                'target'   => $this->encoding & SabreAMF_Const::FLEXMSG?'null':$servicePath,
                'response' => '/1',
                'data'     => $data
            ));
            /** 序列化要发送的数据 */
            $this->amfRequest->serialize($this->amfOutputStream);
            /** 发送请求 tcp通信，用的http协议 */
            // The curl request
            $ch = curl_init($this->endPoint);
            curl_setopt($ch,CURLOPT_POST,1);
            curl_setopt($ch,CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch,CURLOPT_TIMEOUT,20);
            curl_setopt($ch,CURLOPT_HTTPHEADER,array('Content-type: ' . SabreAMF_Const::MIMETYPE));
            curl_setopt($ch,CURLOPT_POSTFIELDS,$this->amfOutputStream->getRawData());
    		if ($this->httpProxy) {
    			curl_setopt($ch,CURLOPT_PROXY,$this->httpProxy);
    		}
            $result = curl_exec($ch);

            if (curl_errno($ch)) {
                throw new Exception('CURL error: ' . curl_error($ch));
                false;
            } else {
                curl_close($ch);
            }
            /** 将接收的数据投递到解码器 */
            $this->amfInputStream = new SabreAMF_InputStream($result);
            /** 初始化响应 */
            $this->amfResponse = new SabreAMF_Message();
            /** 对服务端返回的数据进行解码 */
            $this->amfResponse->deserialize($this->amfInputStream);
            /** 解析头部数据 */
            $this->parseHeaders();
            /** 返回响应结果 */
            foreach($this->amfResponse->getBodies() as $body) {

                if (strpos($body['target'],'/1')===0) return $body['data'] ;

            }

        }

        /**
         * 添加请求头部
         * addHeader
         *
         * Add a header to the client request
         *
         * @param string $name
         * @param bool $required
         * @param mixed $data
         * @return void
         */
        public function addHeader($name,$required,$data) {

            $this->amfRequest->addHeader(array('name'=>$name,'required'=>$required==true,'data'=>$data));

        }

        /**
         * 设置鉴权参数 比如用户名和密码
         * setCredentials
         *
         * @param string $username
         * @param string $password
         * @return void
         */
        public function setCredentials($username,$password) {

            $this->addHeader('Credentials',false,(object)array('userid'=>$username,'password'=>$password));

        }

        /**
         * 设置http端口
         * setHttpProxy
         *
         * @param mixed $httpProxy
         * @return void
         */
        public function setHttpProxy($httpProxy) {
            $this->httpProxy = $httpProxy;
        }

        /**
         * 解析头部数据
         * parseHeaders
         * 目的是获取服务器IP
         * @return void
         */
        private function parseHeaders() {

            foreach($this->amfResponse->getHeaders() as $header) {

                switch($header['name']) {

                    case 'ReplaceGatewayUrl' :
                        if (is_string($header['data'])) {
                            $this->endPoint = $header['data'];
                        }
                        break;

                }


            }

        }

        /**
         * 设置编码格式
         * Change the AMF encoding (0 or 3)
         *
         * @param int $encoding
         * @return void
         */
        public function setEncoding($encoding) {

            $this->encoding = $encoding;
            $this->amfRequest->setEncoding($encoding & SabreAMF_Const::AMF3);

        }

    }



