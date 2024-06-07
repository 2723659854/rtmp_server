<?php


namespace MediaServer\Flv;


use Evenement\EventEmitter;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use MediaServer\PushServer\PlayStreamInterface;
use MediaServer\Utils\WMChunkStreamInterface;
use MediaServer\Utils\WMHttpChunkStream;
use Root\Io\RtmpDemo;
use Root\Request;
use function chr;
use function ord;

/**
 * @purpose flv播放资源
 */
class FlvPlayStream extends EventEmitter implements PlayStreamInterface
{
    /** 播放路径 */
    protected $playPath = '';
    /**
     * 输入 数据分片流
     * @var WMHttpChunkStream
     */
    protected $input;

    /** 空闲状态 */
    protected $isPlayerIdling = true;
    /** 播放中 */
    protected $isPlaying = false;
    /** 是否flv头部 */
    protected $isFlvHeader = false;
    /** 关闭 */
    protected $closed = false;

    /**
     * FlvPlayStream constructor.
     * @param $input WMChunkStreamInterface
     * @param $playPath
     */
    public function __construct($input, $playPath)
    {
        $this->input = $input;
        /** 给当前链接绑定 error事件 */
        $input->on('error', [$this, 'onStreamError']);
        /** 绑定close事件 */
        $input->on('close', [$this, 'close']);
        /** 绑定播放路径 */
        $this->playPath = $playPath;
    }

    public function __destruct()
    {
        logger()->info("player flv stream {path} destruct", ['path' => $this->playPath]);
    }

    /**
     * 触发错误回调
     * @param \Exception $e
     * @internal
     */
    public function onStreamError(\Exception $e)
    {
        $this->close();
    }

