<?php


namespace MediaServer\MediaReader;

use MediaServer\MediaServer;
use MediaServer\Utils\BitReader;

/**
 * @purpose 音频数据包序列参数处理
 * @note
 * Profile：视频编码的配置文件，标识了视频编码的特性和能力。
 * Level：视频编码的级别，用于指示视频的质量和复杂度。
 * Width：视频的宽度，以像素为单位。
 * Height：视频的高度，以像素为单位。
 * Frame Rate：视频的帧率，即每秒显示的帧数。
 * Bit Rate：视频的码率，用于衡量视频数据的传输速率。
 */
class AVCSequenceParameterSet extends BitReader
{
    /** 配置文件 */
    public $profile;

    /** 编码级别 */

    public $level;

    /** 宽度 */

    public $width;

    /** 高度 */

    public $height;
    /** 参考帧 关键帧 参考帧是指在解码过程中可以被参考的图像帧，用于提高视频的压缩效率和质量。*/
    public $avc_ref_frames = 0;

    public function __construct($data)
    {
        /** 装载数据 */
        parent::__construct($data);
        /** 读取数据 */
        $this->readData();
    }

    /**
     * 获取配置名称  获取压缩算法
     * @return string
     * @note
     * baseline profile：基本画质，支持I/P帧，只支持无交错（Progressive）和CAVLC。
     * main profile：主流画质，提供I/P/B帧，支持无交错（Progressive）和交错（Interlaced），也支持CAVLC和CABAC的支持。
     * main 10 profile：main profile的扩展，在main profile的基础上增加了对10bit色深的支持。
     * high profile：高级画质，在main profile的基础上增加了8x8内部预测、自定义量化、无损视频编码和更多的YUV格式。
     */
    public function getAVCProfileName()
    {
        /** 配置越高 压缩率越高 对硬件的要求越高，因为计算量更大 */
        switch ($this->profile) {
            case 1:
                return 'Main';
            case 2:
                return 'Main 10';
            case 3:
                return 'Main Still Picture';
            case 66:
                return 'Baseline';
            case 77:
                return 'Main';
            case 100:
                return 'High';
            default:
                return '';
        }
    }

