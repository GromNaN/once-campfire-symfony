<?php

declare(strict_types=1);

namespace App\Form\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Holds the fields of the sign in form.
 */
final class LoginData
{
    #[Assert\NotBlank(message: 'Enter an email address.')]
    public string $emailAddress = '';

    #[Assert\NotBlank(message: 'Enter a password.')]
    public string $password = '';
}
