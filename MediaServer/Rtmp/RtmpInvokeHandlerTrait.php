<?php


namespace MediaServer\Rtmp;


use MediaServer\MediaServer;
use \Exception;
use React\Promise\PromiseInterface;

/**
 * @purpose rtmp操作命令解析
 * @comment 这个是处理 连接，播放，推流等命令的
 *
 * @note 每一个命令的第二个参数是transId，相当于是请求ID，每一个命令有请求，就有回复，一一对应的。
 * 1. connect
 * 功能: 建立客户端与RTMP服务器之间的连接。
 * 使用场景: 客户端初次连接到服务器时发送。
 * 参数: 包括应用名称、版本、闪存版本、代理信息等。
 *  <code>
 *      ['connect', 1, {
 *  'app': 'live',
 *  'flashVer': 'FMLE/3.0 (compatible; FMSc/1.0)',
 *  'tcUrl': 'rtmp://localhost/live',
 *  'fpad': false,
 *  'capabilities': 15,
 *  'audioCodecs': 4071,
 *  'videoCodecs': 252,
 *  'videoFunction': 1
 *  }]
 *  </code>
 * 2. releaseStream
 * 功能: 请求服务器释放指定的流名。通常在发布流之前使用，以确保没有其他客户端使用该流名。
 * 使用场景: 在发布流之前，确保流名是可用的。
 * <code>
 *     ['releaseStream', 2, null, 'mystream']
 * </code>
 * 3. FCPublish
 * 功能: 通知服务器客户端准备发布一个流。
 * 使用场景: 在发布流之前，服务器将预先进行相关准备工作。
 * <code>
 *     ['FCPublish', 3, null, 'mystream']
 * </code>
 * 4. createStream
 * 功能: 请求服务器创建一个新的流并分配一个流ID。
 * 使用场景: 在发布或播放流之前使用，以获得一个新的流ID。
 * 响应: 服务器返回创建的流ID。
 * <code>
 *     ['createStream', 4, null]
 * </code>
 * 返回响应
 * <code>
 *     ['_result', 4, null, 1]  # 返回流ID 1
 * </code>
 * 5. publish
 * 功能: 告诉服务器客户端要开始发布音视频流。
 * 使用场景: 当客户端准备开始发送音视频数据时使用。
 * 参数: 流名称、发布类型（如 live, record, append）。
 * <code>
 *     ['publish', 5, null, 'mystream', 'live']
 * </code>
 * 6. play
 * 功能: 告诉服务器客户端要播放指定的流。
 * 使用场景: 当客户端准备接收并播放音视频数据时使用。
 * 参数: 流名称、开始时间、播放时长等。
 * <code>
 *     ['play', 6, null, 'mystream', -2, -1, false]
 * </code>
 * 7. pause
 * 功能: 暂停或恢复播放流。
 * 使用场景: 客户端需要暂停或恢复正在播放的流时使用。
 * 参数: 暂停标志（true/false）、暂停时间点。
 * <code>
 *     ['pause', 7, null, true, 1234]  # 暂停在1234毫秒
 * </code>
 * 8. FCUnpublish
 * 功能: 通知服务器客户端要停止发布一个流。
 * 使用场景: 当客户端准备停止发送音视频数据时使用。
 * <code>
 *     ['FCUnpublish', 8, null, 'mystream']
 * </code>
 * 9. deleteStream
 * 功能: 请求服务器删除指定的流。
 * 使用场景: 当客户端不再需要某个流时使用。
 * <code>
 *     ['deleteStream', 9, null, 1]  # 删除流ID 1
 * </code>
 * 10. closeStream
 * 功能: 告诉服务器客户端要关闭指定的流。
 * 使用场景: 客户端完成播放或发布流时使用。
 * <code>
 *     ['closeStream', 10, null]
 * </code>
 * 11. receiveAudio
 * 功能: 控制客户端是否接收音频数据。
 * 使用场景: 客户端希望仅接收视频数据或希望恢复接收音频数据时使用。
 * 参数: 接收标志（true/false）。
 * <code>
 *     ['receiveAudio', 11, null, false]  # 停止接收音频
 * </code>
 * 12. receiveVideo
 * 功能: 控制客户端是否接收视频数据。
 * 使用场景: 客户端希望仅接收音频数据或希望恢复接收视频数据时使用。
 * 参数: 接收标志（true/false）。
 * <code>
 *     ['receiveVideo', 12, null, true]  # 开始接收视频
 * </code>
 *
 */
