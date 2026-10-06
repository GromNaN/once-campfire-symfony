<?php

declare(strict_types=1);

namespace App\Form\Data;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * What a browser sends when it registers for push notifications.
 *
 * The names are the ones the original application uses, so a client written
 * against it keeps working. The values are kept as they arrive: what makes an
 * endpoint acceptable belongs to the record, not to the envelope.
 */
final readonly class PushSubscriptionData
{
    public function __construct(
        #[SerializedName('endpoint')]
        public string $endpoint = '',
        #[SerializedName('p256dh_key')]
        public string $p256dhKey = '',
        #[SerializedName('auth_key')]
        public string $authKey = '',
    ) {
    }
}
