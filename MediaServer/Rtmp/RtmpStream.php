<?php


namespace MediaServer\Rtmp;


use Evenement\EventEmitter;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\DuplexMediaStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;
use MediaServer\Utils\WMBufferStream;



/**
 * 流媒体资源
 * Class RtmpStream
 * @package MediaServer\Rtmp
 * DuplexMediaStreamInterface 推流和播放的接口
 * VerifyAuthStreamInterface 鉴权接口
 *
 */
class RtmpStream extends EventEmitter implements DuplexMediaStreamInterface, VerifyAuthStreamInterface
{

    use RtmpHandshakeTrait,/** 握手 */
        RtmpChunkHandlerTrait,/** 分包 */
        RtmpPacketTrait,/** 打包 */
        RtmpTrait,/** rtmp工具 */
        RtmpPublisherTrait,/** 推流 */
        RtmpPlayerTrait;/** 播放 */

    /**
     * 握手状态
     * @var int handshake state
     */
    public int $handshakeState;

    /**
     * 资源id
     * @var string|false $id
     */
    public string|false $id;

    /**
     * IP
     * @var string $ip
     */
    public string $ip;

    /** 分包头部长度 */
    protected int $chunkHeaderLen = 0;
    /** 分片状态 */
    protected int $chunkState;

    /**
     * 所有的包
     * @var RtmpPacket[]
     */
    protected $allPackets = [];

    /**
     * @var int 接收数据时的  chunk size
     */
    protected    $inChunkSize = 128;
    /**
     * @var int 发送数据时的 chunk size
     */
    protected $outChunkSize = 60000;


    /**
     * 当前的包
     * @var RtmpPacket
     */
    protected $currentPacket;

    /** 开始时间戳 */
    public $startTimestamp;

    /** 编码方式 */
    public $objectEncoding;

    /** 流 */
    public $streams = 0;

    /** 播放流id */
    public $playStreamId = 0;
    /** 播放路径 */
    public $playStreamPath = '';
    /** 播放参数 */
    public $playArgs = [];
    /** 是否开始 */
    public $isStarting = false;
    /** 链接命令 */
    public $connectCmdObj = null;
    /** 应用名称 */
    public $appName = '';
    /** 是否接收音频帧 */
    public $isReceiveAudio = true;
    /** 是否接收视频帧 */
    public $isReceiveVideo = true;


    /**
     * @var int
     */
    public $pingTimer;

    /**
     * @var int ping interval
     */
    public $pingTime = 60;
    /** 比特率缓存 在 RTMP 协议中，音频头的前 4 个字节包含了一些信息，其中可能包括 bitrateCache。
     * 这些信息用于描述音频数据的特征和参数，以便在传输和播放过程中进行正确的处理 ，比特率缓存可以帮助优化流媒体的性能，减少卡顿和缓冲时间。
     */
    public $bitrateCache;


    /** 推流路径 */
    public $publishStreamPath;
    /** 推流参数 */
    public $publishArgs;
    /** 推流资源id */
    public $publishStreamId;


    /**
     * @var int 发送ack的长度
     */
    protected $ackSize = 0;

    /**
     * @var int 当前size统计
     */
    protected $inAckSize = 0;
    /**
     * @var int 上次ack的size
     */
    protected $inLastAck = 0;

    /** 是否是元数据 */
    public $isMetaData = false;
    /**
     * 元数据  配置信息
     * @var MetaDataFrame
     */
    public $metaDataFrame;

    /** 视频宽度 */
    public $videoWidth = 0;
    /** 视频高度 */
    public $videoHeight = 0;
    /** 视频帧率 */
    public $videoFps = 0;
    /** 当前帧 */
    public $videoCount = 0;
    /** 视频帧率计算器  每一秒多少张画面 */
    public $videoFpsCountTimer;
    /** 配置文件定义了编码的功能和特性 */
    public $videoProfileName = '';
    /** 视频编码等级 ，涉及分辨率，质量 */
    public $videoLevel = 0;
    /** 视频解码器 */
    public $videoCodec = 0;
    /** 视频解码器名称 */
    public $videoCodecName = '';
    /** 是否视频avc序列 */
    public $isAVCSequence = false;
    /**
     * 视频avc序列包
     * @var VideoFrame
     */
    public $avcSequenceHeaderFrame;

