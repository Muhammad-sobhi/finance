<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Envelope encryption for financial data: every owner has their own random data key,
 * stored wrapped by the master key (config/vault.php). A database dump or backup alone
 * is unreadable, and one owner's key can never open another owner's rows.
 */
class DataVault
{
    private const CIPHER = 'aes-256-gcm';

    private ?Encrypter $master = null;

    /** @var array<int, Encrypter> unwrapped data keys for this process, by owner id */
    private array $owners = [];

    public function encrypt(int $ownerId, string $value): string
    {
        return $this->forOwner($ownerId)->encryptString($value);
    }

    public function decrypt(int $ownerId, string $payload): string
    {
        return $this->forOwner($ownerId)->decryptString($payload);
    }

    /** A fresh data key, wrapped by the master key, ready for `users.data_key`. */
    public function newWrappedKey(): string
    {
        return $this->master()->encryptString(base64_encode(Encrypter::generateKey(self::CIPHER)));
    }

    /** Re-wrap a data key with the current master key (after a rotation). */
    public function rewrap(string $wrapped): string
    {
        return $this->master()->encryptString($this->master()->decryptString($wrapped));
    }

    /** Can `$masterKey` (or the configured one) unwrap this data key? */
    public function canUnwrap(string $wrapped, ?string $masterKey = null): bool
    {
        try {
            $master = $masterKey === null ? $this->master() : new Encrypter($this->parseKey($masterKey), self::CIPHER);
            $master->decryptString($wrapped);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Short, non-secret id of a master key, to compare a stored copy with the live one. */
    public function fingerprint(?string $masterKey = null): string
    {
        $raw = $this->parseKey($masterKey ?? $this->configuredKey());

        return substr(hash_hmac('sha256', 'treasury-vault-fingerprint', $raw), 0, 16);
    }

    private function forOwner(int $ownerId): Encrypter
    {
        return $this->owners[$ownerId] ??= new Encrypter(
            base64_decode($this->master()->decryptString($this->wrappedKeyFor($ownerId))),
            self::CIPHER,
        );
    }

    /** Owners get a key on creation; older rows get one here, under a row lock so two requests can't race. */
    private function wrappedKeyFor(int $ownerId): string
    {
        $wrapped = DB::table('users')->where('id', $ownerId)->value('data_key');
        if ($wrapped !== null) {
            return $wrapped;
        }

        return DB::transaction(function () use ($ownerId) {
            $row = DB::table('users')->where('id', $ownerId)->lockForUpdate()->first(['id', 'data_key']);
            if ($row === null) {
                throw new RuntimeException("No user {$ownerId} to hold a data key.");
            }
            if ($row->data_key !== null) {
                return $row->data_key;
            }
            $wrapped = $this->newWrappedKey();
            DB::table('users')->where('id', $ownerId)->update(['data_key' => $wrapped]);

            return $wrapped;
        });
    }

    private function master(): Encrypter
    {
        if ($this->master === null) {
            $this->master = new Encrypter($this->parseKey($this->configuredKey()), self::CIPHER);
            $previous = array_map(fn (string $k) => $this->parseKey($k), config('vault.previous_keys', []));
            if ($previous !== []) {
                $this->master->previousKeys($previous);
            }
        }

        return $this->master;
    }

    private function configuredKey(): string
    {
        $file = config('vault.master_key_file');
        if ($file) {
            if (! is_readable($file)) {
                throw new RuntimeException("The data master key file [{$file}] is missing or not readable.");
            }

            return trim((string) file_get_contents($file));
        }

        return (string) config('vault.master_key')
            ?: throw new RuntimeException('No data master key. Set DATA_MASTER_KEY_FILE (or DATA_MASTER_KEY); see `php artisan vault:key`.');
    }

    private function parseKey(string $key): string
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('The data master key must be "base64:" followed by 32 random bytes.');
        }

        return $raw;
    }
}
