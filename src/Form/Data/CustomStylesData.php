<?php

declare(strict_types=1);

namespace App\Form\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Holds the CSS an administrator adds to the account.
 */
final class CustomStylesData
{
    /**
     * The styles are written into a style element of every page, so a value
     * that could close that element, pull in another stylesheet or run code is
     * refused. Legitimate CSS, such as custom properties, selectors and
     * colours, contains none of these sequences.
     */
    #[Assert\Regex(
        pattern: '/<|>|@import|expression\s*\(|javascript:|url\s*\(\s*[\'"]?\s*(?:[a-z][a-z0-9+.-]*:|\/\/)/i',
        match: false,
        message: 'The custom CSS contains a value that is not allowed.',
    )]
    public ?string $customStyles = null;
}