trait RtmpInvokeHandlerTrait
{

    /**
     * @return mixed | void
     * @throws Exception
     * @comment 从下面的命令来开，播放端只发送了connect和play两个命令，其余全部是推流端的命令
     */
    public function rtmpInvokeHandler()
    {
        /** 获取当前毫秒时间戳  */
        $b = microtime(true);
        /** 获取当前的包 */
        $p = $this->currentPacket;
        //AMF0 数据解释
        /** 读取消息 amf流媒体格式 */
        $invokeMessage = RtmpAMF::rtmpCMDAmf0Reader($p->payload);
        //logger()->info("[invokeMessage]:".json_encode($invokeMessage));
        /** 判断命令 */
        switch ($invokeMessage['cmd']) {
            /** 连接事件 推流端和播放端的事件  */
            case 'connect':
                $this->onConnect($invokeMessage);
                break;
            /** 释放流事件 推流端 */
            case 'releaseStream':
                break;

            /** 在 RTMP（Real-Time Messaging Protocol，实时消息传输协议）中，FCPublish是一个信令消息，
             * 用于发布或推送数据到 RTMP 服务器。它通常在客户端与服务器建立连接后发送，用于创建一个新的流媒体通道或发布新的内容。
             */
            /** 关闭发布流 */
            case 'FCPublish':
                break;
            /** 创建流 推流端 */
            case 'createStream':
                $this->onCreateStream($invokeMessage);
                break;
            /** 发布流媒体 推流端  */
            case 'publish':
                $this->onPublish($invokeMessage);
                break;
            /** 播放 播放端 */
            case 'play':
                $this->onPlay($invokeMessage);
                break;
            /** 暂停 推流端 */
            case 'pause':
                $this->onPause($invokeMessage);
                break;
            /** 不发布  推流端 */
            case 'FCUnpublish':
                break;
            /** 删除流媒体资源 推流端 */
            case 'deleteStream':
                $this->onDeleteStream($invokeMessage);
                break;
            /** 关闭资源 推流端 */
            case 'closeStream':
                $this->onCloseStream();
                break;
            /** 接收到音频数据 推流端 */
            case 'receiveAudio':
                $this->onReceiveAudio($invokeMessage);
                break;
            /** 接收到视频数据 推流端 */
            case 'receiveVideo':
                $this->onReceiveVideo($invokeMessage);
                break;
        }
        /** 打印命令操作耗时 rtmpInvokeHandler publish use:1.4660358428955ms */
        logger()->info("rtmpInvokeHandler {$invokeMessage['cmd']} use:" . ((microtime(true) - $b) * 1000) . 'ms');
    }

