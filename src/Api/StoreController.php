<?php declare(strict_types=1);
namespace Inocean\Messaging\Api;
use Inocean\Messaging\Core\Contract\{StoreAccessInterface,BusinessReferenceResolverInterface};
use Inocean\Messaging\Core\DTO\SmsRequest;
use Inocean\Messaging\Core\Service\{MessagingService,SmsTemplateService,ReadService,SmsConsentService,SmsSuppressionService};
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
#[Route(defaults:['_routeScope'=>['store-api']])]
final class StoreController
{
    public function __construct(private readonly StoreAccessInterface $access,private readonly BusinessReferenceResolverInterface $references,private readonly MessagingService $messaging,private readonly SmsTemplateService $templates,private readonly ReadService $read,private readonly SmsConsentService $consent,private readonly SmsSuppressionService $suppression) {}
    #[Route(path:'/store-api/inocean/messaging/send',name:'store-api.inocean.messaging.send',methods:['POST'])]
    #[Route(path:'/store-api/inocean/messaging/template/send',name:'store-api.inocean.messaging.template.send',methods:['POST'])]
    public function send(Request $r,SalesChannelContext $context): JsonResponse {
        $scope=$this->access->authorize($r,$context,'inocean_messaging:send'); $this->read->scope($scope);
        if($scope!==$context->getSalesChannelId()) { throw new AccessDeniedHttpException('Sales channel scope mismatch'); }
        $p=Payload::read($r); $resolved=$this->references->resolve($scope,$r,$p);
        if(($resolved['templateCode']??null)!==($p['templateCode']??null)) { throw new AccessDeniedHttpException('Template not authorized for business record'); }
        $render=$this->templates->render($scope,$resolved['templateCode'],$p['language']??'en-CA',$resolved['variables']??[]);
        if($render['purpose']!==($resolved['purpose']??null)) { throw new AccessDeniedHttpException('Purpose mismatch'); }
        return new JsonResponse($this->messaging->enqueue(new SmsRequest($scope,$resolved['phone'],$render['body'],$render['purpose'],$r->headers->get('Idempotency-Key',''),$resolved['templateCode'])),202);
    }
    #[Route(path:'/store-api/inocean/messaging/status/{id}',name:'store-api.inocean.messaging.status',methods:['GET'])]
    public function status(string $id,Request $r,SalesChannelContext $c): JsonResponse { return new JsonResponse($this->read->status($this->scope($r,$c),$id)); }
    #[Route(path:'/store-api/inocean/messaging/templates',name:'store-api.inocean.messaging.templates',methods:['GET'])]
    public function templates(Request $r,SalesChannelContext $c): JsonResponse { return new JsonResponse(['data'=>$this->read->templates($this->scope($r,$c))]); }
    #[Route(path:'/store-api/inocean/messaging/quota',name:'store-api.inocean.messaging.quota',methods:['GET'])]
    public function quota(Request $r,SalesChannelContext $c): JsonResponse { return new JsonResponse($this->read->quota($this->scope($r,$c))); }
    #[Route(path:'/store-api/inocean/messaging/consent',name:'store-api.inocean.messaging.consent',methods:['POST','GET'])]
    public function consent(Request $r,SalesChannelContext $c): JsonResponse {
        $scope=$this->access->authorize($r,$c,'inocean_messaging:manage');
        if($scope!==$c->getSalesChannelId()) { throw new AccessDeniedHttpException(); }
        // Resolver must bind phone to the authenticated customer or authorized business record.
        $resolved=$this->references->resolve($scope,$r,$r->isMethod('POST')?Payload::read($r):$r->query->all());
        if($r->isMethod('GET')) { return new JsonResponse(['transactional'=>$resolved['transactionalAllowed']??false,'marketing'=>$resolved['marketingAllowed']??false]); }
        // Public grant of consent requires separate, verified evidence integration. Opt-out is supported here.
        $this->suppression->set($scope,$resolved['phone'],'marketing','',true,'trusted-self-service');
        $this->consent->record($scope,$resolved['phone'],'marketing_opt_out','trusted-self-service','Verified business record opt-out');
        return new JsonResponse(['optedOut'=>true]);
    }
    private function scope(Request $r,SalesChannelContext $c): string { $scope=$this->access->authorize($r,$c,'inocean_messaging:read'); if($scope!==$c->getSalesChannelId()) { throw new AccessDeniedHttpException(); } return $this->read->scope($scope); }
}
