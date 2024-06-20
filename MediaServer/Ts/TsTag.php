<?php

namespace MediaServer\Ts;

/**
 * Class TsTag
 * @package MediaServer\Ts
 *
 * @purpose MPEG-TS 文件结构
 */
class TsTag
{
    /**
     * 同步字节
     * 长度：1字节
     * 值：0x47
     * 描述：每个TS包的开头都有一个同步字节，值固定为0x47，用于帧同步。
     */
    public $syncByte = "\x47";

    /**
     * 传输错误指示符（Transport Error Indicator, TEI）
     * 长度：1位
     * 描述：如果设置为1，表示当前TS包存在传输错误。
     */
    public $tei = 0;

    /**
     * 有效负载单位起始指示符（Payload Unit Start Indicator, PUSI）
     * 长度：1位
     * 描述：如果设置为1，表示此TS包包含PES（Packetized Elementary Stream）头或其他表的开始。
     */
    public $pusi = 0;

    /**
     * 传输优先级（Transport Priority）：
     * 长度：1位
     * 描述：如果设置为1，表示此TS包有更高的优先级。
     */
    public $priority = 0;

    /**
     * PID（Packet Identifier）：
     * 长度：13位
     * 描述：用于标识TS包的类型和内容，如视频、音频或其它数据流。
     */
    public $pid = 0;

    /**
     * 加扰控制（Scrambling Control）：
     * 长度：2位
     * 描述：指示TS包是否加扰及其加扰方法。
     */
    public $scrambling = 0;

    /**
     * 自适应字段控制（Adaptation Field Control）：
     * 长度：2位
     * 描述：指示TS包是否包含自适应字段以及有效负载数据。
     */
    public $adaptation = 0;

    /**
     * 连续计数器（Continuity Counter）：
     * 长度：4位
     * 描述：用于检测TS包丢失或重复，按每个PID独立计数。
     */
    public $continuity = 0;

    /**
     * 自适应字段（Adaptation Field）：
     * 可变长度：0-183字节
     * 描述：包含时间戳、填充字节等信息。
     */
    public $adaptationField = "";

    /**
     * 有效负载（Payload）：
     * 可变长度：根据自适应字段长度决定
     * 描述：包含视频、音频数据或其它数据。
     */
    public $payload = "";

    /**
     * 获取TS包的字节表示。
     * @return string TS包的字节表示。
     */
    public function getBytes()
    {
        $bytes = "";

        // 同步字节
        $bytes .= $this->syncByte;

        // 第一个字节
        $byte1 = ($this->tei << 7) | ($this->pusi << 6) | ($this->priority << 5) | (($this->pid >> 8) & 0x1F);
        $bytes .= chr($byte1);

        // 第二个字节
        $byte2 = $this->pid & 0xFF;
        $bytes .= chr($byte2);

        // 第三个字节
        $byte3 = ($this->scrambling << 6) | ($this->adaptation << 4) | ($this->continuity);
        $bytes .= chr($byte3);

        // 自适应字段（如果存在）
        if (!empty($this->adaptationField)) {
            $bytes .= chr(strlen($this->adaptationField)); // 自适应字段长度
            $bytes .= $this->adaptationField; // 自适应字段内容
        }

        // 有效负载
        if (!empty($this->payload)) {
            $bytes .= $this->payload; // 有效负载内容
        }

        return $bytes;
    }
}
