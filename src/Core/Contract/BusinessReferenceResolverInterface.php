<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Contract;
use Symfony\Component\HttpFoundation\Request;
interface BusinessReferenceResolverInterface
{
    /** Resolve only authorized business records. Return phone, allowed template code/purpose and scalar variables, never client phone/variables. */
    public function resolve(string $scope, Request $request, array $payload): array;
}
