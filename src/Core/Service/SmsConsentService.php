<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
final class SmsConsentService
{
    public function __construct(private readonly Connection $db, private readonly Privacy $privacy) {}
    public function record(string $scope, string $phone, string $type, string $source, string $evidence, ?string $expires = null, ?string $customerId = null): void {
        if (!Uuid::isValid($scope) || !preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone) || !in_array($type, ['transactional_authorization','marketing_express','marketing_implied','marketing_opt_out','unknown','start','help'], true) || $source === '' || $evidence === '') { throw new \InvalidArgumentException('Consent requires a valid scope, phone, type, source and evidence'); }
        $this->recordHash($scope, $this->privacy->hash($phone), $type, $source, $evidence, $expires, $customerId);
    }
    public function recordHash(string $scope, string $hash, string $type, string $source, string $evidence, ?string $expires = null, ?string $customerId = null): void {
        if (!Uuid::isValid($scope) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || ($customerId !== null && !Uuid::isValid($customerId)) || !in_array($type, ['transactional_authorization','marketing_express','marketing_implied','marketing_opt_out','unknown','start','help'], true) || $source === '' || $evidence === '') { throw new \InvalidArgumentException('Invalid consent identity'); }
        if ($expires !== null) { try { $expires = (new \DateTimeImmutable($expires))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'); } catch (\Exception) { throw new \InvalidArgumentException('Invalid consent expiry'); } }
        $this->db->insert('ino_sms_consent', ['id'=>Uuid::randomBytes(),'scope_id'=>Uuid::fromHexToBytes($scope),'phone_hash'=>$hash,'customer_id'=>$customerId === null ? null : Uuid::fromHexToBytes($customerId),'type'=>$type,'source'=>substr($source,0,128),'evidence'=>$this->privacy->seal($evidence),'expires_at'=>$expires,'created_at'=>self::now()]);
    }
    public function allowed(string $scope, string $hash, string $purpose): bool {
        $types = $purpose === 'marketing' ? ['marketing_express','marketing_opt_out'] : ['transactional_authorization'];
        $row = $this->db->fetchAssociative('SELECT type, expires_at FROM ino_sms_consent WHERE scope_id=UNHEX(?) AND phone_hash=? AND type IN (?) ORDER BY sequence DESC LIMIT 1', [$scope,$hash,$types], [\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ArrayParameterType::STRING]);
        return $row && $row['type'] === $types[0] && ($row['expires_at'] === null || $row['expires_at'] > self::now());
    }
    public static function now(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'); }
}
