<?php


namespace MediaServer\Rtmp;

require_once __DIR__ . '/../../SabreAMF/OutputStream.php';
require_once __DIR__ . '/../../SabreAMF/InputStream.php';

require_once __DIR__ . '/../../SabreAMF/AMF0/Serializer.php';
require_once __DIR__ . '/../../SabreAMF/AMF0/Deserializer.php';

/**
 * @comment 这个是工具类
 * @purpose amf流媒体格式
 */
class RtmpAMF
{
    /** 定义命令编码 可以使用下面的命令编写rtmp客户端 */
    const RTMP_CMD_CODE = [
        '_result' => ['transId', 'cmdObj', 'info'],
        '_error' => ['transId', 'cmdObj', 'info', 'streamId'], // Info / Streamid are optional
        'onStatus' => ['transId', 'cmdObj', 'info'],
        'releaseStream' => ['transId', 'cmdObj', 'streamName'],
        'getStreamLength' => ['transId', 'cmdObj', 'streamId'],
        'getMovLen' => ['transId', 'cmdObj', 'streamId'],
        'FCPublish' => ['transId', 'cmdObj', 'streamName'],
        'FCUnpublish' => ['transId', 'cmdObj', 'streamName'],
        'FCSubscribe' => ['transId', 'cmdObj', 'streamName'],
        'onFCPublish' => ['transId', 'cmdObj', 'info'],
        'connect' => ['transId', 'cmdObj', 'args'],
        'call' => ['transId', 'cmdObj', 'args'],
        'createStream' => ['transId', 'cmdObj'],
        'close' => ['transId', 'cmdObj'],
        'play' => ['transId', 'cmdObj', 'streamName', 'start', 'duration', 'reset'],
        'play2' => ['transId', 'cmdObj', 'params'],
        'deleteStream' => ['transId', 'cmdObj', 'streamId'],
        'closeStream' => ['transId', 'cmdObj'],
        'receiveAudio' => ['transId', 'cmdObj', 'bool'],
        'receiveVideo' => ['transId', 'cmdObj', 'bool'],
        'publish' => ['transId', 'cmdObj', 'streamName', 'type'],
        'seek' => ['transId', 'cmdObj', 'ms'],
        'pause' => ['transId', 'cmdObj', 'pause', 'ms']
    ];

    /** 定义数据编码 */
    const RTMP_DATA_CODE = [
        '@setDataFrame' => ['method', 'dataObj'],
        'onFI' => ['info'],
        'onMetaData' => ['dataObj'],
        '|RtmpSampleAccess' => ['bool1', 'bool2'],
    ];


    /**
     * 讀取rtmp的命令
     * @param $payload
     * @return null[]
     * @throws \Exception
     */
    static function rtmpCMDAmf0Reader($payload)
    {
        /** 加載數據 */
        $stream = new \SabreAMF_InputStream($payload);
        /** 解码 */
        $deserializer = new \SabreAMF_AMF0_Deserializer($stream);
        /** 初始化命令 */
        $result = [
            'cmd' => null,
        ];
        /** 解码操作数据 */
        if ($cmd = @$deserializer->readAMFData()) {
            $result['cmd'] = $cmd;
            if (isset(self::RTMP_CMD_CODE[$cmd])) {
                foreach (self::RTMP_CMD_CODE[$cmd] as $k) {
                    if ($stream->isEnd()) {
                        break;
                    }
                    $result[$k] = $deserializer->readAMFData();
                }
            } else {
                logger()->warning('AMF Unknown command {cmd}', $result);
            }
        } else {
            logger()->warning('AMF read data error');
        }

        return $result;
    }


    /**
     * 解码amf操作消息包载荷
     * @param $payload
     * @return null[]
     * @throws \Exception
     */
    static function rtmpDataAmf0Reader($payload)
    {
        /** 加载数据 */
        $stream = new \SabreAMF_InputStream($payload);
        /** 解码数据 */
        $deserializer = new \SabreAMF_AMF0_Deserializer($stream);
        /** 初始化 */
        $result = [
            'cmd' => null,
        ];
        /** 读取数据 */
        if ($cmd = @$deserializer->readAMFData()) {
            $result['cmd'] = $cmd;
            if (isset(self::RTMP_DATA_CODE[$cmd])) {
                foreach (self::RTMP_DATA_CODE[$cmd] as $k) {
                    if ($stream->isEnd()) {
                        break;
                    }
                    $result[$k] = $deserializer->readAMFData();
                }
            } else {
                logger()->warning('AMF Unknown command {cmd}', $result);
            }
        } else {
            logger()->warning('AMF read data error');
        }
        return $result;
    }

    /**
     * afm操作消息编码
     * Encode AMF0 Command
     * @param $opt
     * @throws \Exception
     * @return string
     */
    static function rtmpCMDAmf0Creator($opt)
    {
        /** 初始化 */
        $outputStream = new \SabreAMF_OutputStream();
        /** 序列化 */
        $serializer = new \SabreAMF_AMF0_Serializer($outputStream);
        /** 写入命令 */
        $serializer->writeAMFData($opt['cmd']);
        if (isset(self::RTMP_CMD_CODE[$opt['cmd']])) {
            foreach (self::RTMP_CMD_CODE[$opt['cmd']] as $k) {
                if (key_exists($k, $opt)) {
                    $serializer->writeAMFData($opt[$k]);
                } else {
                    logger()->debug("amf 0 create {$k} not in opt " . json_encode($opt));
                }
            }
        } else {
            logger()->debug('AMF Unknown command {cmd}', $opt);
        }
        //logger()->debug('Encoded as ' . bin2hex($outputStream->getRawData()));
        return $outputStream->getRawData();
    }

    /**
     * 写入amf消息载荷，并编码
     * Encode AMF0 Command
     * @param $opt
     * @throws \Exception
     * @return string
     */
    static function rtmpDATAAmf0Creator($opt)
    {
        /** 初始化 */
        $outputStream = new \SabreAMF_OutputStream();
        /** 序列化 */
        $serializer = new \SabreAMF_AMF0_Serializer($outputStream);
        /** 写入数据 */
        $serializer->writeAMFData($opt['cmd']);
        if (isset(self::RTMP_DATA_CODE[$opt['cmd']])) {
            foreach (self::RTMP_DATA_CODE[$opt['cmd']] as $k) {
                if (key_exists($k, $opt)) {
                    $serializer->writeAMFData($opt[$k]);
                }
            }
        } else {
            logger()->debug('AMF Unknown command {cmd}', $opt);
        }
        //logger()->debug('Encoded as' . bin2hex($outputStream->getRawData()));
        return $outputStream->getRawData();
    }
}