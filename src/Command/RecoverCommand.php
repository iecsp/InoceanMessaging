<?php declare(strict_types=1);
namespace Inocean\Messaging\Command;
use Doctrine\DBAL\Connection;
use Inocean\Messaging\Core\Message\SendSmsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
#[AsCommand(name: 'inocean:messaging:recover', description: 'Recover SMS outbox and quarantine interrupted sends')]
final class RecoverCommand extends Command
{
    public function __construct(private readonly Connection $db,private readonly MessageBusInterface $bus) { parent::__construct(); }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        $this->db->transactional(function (): void {
            $this->db->executeStatement("UPDATE ino_sms_attempt a JOIN ino_sms_message m ON m.id=a.message_id SET a.status='unknown',a.error_code='interrupted_send',a.updated_at=UTC_TIMESTAMP(3) WHERE a.status='sending' AND m.status='sending' AND m.updated_at<DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE)");
            $this->db->executeStatement("UPDATE ino_sms_message SET status='unknown', error_code='interrupted_send', updated_at=UTC_TIMESTAMP(3) WHERE status='sending' AND updated_at<DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE)");
        });
        $ids=$this->db->fetchFirstColumn("SELECT LOWER(HEX(id)) FROM ino_sms_message WHERE status='queued' AND next_at<=UTC_TIMESTAMP(3) ORDER BY next_at LIMIT 500");
        foreach($ids as $id) { $this->bus->dispatch(new SendSmsMessage($id)); }
        $output->writeln('Dispatched '.count($ids).' persisted tasks'); return Command::SUCCESS;
    }
}
