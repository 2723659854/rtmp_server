<?php


namespace MediaServer\Rtmp;

/**
 * @purpose rtmp 数据分片
 */
class RtmpChunk
{

    /** 定义数据分片状态 */
    const CHUNK_STATE_BEGIN = 0; //Chunk state begin
    /** 分片准备完毕 */
    const CHUNK_STATE_HEADER_READY = 1; //Chunk state header ready
    /** 分片完成 */
    const CHUNK_STATE_CHUNK_READY = 2; //Chunk state chunk date ready

    /**
     * 资源ID分块 基本表头长度
     * chunk stream id length base header length
     * 3個字節的頭部長度：
     *
     * 對於 RTMP 封包來說，如果消息的大小不超過 65536 個字節，則頭部長度為3個字節。這種情況下，封包的格式是：1個字節的基本頭部（Basic Header）和2個字節的消息長度字段（Message Header）。
     * 4個字節的頭部長度：
     *
     * 當消息的大小超過 65536 個字節時，RTMP 封包的頭部就會變成4個字節。這種情況下，封包的格式是：1個字節的基本頭部、3個字節的扩展消息长度字段（Extended Message Header）。
     */
    const BASE_HEADER_SIZES = [3, 4];

    /**
     * fmt消息头部长度
     * fmt message header size
     */
    const MSG_HEADER_SIZES = [11, 7, 3, 0];


    /** 分块类型 */
    /** 大数据 */
    const CHUNK_TYPE_0 = 0; //Large type
    /** 中数据包 */
    const CHUNK_TYPE_1 = 1; //Medium
    /** 小数据包 */
    const CHUNK_TYPE_2 = 2;    //Small
    /** 微型数据包 */
    const CHUNK_TYPE_3 = 3; //Minimal


    /**
     * 默认分包类型
     * chunk type default chunk stream id
     * 发送的所有数据都有chunkStreamId 区分消息类型
     */
    /** 协议通道 */
    const CHANNEL_PROTOCOL = 2;
    /** 命令消息通道 */
    const CHANNEL_INVOKE = 3;
    /** 音频通道 */
    const CHANNEL_AUDIO = 4;
    /** 视频通道 */
    const CHANNEL_VIDEO = 5;
    /** 数据通道 */
    const CHANNEL_DATA = 6;





}
