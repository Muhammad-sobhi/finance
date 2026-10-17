<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Nightly backup of the whole server: the database and the receipt files, zipped and
 * encrypted with BACKUP_KEY (libsodium secretstream, XChaCha20-Poly1305), then put on the
 * "backups" disk — an S3-compatible bucket in production, so a copy survives losing the VPS.
 *
 * The financial rows inside are already sealed with the data master key, which is never part
 * of a backup. Restoring needs this file, BACKUP_KEY and the master key (README → Backups).
 */
class ServerBackup
{
    public const MAGIC = 'DINARBK1';

    public const EXT = '.dinarbak';

    private const CHUNK = 1048576;

    public function __construct(private DataVault $vault) {}

    public function enabled(): bool
    {
        return filled(config('ops.backup.key'));
    }

    /** @return array{path: string, bytes: int, pruned: int} */
    public function run(): array
    {
        $key = $this->key();
        $tmp = storage_path('app/backup-tmp/'.Str::random(16));
        File::ensureDirectoryExists($tmp, 0700);

        try {
            $zip = "{$tmp}/backup.zip";
            $this->archive($tmp, $zip);

            $sealed = "{$tmp}/sealed";
            $this->seal($zip, $sealed, $key);

            $path = 'dinar-'.now()->format('Y-m-d-His').self::EXT;
            $in = fopen($sealed, 'rb');
            try {
                Storage::disk(config('ops.backup.disk'))->writeStream($path, $in);
            } finally {
                is_resource($in) && fclose($in);
            }

            return ['path' => $path, 'bytes' => filesize($sealed), 'pruned' => $this->prune()];
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    /** Decrypt a backup file into the zip it was made from. */
    public function open(string $file, string $out, ?string $key = null): void
    {
        $key = $key !== null ? $this->parseKey($key) : $this->key();
        $in = fopen($file, 'rb') ?: throw new RuntimeException("Can't read {$file}.");
        $dst = fopen($out, 'wb') ?: throw new RuntimeException("Can't write {$out}.");

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Not a Dinar server backup.');
            }
            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $tag = null;
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
                if ($res === false) {
                    throw new RuntimeException('Wrong backup key, or the file is damaged.');
                }
                [$plain, $tag] = $res;
                fwrite($dst, $plain);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    break;
                }
            }
            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                throw new RuntimeException('The backup file is incomplete.');
            }
        } finally {
            fclose($in);
            fclose($dst);
        }
    }

    public static function newKey(): string
    {
        return 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /** Database + receipts + a manifest saying which master key the rows need. */
    private function archive(string $tmp, string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Can't create {$zipPath}.");
        }

        $driver = DB::connection()->getDriverName();
        $dump = match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql("{$tmp}/database.sql"),
            'sqlite' => $this->dumpSqlite("{$tmp}/database.sqlite"),
            default => throw new RuntimeException("Backups don't support the {$driver} database driver."),
        };
        $zip->addFile($dump, basename($dump));

        $receipts = Storage::disk('local')->path('receipts');
        $files = 0;
        if (is_dir($receipts)) {
            foreach (File::allFiles($receipts) as $file) {
                $zip->addFile($file->getPathname(), 'receipts/'.str_replace('\\', '/', $file->getRelativePathname()));
                $files++;
            }
        }

        $zip->addFromString('manifest.json', json_encode([
            'format' => 'dinar-server-backup',
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'database' => $driver,
            'receipts' => $files,
            'master_key_fingerprint' => $this->vault->fingerprint(),
            'app_url' => config('app.url'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! $zip->close()) {
            throw new RuntimeException('Writing the backup archive failed.');
        }
    }

    private function dumpMysql(string $out): string
    {
        $c = DB::connection()->getConfig();
        $cnf = dirname($out).'/client.cnf';
        $lines = ['[client]', 'user='.$this->cnfValue($c['username'] ?? ''), 'password='.$this->cnfValue((string) ($c['password'] ?? ''))];
        if (! empty($c['unix_socket'])) {
            $lines[] = 'socket='.$this->cnfValue($c['unix_socket']);
        } else {
            $lines[] = 'host='.$this->cnfValue($c['host'] ?? '127.0.0.1');
            $lines[] = 'port='.(int) ($c['port'] ?? 3306);
        }
        File::put($cnf, implode("\n", $lines)."\n");
        chmod($cnf, 0600);

        $cmd = [config('ops.backup.mysqldump'), "--defaults-extra-file={$cnf}", '--single-transaction', '--quick',
            '--no-tablespaces', '--routines', '--default-character-set=utf8mb4', $c['database']];
        $proc = proc_open($cmd, [1 => ['file', $out, 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('Could not start '.config('ops.backup.mysqldump').'.');
        }
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        File::delete($cnf);

        if ($code !== 0 || ! filesize($out)) {
            throw new RuntimeException('Database dump failed: '.trim($err ?: "exit code {$code}"));
        }

        return $out;
    }

    /** A consistent copy, also of an in-memory database (tests). */
    private function dumpSqlite(string $out): string
    {
        DB::statement('VACUUM INTO ?', [$out]);

        return $out;
    }

    private function seal(string $src, string $dst, string $key): void
    {
        $in = fopen($src, 'rb');
        $out = fopen($dst, 'wb');
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($out, self::MAGIC.$header);
            $size = filesize($src);
            $read = 0;
            do {
                $chunk = (string) fread($in, self::CHUNK);
                $read += strlen($chunk);
                $last = $read >= $size;
                fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '',
                    $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
            } while (! $last);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** Delete our backups older than keep_days (by the date in the name). */
    private function prune(): int
    {
        $disk = Storage::disk(config('ops.backup.disk'));
        $cutoff = now()->subDays(max(1, config('ops.backup.keep_days')))->format('Y-m-d-His');
        $old = array_filter($disk->files(), fn ($f) => preg_match('/^dinar-(\d{4}-\d{2}-\d{2}-\d{6})\.dinarbak$/', $f, $m) && $m[1] < $cutoff);
        if ($old) {
            $disk->delete(array_values($old));
        }

        return count($old);
    }

    private function key(): string
    {
        $key = config('ops.backup.key');
        if (blank($key)) {
            throw new RuntimeException('BACKUP_KEY is not set. Generate one with `php artisan finance:backup-key`.');
        }

        return $this->parseKey($key);
    }

    private function parseKey(string $key): string
    {
        $raw = base64_decode(Str::after(trim($key), 'base64:'), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_KEY must be base64:… of 32 bytes (see `php artisan finance:backup-key`).');
        }

        return $raw;
    }

    private function cnfValue(string $v): string
    {
        return '"'.addcslashes($v, '\\"').'"';
    }
}