    /**
     * 读取数据
     * @return void
     * @note 必须对avc的数据格式很清楚才能看明白这一个方法
     */
    public function readData()
    {
        $index = $this->currentBytes;
        $data = [];
        $data['version'] = ord($this->data[$this->currentBytes++]);
        $data['profile'] = ord($this->data[$this->currentBytes++]);
        $data['profileCompatibility'] = ord($this->data[$this->currentBytes++]);
        $data['level'] = ord($this->data[$this->currentBytes++]);
        $data['naluSize'] = (ord($this->data[$this->currentBytes++]) & 0x03) + 1;
        $data['nbSps'] = ord($this->data[$this->currentBytes++]) & 0x1F;

        $data['sps'] = [];
        for ($i = 0; $i < $data['nbSps']; $i++) {
            //读取sps
            $len = (ord($this->data[$this->currentBytes++]) << 8) | ord($this->data[$this->currentBytes++]);
           //var_dump(bin2hex(substr($this->data, $this->currentBytes, $len)));
            $content = substr($this->data, $this->currentBytes, $len);
            //var_dump(base64_encode(substr($this->data, $this->currentBytes, $len)));
            $byteTmp=$this->currentBytes;
            //var_dump(bin2hex($this->data[$this->currentBytes]));

            $nalType=ord($this->data[$this->currentBytes++]) & 0x1f;

            if($nalType !== 0x07){
                continue;
            }
            $sps=[];
            $sps['nalType']=$nalType;
            $sps['profileIdc']=ord($this->data[$this->currentBytes++]);
            $sps['flags']=ord($this->data[$this->currentBytes++]);
            $sps['levelIdc']=ord($this->data[$this->currentBytes++]);

            $sps['length'] = $len;
            $sps['content'] = $content;

            $data['sps'][] = $sps;
            $this->currentBytes = $byteTmp+$len;
        }

        $data['nbPps'] = ord($this->data[$this->currentBytes++]);
        $data['pps'] = [];
        for ($i = 0; $i < $data['nbPps']; $i++) {
            //读取sps
            $len = (ord($this->data[$this->currentBytes++]) << 8) | ord($this->data[$this->currentBytes++]);
            $pps = [];
            $pps['length'] = $len;
            $pps['content'] = substr($this->data, $this->currentBytes, $len);
            $data['pps'][] = $pps;
            $this->currentBytes += $len;
        }

        //var_dump($data);

        MediaServer::$spsInfo = $data;
        $this->currentBytes = $index;


        //configurationVersion
        /** 跳过8个字节 就是跳过avc的头 */
        $this->skipBits(8);
        //profile
        /** 获取资源概要 */
        $this->profile = $profile = $this->getBits(8);                               // read profile
        //profile compat
        /** 跳过8个字节 */
        $this->skipBits(8);
        /** 获取画面等级信息 */
        $this->level = $level = $this->getBits(8);                         // level_idc
        /** 视频画面的h264格式的编码信息 */
        $naluSize = ($this->getBits(8) & 0x03) + 1;
        /** NAL 单元流参数 用户描述h264的视频编码信息 */
        $nb_sps = $this->getBits(8) & 0x1F;


        if ($nb_sps === 0) {
            echo "none sps", PHP_EOL;
            return;
        }

        /** 指针移动16个字节 */
        //nalSize
        $this->getBits(16);

        /* nal type */
        if (($this->getBits(8) & 0x1F) != 0x07) {
            return;
        }

        /** 读取sps 编码信息 */
        /* SPS */
        $profile_idc = $this->getBits(8);

        /** 获取视频标记 */
        /* flags */
        $this->getBits(8);

        /** 获取等级信息 */
        /* level idc */
        $this->getBits(8);

        /**  */
        $this->expGolombUe();                                   // seq_parameter_set_id // sps

        if ($profile_idc == 100 || $profile_idc == 110 ||
            $profile_idc == 122 || $profile_idc == 244 || $profile_idc == 44 ||
            $profile_idc == 83 || $profile_idc == 86 || $profile_idc == 118) {
            /* chroma format idc */
            /** 色度格式idc */
            $cf_idc = $this->expGolombUe();

            if ($cf_idc == 3) {

                /* separate color plane */
                /** 单独的彩色平面 */
                $this->getBits(1);
            }

            /** 处理亮度 比特深度亮度 */
            /* bit depth luma - 8 */
            $this->expGolombUe();

            /* bit depth chroma - 8 */
            /** 位深度色度 */
            $this->expGolombUe();

            /* qpprime y zero transform bypass */
            /** 变换旁路 */
            $this->getBits(1);

            /* seq scaling matrix present */
            /** 缩放比例矩阵 */
            if ($this->getBits(1)) {

                for ($n = 0; $n < ($cf_idc != 3 ? 8 : 12); $n++) {

                    /* seq scaling list present */
                    if ($this->getBits(1)) {

                        /** 比例列表 */
                        /* TODO: scaling_list()
                        if (n < 6) {
                        } else {
                        }
                        */
                    }
                }
            }
        }

        /** 获取最大帧数 */
        /* log2 max frame num */
        $this->expGolombUe();

        /* pic order cnt type */
        switch ($this->expGolombUe()) {
            case 0:

                /* max pic order cnt */
                $this->expGolombUe();
                break;

            case 1:

                /* delta pic order alwys zero */
                $this->getBits(1);

                /* offset for non-ref pic */
                $this->expGolombUe();

                /* offset for top to bottom field */
                $this->expGolombUe();

                /* num ref frames in pic order */
                $num_ref_frames = $this->expGolombUe();

                for ($n = 0; $n < $num_ref_frames; $n++) {

                    /* offset for ref frame */
                    $this->expGolombUe();
                }
        }

        /** 确定参考帧 */
        /* num ref frames */
        $this->avc_ref_frames = $this->expGolombUe();

        /* gaps in frame num allowed */
        $this->getBits(1);
        /** 宽度 */
        /* pic width in mbs - 1 */
        $width = $this->expGolombUe();
        /** 高度 */
        /* pic height in map units - 1 */
        $height = $this->expGolombUe();

        /** frame_mbs_only_flag是H.264视频编码中的一个参数，用于表示宏块的编码方式。当frame_mbs_only_flag为1时，宏块都采用帧编码；
         * 当frame_mbs_only_flag为0时，宏块可能为帧编码或者场编码
         */
        /* frame mbs only flag */
        $frame_mbs_only = $this->getBits(1);

        if (!$frame_mbs_only) {

            /* mbs adaprive frame field */
            $this->getBits(1);
        }

        /* direct 8x8 inference flag */
        $this->getBits(1);

        /**
         * 在RTMP协议中，crop_left、crop_right、crop_top和crop_bottom是用于视频画面裁剪的参数，它们分别表示裁剪区域的左边界、右边界、
         * 上边界和下边界的偏移量，单位是像素。
         * 这些参数可以用于在传输视频时裁剪视频画面的一部分，以便在播放时只显示裁剪后的区域。
         * 例如，如果crop_left的值为10，crop_right的值为20，crop_top的值为30，crop_bottom的值为40，那么在播放视频时，
         * 将只显示视频画面中从左边界偏移10像素开始，到右边界偏移20像素结束，上边界偏移30像素开始，到下边界偏移40像素结束的区域。
         */
        /* frame cropping */
        if ($this->getBits(1)) {

            $crop_left = $this->expGolombUe();
            $crop_right = $this->expGolombUe();
            $crop_top = $this->expGolombUe();
            $crop_bottom = $this->expGolombUe();

        } else {
            $crop_left = 0;
            $crop_right = 0;
            $crop_top = 0;
            $crop_bottom = 0;
        }
        /** 编码级别 */
        $this->level = $this->level / 10.0;
        /** 视频宽度 */
        $this->width = ($width + 1) * 16 - ($crop_left + $crop_right) * 2;
        /** 视频高度 */
        $this->height = (2 - $frame_mbs_only) * ($height + 1) * 16 - ($crop_top + $crop_bottom) * 2;

    }


}