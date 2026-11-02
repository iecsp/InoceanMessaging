<?php declare(strict_types=1);
namespace Inocean\Messaging\Api;
use Inocean\Messaging\Core\Service\{WebhookSignature,WebhookService};
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
#[Route(defaults: ['_routeScope'=>['ino-messaging-public'], '_csrf_protected'=>false])]
final class PlivoWebhookController
{
    public function __construct(private readonly WebhookSignature $signature,private readonly WebhookService $webhooks) {}
    #[Route(path: '/inocean/messaging/webhook/plivo', name: 'frontend.inocean.messaging.webhook.plivo', methods: ['POST'])]
    public function receive(Request $request): Response {
        if(strlen($request->getContent())>65536) { return new Response('',413); }
        $this->signature->plivo($request);
        $data=$request->request->all();
        $this->webhooks->handle('plivo',$data);
        return new Response('<Response/>',200,['Content-Type'=>'application/xml']);
    }
}
