<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Account;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use Twig\Attribute\AsTwigFunction;

/**
 * Exposes the account to every template, the way the original application
 * exposes Current.account.
 */
final class AccountExtension
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * The administrator a reader can write to when something goes wrong.
     *
     * The pages someone reaches before they have an account, such as signing in
     * or joining, show this address, because there is nobody else to ask.
     */
    #[AsTwigFunction('help_administrator')]
    public function helpAdministrator(): ?User
    {
        return $this->users->findFirstAdministrator();
    }

    #[AsTwigFunction('account_name')]
    public function accountName(): string
    {
        return $this->account()?->getName() ?? Account::DEFAULT_NAME;
    }

    /**
     * The styles an administrator added, or null when there are none.
     *
     * They are written into the page as they are, so that the look of the
     * application can be changed. A sequence that would close the style
     * element is broken up, which neutralises a value stored before the
     * validation rule existed without changing any legitimate CSS.
     */
    #[AsTwigFunction('account_custom_styles')]
    public function customStyles(): ?string
    {
        $styles = $this->account()?->getCustomStyles();

        if (null === $styles || '' === trim($styles)) {
            return null;
        }

        return str_replace('</', '<\/', $styles);
    }

    private function account(): ?Account
    {
        return $this->accounts->findOneBy([]);
    }
}
