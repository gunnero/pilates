<?php

namespace App\Http\Controllers;

use App\Http\Requests\MemberCheckInRequest;
use App\Support\Attendance;
use Illuminate\Http\JsonResponse;

final class MemberCheckInController
{
    public function __invoke(MemberCheckInRequest $r, string $organization, string $session): JsonResponse
    {
        $record = Attendance::selfCheckIn(MemberAccountController::actor($r), $organization, $session, $r->validated('credential'), true);
        $s = $record->reservation->session;

        return response()->json(['data' => ['outcome' => $record->wasRecentlyCreated ? 'checked_in' : 'already_present', 'attendance' => Attendance::represent($record), 'session' => ['id' => $s->public_id, 'name' => $s->group_name, 'starts_at' => $s->starts_at->toISOString(), 'timezone' => $s->timezone]]])->header('Cache-Control', 'no-store, private');
    }
}
