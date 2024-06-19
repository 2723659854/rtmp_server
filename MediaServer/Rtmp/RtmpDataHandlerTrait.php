<?php


namespace MediaServer\Rtmp;


use MediaServer\MediaReader\MetaDataFrame;
use \Exception;

/**
 * @purpose 命令处理
 * @comment 播放器会发送amf命令，服务端解析amf命令，并回复
 * @note 这个就是配置信息的metaData帧，主要设置四个配置：音频采样率，单双声道，视频宽度，高度，fps
 */
trait RtmpDataHandlerTrait
{

    /**
     * 幀率設置
     * @throws Exception
     */
    public function rtmpDataHandler()
    {
        /** 获取当前的数据包 */
        $p = $this->currentPacket;
        //AMF0 数据解释
        /** 读取命令 */
        $dataMessage = RtmpAMF::rtmpDataAmf0Reader($p->payload);
        /**
         * 数据内容举例：
         * rtmpDataHandler @setDataFrame {
         * "cmd":"@setDataFrame", 表示这是一个设置数据帧的命令
         * "method":"onMetaData", 指示这是一个元数据帧，通常用于传递流的元数据。
         * "dataObj":{ 一个对象，包含了以下元数据信息：
         * "duration":0, 流的总时长（以秒为单位）。这里是0，表示流的时长未知或实时流。
         * "fileSize":0, 文件大小（以字节为单位）。这里是0，表示文件大小未知或实时流。
         * "width":1920, 视频的宽度（像素）
         * "height":1080, 视频的高度（像素）。
         * "videocodecid":7, 视频编码器ID。7表示H.264编码。
         * "videodatarate":2500, 视频数据速率（以kbps为单位）。
         * "framerate":30, 视频帧率（每秒帧数）。
         * "audiocodecid":10, 音频编码器ID。10表示AAC编码。
         * "audiodatarate":160, 音频数据速率（以kbps为单位）
         * "audiosamplerate":48000, 音频采样率（以Hz为单位）。
         * "audiosamplesize":16, 音频样本大小（以位为单位）
         * "audiochannels":2, 音频通道数。2表示立体声。
         * "stereo":true, 表示音频是立体声。
         * "2.1":false, 表示音频不是这些多声道配置。
         * "3.1":false,  表示音频不是这些多声道配置。
         * "4.0":false, 表示音频不是这些多声道配置。
         * "4.1":false, 表示音频不是这些多声道配置。
         * "5.1":false, 表示音频不是这些多声道配置。
         * "7.1":false, 表示音频不是这些多声道配置。
         * "encoder":"obs-output module (libobs version 30.1.2)" 编码器的名称和版本。这里表示使用OBS（Open Broadcaster Software）输出模块版本30.1.2。
         * }}
         */
        logger()->info("rtmpDataHandler {$dataMessage['cmd']} " . json_encode($dataMessage));
        /** 判断命令 */
        switch ($dataMessage['cmd']) {
            /** 设置数据格式 客戶端向服務端發送命令設置數據流 主要用來采集客户端的音频采样率和视频的尺寸和帧率 */
            case '@setDataFrame':
                if (isset($dataMessage['dataObj'])) {
                    /** 音频采样频率 */
                    $this->audioSamplerate = $dataMessage['dataObj']['audiosamplerate'] ?? $this->audioSamplerate;
                    /** 声道信息 单声道还是双声道 */
                    $this->audioChannels = isset($dataMessage['dataObj']['stereo']) ? ($dataMessage['dataObj']['stereo'] ? 2 : 1) : $this->audioChannels;
                    /** 视频宽度 */
                    $this->videoWidth = $dataMessage['dataObj']['width'] ?? $this->videoWidth;
                    /** 视频高度 */
                    $this->videoHeight = $dataMessage['dataObj']['height'] ?? $this->videoHeight;
                    /** 视频帧率 */
                    $this->videoFps = $dataMessage['dataObj']['framerate'] ?? $this->videoFps;
                }
                /** 标记 已设置媒体元素 */
                $this->isMetaData = true;
                /** 解析命令 */
                $metaDataFrame = new MetaDataFrame(RtmpAMF::rtmpDATAAmf0Creator([
                    /** 设置元数据 */
                    'cmd' => 'onMetaData',
                    'dataObj' => $dataMessage['dataObj']
                ]));
                /** 保存命令 */
                $this->metaDataFrame = $metaDataFrame;
                /** 设置回调 on_frame事件 */

                /** 每一个推波数据流都绑定了on_frame 事件，每一个播放器也绑定了这个事件，只要有视频或者音频数据包被发送，都会触发这个事件，这个事件的作用是：给所有链接这个推流资源的链接推送数据 */
                $this->emit('on_frame', [$metaDataFrame, $this]);

            //播放类群发onMetaData
        }
    }
}