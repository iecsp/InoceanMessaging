<?php declare(strict_types=1);
namespace Inocean\Messaging\Command;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'inocean:messaging:retention',description:'Dry-run encrypted SMS payload retention; consent and suppression remain intact')]
final class RetentionCommand extends Command
{
    public function __construct(private readonly Connection $db) { parent::__construct(); }
    protected function configure(): void { $this->addOption('days',null,InputOption::VALUE_REQUIRED,'Payload retention in days','90')->addOption('execute',null,InputOption::VALUE_NONE,'Irreversibly redact expired payloads'); }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        $days=filter_var($input->getOption('days'),FILTER_VALIDATE_INT); if($days===false || $days<1) { throw new \InvalidArgumentException('days must be a positive integer'); }
        $before=gmdate('Y-m-d H:i:s',time()-$days*86400);
        $where="created_at<? AND body_cipher IS NOT NULL AND status NOT IN ('queued','sending','unknown')";
        $count=$this->db->fetchOne('SELECT COUNT(*) FROM ino_sms_message WHERE '.$where,[$before]);
        $output->writeln('Eligible payloads: '.$count);
        if($input->getOption('execute')) { $this->db->executeStatement('UPDATE ino_sms_message SET phone_cipher=NULL,body_cipher=NULL WHERE '.$where,[$before]); }
        return Command::SUCCESS;
    }
}
