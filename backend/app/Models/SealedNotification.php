<?php

namespace App\Models;

use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Notifications\DatabaseNotification;

/** In-app notifications name bills and amounts, so their data is encrypted for the recipient. */
class SealedNotification extends DatabaseNotification
{
    use EncryptedForOwner;

    public const ENCRYPTED = ['data'];

    public const ENCRYPTION_OWNER = 'notifiable_id';
}
