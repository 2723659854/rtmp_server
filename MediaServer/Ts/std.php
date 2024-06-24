<?php


class CRC32
{
    public static function calculate($data)
    {
        $crc = 0xFFFFFFFF;
        foreach (str_split($data) as $byte) {
            $crc ^= ord($byte) << 24;
            for ($i = 0; $i < 8; $i++) {
                if ($crc & 0x80000000) {
                    $crc = ($crc << 1) ^ 0x04C11DB7;
                } else {
                    $crc <<= 1;
                }
            }
        }
        return $crc & 0xFFFFFFFF;
    }
}

class TsServiceDescriptionTable
{
    private $table_id;
    private $section_syntax_indicator;
    private $section_length;
    private $transport_stream_id;
    private $version_number;
    private $current_next_indicator;
    private $section_number;
    private $last_section_number;
    private $original_network_id;
    private $section_list;
    private $crc_32;

    public function __construct($data = null)
    {
        if ($data === null) return;

        $this->table_id = ord($data[0]);
        $this->section_syntax_indicator = ord($data[1]) >> 7;
        $this->section_length = ((ord($data[1]) & 0x0F) << 8) | ord($data[2]);
        $this->transport_stream_id = (ord($data[3]) << 8) | ord($data[4]);
        $this->version_number = (ord($data[5]) >> 1) & 0x1F;
        $this->current_next_indicator = ord($data[5]) & 0x01;
        $this->section_number = ord($data[6]);
        $this->last_section_number = ord($data[7]);
        $this->original_network_id = (ord($data[8]) << 8) | ord($data[9]);

        $end = $this->section_length + 3;
        $section = substr($data, 11, $end - 15);
        $this->section_list = [];

        $i = 0;
        while ($i < strlen($section)) {
            $section_map = [];
            $section_map["service_id"] = (ord($section[$i]) << 8) | ord($section[$i + 1]);
            $i += 2;
            $section_map["EIT_schedule_flag"] = (ord($section[$i]) >> 1) & 1;
            $section_map["EIT_present_following_flag"] = ord($section[$i]) & 1;
            $i++;
            $section_map["running_status"] = ord($section[$i]) >> 5;
            $section_map["free_CA_mode"] = (ord($section[$i]) >> 4) & 1;
            $section_map["descriptors_loop_length"] = ((ord($section[$i]) & 0x0F) << 8) | ord($section[$i + 1]);
            $i += 2;
            $descriptors = substr($section, $i, $section_map["descriptors_loop_length"]);
            $i += $section_map["descriptors_loop_length"];
            $section_map["descriptors"] = [];

            $ii = 0;
            while ($ii < strlen($descriptors)) {
                $descriptors_map = [];
                $descriptors_map["descriptor_tag"] = ord($descriptors[$ii]);
                $ii++;
                $descriptors_map["descriptor_length"] = ord($descriptors[$ii]);
                $ii++;
                $descriptors_map["service_type"] = ord($descriptors[$ii]);
                $ii++;
                $descriptors_map["service_provider_name_length"] = ord($descriptors[$ii]);
                $ii++;
                $descriptors_map["service_provider_name"] = substr($descriptors, $ii, $descriptors_map["service_provider_name_length"]);
                $ii += $descriptors_map["service_provider_name_length"];
                $descriptors_map["service_name_length"] = ord($descriptors[$ii]);
                $ii++;
                $descriptors_map["service_name"] = substr($descriptors, $ii, $descriptors_map["service_name_length"]);
                $ii += $descriptors_map["service_name_length"];
                $section_map["descriptors"][] = $descriptors_map;
            }
            $this->section_list[] = $section_map;
        }

        $this->crc_32 = unpack("N", substr($data, $end - 4, 4))[1];
    }

    public function toByte()
    {
        $data = str_repeat("\0", 11);
        $data[0] = chr($this->table_id);
        $data[1] = chr(($this->section_syntax_indicator << 7) | 0x40 | (3 << 4) | (($this->section_length >> 8) & 0x0F));
        $data[2] = chr($this->section_length & 0xFF);
        $data[3] = chr($this->transport_stream_id >> 8);
        $data[4] = chr($this->transport_stream_id & 0xFF);
        $data[5] = chr(0xC0 | ($this->version_number << 1) | $this->current_next_indicator);
        $data[6] = chr($this->section_number);
        $data[7] = chr($this->last_section_number);
        $data[8] = chr($this->original_network_id >> 8);
        $data[9] = chr($this->original_network_id & 0xFF);
        $data[10] = chr(0xFF);

        foreach ($this->section_list as $item_section) {
            $item_section_byte = str_repeat("\0", 5);
            $item_section_byte[0] = chr(($item_section["service_id"] >> 8) & 0xFF);
            $item_section_byte[1] = chr($item_section["service_id"] & 0xFF);
            $item_section_byte[2] = chr(0xFC | ($item_section["EIT_schedule_flag"] << 1) | $item_section["EIT_present_following_flag"]);
            $item_section_byte[3] = chr(($item_section["running_status"] << 5) | ($item_section["free_CA_mode"] << 4) | ($item_section["descriptors_loop_length"] >> 8));
            $item_section_byte[4] = chr($item_section["descriptors_loop_length"] & 0xFF);
            $data .= $item_section_byte;

            foreach ($item_section["descriptors"] as $descriptors) {
                $descriptors_byte = str_repeat("\0", 4);
                $descriptors_byte[0] = chr($descriptors["descriptor_tag"]);
                $descriptors_byte[1] = chr($descriptors["descriptor_length"]);
                $descriptors_byte[2] = chr($descriptors["service_type"]);
                $descriptors_byte[3] = chr($descriptors["service_provider_name_length"]);
                $descriptors_byte .= $descriptors["service_provider_name"];
                $descriptors_byte .= chr($descriptors["service_name_length"]);
                $descriptors_byte .= $descriptors["service_name"];
                $data .= $descriptors_byte;
            }
        }

        $crc_32_byte = pack("N", $this->crc_32);
        $data .= $crc_32_byte;

        return $data;
    }

    public function genSDT()
    {
        $this->table_id = 66;
        $this->section_syntax_indicator = 1;
        $this->section_length = 37;
        $this->transport_stream_id = 1;
        $this->version_number = 0;
        $this->current_next_indicator = 1;
        $this->section_number = 0;
        $this->last_section_number = 0;
        $this->original_network_id = 65281;
        $this->section_list = [
            [
                "service_id" => 1,
                "EIT_schedule_flag" => 0,
                "EIT_present_following_flag" => 0,
                "running_status" => 4,
                "free_CA_mode" => 0,
                "descriptors_loop_length" => 20,
                "descriptors" => [
                    [
                        "descriptor_tag" => 72,
                        "descriptor_length" => 18,
                        "service_type" => 1,
                        "service_provider_name_length" => 6,
                        "service_provider_name" => "FFmpeg",
                        "service_name_length" => 9,
                        "service_name" => "Service01"
                    ]
                ]
            ]
        ];
        $this->crc_32 = 2004632522;
        $sdt_byte = chr(0x47) . chr(0x40) . chr(0x11) . chr(0x10) . chr(0x00) . $this->toByte();
        $data = str_repeat(chr(0xff), 188);
        for ($i = 0; $i < strlen($sdt_byte); $i++) {
            $data[$i] = $sdt_byte[$i];
        }
        return $data;
    }
}

// Example usage
$sdt = new TsServiceDescriptionTable();
$sdt->genSDT();
echo bin2hex($sdt->genSDT());


