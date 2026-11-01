<?php declare(strict_types=1);
namespace Inocean\Messaging\Command;
use Doctrine\DBAL\Connection;
use Inocean\Messaging\Core\Service\{SmsSuppressionService,SmsConsentService};
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'inocean:messaging:seed-templates',description:'Create missing default templates without overwriting edits')]
final class SeedTemplatesCommand extends Command
{
    public function __construct(private readonly Connection $db) { parent::__construct(); }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        $codes=['reservation_confirmed','reservation_reminder','reservation_cancelled','queue_ready','order_ready','giftcard_notification','loyalty_notification','loyalty_promotion','staff_notification'];
        $copy=[
          'en-CA'=>['Your reservation is confirmed.','Reservation reminder.','Your reservation is cancelled.','Your table is ready.','Your order is ready.','You received a gift card.','Your loyalty account has an update.','A new offer is available.','Staff notification.'],
          'zh-CN'=>['您的预约已确认。','预约提醒。','您的预约已取消。','您的餐位已准备好。','您的订单已完成。','您收到了一张礼品卡。','您的会员账户有新通知。','现有新的优惠活动。','员工通知。'],
          'zh-TW'=>['您的預約已確認。','預約提醒。','您的預約已取消。','您的餐位已準備好。','您的訂單已完成。','您收到了一張禮品卡。','您的會員帳戶有新通知。','現有新的優惠活動。','員工通知。'],
          'fr-CA'=>['Votre réservation est confirmée.','Rappel de réservation.','Votre réservation est annulée.','Votre table est prête.','Votre commande est prête.','Vous avez reçu une carte-cadeau.','Votre compte fidélité a été mis à jour.','Une nouvelle offre est disponible.','Notification au personnel.']
        ];
        $this->db->transactional(function() use($codes,$copy) {
            foreach($codes as $i=>$code) {
                $id=$this->db->fetchOne('SELECT id FROM ino_sms_template WHERE scope_id=UNHEX(?) AND code=?',[SmsSuppressionService::GLOBAL,$code]);
                if(!$id) { $id=Uuid::randomBytes(); $this->db->insert('ino_sms_template',['id'=>$id,'scope_id'=>Uuid::fromHexToBytes(SmsSuppressionService::GLOBAL),'code'=>$code,'purpose'=>$code==='loyalty_promotion'?'marketing':'transactional','active'=>1,'allowed_variables'=>'["businessName","reference"]','created_at'=>SmsConsentService::now()]); }
                $languages=$this->db->fetchAllAssociative('SELECT l.id,o.code FROM language l JOIN locale o ON o.id=l.locale_id');
                foreach($languages as $l) {
                    if(!isset($copy[$l['code']]) && bin2hex($l['id'])!=='2fbb5fe2e29a4d70aa5854ce7ce3e20b') { continue; }
                    $text=($copy[$l['code']]??$copy['en-CA'])[$i];
                    $this->db->executeStatement('INSERT IGNORE INTO ino_sms_template_translation (ino_sms_template_id,language_id,body,created_at) VALUES (?,?,?,?)',[$id,$l['id'],'{{ businessName }}: '.$text.' {{ reference }}',SmsConsentService::now()]);
                }
            }
        });
        $output->writeln('Default templates seeded for installed languages; existing translations preserved.'); return Command::SUCCESS;
    }
}