    /**
     * 客户端连接事件
     * @param $invokeMessage
     * @throws Exception
     */
    public function onConnect($invokeMessage)
    {
        /** 获取毫秒时间戳 */
        $b = microtime(true);
        /** 整理应用名称  */
        $invokeMessage['cmdObj']['app'] = str_replace('/', '', $invokeMessage['cmdObj']['app']); //fix jwplayer??
        /** 触发preConnect事件 */
        $this->emit('preConnect', [$this->id, $invokeMessage['cmdObj']]);
        if (!$this->isStarting) {
            return;
        }
        /** 获取命令 */
        $this->connectCmdObj = $invokeMessage['cmdObj'];
        /** 获取应用名称 */
        $this->appName = $invokeMessage['cmdObj']['app'];
        /** 获取编码方式 */
        $this->objectEncoding = (isset($invokeMessage['cmdObj']['objectEncoding']) && !is_null($invokeMessage['cmdObj']['objectEncoding'])) ? $invokeMessage['cmdObj']['objectEncoding'] : 0;

        /** 记录连接时间 */
        $this->startTimestamp = timestamp();
        /** 添加一个定时器 */
        /** 返回ack */
        $this->sendWindowACK(5000000);
        /** 设置宽带 */
        $this->setPeerBandwidth(5000000, 2);
        /** 设置分包大小 */
        $this->setChunkSize($this->outChunkSize);
        /** 回复客户端 */
        $this->responseConnect($invokeMessage['transId']);

        /** 记录心跳检测时间 */
        $this->bitrateCache = [
            'intervalMs' => 1000,
            'last_update' => $this->startTimestamp,
            'bytes' => 0,
        ];
        /** id=3OKLQ5OF ip= app=a args={"app":"a","type":"nonprivate","flashVer":"FMLE\/3.0 (compatible; FMSc\/1.0)","swfUrl":"rtmp:\/\/127.0.0.1:1935\/a","tcUrl":"rtmp:\/\/127.0.0.1:1935\/a"} use:0.36311149597168ms */
        logger()->info("[rtmp connect] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage['cmdObj']) . " use:" . ((microtime(true) - $b) * 1000) . 'ms');
    }

    /**
     * 创建流媒体事件
     * @param $invokeMessage
     * @throws Exception
     */
    public function onCreateStream($invokeMessage)
    {
        /** id=3OKLQ5OF ip= app=a args={"cmd":"createStream","transId":4} */
        logger()->info("[rtmp create stream] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
        /** 创建资源 */
        $this->respondCreateStream($invokeMessage['transId']);
    }

    /**
     * 推流
     * @param $invokeMessage
     * @param $isPromise bool 是否异步回调
     * @throws Exception
     */
    public function onPublish($invokeMessage, $isPromise = false)
    {
        if (!$isPromise) {
            /** id=3OKLQ5OF ip= app=a args={"cmd":"publish","transId":5,"cmdObj":null,"streamName":"b","type":"live"} */
            //发布一个视频
            logger()->info("[rtmp publish] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            if (!is_string($invokeMessage['streamName'])) {
                return;
            }
            /** 获取流媒体信息 */
            $streamInfo = explode('?', $invokeMessage['streamName']);
            /** 流媒体所属位置，*/
            $this->publishStreamPath = '/' . $this->appName . '/' . $streamInfo[0];
            /** 解析推流参数 */
            parse_str($streamInfo[1] ?? '', $this->publishArgs);
            $this->publishStreamId = $this->currentPacket->streamId;
        }
        //auth check
        /** 权限检查，这里是的权限检查是假的 */
        if (!$isPromise && $result = MediaServer::verifyAuth($this)) {
            if ($result === false) {
                logger()->info("[rtmp publish] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
                //check false
                $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.publish.Unauthorized', 'Authorization required.');
                return;
            }
            /** 如果这个权限检查是异步的 不会走这一步的，因为鉴权直接反回的TRUE */
            if ($result instanceof PromiseInterface) {
                //异步检查
                $result->then(function () use ($invokeMessage) {
                    //resolve
                    /** 检查通过后，重新推流 */
                    $this->onPublish($invokeMessage, true);
                }, function ($exception) use ($invokeMessage) {
                    logger()->info("[rtmp publish] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage) . " " . $exception->getMessage());
                    //check false
                    $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.publish.Unauthorized', 'Authorization required.');
                });
                return;
            }
        }
        /** 如果已分配推流路径 ，就是每一个资源在服务端都必须有一个空间，临时存放数据，说明这个已经在推流了，这个资源点不能用了 */
        if (MediaServer::hasPublishStream($this->publishStreamPath)) {
            //publishStream already
            logger()->info("[rtmp publish] Already has a stream. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            $this->reject();
            $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.Publish.BadName', 'Stream already publishing');
        } else if ($this->isPublishing) {
            /** 如果正在推流中 */
            logger()->info("[rtmp publish] NetConnection is publishing. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            $this->sendStatusMessage($this->publishStreamId, 'error', 'NetStream.Publish.BadConnection', 'Connection already publishing');
        } else {
            /** 添加推流 这个方法绑定了推流事件， 这里和MediaServer产生了联系 */
            MediaServer::addPublish($this);
            /** 标记为正在推理 */
            $this->isPublishing = true;
            $this->sendStatusMessage($this->publishStreamId, 'status', 'NetStream.Publish.Start', "{$this->publishStreamPath} is now published.");

            //emit on on_publish_ready
            /** 触发事件推流已就绪 */
            $this->emit('on_publish_ready');

        }
    }

    /**
     * 播放事件
     * @param $invokeMessage
     * @param $isPromise bool
     * @throws Exception
     * 客户端发送播放命令后，会调用这个方法，
     */
    public function onPlay($invokeMessage, $isPromise = false)
    {
        if (!$isPromise) {
            logger()->info("[rtmp play] id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
            if (!is_string($invokeMessage['streamName'])) {
                return;
            }
            /** 解析流媒体相关参数的 */
            /** @var RtmpPacket $p */
            $parse = explode('?', $invokeMessage['streamName']);
            $this->playStreamPath = '/' . $this->appName . '/' . $parse[0];
            parse_str($parse[1] ?? '', $this->playArgs);
            $this->playStreamId = $this->currentPacket->streamId;
        }

        //auth check
        if (!$isPromise && $result = MediaServer::verifyAuth($this)) {
            /** 这里鉴权永远成功 */
            if ($result === false) {
                logger()->info("[rtmp play] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage));
                $this->sendStatusMessage($this->playStreamId, 'error', 'NetStream.play.Unauthorized', 'Authorization required.');
                return;
            }
            /** 不会走这一步 */
            if ($result instanceof PromiseInterface) {
                //异步检查
                $result->then(function () use ($invokeMessage) {
                    //resolve
                    $this->onPlay($invokeMessage, true);
                }, function ($exception) use ($invokeMessage) {
                    logger()->info("[rtmp play] Unauthorized. id={$this->id} ip={$this->ip} app={$this->appName} args=" . json_encode($invokeMessage) . " " . $exception->getMessage());
                    //check false
                    $this->sendStatusMessage($this->playStreamId, 'error', 'NetStream.play.Unauthorized', 'Authorization required.');
                });
                return;
            }
        }

        if ($this->isPlaying) {
            $this->sendStatusMessage($this->playStreamId, 'error', 'NetStream.Play.BadConnection', 'Connection already playing');
        } else {
            /** 返回播放结果 */
            $this->respondPlay();
        }
        /** 添加播放 ，这个方法绑定推流事件 ，是这个方法和MediaServer对象产生了联系 */
        MediaServer::addPlayer($this);

    }

    /**
     * 暂停
     * @param $invokeMessage
     * @return void
     */
    public function onPause($invokeMessage)
    {
        //暂停视频
        /** 如果暂停标识为true，保存暂停时间戳，不再给客户端推送数据，并且将音视频数据帧按客户端保存到缓存中，这里存数据需要谨慎处理，防止内存泄漏，可能需要单独弄一个临时内存来保存 */
        /** 如果暂停标识为false，则从缓存中取出数据，从时间戳处开始推送数据给客户端 */
    }

    /**
     * 通知服务器停止推流，释放相关资源
     * @param $invokeMessage
     * @return void
     */
    public function onDeleteStream($invokeMessage)
    {
        //删除流
        /**
         * 停止接收推流数据: 停止从该客户端接收推流的音视频数据。
         *
         * 释放相关资源: 释放与该推流相关的资源，包括网络连接、缓存、内存等。
         *
         * 响应客户端: 向推流客户端发送确认消息，通知成功停止推流。
         *
         * 不需要处理播放客户端，播放器继续播放缓存的数据，继续维持链接
         */
    }

    /** 关闭资源 */
    public function onCloseStream()
    {
        //关闭流，调用删除流逻辑
        $this->onDeleteStream(['streamId' => $this->currentPacket->streamId]);
    }

    /**
     * 是否接收音频数据
     * @param $invokeMessage
     * @return void
     * @comment 播放器告知服务端是否需要接收音频数据
     */
    public function onReceiveAudio($invokeMessage)
    {
        logger()->info("[rtmp play] receiveAudio=" . ($invokeMessage['bool'] ? 'true' : 'false'));
        /** 标记是否接收到音频数据 实际上应该按客户端保存，推送数据的时候应该按客户端判断是否需要发送音频数据 */
        $this->isReceiveAudio = $invokeMessage['bool'];
    }

    /**
     * 是否接收视频数据
     * @param $invokeMessage
     * @return void
     * @comment  播放器告知服务端是否需要接收视频数据
     */
    public function onReceiveVideo($invokeMessage)
    {
        logger()->info("[rtmp play] receiveVideo=" . ($invokeMessage['bool'] ? 'true' : 'false'));
        $this->isReceiveVideo = $invokeMessage['bool'];
    }

    /** 发送流媒体状态 */
    public function sendStreamStatus($st, $id)
    {
        /** 16进制转二进制 */
        $buf = hex2bin('020000000000060400000000000000000000');
        $buf = substr_replace($buf, pack('nN', $st, $id), 12);
        $this->write($buf);
    }


    /**
     * 发送invoke消息
     * @param $sid
     * @param $opt
     * @throws Exception
     * @comment 发送cmd命令回复消息
     */
    public function sendInvokeMessage($sid, $opt)
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_INVOKE;
        $packet->type = RtmpPacket::TYPE_INVOKE;
        $packet->streamId = $sid;
        $packet->payload = RtmpAMF::rtmpCMDAmf0Creator($opt);
        $packet->length = strlen($packet->payload);
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }

    /**
     * 发送数据消息
     *
     * @param $sid
     * @param $opt
     * @throws Exception
     * @comment 发送普通数据消息
     */
    public function sendDataMessage($sid, $opt)
    {
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_DATA;
        $packet->type = RtmpPacket::TYPE_DATA;
        $packet->streamId = $sid;
        $packet->payload = RtmpAMF::rtmpDATAAmf0Creator($opt);
        $packet->length = strlen($packet->payload);
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }

    /**
     * 发送状态信息
     * @param $sid
     * @param $level
     * @param $code
     * @param $description
     * @throws Exception
     */
    public function sendStatusMessage($sid, $level, $code, $description)
    {
        $opt = [
            'cmd' => 'onStatus',
            'transId' => 0,
            'cmdObj' => null,
            'info' => [
                'level' => $level,
                'code' => $code,
                'description' => $description
            ]
        ];
        $this->sendInvokeMessage($sid, $opt);
    }

    /**
     * 发送权限
     * @param $sid
     * @throws Exception
     * @comment  RtmpSampleAccess设置为true，表示该流支持随机访问。播放器在播放这样的流时，可以根据用户的操作随意跳转到不同的时间点进行
     * 播放，而不必从头开始播放或等待加载完整个流。
     */
    public function sendRtmpSampleAccess($sid)
    {
        $opt = [
            'cmd' => '|RtmpSampleAccess',
            'bool1' => false,
            'bool2' => false
        ];
        $this->sendDataMessage($sid, $opt);
    }


    /**
     * 发送心跳消息
     * @throws Exception
     */
    public function sendPingRequest()
    {

        $currentTimestamp = timestamp() - $this->startTimestamp;
        //logger()->debug("send ping time:" . $currentTimestamp);
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_PROTOCOL;
        $packet->type = RtmpPacket::TYPE_EVENT;
        $packet->payload = pack("nN", 6, $currentTimestamp);
        $packet->length = 6;
        $chunks = $this->rtmpChunksCreate($packet);
        $this->write($chunks);
    }


    /**
     * 返回连接成功数据
     * @param $tid
     * @throws Exception
     */
    public function responseConnect($tid)
    {
        $opt = [
            'cmd' => '_result',
            /** 事务id 就是将命令分组，和MySQL的事务一样，将一件大事情拆分成很多小事情 */
            'transId' => $tid,
            'cmdObj' => [
                /** flash 版本号，就是协议版本号 */
                'fmsVer' => 'FMS/3,0,1,123',
                /** 客户端应该按位进行解释 有点懵逼 */
                'capabilities' => 31
            ],
            'info' => [
                'level' => 'status',
                'code' => 'NetConnection.Connect.Success',
                'description' => 'Connection succeeded.',
                'objectEncoding' => $this->objectEncoding
            ]
        ];
        $this->sendInvokeMessage(0, $opt);
    }

    /**
     * 返回创建资源信息
     * @param $tid
     * @throws Exception
     */
    public function respondCreateStream($tid)
    {
        $this->streams++;
        $opt = [
            'cmd' => '_result',
            'transId' => $tid,
            'cmdObj' => null,
            'info' => $this->streams
        ];
        $this->sendInvokeMessage(0, $opt);
    }

    /**
     * 发送播放数据
     * @throws Exception
     * @comment 播放器发送播放命令，服务端接收到这个命令后，调用这个函数发送给播放器，然后开始推送音视频数据
     */
    public function respondPlay()
    {
        /** 发送二进制数据 资源已准备 */
        $this->sendStreamStatus(RtmpPacket::STREAM_BEGIN, $this->playStreamId);
        /** 告诉客户端，播放资源重置 */
        $this->sendStatusMessage($this->playStreamId, 'status', 'NetStream.Play.Reset', 'Playing and resetting stream.');
        /** 开始播放 */
        $this->sendStatusMessage($this->playStreamId, 'status', 'NetStream.Play.Start', 'Started playing stream.');
        /** 发送权限，可以在任意位置播放 */
        $this->sendRtmpSampleAccess($this->playStreamId);
    }

    /**
     * 拒绝
     * @return void
     */
    public function reject()
    {
        logger()->info("[rtmp reject] reject stream publish id={$this->id}");
        $this->stop();
    }

}
