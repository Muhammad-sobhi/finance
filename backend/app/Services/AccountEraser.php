<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes an owner and everything they have: every financial table (foreign keys cascade
 * from `users`), their heir logins, tokens, notifications, push subscriptions, sessions,
 * receipt files — and their data key, so no copy of their rows on this server can be read.
 */
class AccountEraser
{
    public function erase(User $owner, User $admin, ?string $ip = null): void
    {
        $ids = User::where('owner_id', $owner->id)->pluck('id')->push($owner->id)->all();
        $emails = User::whereIn('id', $ids)->pluck('email')->all();

        DB::transaction(function () use ($owner, $admin, $ip, $ids, $emails) {
            // Polymorphic rows have no foreign key to cascade from.
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)->delete();
            DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $ids)->delete();
            DB::table(config('webpush.table_name'))->where('subscribable_type', User::class)->whereIn('subscribable_id', $ids)->delete();
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();

            DB::table('admin_audit_logs')->insert([
                'admin_id' => $admin->id, 'action' => 'delete_user', 'target_id' => $owner->id,
                'target_email' => $owner->email, 'ip' => $ip, 'created_at' => now(),
            ]);
            $owner->delete(); // cascades to all their data, heir logins and the wrapped data key
        });

        Storage::disk('local')->deleteDirectory("receipts/{$owner->id}");
    }
}
