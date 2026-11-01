<?php declare(strict_types=1);
namespace Inocean\Messaging\Api;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return ['kernel.exception'=>['onException',10]]; }
    public function onException(ExceptionEvent $event): void {
        if(!str_contains($event->getRequest()->getPathInfo(),'/inocean/messaging/')) { return; }
        $e=$event->getThrowable();
        if($e instanceof \InvalidArgumentException || $e instanceof \DomainException) { $event->setResponse(new JsonResponse(['errors'=>[['code'=>'INO_MESSAGING_REQUEST_REJECTED','detail'=>$e->getMessage()]]],$e instanceof \DomainException?409:400)); }
    }
}
