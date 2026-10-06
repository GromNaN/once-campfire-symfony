<?php

declare(strict_types=1);

namespace App\Form\Data;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Holds the name and the picture of the account.
 */
final class AccountData
{
    #[Assert\NotBlank(message: 'Enter a name.')]
    #[Assert\Length(max: 255, maxMessage: 'Use at most {{ limit }} characters.')]
    public ?string $name = null;

    #[Assert\Image(maxSize: '8M', maxSizeMessage: 'Upload a picture smaller than {{ limit }}.')]
    public ?UploadedFile $logo = null;
}
