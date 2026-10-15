<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Heir;
use App\Models\HeirRequest;
use App\Models\TrustedContact;
use App\Models\User;
use App\Notifications\HeirMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Heir access, both mechanisms:
 *  - Dead-man switch: inactivity ≥ N days → owner warned daily for the grace window →
 *    no check-in → access activates and heirs get a set-password link.
 *  - Manual request: heir asks → a trusted contact confirms → owner is told and has the
 *    grace window to cancel → access activates.
 */
class HeirService
{
    /** Called hourly. Returns a summary for the command output. */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stats = ['warned' => 0, 'activated' => 0];

        User::where('role', UserRole::Owner)->where('heir_enabled', true)->whereNull('heir_access_activated_at')
            ->each(function (User $owner) use ($now, &$stats) {
                // Manual requests whose cancel window has passed.
                $due = $owner->heirRequests()->where('status', 'confirmed')->where('activates_at', '<=', $now)->first();
                if ($due) {
                    $due->update(['status' => 'activated']);
                    $this->activate($owner, 'request');
                    $stats['activated']++;

                    return;
                }

                $last = CarbonImmutable::parse($owner->last_activity_at ?? $owner->created_at);
                if ($last->diffInDays($now) < $owner->heir_inactivity_days) {
                    return;
                }
                if ($owner->heir_warning_started_at === null) {
                    $owner->forceFill(['heir_warning_started_at' => $now])->save();
                    $this->warn($owner, $last, $now);
                    $stats['warned']++;
                } elseif (CarbonImmutable::parse($owner->heir_warning_started_at)->addDays($owner->heir_grace_days)->lte($now)) {
                    $this->activate($owner, 'inactivity');
                    $stats['activated']++;
                } elseif ($owner->heir_last_reminder_at === null || CarbonImmutable::parse($owner->heir_last_reminder_at)->addDay()->lte($now)) {
                    $this->warn($owner, $last, $now);
                    $stats['warned']++;
                }
            });

        return $stats;
    }

    /** "I'm fine": resets the inactivity timer and any running grace window. */
    public function checkIn(User $owner): void
    {
        $owner->forceFill(['last_activity_at' => now(), 'heir_warning_started_at' => null, 'heir_last_reminder_at' => null])->save();
    }

    public function activate(User $owner, string $reason): void
    {
        DB::transaction(function () use ($owner) {
            $owner->forceFill(['heir_access_activated_at' => now(), 'heir_warning_started_at' => null, 'heir_last_reminder_at' => null])->save();
            $owner->heirRequests()->whereIn('status', HeirRequest::OPEN)->update(['status' => 'activated']);
        });
        foreach ($owner->heirs as $heir) {
            $this->invite($heir);
        }
        $owner->notify(new HeirMail('activated', ['reason' => __("heir.reason_{$reason}", [], $owner->locale)], null, [$owner->locale], inApp: true));
    }

    /** Email an heir their set-password link (also used to re-send). */
    public function invite(Heir $heir): void
    {
        $url = SignedLinks::make('links.activate', ['heir' => $heir->id]);
        Notification::route('mail', $heir->email)->notify(new HeirMail('granted', ['owner' => $heir->user->name], $url, ['ar', 'en']));
        $heir->forceFill(['invited_at' => now()])->save();
    }

    /** Owner takes access back (e.g. they were just away). Heir logins stop working. */
    public function revoke(User $owner): void
    {
        $owner->forceFill(['heir_access_activated_at' => null])->save();
        $owner->heirs()->with('login')->get()->each(fn (Heir $h) => $h->login?->tokens()->delete());
    }

    /** Heir sets a password from the emailed link; creates or updates their read-only login. */
    public function setPassword(Heir $heir, string $password): User
    {
        abort_unless($heir->user->heirAccessActive(), 403, 'Heir access is not active.');

        return DB::transaction(function () use ($heir, $password) {
            $user = $heir->login ?? User::where('email', strtolower($heir->email))->first();
            if ($user && ! ($user->isHeir() && (int) $user->owner_id === $heir->user_id)) {
                throw ValidationException::withMessages(['email' => 'This email already belongs to another account.']);
            }
            $user ??= new User(['email' => strtolower($heir->email)]);
            $user->fill(['name' => $heir->name, 'password' => $password, 'role' => UserRole::Heir, 'owner_id' => $heir->user_id, 'locale' => $heir->user->locale]);
            $user->save();
            $heir->forceFill(['login_user_id' => $user->id])->save();

            return $user;
        });
    }

    /**
     * Public request. Same outcome whether or not the email is known (no account enumeration).
     */
    public function request(string $email, ?string $ip): void
    {
        $heirs = Heir::whereRaw('lower(email) = ?', [strtolower(trim($email))])->with('user')->get()
            ->filter(fn (Heir $h) => $h->user->heir_enabled && ! $h->user->heirAccessActive());

        foreach ($heirs as $heir) {
            $owner = $heir->user;
            if ($owner->heirRequests()->where('heir_id', $heir->id)->whereIn('status', HeirRequest::OPEN)->exists()) {
                continue;
            }
            $req = $owner->heirRequests()->create(['heir_id' => $heir->id, 'status' => 'pending', 'requested_ip' => $ip]);
            foreach ($owner->trustedContacts as $contact) {
                $url = SignedLinks::make('links.confirm', ['heirRequest' => $req->id, 'contact' => $contact->id]);
                Notification::route('mail', $contact->email)
                    ->notify(new HeirMail('confirm', ['heir' => $heir->name, 'owner' => $owner->name], $url, ['ar', 'en']));
            }
        }
    }

    public function confirm(HeirRequest $req, TrustedContact $contact): HeirRequest
    {
        abort_unless($contact->user_id === $req->user_id, 404);
        abort_unless($req->status === 'pending', 409, 'This request is no longer pending.');
        $owner = $req->user;
        $req->update([
            'status' => 'confirmed', 'confirmed_by' => $contact->id, 'confirmed_at' => now(),
            'activates_at' => now()->addDays($owner->heir_grace_days),
        ]);
        $cancel = SignedLinks::make('links.cancel', ['heirRequest' => $req->id], days: max(1, $owner->heir_grace_days));
        $owner->notify(new HeirMail('confirmed', [
            'heir' => $req->heir->name, 'contact' => $contact->name, 'date' => $req->activates_at->toDateString(),
        ], $cancel, [$owner->locale], inApp: true));

        if ($owner->heir_grace_days === 0) {
            $req->update(['status' => 'activated']);
            $this->activate($owner, 'request');
        }

        return $req->fresh();
    }

    public function cancel(HeirRequest $req): HeirRequest
    {
        abort_unless(in_array($req->status, HeirRequest::OPEN, true), 409, 'This request can no longer be cancelled.');
        $req->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return $req;
    }

    private function warn(User $owner, CarbonImmutable $last, CarbonImmutable $now): void
    {
        $url = SignedLinks::make('links.check-in', ['user' => $owner->id], days: max(1, $owner->heir_grace_days));
        $owner->notify(new HeirMail('warning', ['days' => (int) $last->diffInDays($now), 'grace' => $owner->heir_grace_days], $url, [$owner->locale], inApp: true));
        $owner->forceFill(['heir_last_reminder_at' => $now])->save();
    }
}
