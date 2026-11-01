<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Provider;
use Inocean\Messaging\Core\Contract\SmsProviderInterface;
use Inocean\Messaging\Core\DTO\{SmsRequest, SmsResult};
use Inocean\Messaging\Core\Service\Settings;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
abstract class HttpSmsProvider implements SmsProviderInterface
{
    public function __construct(protected readonly HttpClientInterface $http, protected readonly Settings $settings) {}
    protected function perform(string $url, array $options, int $timeout = 10): SmsResult {
        $timeout = max(1,min(30,$timeout));
        try {
            $response = $this->http->request('POST', $url, $options + ['timeout' => $timeout, 'max_duration' => $timeout+5, 'max_redirects' => 0]);
            $code = $response->getStatusCode();
            if ($code === 429) { return new SmsResult('rate_limited', error: 'http_429'); }
            // 5xx may occur after acceptance. Never assume they are safe to repeat.
            if ($code >= 500 || $code === 408) { return new SmsResult('unknown', error: 'http_'.$code); }
            if ($code >= 400) {
                try { $error = $response->toArray(false); } catch (\Throwable) { $error = []; }
                $providerCode = (string)($error['errors'][0]['code'] ?? $error['error_code'] ?? '');
                if (($this->name() === 'telnyx' && $providerCode === '40300') || ($this->name() === 'plivo' && $providerCode === '200')) { return new SmsResult('provider_suppressed',error:'provider_opt_out'); }
                return new SmsResult('rejected', error: 'http_'.$code);
            }
            try { return $this->accepted($response->toArray(false)); } catch (\Throwable) { return new SmsResult('unknown', error: 'invalid_response'); }
        } catch (TransportExceptionInterface) { return new SmsResult('unknown', error: 'transport_unknown'); }
    }
    protected function senderMatchesScope(string $from, string $scope): bool {
        $map=json_decode((string)(\Inocean\Messaging\Core\Service\Environment::get('INO_SMS_SENDER_SCOPES')?:'{}'),true);
        return is_array($map) && ($map[$this->name().':'.$from]??null)===$scope;
    }
    abstract protected function accepted(array $data): SmsResult;
}
