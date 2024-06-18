<?php


namespace MediaServer\Rtmp;

use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use Root\Io\RtmpDemo;


/**
 * @purpose 视频数据处理
 */
trait RtmpVideoHandlerTrait
{


    public function rtmpVideoHandler()
    {
        //视频包拆解
        /**
         * @var $p RtmpPacket
         */
        $p = $this->currentPacket;
        /** 将视频数据存入视频帧包 */
        $videoFrame = new VideoFrame($p->payload, $p->clock);

        /** 获取视频编码 */
        if ($this->videoCodec == 0) {
            /** 获取解码器 */
            $this->videoCodec = $videoFrame->codecId;
            /** 获取解码器名称 */
            $this->videoCodecName = $videoFrame->getVideoCodecName();
        }

        /** 获取视频fps 帧率 */
        if ($this->videoFps === 0) {
            //当前帧为第0
            if ($this->videoCount++ === 0) { }
        }
        /** 获取视频编码 通过分片id判断 */
        switch ($videoFrame->codecId) {
            /** 只处理avc格式 表示 AVC H264 编码 */
            case VideoFrame::VIDEO_CODEC_ID_AVC:
                //h264
                /** 获取视频的包信息 */
                $avcPack = $videoFrame->getAVCPacket();
                /** 表示这是第一个avc包，这是视频解码帧，保存了视频的解码参数，宽，高，采样率等 */
                if ($avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    /** 是否avc序列 */
                    $this->isAVCSequence = true;
                    /** 解码帧，推流端只会发送一次，需要保存 */
                    $this->avcSequenceHeaderFrame = $videoFrame;
                    /** 获取包的视频配置 */
                    $specificConfig = $avcPack->getAVCSequenceParameterSet();
                    /** 视频的宽 */
                    $this->videoWidth = $specificConfig->width;
                    /** 视频的高 */
                    $this->videoHeight = $specificConfig->height;
                    /** 视频资源名称 ，配置信息 */
                    $this->videoProfileName = $specificConfig->getAVCProfileName();
                    /** 等级 视频等级 */
                    $this->videoLevel = $specificConfig->level;
                }
                if ($this->isAVCSequence) {
                    /** 如果是关键帧I帧 */
                    if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                        &&
                        /** 是nalu数据信息，就是媒体信息，表示这是一个独立的片段  */
                        $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_NALU) {
                        /** 如果这是一个独立的片段，那么就可以清空前面的连续帧，保存新的关键帧作为连续帧，可以用来解码出一个完整的画面 */
                        $this->gopCacheQueue = [];
                    }

                    /** 如果是关键帧  */
                    if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                        &&
                        $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                        //skip avc sequence
                        /** 忽略avc序列头，就是忽略解码帧 */
                    } else {

                        /** 将包投递到队列中，其他的关键帧全部保存 */
                        $this->gopCacheQueue[] = $videoFrame;
                    }
                }

                break;
        }
        /** 将数据推送给播放器 */
        //数据处理与数据发送
        $this->emit('on_frame', [$videoFrame, $this]);
        //销毁AVC
        $videoFrame->destroy();

    }
}
