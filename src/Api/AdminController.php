<?php declare(strict_types=1);
namespace Inocean\Messaging\Api;
use Doctrine\DBAL\Connection;
use Inocean\Messaging\Core\DTO\SmsRequest;
use Inocean\Messaging\Core\Service\{MessagingService,ReadService,SmsConsentService,SmsSuppressionService,SmsTemplateService,TemplateRenderer,Segments,Privacy};
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
#[Route(defaults: ['_routeScope'=>['api']])]
final class AdminController
{
    public function __construct(private readonly MessagingService $messaging,private readonly ReadService $read,private readonly SmsConsentService $consent,private readonly SmsSuppressionService $suppression,private readonly SmsTemplateService $templates,private readonly TemplateRenderer $renderer,private readonly Connection $db,private readonly Privacy $privacy) {}
    #[Route(path:'/api/_action/inocean/messaging/send',name:'api.action.inocean.messaging.send',methods:['POST'],defaults:['_acl'=>['inocean_messaging:send']])]
    public function send(Request $request): JsonResponse {
        $p=Payload::read($request); $scope=$this->read->scope((string)($p['scopeId']??''));
        if(isset($p['templateCode'])) { $rendered=$this->templates->render($scope,$p['templateCode'],$p['language']??'en-CA',$p['variables']??[]); }
        else { $rendered=['body'=>$p['body']??'','purpose'=>$p['purpose']??'transactional']; }
        return new JsonResponse($this->messaging->enqueue(new SmsRequest($scope,(string)($p['phone']??''),$rendered['body'],$rendered['purpose'],$request->headers->get('Idempotency-Key',''),$p['templateCode']??null)),202);
    }
    #[Route(path:'/api/_action/inocean/messaging/messages/{id}',name:'api.action.inocean.messaging.status',methods:['GET'],defaults:['_acl'=>['inocean_messaging:read']])]
    public function status(string $id,Request $r): JsonResponse { return new JsonResponse($this->read->status($this->read->scope($r->query->get('scopeId','')),$id)); }
    #[Route(path:'/api/_action/inocean/messaging/messages',name:'api.action.inocean.messaging.messages',methods:['GET'],defaults:['_acl'=>['inocean_messaging:read']])]
    public function messages(Request $r): JsonResponse { return new JsonResponse(['data'=>$this->read->messages($this->read->scope($r->query->get('scopeId','')),$r->query->getInt('page',1))]); }
    #[Route(path:'/api/_action/inocean/messaging/usage',name:'api.action.inocean.messaging.usage',methods:['GET'],defaults:['_acl'=>['inocean_messaging:read']])]
    public function usage(Request $r): JsonResponse { return new JsonResponse(['data'=>$this->read->quota($this->read->scope($r->query->get('scopeId','')))]); }
    #[Route(path:'/api/_action/inocean/messaging/preview',name:'api.action.inocean.messaging.preview',methods:['POST'],defaults:['_acl'=>['inocean_messaging:manage']])]
    public function preview(Request $r): JsonResponse { $p=Payload::read($r); $body=$this->renderer->render($p['body']??'',$p['variables']??[],$p['allowedVariables']??[]); return new JsonResponse(['body'=>$body]+Segments::count($body)); }
    #[Route(path:'/api/_action/inocean/messaging/consent',name:'api.action.inocean.messaging.consent.read',methods:['GET'],defaults:['_acl'=>['inocean_messaging:manage']])]
    public function consentStatus(Request $r): JsonResponse {
        $scope=$this->read->scope($r->query->get('scopeId','')); $phone=$r->query->get('phone',''); $hash=$this->privacy->hash($phone);
        $records=$this->db->fetchAllAssociative('SELECT type,source,expires_at expiresAt,created_at createdAt FROM ino_sms_consent WHERE scope_id=UNHEX(?) AND phone_hash=? ORDER BY created_at DESC LIMIT 100',[$scope,$hash]);
        $suppressed=$this->db->fetchAllAssociative('SELECT purpose,provider,active,source,created_at createdAt FROM ino_sms_suppression WHERE scope_id IN (UNHEX(?),UNHEX(?)) AND phone_hash=?',[$scope,SmsSuppressionService::GLOBAL,$hash]);
        return new JsonResponse(['records'=>$records,'suppression'=>$suppressed]);
    }
    #[Route(path:'/api/_action/inocean/messaging/consent',name:'api.action.inocean.messaging.consent',methods:['POST'],defaults:['_acl'=>['inocean_messaging:manage']])]
    public function consent(Request $r, \Shopware\Core\Framework\Context $context): JsonResponse {
        $p=Payload::read($r); $p['source']=$this->actor($context).':'.($p['source']??''); $scope=$this->read->scope((string)($p['scopeId']??'')); $phone=(string)($p['phone']??'');
        $this->db->transactional(function() use($p,$scope,$phone) {
            $this->consent->record($scope,$phone,$p['type']??'unknown',$p['source']??'',$p['evidence']??'',$p['expiresAt']??null);
            if(($p['type']??'')==='marketing_opt_out') { $this->suppression->set($scope,$phone,'marketing','',true,$p['source']); }
            if(($p['type']??'')==='marketing_express' && ($p['clearMarketingOptOut']??false)===true) { $this->suppression->set($scope,$phone,'marketing','',false,$p['source']); }
        });
        return new JsonResponse(['recorded'=>true],201);
    }
    #[Route(path:'/api/_action/inocean/messaging/suppression',name:'api.action.inocean.messaging.suppression',methods:['POST'],defaults:['_acl'=>['inocean_messaging:manage']])]
    public function suppress(Request $r, \Shopware\Core\Framework\Context $context): JsonResponse {
        $p=Payload::read($r); $p['source']=$this->actor($context).':'.($p['source']??'manual'); $scope=($p['global']??false)===true ? SmsSuppressionService::GLOBAL : $this->read->scope($p['scopeId']??'');
        $this->db->transactional(function() use($p,$scope) {
            $active=$p['active']??true; $purpose=$p['purpose']??'all'; $provider=$p['provider']??'';
            if (!$active && (($p['evidence']??'')==='' || $purpose==='marketing')) { throw new \InvalidArgumentException('Clearing all/provider suppression requires reconciliation evidence; use express consent API for marketing'); }
            $this->suppression->set($scope,$p['phone']??'',$purpose,$provider,$active,'admin:'.($p['source']??'manual'));
            $this->consent->record($scope,$p['phone']??'',$active?'marketing_opt_out':'unknown','admin:'.($p['source']??'manual'),$p['evidence']??'Manual suppression');
        }); return new JsonResponse(['suppressed'=>$p['active']??true]);
    }
    private function actor(\Shopware\Core\Framework\Context $context): string {
        $source=$context->getSource();
        if (!$source instanceof \Shopware\Core\Framework\Api\Context\AdminApiSource) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Admin identity required'); }
        return 'api:'.($source->getUserId() ?? $source->getIntegrationId() ?? 'unknown');
    }
}
