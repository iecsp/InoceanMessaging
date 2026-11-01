<?php declare(strict_types=1);
namespace Inocean\Messaging\Api;
use Inocean\Messaging\Core\Service\{WebhookSignature,WebhookService};
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
#[Route(defaults: ['_routeScope'=>['storefront'], '_csrf_protected'=>false])]
final class TelnyxWebhookController
{
    public function __construct(private readonly WebhookSignature $signature,private readonly WebhookService $webhooks) {}
    #[Route(path: '/inocean/messaging/webhook/telnyx', name: 'frontend.inocean.messaging.webhook.telnyx', methods: ['POST'])]
    public function receive(Request $request): Response {
        if(strlen($request->getContent())>65536) { return new Response('',413); }
        $this->signature->telnyx($request);
        $data=$request->toArray();
        $this->webhooks->handle('telnyx',$data);
        return new Response('',200,[]);
    }
}
