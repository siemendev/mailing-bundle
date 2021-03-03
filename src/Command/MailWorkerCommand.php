<?php

namespace SVB\Mailing\Command;

use Doctrine\DBAL\Driver\Exception;
use SVB\Mailing\Exception\MailingException;
use SVB\Mailing\Mail\MailInterface;
use SVB\Mailing\Mailer;
use SVB\Mailing\Repository\MailRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MailWorkerCommand extends Command
{
    /** @var Mailer */
    private $mailer;

    /** @var MailRepository */
    private $mailRepository;

    public function setMailer(Mailer $mailer)
    {
        $this->mailer = $mailer;
    }

    public function setMailRepository(MailRepository $mailRepository)
    {
        $this->mailRepository = $mailRepository;
    }

    protected function configure()
    {
        $this
            ->setName('svb:mailing:worker')
            ->setDescription('Used for cronjobs')
        ;
    }

    /**
     * @throws MailingException
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->mailRepository->getUnhandledMails(50) as $mailRow) {
            if ($mailRow['tries'] > 5) {
                $this->mailRepository->markMailAsFailed($mailRow['id']);
                // TODO log something?
                continue;
            }

            $mail = $this->mailRepository->getMailFromDatabaseResult($mailRow);
            if (!$mail instanceof MailInterface) {
                $this->mailRepository->markMailAsFailed($mailRow['id']);
                // todo log exception?
                #throw new MailingException('mail data could not be deserialized from database into MailInterface object.');
                continue;
            }

            if (!empty($mailRow['api_identifier'])) {
                try {
                    if ($this->mailer->getMailStatus($mail, $mailRow['api_identifier'])) {
                        $this->mailRepository->markMailAsSucceeded($mailRow['id']);
                        continue;
                    }
                } catch (MailingException|Exception $exception) {
                    // TODO do something
                    $a = true;
                }
            }

            try {
                $this->mailer->resendMail($mailRow['id'], $mail);
            } catch (MailingException|Exception $exception) {
                // TODO do something
                $a = true;
            }

            $this->mailRepository->increaseMailTries($mailRow['id'], $mailRow['tries']);
        }

        return 0;
    }
}