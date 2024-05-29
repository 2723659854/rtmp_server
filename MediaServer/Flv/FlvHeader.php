<?php


namespace MediaServer\Flv;


/**
 * @purpose flv 数据包头
 */
class FlvHeader
{
    /** 签名 */
    public $signature;
    /** 版本号 */
    public $version;
    /** 类型 */
    public $typeFlags;
    /** 偏移量 */
    public $dataOffset;
    /** 是否包含音频 */
    public $hasAudio;
    /** 是否包含视频 */
    public $hasVideo;

    /**
     * 数据包解码
     * @param $data
     */
    public function __construct($data)
    {

        $data = unpack("a3signature/Cversion/CtypeFlags/NdataOffset", $data);
        /** 签名：flv （0x46,0x4c,0x66）*/
        $this->signature = $data['signature'];
        /** 版本号：0x01 */
        $this->version = $data['version'];
        /** flag: 00000101 前5为固定为0 ，第六位1表示有音频 第七位必须为0 第八位1表示有视频 */
        $this->typeFlags = $data['typeFlags'];
        /** 总是为9 */
        $this->dataOffset = $data['dataOffset'];
        $this->hasAudio = $this->typeFlags & 4 ? true : false;
        $this->hasVideo = $this->typeFlags & 1 ? true : false;
    }
}
