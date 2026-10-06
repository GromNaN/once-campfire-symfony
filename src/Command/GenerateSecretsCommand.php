<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes the secrets the application needs into .env.local.
 *
 * .env holds the defaults and is committed, .env.local holds the secrets and is
 * not, so a fresh checkout has none of them. An entry the file already has is
 * kept as it is: replacing a secret that is already in use would sign every
 * session and every link out.
 */
#[AsCommand(name: 'campfire:generate-secrets', description: 'Generate the missing secrets in .env.local')]
final class GenerateSecretsCommand extends Command
{
    /**
     * The secrets held as a random hexadecimal string, and the number of random
     * bytes each one holds.
     */
    private const SECRETS = [
        'APP_SECRET' => 16,
        'MERCURE_JWT_SECRET' => 32,
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/.env.local')]
        private readonly string $file,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $this->file;
        $contents = is_file($file) ? (string) file_get_contents($file) : '';

        $written = [];

        foreach (self::SECRETS as $name => $length) {
            if (null !== self::value($contents, $name)) {
                continue;
            }

            $contents = self::withValue($contents, $name, bin2hex(random_bytes($length)));
            $written[] = $name;
        }

        // A public key without its private key signs nothing, so an incomplete
        // pair is replaced as a whole, even the half that is already there.
        if (null === self::value($contents, 'VAPID_PUBLIC_KEY') || null === self::value($contents, 'VAPID_PRIVATE_KEY')) {
            $vapid = VAPID::createVapidKeys();
            $contents = self::withValue($contents, 'VAPID_PUBLIC_KEY', $vapid['publicKey']);
            $contents = self::withValue($contents, 'VAPID_PRIVATE_KEY', $vapid['privateKey']);
            $written[] = 'VAPID_PUBLIC_KEY';
            $written[] = 'VAPID_PRIVATE_KEY';
        }

        if ([] === $written) {
            $io->comment(sprintf('%s already holds every secret.', $file));

            return Command::SUCCESS;
        }

        file_put_contents($file, $contents);

        $io->writeln(sprintf('Written to %s:', $file));

        foreach ($written as $name) {
            $io->writeln('  '.$name);
        }

        return Command::SUCCESS;
    }

    /**
     * The value the dotenv file gives to the name, or null when it gives none.
     * An entry that is present but empty counts as missing, which is how .env
     * marks a secret the environment has to provide.
     */
    private static function value(string $contents, string $name): ?string
    {
        if (!preg_match('/^'.preg_quote($name, '/').'=(.*)$/m', $contents, $matches)) {
            return null;
        }

        $value = trim($matches[1], " \t\r\n\"'");

        return '' === $value ? null : $value;
    }

    /**
     * The contents of the dotenv file with the name set to the value. An entry
     * the file already has is replaced, the other entries are left alone.
     */
    private static function withValue(string $contents, string $name, string $value): string
    {
        $line = $name.'='.$value;
        $lines = '' === trim($contents) ? [] : (preg_split('/\r\n|\n|\r/', rtrim($contents, "\r\n")) ?: []);
        $replaced = false;

        foreach ($lines as $index => $existing) {
            if (str_starts_with($existing, $name.'=')) {
                $lines[$index] = $line;
                $replaced = true;
            }
        }

        if (!$replaced) {
            $lines[] = $line;
        }

        return implode("\n", $lines)."\n";
    }
}
