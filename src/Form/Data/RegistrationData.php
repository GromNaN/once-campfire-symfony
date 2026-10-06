<?php

declare(strict_types=1);

namespace App\Form\Data;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Holds the fields of a signup form, for both the first run and the join page.
 */
final class RegistrationData
{
    #[Assert\NotBlank(message: 'Enter a name.')]
    #[Assert\Length(max: 255, maxMessage: 'Use at most {{ limit }} characters.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Enter an email address.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    #[Assert\Length(max: 255, maxMessage: 'Use at most {{ limit }} characters.')]
    public string $emailAddress = '';

    #[Assert\NotBlank(message: 'Enter a password.')]
    #[Assert\Length(min: 8, minMessage: 'Use at least {{ limit }} characters.')]
    public string $password = '';

    /**
     * The picture the person picks for themselves while signing up, which they
     * can leave out and set later from their profile.
     */
    #[Assert\Image(maxSize: '10M', maxSizeMessage: 'Use a picture of at most {{ limit }}.')]
    public ?UploadedFile $avatar = null;
}
