<?php

namespace App\Support;

use App\Models\TrainingSession;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class AttendanceQr
{
    /** @return array{token:string,expires_at:string,refresh_after:int} */
    public static function issue(TrainingSession $s): array
    {
        AttendancePolicy::memberWindow($s);
        $expires = now()->addSeconds(AttendancePolicy::QR_SECONDS)->min($s->starts_at->addMinutes(AttendancePolicy::MEMBER_LATE_MINUTES))->min($s->ends_at);

        return ['token' => Crypt::encryptString(json_encode(['v' => 1, 'organization' => $s->organization_id, 'session' => $s->public_id, 'issued' => now()->timestamp, 'expires' => $expires->timestamp], JSON_THROW_ON_ERROR)), 'expires_at' => $expires->toIso8601String(), 'refresh_after' => max(1, min(60, $expires->getTimestamp() - now()->getTimestamp()))];
    }

    public static function validate(TrainingSession $s, string $token): void
    {
        if (strlen($token) > 2048) {
            throw new AttendanceConflict('qr_invalid');
        }
        try {
            $p = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $e) {
            throw new AttendanceConflict('qr_invalid');
        }
        if (! is_array($p) || ($p['v'] ?? null) !== 1 || ($p['organization'] ?? null) !== $s->organization_id || ($p['session'] ?? null) !== $s->public_id || ! is_int($p['issued'] ?? null) || ! is_int($p['expires'] ?? null) || $p['issued'] > now()->timestamp || $p['expires'] <= now()->timestamp || $p['expires'] - $p['issued'] > AttendancePolicy::QR_SECONDS) {
            throw new AttendanceConflict('qr_invalid');
        }
        AttendancePolicy::memberWindow($s);
    }
}
