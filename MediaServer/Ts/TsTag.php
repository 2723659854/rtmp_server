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
     * 这个位标志为1，指的是一个包的启示，因为ts包只有188个字节，对于一个PES包的话往往大于188字节，因此一个PES包往往要拆成多个TS包，
     * 为了识别收到的TS包属于另一个PES包，起始位表示新的一个PES包或者PSI包等到来了。
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
     * 节目标示符，一个13位的无符号整数。作用如下表描述。一般来说，参考FFMPEG，PMT表使用PID 4096，VIDEOSTREAM 采用256，AUDIOSTREAM采用257。
     */
    public $pid = 0;

    /**
     * 加扰控制（Scrambling Control）：
     * 长度：2位
     * 描述：指示TS包是否加扰及其加扰方法。
     *
     */
    public $scrambling = 0;

    /**
     * 自适应字段控制（Adaptation Field Control）：
     * 长度：2位
     * 描述：指示TS包是否包含自适应字段以及有效负载数据。
     * 当adaptation_field_control的值为10，接下来的是自适应字段adaptation_field，当adaptation_field_control的值如下表描述
     * <code>
     *     | 值 | 描述                            |
     *     |00 |  供未来使用，由ISO/IEC所保留        |
     *     |01 |  无adaptation_field，仅有效载荷    |
     *     |10 |  仅有adaptation_field，无有效载荷  |
     *     |11 |  adaptation_field后随有效载荷     |
     * </code>
     */
    public $adaptation = 0;

    /**
     * 连续计数器（Continuity Counter）：
     * 长度：4位
     * 描述：用于检测TS包丢失或重复，按每个PID独立计数。
     * 这个是当前节目的一个计数器，独立于PID，也就是说各个PID分开计算。
     */
    public $continuity = 0;

    /**
     * 自适应字段（Adaptation Field）：
     * 可变长度：0-183字节
     * 描述：包含时间戳、填充字节等信息。
     *
     * 自适应字段我们主要关注的是第一个adaptation  field length和PCR，这里重点讲解他们的主要用处：
     * adaptationfield length指的是自适应字段的长度，也就是，从discontinuity indicator 到adaptation field最后的长度，
     * 也就是从第6字节（包含第6字节）开始算到最后。
     * 这个值是系统的时间戳，在PES层时间戳是PTS与DTS，这里要注意与PCR，PTS,DTS的概念，可能会让人模糊。PCR是TS层的时间戳，PTS与DTS是PES
     * 的时间戳，PCR在PES层相当于DTS，TS不需要考虑PTS。为啥不需要，这里就要讲下，PTS的具体概念。详细的在ISO-13818-1上有，详细到可以看到你吐。
     * 其实实际中不需要考虑这么多。我简单的讲吧。在ES流中，依次组成图像帧序为I1P4B2B3P7B5B6I10B8B9的，这里,I、P、B分别指I帧，P帧，B帧。
     * 具体意义可以参考H264的相关基本概念，对于I、P帧而言，PES的图像帧序为I1P4B2B3P7B5B6I10B8B9，应该P4比B2、B3在先，但显示时P4一定
     * 要比B2、B3在后，这就必须重新排序。在PTS/DTS时间标志指引下，将P4提前插入数据流，经过缓存器重新排序，重建视频帧序 I1B2B3P4B5B6P7B8B9I10。
     * 显然，PTS/DTS是表明确定事件或确定信息，并以专用时标形态确定事件或信息的开始时刻。说到这里，PTS,与DTS的概念应该明白了。但是为啥TS层不需要呢，
     * 因为TS层只是负责传输，你知道解码的时间在什么位置，确保传输的TS包不是延迟太久就可以了，具体的显示细节交给PES层去做。
     *
     * TS层里的PCR可以直接采用DTS
     */
    public $adaptationField = "";

    /**
     * 有效负载（Payload）：
     * 可变长度：根据自适应字段长度决定
     * 描述：包含视频、音频数据或其它数据，必须是 PES 包。
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
