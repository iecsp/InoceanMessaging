<?php declare(strict_types=1);
namespace Inocean\Messaging\Api;
use Inocean\Messaging\Core\Service\{ReadService,Settings};
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
#[Route(defaults:['_routeScope'=>['api']])]
final class ProviderController
{
    public function __construct(private readonly ReadService $read,private readonly Settings $settings,private readonly SystemConfigService $config,private readonly HttpClientInterface $http) {}
    #[Route(path:'/api/_action/inocean/messaging/providers/switch',name:'api.action.inocean.messaging.providers.switch',methods:['POST'],defaults:['_acl'=>['inocean_messaging:manage']])]
    public function switch(Request $r): JsonResponse {
        $p=Payload::read($r);$scope=$this->read->scope($p['scopeId']??'');$provider=$p['provider']??'';
        if(!in_array($provider,['telnyx','plivo'],true)) { throw new \InvalidArgumentException('Invalid provider'); }
        $this->config->set('InoceanMessaging.config.defaultProvider',$provider,$scope);
        return new JsonResponse(['provider'=>$provider]);
    }
    #[Route(path:'/api/_action/inocean/messaging/providers/test',name:'api.action.inocean.messaging.providers.test',methods:['POST'],defaults:['_acl'=>['inocean_messaging:manage']])]
    public function test(Request $r): JsonResponse {
        $p=Payload::read($r);$this->read->scope($p['scopeId']??'');$provider=$p['provider']??'';
        if(!in_array($provider,['telnyx','plivo'],true)) { throw new \InvalidArgumentException('Invalid provider'); }
        // Read-only account probe: never sends a message.
        try {
            $options=['timeout'=>5,'max_duration'=>10,'max_redirects'=>0];
            if($provider==='telnyx') { $url='https://api.telnyx.com/v2/balance';$options['headers']=['Authorization'=>'Bearer '.$this->settings->secret('INO_SMS_TELNYX_API_KEY')]; }
            else { $id=$this->settings->secret('INO_SMS_PLIVO_AUTH_ID');$url='https://api.plivo.com/v1/Account/'.rawurlencode($id).'/';$options['auth_basic']=[$id,$this->settings->secret('INO_SMS_PLIVO_AUTH_TOKEN')]; }
            $response=$this->http->request('GET',$url,$options);$status=$response->getStatusCode();$response->cancel();
            return new JsonResponse(['provider'=>$provider,'connected'=>$status>=200&&$status<300,'httpStatus'=>$status]);
        } catch(\Throwable) { return new JsonResponse(['provider'=>$provider,'connected'=>false],502); }
    }
}
