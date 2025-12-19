<?php

namespace App\Helpers;

class Utf8Cleaner
{
    public static function clean($data)
    {
        if (is_array($data) || is_object($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::clean($value);
            }
            return $data;
        }

        if (is_string($data)) {
            // Cara ampuh: convert ke UTF-8, ignore invalid
            return iconv('UTF-8', 'UTF-8//IGNORE', $data);
            // Alternatif: mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }

        return $data;
    }
}
