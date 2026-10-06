<?php

declare(strict_types=1);

namespace App\Form\Data;

/**
 * Holds the role an administrator gives a member.
 *
 * The account page shows a single switch, so the form carries a boolean rather
 * than the role itself: on means administrator, off means member.
 */
final class AccountUserRoleData
{
    public bool $administrator = false;
}
