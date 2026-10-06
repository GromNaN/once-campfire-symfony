<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a key pair for push notifications.
 *
 * The keys are printed in the form a dotenv file reads, so the output can be
 * pasted into .env.local as it is. The same pair must be given to the browser
 * as the public key and kept on the server as the private one; changing it
 * invalidates every subscription already registered.
 */
#[AsCommand(name: 'campfire:vapid-key', description: 'Generate a VAPID key pair for push notifications')]
final class VapidKeyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keys = VAPID::createVapidKeys();

        $io->writeln('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $io->writeln('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        $io->newLine();
        $io->comment('Add these lines to .env.local, then restart the application.');

        return Command::SUCCESS;
    }
}
