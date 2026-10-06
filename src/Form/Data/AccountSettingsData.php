<?php

declare(strict_types=1);

namespace App\Form\Data;

/**
 * Holds the switches of the account page.
 *
 * There is one switch today. It is a plain boolean rather than the JSON column
 * the entity keeps, so the form does not have to know how the setting is
 * stored.
 */
final class AccountSettingsData
{
    public bool $restrictRoomCreationToAdministrators = false;
}
