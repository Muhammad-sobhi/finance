<?php

namespace App\Models\Concerns;

/**
 * Child rows (payments, contributions, value history) copy their owner's `user_id` from the
 * parent named in the model's PARENT constant ([parent class, foreign key]), so each row can
 * be decrypted on its own.
 */
trait OwnedThroughParent
{
    protected static function bootOwnedThroughParent(): void
    {
        static::creating(function (self $row) {
            [$parent, $fk] = static::PARENT;
            $row->user_id ??= $parent::whereKey($row->{$fk})->value('user_id');
        });
    }
}
