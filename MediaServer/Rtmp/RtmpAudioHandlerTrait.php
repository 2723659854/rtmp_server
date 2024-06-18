<?php


namespace MediaServer\Rtmp;

use MediaServer\MediaReader\AACPacket;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaServer;
use Root\Io\RtmpDemo;

/**
 * @purpose 流媒体之音频处理
 */
trait RtmpAudioHandlerTrait
{

    /**
     * rtmp音频处理函数
     * @return void
     */
    public function rtmpAudioHandler()
    {
        //音频包拆解
        /**
         * rtmp数据包
         * @var $p RtmpPacket
         */
        $p = $this->currentPacket;

        /** 将音频文件投递到audio解码器中 */
        $audioFrame = new AudioFrame($p->payload, $p->clock);

        /** 如果音频编码是0，未定义 */
        if ($this->audioCodec == 0) {
            /** 获取音频编码格式 */
            $this->audioCodec = $audioFrame->soundFormat;
            /** 获取编码名称 */
            $this->audioCodecName = $audioFrame->getAudioCodecName();
            /** 获取音频采样率 */
            $this->audioSamplerate = $audioFrame->getAudioSamplerate();
            /** 获取编码格式 */
            $this->audioChannels = ++$audioFrame->soundType;
        }

        /** 如果音频是aac格式 */
        if ($audioFrame->soundFormat == AudioFrame::SOUND_FORMAT_AAC) {
            /** 获取aac数据包 */
            $aacPack = $audioFrame->getAACPacket();
            /** 根据aac的类型进行处理 */
            /** 获取数据包头 是0 这个包是设置aac的相关配置，解码参数，比如采样率，声道等，通常这个包是音频第一个包 */
            if ($aacPack->aacPacketType === AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                /** aac 序列  aac配置包 */
                $this->isAACSequence = true;
                /** aac序列的头部 */
                $this->aacSequenceHeaderFrame = $audioFrame;
                /** 获取aac数据包里面的额参数 就是获取音频参数 */
                $set = $aacPack->getAACSequenceParameterSet();
                /** 获取音频特征 */
                $this->audioProfileName = $set->getAACProfileName();
                /** 获取采样频率 */
                $this->audioSamplerate = $set->sampleRate;
                /** 获取音频通道 左声道，右声道，立体音 */
                $this->audioChannels = $set->channels;
                //logger()->info("publisher {path} recv acc sequence.", ['path' => $this->pathIndex]);
            }
            /** 如果已经解码了音频信息 */
            if ($this->isAACSequence) {
                /** 如果是继续接收到客户端发送的音频头部数据，直接丢弃 */
                if ($aacPack->aacPacketType == AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {

                } else {
                    //音频关键帧缓存
                    /** 音频帧，除了第一帧是配置参数需要丢弃，后面的音频帧都要保存到连续帧队里里面 */
                    $this->gopCacheQueue[] = $audioFrame;
                }
            }


        }
        // MediaServer::addPublish($this); 在RTMPinvokeHandlerTrait.PHP 处理命令的时候调用了媒体服务中心，关联上这个on_frame事件的
        /** 绑定On_fram事件 ，传入数据包，将音频数据包推送给所有链接当前直播源的播放器客户端 */
        $this->emit('on_frame', [$audioFrame, $this]);

        //logger()->info("rtmpAudioHandler");
        /** 销毁数据包 */
        $audioFrame->destroy();
    }
}
