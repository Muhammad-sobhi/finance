<?php

namespace App\Models\Concerns;

use App\Services\DataVault;
use LogicException;

/**
 * Columns listed in the model's ENCRYPTED constant are stored encrypted with the owner's
 * data key (see DataVault) and are plain values in memory. Encryption happens only on the
 * way to the database and decryption on the way out, so casts, dirty checks and
 * calculations work as usual. The database can't compare or sum these columns: filter,
 * sort and total them in PHP.
 *
 * Every row needs its owner column (`user_id`, or the model's ENCRYPTION_OWNER) set before
 * it is saved and selected when read.
 */
trait EncryptedForOwner
{
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $vault = null;
        foreach (static::ENCRYPTED as $key) {
            if (isset($attributes[$key])) {
                $vault ??= app(DataVault::class);
                $attributes[$key] = $vault->decrypt($this->ownerIdFrom($attributes), $attributes[$key]);
            }
        }

        return parent::newFromBuilder($attributes, $connection);
    }

    protected function getAttributesForInsert()
    {
        return $this->sealAttributes(parent::getAttributesForInsert());
    }

    protected function getDirtyForUpdate()
    {
        return $this->sealAttributes(parent::getDirtyForUpdate());
    }

    /** Atomic SQL increments can't work on ciphertext; change the value and save() instead. */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        if (in_array($column, static::ENCRYPTED, true)) {
            throw new LogicException("Cannot {$method} encrypted column [{$column}]; set it and save().");
        }

        return parent::incrementOrDecrement($column, $amount, $extra, $method);
    }

    private function sealAttributes(array $attributes): array
    {
        $vault = null;
        foreach (static::ENCRYPTED as $key) {
            if (isset($attributes[$key])) {
                $vault ??= app(DataVault::class);
                $attributes[$key] = $vault->encrypt($this->ownerIdFrom($this->attributes), (string) $attributes[$key]);
            }
        }

        return $attributes;
    }

    private function ownerIdFrom(array $attributes): int
    {
        $column = defined(static::class.'::ENCRYPTION_OWNER') ? static::ENCRYPTION_OWNER : 'user_id';
        if (empty($attributes[$column])) {
            throw new LogicException(static::class." needs {$column} to encrypt or decrypt; select it in partial queries.");
        }

        return (int) $attributes[$column];
    }
}
