<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Provider;
use Inocean\Messaging\Core\DTO\{SmsRequest, SmsResult};
final class TelnyxProvider extends HttpSmsProvider
{
    public function name(): string { return 'telnyx'; }
    public function send(SmsRequest $request): SmsResult {
        $from = (string) $this->settings->get('telnyxFrom', $request->scope, '');
        if (!$this->settings->get('telnyxEnabled', $request->scope, false) || $from === '') { return new SmsResult('unavailable_rejected', error: 'provider_disabled'); }
        if (!$this->senderMatchesScope($from,$request->scope)) { return new SmsResult('rejected',error:'sender_scope_not_configured'); }
        $data = ['from' => $from, 'to' => $request->phone, 'text' => $request->body, 'type' => 'SMS'];
        $profile = $this->settings->get('telnyxProfile', $request->scope);
        if ($profile) { $data['messaging_profile_id'] = $profile; }
        try { $key=$this->settings->secret('INO_SMS_TELNYX_API_KEY'); } catch (\RuntimeException) { return new SmsResult('unavailable_rejected',error:'missing_provider_configuration'); }
        return $this->perform('https://api.telnyx.com/v2/messages', ['headers' => ['Authorization' => 'Bearer '.$key], 'json' => $data], (int)$this->settings->get('timeoutSeconds',$request->scope,10));
    }
    protected function accepted(array $data): SmsResult { $id = $data['data']['id'] ?? null; return is_string($id) && $id !== '' ? new SmsResult('accepted', $id) : new SmsResult('unknown', error: 'missing_message_id'); }
}
