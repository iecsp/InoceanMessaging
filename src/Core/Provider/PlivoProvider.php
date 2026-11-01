<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Provider;
use Inocean\Messaging\Core\DTO\{SmsRequest, SmsResult};
final class PlivoProvider extends HttpSmsProvider
{
    public function name(): string { return 'plivo'; }
    public function send(SmsRequest $request): SmsResult {
        $from = (string) $this->settings->get('plivoFrom', $request->scope, '');
        if (!$this->settings->get('plivoEnabled', $request->scope, false) || $from === '') { return new SmsResult('unavailable_rejected', error: 'provider_disabled'); }
        if (!$this->senderMatchesScope($from,$request->scope)) { return new SmsResult('rejected',error:'sender_scope_not_configured'); }
        try { $auth=$this->settings->secret('INO_SMS_PLIVO_AUTH_ID'); $token=$this->settings->secret('INO_SMS_PLIVO_AUTH_TOKEN'); $url=$this->settings->secret('INO_SMS_PLIVO_WEBHOOK_URL'); } catch (\RuntimeException) { return new SmsResult('unavailable_rejected',error:'missing_provider_configuration'); }
        return $this->perform('https://api.plivo.com/v1/Account/'.rawurlencode($auth).'/Message/', ['auth_basic' => [$auth, $token], 'json' => ['src' => $from, 'dst' => ltrim($request->phone, '+'), 'text' => $request->body, 'url' => $url, 'method' => 'POST']], (int)$this->settings->get('timeoutSeconds',$request->scope,10));
    }
    protected function accepted(array $data): SmsResult { $id = $data['message_uuid'][0] ?? null; return is_string($id) && $id !== '' ? new SmsResult('accepted', $id) : new SmsResult('unknown', error: 'missing_message_id'); }
}
