<?php

declare(strict_types=1);

namespace App\Form\Data;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Holds the fields of the profile form.
 *
 * A field the form leaves empty is not applied, so saving a page without
 * touching the password keeps the current one. This is what the original does:
 * an empty password is ignored rather than hashed.
 */
final class ProfileData
{
    #[Assert\NotBlank(message: 'Enter a name.')]
    #[Assert\Length(max: 255, maxMessage: 'Use at most {{ limit }} characters.')]
    public ?string $name = null;

    #[Assert\Length(max: 255, maxMessage: 'Use at most {{ limit }} characters.')]
    public ?string $emailAddress = null;

    /**
     * The plain password, only when a new one is being set.
     */
    #[Assert\Length(max: 72, maxMessage: 'Use at most {{ limit }} characters.')]
    public ?string $password = null;

    #[Assert\Length(max: 200, maxMessage: 'Use at most {{ limit }} characters.')]
    public ?string $bio = null;

    #[Assert\Image(maxSize: '8M', maxSizeMessage: 'Upload a picture smaller than {{ limit }}.')]
    public ?UploadedFile $avatar = null;
}
