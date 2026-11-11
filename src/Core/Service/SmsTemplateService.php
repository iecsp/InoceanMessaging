<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Doctrine\DBAL\Connection;
final class SmsTemplateService
{
    public function __construct(private readonly Connection $db, private readonly TemplateRenderer $renderer) {}
    public function render(string $scope, string $code, string $locale, array $variables): array {
        $row = $this->db->fetchAssociative('SELECT LOWER(HEX(id)) id,purpose,allowed_variables FROM ino_sms_template WHERE code=? AND active=1 AND scope_id IN (UNHEX(?),UNHEX(?)) ORDER BY scope_id=UNHEX(?) DESC LIMIT 1',[$code,$scope,SmsSuppressionService::GLOBAL,$scope]);
        return $this->renderTemplate($row, $locale, $variables);
    }

    public function renderById(string $scope, string $id, string $locale, array $variables): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT LOWER(HEX(id)) id, code, purpose, allowed_variables FROM ino_sms_template WHERE id=UNHEX(?) AND active=1 AND scope_id IN (UNHEX(?),UNHEX(?))',
            [$id, $scope, SmsSuppressionService::GLOBAL],
        );

        return $this->renderTemplate($row, $locale, $variables) + ['code' => $row['code']];
    }

    private function renderTemplate(array|false $row, string $locale, array $variables): array
    {
        if (!$row || !in_array($row['purpose'],['transactional','marketing'],true)) { throw new \InvalidArgumentException('Template unavailable'); }
        $body = $this->db->fetchOne("SELECT t.body FROM ino_sms_template_translation t JOIN language l ON l.id=t.language_id JOIN locale o ON o.id=l.locale_id WHERE t.ino_sms_template_id=UNHEX(?) AND (o.code IN (?,?) OR l.id=UNHEX('2fbb5fe2e29a4d70aa5854ce7ce3e20b')) ORDER BY o.code=? DESC, o.code='en-CA' DESC LIMIT 1",[$row['id'],$locale,'en-CA',$locale]);
        if (!$body) { throw new \InvalidArgumentException('Template translation unavailable'); }
        return ['body'=>$this->renderer->render($body,$variables,json_decode($row['allowed_variables'],true,512,JSON_THROW_ON_ERROR)), 'purpose'=>$row['purpose']];
    }
}
