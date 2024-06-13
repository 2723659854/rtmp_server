<?php

/**
 * AMF0（Action Message Format 0）是一种二进制数据格式，最初由 Macromedia（后来被 Adobe 收购）为 Flash 应用程序设计，用于在客户端和
 * 服务器之间传输数据。它广泛用于 Flash 的远程过程调用（RPC）和消息传递服务，如 Flash Remoting 和 RTMP（Real-Time Messaging Protocol）。
 * AMF0 允许序列化各种数据类型，以便它们可以在网络上高效地传输。
 *
 * AMF0 支持的数据类型包括：
 *
 * Number：双精度浮点数。
 * Boolean：布尔值（true 或 false）。
 * String：字符串。
 * Object：键值对组成的对象。
 * MovieClip：用于表示 Flash 中的电影剪辑对象（已弃用）。
 * Null：空值。
 * Undefined：未定义的值。
 * Reference：对象的引用。
 * Mixed Array：混合类型的数组，带有键值对和数值索引。
 * Object End：对象的结束标志。
 * Strict Array：严格类型的数组，只有数值索引。
 * Date：日期对象。
 * Long String：长字符串。
 * Unsupported：不支持的类型。
 * Recordset：数据库记录集（已弃用）。
 * XML：XML 对象。
 * Typed Object：带有类型定义的对象。
 * AMF3：标记用于 AMF3 编码的数据。
 *
 * AMF0 是 Flash 生态系统的重要组成部分，它使得客户端和服务器能够高效地交换复杂的数据结构。虽然 AMF0 仍然被广泛使用，但它后来被 AMF3 所扩展
 * 和改进，特别是在支持 ActionScript 3.0 和 Flex 应用程序方面。AMF3 提供了更好的性能和更多的数据类型支持。
 *
 * SabreAMF_AMF0_Const
 *
 * @package SabreAMF
 * @subpackage AMF0
 * @version $Id: Const.php 233 2009-06-27 23:10:34Z evertpot $
 * @copyright Copyright (C) 2006-2009 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @licence http://www.freebsd.org/copyright/license.html  BSD License (4 Clause)
 */
final class SabreAMF_AMF0_Const
{

    const DT_NUMBER = 0x00;
    const DT_BOOL = 0x01;
    const DT_STRING = 0x02;
    const DT_OBJECT = 0x03;
    const DT_MOVIECLIP = 0x04;
    const DT_NULL = 0x05;
    const DT_UNDEFINED = 0x06;
    const DT_REFERENCE = 0x07;
    const DT_MIXEDARRAY = 0x08;
    const DT_OBJECTTERM = 0x09;
    const DT_ARRAY = 0x0a;
    const DT_DATE = 0x0b;
    const DT_LONGSTRING = 0x0c;
    const DT_UNSUPPORTED = 0x0e;
    const DT_XML = 0x0f;
    const DT_TYPEDOBJECT = 0x10;
    const DT_AMF3 = 0x11;

}



