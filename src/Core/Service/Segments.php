<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
final class Segments
{
    public static function count(string $body): array {
        $basic = '@£$¥èéùìòÇ'."\n".'Øø'."\r".'ÅåΔ_ΦΓΛΩΠΨΣΘΞ'."\x1b".'ÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
        $extended = "\f^{}\\[~]|€"; $units = 0; $unicode = false;
        foreach (mb_str_split($body) as $char) { if (mb_strpos($basic, $char) !== false) { ++$units; } elseif (mb_strpos($extended, $char) !== false) { $units += 2; } else { $unicode = true; break; } }
        if ($unicode) { $units = intdiv(strlen(mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')), 2); }
        $single = $unicode ? 70 : 160; $multi = $unicode ? 67 : 153;
        $parts = 1;
        if ($units > $single) {
            $parts = 1; $used = 0;
            foreach (mb_str_split($body) as $char) {
                $weight = $unicode ? intdiv(strlen(mb_convert_encoding($char, 'UTF-16BE', 'UTF-8')), 2) : (mb_strpos($extended, $char) !== false ? 2 : 1);
                if ($used + $weight > $multi) { ++$parts; $used = 0; }
                $used += $weight;
            }
        }
        return ['encoding' => $unicode ? 'UCS-2' : 'GSM-7', 'characters' => mb_strlen($body), 'units' => $units, 'segments' => $parts];
    }
}
