<?php

    /**
     * amf数据包是用来发送rtmp通信协议的命令或者媒体数据，avc和aac是rtmp通信协议的音视频数据包
     * SabreAMF_AMF3_CommandMessage
     *
     * @uses SabreAMF
     * @uses _AMF3_AbstractMessage
     * @package
     * @version $Id: CommandMessage.php 233 2009-06-27 23:10:34Z evertpot $
     * @copyright Copyright (C) 2006-2009 Rooftop Solutions. All rights reserved.
     * @author Evert Pot (http://www.rooftopsolutions.nl/)
     * @licence http://www.freebsd.org/copyright/license.html  BSD License (4 Clause)
     */

    require_once dirname(__FILE__) . '/../AMF3/AbstractMessage.php';

    /**
     * This class is used for service commands, like pinging the MediaServer
     * 这个类定义了一个 AMF3 命令消息结构，用于描述在 AMF3 编码中传输的各种命令操作。通过定义常量，可以轻松地使用这些操作类型来处理不同的命令。
     * 这个类的实例可以包含一个操作类型、一个消息引用类型和一个关联 ID，以便在客户端和服务器之间传输命令消息时使用。
     */
    class SabreAMF_AMF3_CommandMessage extends SabreAMF_AMF3_AbstractMessage {

        /** 订阅操作 */
        const SUBSCRIBE_OPERATION          = 0;
        /** 取消订阅 */
        const UNSUSBSCRIBE_OPERATION       = 1;
        /** 轮训操作 */
        const POLL_OPERATION               = 2;
        /** 客户端同步操作 */
        const CLIENT_SYNC_OPERATION        = 4;
        /** 客户端心跳操作 */
        const CLIENT_PING_OPERATION        = 5;
        /** 集群请求操作 */
        const CLUSTER_REQUEST_OPERATION    = 7;
        /** 登录操作 */
        const LOGIN_OPERATION              = 8;
        /** 退出操作 */
        const LOGOUT_OPERATION             = 9;
        /** 会话失效操作 */
        const SESSION_INVALIDATE_OPERATION = 10;
        /** 批量订阅操作 */
        const MULTI_SUBSCRIBE_OPERATION    = 11;
        /** 断开连接操作 */
        const DISCONNECT_OPERATION         = 12;

        /**
         * operation
         *
         * @var int
         */
        public $operation;

        /**
         * messageRefType
         *
         * @var int
         */
        public $messageRefType;

        /**
         * correlationId
         *
         * @var string
         */
        public $correlationId;

    }


