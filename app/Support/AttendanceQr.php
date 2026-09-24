<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\TrainingSession;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class AttendanceQr
{
    /** @return array{token:string,payload:string,expires_at:string,server_time:string,refresh_after:int} */
    public static function issue(TrainingSession $s): array
    {
        AttendancePolicy::memberWindow($s);
        $policy = StudioSettings::forOrganization($s->organization_id);
        $expires = now()->addSeconds($policy->qr_lifetime_seconds)->min($s->starts_at->addMinutes($policy->check_in_late_minutes))->min($s->ends_at);

        if ($expires->lte(now())) {
            throw new AttendanceConflict('window');
        }
        $token = Crypt::encryptString(json_encode(['v' => 1, 'organization' => $s->organization_id, 'session' => $s->public_id, 'issued' => now()->timestamp, 'expires' => $expires->timestamp], JSON_THROW_ON_ERROR));
        $payload = 'GTQR1:'.json_encode(['organization' => Organization::findOrFail($s->organization_id)->public_id, 'session' => $s->public_id, 'credential' => $token], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return ['token' => $token, 'payload' => $payload, 'expires_at' => $expires->toIso8601String(), 'server_time' => now()->toIso8601String(), 'refresh_after' => max(1, min($policy->qr_rotation_seconds, $expires->getTimestamp() - now()->getTimestamp()))];
    }

    public static function validate(TrainingSession $s, string $token, bool $detailed = false): void
    {
        if (strlen($token) > 2048) {
            throw new AttendanceConflict('qr_invalid');
        }
        try {
            $p = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $e) {
            throw new AttendanceConflict('qr_invalid');
        }
        if (! is_array($p) || ($p['v'] ?? null) !== 1 || ($p['organization'] ?? null) !== $s->organization_id || ($p['session'] ?? null) !== $s->public_id || ! is_int($p['issued'] ?? null) || ! is_int($p['expires'] ?? null) || $p['issued'] > now()->timestamp || $p['expires'] <= $p['issued'] || $p['expires'] - $p['issued'] > AttendancePolicy::QR_SECONDS) {
            throw new AttendanceConflict('qr_invalid');
        }
        if ($p['expires'] <= now()->timestamp) {
            throw new AttendanceConflict($detailed ? 'qr_expired' : 'qr_invalid');
        }
        AttendancePolicy::memberWindow($s, $detailed);
    }
}
