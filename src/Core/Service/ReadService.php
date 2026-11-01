<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
final class ReadService
{
    public function __construct(private readonly Connection $db,private readonly Settings $settings) {}
    public function scope(string $scope): string { if(!Uuid::isValid($scope)||!$this->db->fetchOne('SELECT 1 FROM sales_channel WHERE id=UNHEX(?)',[$scope])) { throw new \InvalidArgumentException('Valid sales channel scope required'); } return $scope; }
    public function status(string $scope,string $id): array {
        if(!Uuid::isValid($id)) { throw new NotFoundHttpException(); }
        $row=$this->db->fetchAssociative('SELECT LOWER(HEX(id)) id,status,provider,segments,cost,currency,error_code errorCode,created_at createdAt FROM ino_sms_message WHERE id=UNHEX(?) AND scope_id=UNHEX(?)',[$id,$scope]);
        if(!$row) { throw new NotFoundHttpException(); } return $row;
    }
    public function messages(string $scope,int $page): array { return $this->db->fetchAllAssociative('SELECT LOWER(HEX(id)) id,status,template_code templateCode,provider,segments,cost,currency,error_code errorCode,created_at createdAt FROM ino_sms_message WHERE scope_id=UNHEX(?) ORDER BY created_at DESC LIMIT 50 OFFSET '.(max(0,min(10000,$page-1))*50),[$scope]); }
    public function usage(string $scope): array { return $this->db->fetchAllAssociative('SELECT provider,status,currency,COUNT(*) messages,SUM(segments) segments,SUM(cost) cost FROM ino_sms_message WHERE scope_id=UNHEX(?) AND COALESCE(quota_month,DATE_FORMAT(created_at,"%Y-%m"))=? GROUP BY provider,status,currency',[$scope,gmdate('Y-m')]); }
    public function quota(string $scope): array { return ['monthlySegments'=>(int)$this->settings->get('monthlySegments',$scope,1000),'usage'=>$this->usage($scope)]; }
    public function templates(string $scope): array { return $this->db->fetchAllAssociative('SELECT LOWER(HEX(id)) id,code,purpose FROM ino_sms_template WHERE active=1 AND scope_id IN (UNHEX(?),UNHEX(?))',[$scope,SmsSuppressionService::GLOBAL]); }
}
