<?php declare(strict_types=1);
namespace Inocean\Messaging\Api;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
/** Small type boundary before typed DTOs/services, not a second API schema framework. */
final class Payload
{
    public static function read(Request $request): array {
        if (strlen($request->getContent()) > 65536) { throw new HttpException(413,'Payload too large'); }
        $payload=$request->toArray();
        foreach ($payload as $key=>$value) {
            if (!is_string($key)) { throw new \InvalidArgumentException('JSON object required'); }
            if (in_array($key,['variables','allowedVariables'],true)) {
                if (!is_array($value) || count($value)>64) { throw new \InvalidArgumentException('Invalid template variables'); }
                foreach($value as $item) { if(!is_scalar($item) && $item!==null) { throw new \InvalidArgumentException('Only scalar template variables allowed'); } if(is_string($item)&&strlen($item)>6000) { throw new \InvalidArgumentException('Template variable too long'); } }
            } elseif (in_array($key,['global','clearMarketingOptOut','active'],true)) {
                if (!is_bool($value)) { throw new \InvalidArgumentException($key.' must be boolean'); }
            } elseif (!is_string($value) || strlen($value)>16000) { throw new \InvalidArgumentException('Invalid field: '.$key); }
        }
        return $payload;
    }
}
