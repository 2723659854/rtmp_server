<?php

namespace MediaServer\Ts;


/**
 * @purpose ts 文件结构
 *
 * <code>
 * |Sync Byte|TEI|PUSI|Priority|PID|Scrambling|Adaptation|Continuity|Adaptation Field|Payload|
 * | 1  |  1 | 1 |  1  | 13位 |   2位   |   2位   |   4位   |    可变    |  可变  |
 * </code>
 *
 * 典型的TS文件包含：
 * 多个连续的TS数据包（每个188字节）。
 * 每个TS数据包可以包含视频、音频或其它数据。
 * 通过PID区分不同类型的数据流。
 * 通过同步字节0x47来识别每个TS数据包的起始位置
 */
class TsTag
{

    /**
     * 同步字节
     * 长度：1字节
     * 值：0x47
     * 描述：每个TS包的开头都有一个同步字节，值固定为0x47，用于帧同步。
     * */
    public $syncByte = "0x47";

    /** 传输错误指示符（Transport Error Indicator, TEI）
     * 长度：1位
     * 描述：如果设置为1，表示当前TS包存在传输错误。
     * */
    public $tei = 0;

    /**
     * 有效负载单位起始指示符（Payload Unit Start Indicator, PUSI）
     * 长度：1位
     * 描述：如果设置为1，表示此TS包包含PES（Packetized Elementary Stream）头或其他表的开始。
     * */
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
     * */
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
     * 自适应字段（Adaptation Field）（如果存在）：
     * 可变长度：0-183字节
     * 描述：包含时间戳、填充字节等信息。
     */
    public $adaptationField = "";

    /**
     * 有效负载（Payload）：
     * 可变长度：根据自适应字段长度决定
     * 描述：包含视频、音频数据或其它数据。
     * */
    public $payload = "";
}