    /**
     * 关闭链接并移除所有监听事件
     * @return void
     */
    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        /** 关闭链接 */
        $this->input->close();
        $this->emit('on_close');
        /** 移除所有监听事件 */
        $this->removeAllListeners();
    }


    /**
     * 播放器是否空闲
     * @return bool|mixed
     */
    public function isPlayerIdling()
    {
        return $this->isPlayerIdling;
    }

    /**
     * 发送数据
     * @param $data
     * @return null
     */
    public function write2($data)
    {
        return $this->input->write($data);

    }

    /** 是否已经发送了第一个flv块*/
    public static $hasSendHeader = [];
    /**
     * 发送数据
     * @param $data
     * @return null
     * @comment 此方法没有错误，但是为什么不能播放
     */
    public function write($data)
    {
        $client = $this->input->connection->getSocket();
        /** 判断是否是发送第一个分块 */
        if (!isset(self::$hasSendHeader[(int)$client])) {
            /** 配置flv头 */
            $content = "HTTP/1.1 200 OK\r\n";
            $content .="Cache-Control: no-cache\r\n";
            $content .= "Content-Type: video/x-flv\r\n";
            $content .= "Transfer-Encoding: chunked\r\n";
            $content .= "Connection: keep-alive\r\n";
            $content .= "Server: xiaosongshu\r\n";
            $content .= "Access-Control-Allow-Origin: *\r\n";
            $content .="\r\n";
            /** 向浏览器发送数据 */
            fwrite($client, $content.\dechex(\strlen($data))."\r\n$data\r\n");
            /** 标记已发送过头部了 */
            self::$hasSendHeader[(int)$client] = 1;
        }else{
            /** 直接发送分块后的flv数据 */
            fwrite($client,\dechex(\strlen($data))."\r\n$data\r\n");
        }
    }

    /**
     * 开启声音
     * @return true
     */
    public function isEnableAudio()
    {
        return true;
    }

    /**
     * 开启视频
     * @return true
     */
    public function isEnableVideo()
    {
        return true;
    }

    /**
     * 是否重要关键帧
     * @return true
     */
    public function isEnableGop()
    {
        return true;
    }

    /**
     * 设置音频
     * @param $status
     * @return void
     */
    public function setEnableAudio($status)
    {
    }

    /**
     * 设置视频
     * @param $status
     * @return void
     */
    public function setEnableVideo($status)
    {
    }

    /**
     * 设置关键帧
     * @param $status
     * @return void
     */
    public function setEnableGop($status)
    {
    }


    /**
     * 此方法可用，說明不是數據的問題
     * @return void
     */
    public static function startPlay2(Request $request,string $path)
    {

        $client = $request->connection->getSocket();
        /** 發送啟動播放數據 */
        RtmpDemo::startPlay($client);
        /** 获取推流的资源 */
        $publishStream = MediaServer::getPublishStream($path);
        /**
         * 发送meta元数据 就是基本参数
         * meta data send
         */
        if ($publishStream->isMetaData()) {
            $metaDataFrame = $publishStream->getMetaDataFrame();
            RtmpDemo::frameSend($metaDataFrame,$client);
        }

        /**
         * 发送视频avc数据
         * avc sequence send
         */
        if ($publishStream->isAVCSequence()) {
            $avcFrame = $publishStream->getAVCSequenceFrame();
            RtmpDemo::frameSend($avcFrame,$client);
        }


        /**
         * 发送音频aac数据
         * aac sequence send
         */
        if ($publishStream->isAACSequence()) {
            $aacFrame = $publishStream->getAACSequenceFrame();
            RtmpDemo::frameSend($aacFrame,$client);
        }

        //gop 发送
        /**
         * 发送关键帧 經過測試，這個是必須要發送的，否則無法播放
         */
        foreach ($publishStream->getGopCacheQueue() as $frame) {
            RtmpDemo::frameSend($frame,$client);
        }

    }
    /**
     * 开始播放
     * @return void
     * @note 主业务逻辑
     */
    public function startPlay()
    {
        //各种发送数据包
        $path = $this->getPlayPath();
        /** 获取推流的资源 */
        $publishStream = MediaServer::getPublishStream($path);
        logger()->info('flv play stream start play');
        /** 还没有发送flv协议头 */
        if (!$this->isFlvHeader) {
            /** 组装flv头部 签名=flv （0x46,0x4c,0x66）, 版本号=x01, flag = x00，headerSize =9(flvheader的总长度=9，前一个数据块大小为0) */
            $flvHeader = "FLV\x01\x00" . pack('NN', 9, 0);
            /** 是否有音频数据 */
            if ($this->isEnableAudio() && $publishStream->hasAudio()) {
                /** 使用ord将$flvHeader[4]转换为8为二进制 ASCII 码，然后和后面的4进行或运算，4的二进制是00000100 ，第三位是1，或运算后那么$flvHeader[4]的第3位变为1 */
                $flvHeader[4] = chr(ord($flvHeader[4]) | 4);
            }
            /** 是否有视频数据 */
            if ($this->isEnableVideo() && $publishStream->hasVideo()) {
                /** 同理：1的二进制00000001 那么$flvHeader[4]的右边第一位变为1 */
                $flvHeader[4] = chr(ord($flvHeader[4]) | 1);
            }
            /** 发送flv协议头部 数据 */
            $this->write($flvHeader);
            /** 标记已发送flv头部 */
            $this->isFlvHeader = true;
        }


        /**
         * 发送meta元数据 就是基本参数
         * meta data send
         */
        if ($publishStream->isMetaData()) {
            $metaDataFrame = $publishStream->getMetaDataFrame();
            $this->sendMetaDataFrame($metaDataFrame);
        }

        /**
         * 发送视频avc数据
         * avc sequence send
         */
        if ($publishStream->isAVCSequence()) {
            $avcFrame = $publishStream->getAVCSequenceFrame();
            $this->sendVideoFrame($avcFrame);
        }


        /**
         * 发送音频aac数据
         * aac sequence send
         */
        if ($publishStream->isAACSequence()) {
            $aacFrame = $publishStream->getAACSequenceFrame();
            $this->sendAudioFrame($aacFrame);
        }

        //gop 发送
        /**
         * 发送关键帧
         */
        if ($this->isEnableGop()) {
            foreach ($publishStream->getGopCacheQueue() as &$frame) {
                $this->frameSend($frame);
            }
        }
        /** 更新播放器状态为非空闲 */
        $this->isPlayerIdling = false;
        /** 更新为正在播放 */
        $this->isPlaying = true;
    }

    /**
     * 发送数据到客户端
     * @param $frame MediaFrame
     * @return mixed
     * @comment 发送音频，视频，元数据
     */
    public function frameSend($frame)
    {
        //   logger()->info("send ".get_class($frame)." timestamp:".($frame->timestamp??0));
        switch ($frame->FRAME_TYPE) {
            case MediaFrame::VIDEO_FRAME:
                return $this->sendVideoFrame($frame);
            case MediaFrame::AUDIO_FRAME:
                return $this->sendAudioFrame($frame);
            case MediaFrame::META_FRAME:
                return $this->sendMetaDataFrame($frame);
        }
    }

    /**
     * 关闭拉流
     * @return void
     */
    public function playClose()
    {
        $this->input->close();
    }

    /**
     * 获取播放资源地址
     * @return mixed|string
     */
    public function getPlayPath()
    {
        return $this->playPath;
    }


    /**
     * 发送元数据
     * @param $metaDataFrame MetaDataFrame|MediaFrame
     * @return mixed
     */
    public function sendMetaDataFrame($metaDataFrame)
    {
        /** 组装数据 */
        $tag = new FlvTag();
        $tag->type = Flv::SCRIPT_TAG;
        $tag->timestamp = 0;
        $tag->data = (string)$metaDataFrame;
        $tag->dataSize = strlen($tag->data);
        /** 将数据打包编码 */
        $chunks = Flv::createFlvTag($tag);
        /** 发送 */
        $this->write($chunks);
    }

    /**
     * 发送音频帧
     * @param $audioFrame AudioFrame|MediaFrame
     * @return mixed
     */
    public function sendAudioFrame($audioFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::AUDIO_TAG;
        $tag->timestamp = $audioFrame->timestamp;
        $tag->data = (string)$audioFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }

    /**
     * 发送视频帧
     * @param $videoFrame VideoFrame|MediaFrame
     * @return mixed
     */
    public function sendVideoFrame($videoFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::VIDEO_TAG;
        $tag->timestamp = $videoFrame->timestamp;
        $tag->data = (string)$videoFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }


}
