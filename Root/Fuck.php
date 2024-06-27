<?php

namespace Root;

class Fuck
{
    public  $data = '';

    public $currentBytes = 0;

    public function __construct($data)
    {
        $this->data = $data;
    }
    public  function readData()
    {
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

            $data['sps'][] = $sps;
            $this->currentBytes = $byteTmp+$len;
        }

        $data['nbPps'] = ord($this->data[$this->currentBytes++]);
        $data['pps'] = [];
        for ($i = 0; $i < $data['nbPps']; $i++) {
            //读取sps
            $len = (ord($this->data[$this->currentBytes++]) << 8) | ord($this->data[$this->currentBytes++]);
            $data['pps'][] = substr($this->data, $this->currentBytes, $len);
            $this->currentBytes += $len;
        }

        return $data;



    }
}