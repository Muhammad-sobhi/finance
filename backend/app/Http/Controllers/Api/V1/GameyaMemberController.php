<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gameya;
use App\Models\GameyaMember;
use App\Models\GameyaMemberPayment;
use App\Services\GameyaManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The manager's side of a gameya they run: each turn's member, payments, payouts, links. */
class GameyaMemberController extends Controller
{
    public function __construct(private readonly GameyaManager $manager) {}

    public function update(Request $request, Gameya $gameya, GameyaMember $member): JsonResponse
    {
        $this->guard($gameya, $member);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (array_key_exists('email', $data) && strtolower((string) $data['email']) !== strtolower((string) $member->email)) {
            $this->manager->unlink($member); // a new email means a new invitation
        }
        $member->update($data);

        return $this->done($gameya);
    }

    public function pay(Request $request, Gameya $gameya, GameyaMember $member): JsonResponse
    {
        $this->guard($gameya, $member);
        $this->manager->markPaid($member, $request->validate([
            'round' => ['required', 'integer', 'min:1'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]));

        return $this->done($gameya);
    }

    public function unpay(Gameya $gameya, GameyaMember $member, GameyaMemberPayment $payment): JsonResponse
    {
        $this->guard($gameya, $member);
        abort_unless($payment->member_id === $member->id, 404);
        $payment->delete();

        return $this->done($gameya);
    }

    public function payout(Request $request, Gameya $gameya, GameyaMember $member): JsonResponse
    {
        $this->guard($gameya, $member);
        $this->manager->payout($member, $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]));

        return $this->done($gameya);
    }

    public function unpayout(Gameya $gameya, GameyaMember $member): JsonResponse
    {
        $this->guard($gameya, $member);
        $member->forceFill(['payout_date' => null, 'payout_amount' => null])->save();

        return $this->done($gameya);
    }

    public function invite(Request $request, Gameya $gameya, GameyaMember $member): JsonResponse
    {
        $this->guard($gameya, $member);
        $this->manager->invite($member, $request->user());

        return $this->done($gameya);
    }

    public function unlink(Gameya $gameya, GameyaMember $member): JsonResponse
    {
        $this->guard($gameya, $member);
        $this->manager->unlink($member);

        return $this->done($gameya);
    }

    public function remind(Gameya $gameya, GameyaMember $member): JsonResponse
    {
        $this->guard($gameya, $member);

        return response()->json(['sent' => $this->manager->remind($member) ? 1 : 0]);
    }

    /** Remind every linked member who hasn't paid the current round. */
    public function remindAll(Gameya $gameya): JsonResponse
    {
        Gate::authorize('update', $gameya);
        abort_unless($gameya->isManaged(), 404);
        $sent = $gameya->slots()->with(['payments', 'gameya', 'linkedUser'])->get()->filter(fn ($m) => $this->manager->remind($m))->count();

        return response()->json(['sent' => $sent]);
    }

    private function guard(Gameya $gameya, GameyaMember $member): void
    {
        Gate::authorize('update', $gameya);
        abort_unless($gameya->isManaged() && $member->gameya_id === $gameya->id, 404);
        $member->setRelation('gameya', $gameya);
    }

    private function done(Gameya $gameya): JsonResponse
    {
        return app(GameyaController::class)->show($gameya->fresh());
    }
}
