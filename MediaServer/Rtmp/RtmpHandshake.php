<?php


namespace MediaServer\Rtmp;

/**
 * @purpose 服务端生成握手的s0s1s2的方法
 * @note 简单说这个握手，就是服务端接收到c1,立即发送s2；客户端接收到s1，立即发送c2。当客户端收到s2，并且服务端收到c2，握手完成。
 */
class RtmpHandshake
{
    /** 未初始化 */
    const RTMP_HANDSHAKE_UNINIT = 0;
    /** 读取c0 */
    const RTMP_HANDSHAKE_C0 = 1;
    /** 读取c1 */
    const RTMP_HANDSHAKE_C1 = 2;
    /** 读取c2 */
    const RTMP_HANDSHAKE_C2 = 3;


    /**
     * 服务端生成s0 s1 s2
     * @param $c1
     * @return false|string
     * @note s0 固定为0x03
     * @note s1 | 4字节time | 4字节模式串 | 前半部分764字节 | 4字节offset | left[...] | 32字节digest | right[...] |
     * @note 语法，3，s1,s2
     */
    static function handshakeGenerateS0S1S2($c1)
    {
        /** C：无符号字符  a1536：就是生成1536位字符，如果不足，就用空字符串填充 */
        $data = pack("Ca1536a1536",
            /** 版本号默认是3 用C方法打包 */
            3,
            /** 生成s1 用a1536方法打包 */
            self::handshakeGenerateS1(),
            /** 生成s2 用a1536方法打包 */
            self::handshakeGenerateS2($c1)
        );
        return $data;
    }

    /**
     * s1生成
     * @return false|string
     * @note 4个时间戳，4个0，1528个随机字符
     */
    static function handshakeGenerateS1()
    {
        /** N 表示一個無符號長整型數據，佔用 4 個字節 */
        $s1 = pack('NNa1528',
            /** 4为时间戳 使用N方法打包4个bite */
            timestamp(),
            /** 4位0 使用N方法打包4个bite */
            0,
            /** 1528位随机数 */
            make_random_str(1528)
        );
        return $s1;
    }

    /**
     * 生成s2
     * @param $c1
     * @return false|string
     * @note 客户端时间戳，本地毫秒时间戳，客户端时间戳
     */
    static function handshakeGenerateS2($c1)
    {
        /** c1解码 */
        /** 使用N方法解码前4个字节，设置为变量时间戳timestamp 使用N方法解码4个字节赋值为变量zero,读取1528为字节不足用空填充并赋值为random */
        $c1Data = unpack('Ntimestamp/Nzero/a1528random', $c1);
        $s2 = pack('NNa1528',
            $c1Data['timestamp'],
            timestamp(),
            $c1Data['random']
        );
        return $s2;
    }

}
