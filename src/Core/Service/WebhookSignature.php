<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
final class WebhookSignature
{
    public function telnyx(Request $request): void {
        $time = $request->headers->get('telnyx-timestamp','');
        $signature=base64_decode($request->headers->get('telnyx-signature-ed25519',''),true);
        $key=base64_decode((string)\Inocean\Messaging\Core\Service\Environment::get('INO_SMS_TELNYX_PUBLIC_KEY'),true);
        if (!ctype_digit($time) || abs(time()-(int)$time)>300 || $signature===false || strlen($signature)!==64 || $key===false || strlen($key)!==32 || !sodium_crypto_sign_verify_detached($signature,$time.'|'.$request->getContent(),$key)) { throw new AccessDeniedHttpException('Invalid Telnyx signature'); }
    }
    public function plivo(Request $request): void {
        // Require body-binding V3. Legacy V2 does not authenticate POST parameters.
        $url=(string)\Inocean\Messaging\Core\Service\Environment::get('INO_SMS_PLIVO_WEBHOOK_URL'); $token=(string)\Inocean\Messaging\Core\Service\Environment::get('INO_SMS_PLIVO_AUTH_TOKEN');
        $nonce=$request->headers->get('x-plivo-signature-v3-nonce','');
        if ($url==='' || !str_starts_with($url,'https://') || str_contains($url,'?') || str_contains($url,'#') || $token==='' || $nonce==='' || strlen($nonce)>256 || !str_starts_with($request->headers->get('content-type',''),'application/x-www-form-urlencoded')) { throw new AccessDeniedHttpException('Plivo requires V3 form signature'); }
        // Plivo V3 SDK canonicalization includes ? before POST fields and . before nonce.
        $data=$request->request->all(); ksort($data,SORT_STRING); $message=$url.($data ? '?' : '');
        foreach($data as $name=>$value) { if(!is_scalar($value)) { throw new AccessDeniedHttpException('Invalid signature parameters'); } $message.=$name.$value; }
        $expected=base64_encode(hash_hmac('sha256',$message.'.'.$nonce,$token,true));
        foreach(explode(',',$request->headers->get('x-plivo-signature-v3','')) as $signature) { if(hash_equals($expected,trim($signature))) { return; } }
        throw new AccessDeniedHttpException('Invalid Plivo signature');
    }
}
