<?php
$string = '23423423'."\r\n";
$pos = strpos($string,'3');
var_dump($pos);
$a = substr($string,1);
var_dump($a);
//var_dump(strlen("\r\n\r\n"));