<?php


namespace MediaServer\Rtmp;

/**
 * @purpose  rtmp协议-权限校验
 */
trait RtmpAuthorizeTrait
{

    /**
     * 默认检验成功
     * @return true
     */
    public function verifyAuth(){
        return true;
    }

}