    /** 音频解码器 */
    public $audioCodec = 0;
    /** 音频解码器名称 */
    public $audioCodecName = '';
    /** 音频采样率 */
    public $audioSamplerate = 0;
    /** 音频声道1 */
    public $audioChannels = 1;
    /** 是否音频序列 */
    public $isAACSequence = false;
    /**
     * 音频aac 序列包
     * @var AudioFrame
     */
    public $aacSequenceHeaderFrame;
    /** 配置文件定义了编码的功能和特性 */
    public $audioProfileName = '';

    /** 是否在推流状态 */
    public $isPublishing = false;
    /** 是否在播放中 */
    public $isPlaying = false;

    /** 连续帧，就是是否一次清空队列，把队列里面的数据一次性全部发给客户端 */
    public $enableGop = true;

    /**
     * 数据队列 里面存放了 avc 和aac 和 metaData，先进先出的原则，实现原理是foreach
     * @var MediaFrame[]
     */
    public $gopCacheQueue = [];


    /**
     * 数据暂存区
     * @var WMBufferStream
     */
    protected $buffer;

    /** 定时器 */
    public $dataCountTimer;
    /** 已传递的帧数 */
    public $frameCount = 0;
    /** 传输帧数的时间 */
    public $frameTimeCount = 0;
    /** 已读字节数 */
    public $bytesRead = 0;
    /** 比特率 = 已读数据/耗时 */
    public $bytesReadRate = 0;

    /**
     * 初始化流媒体
     * PlayerStream constructor.
     * @param $bufferStream WMBufferStream 媒体资源 是tcp协议也是事件
     */
    public function __construct(WMBufferStream $bufferStream)
    {
        //先随机生成个id
        $this->id = generateNewSessionID();
        /** 先标记为握手还未初始化 */
        $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_UNINIT;
        /** ip */
        $this->ip = '';
        /** 开启了啊 */
        $this->isStarting = true;
        /** 存媒体数据 */
        $this->buffer = $bufferStream;
        /** 绑定接收到数据的事件  */
        $bufferStream->on('onData',[$this,'onStreamData']);
        /** 绑定错误事件 */
        $bufferStream->on('onError',[$this,'onStreamError']);
        /** 绑定关闭事件 */
        $bufferStream->on('onClose',[$this,'onStreamClose']);
    }

    /**
     * 接收到数据
     * @return void
     * @comment 这个方法因为一直在接收数据，所以一直在被不停的调用
     */
    public function onStreamData()
    {
        //若干秒后没有收到数据断开
        $b = microtime(true);

        /** 如果握手没有完成 ，则执行握手 */
        if ($this->handshakeState < RtmpHandshake::RTMP_HANDSHAKE_C2) {
            /** 处理握手 */
            $this->onHandShake();
        }

        /** 如果已经握手成功 */
        /** 这里是处理客户端发送的命令，然后发送数据的 */
        if ($this->handshakeState === RtmpHandshake::RTMP_HANDSHAKE_C2) {
            /** 数据分片 */
            $this->onChunkData();
            /** 计算当前已读数据长度  */
            $this->inAckSize += strlen($this->buffer->recvSize());
            /** 如果长度大于15 */
            if ($this->inAckSize >= 0xf0000000) {
                $this->inAckSize = 0;
                $this->inLastAck = 0;
            }
            /** 长度大于ack */
            if ($this->ackSize > 0 && $this->inAckSize - $this->inLastAck >= $this->ackSize) {
                //每次收到的数据超过ack设的值，上一次ack位置变更为本次的结尾位置
                $this->inLastAck = $this->inAckSize;
                /** 发送ack */
                $this->sendACK($this->inAckSize);
            }
        }
        /** 累加帧计时 */
        $this->frameTimeCount += microtime(true) - $b;
        /** 累加收到的帧数  */
        $this->frameCount++;


        //logger()->info("[rtmp on data] per sec handler times: ".(1/($end?:1)));
    }


    /** 如果资源关闭 则关闭这个连接 */
    public function onStreamClose()
    {
        $this->stop();
    }


    /** 发生了错误，关闭连接 */
    public function onStreamError()
    {
        $this->stop();
    }

    /** 发送数据 最终是通过tcp发送的 */
    public function write($data)
    {
        return $this->buffer->connection->send($data,true);
    }

/*    public function __destruct()
    {
        logger()->info("[RtmpStream __destruct] id={$this->id}");
    }*/



}
