<?php

namespace App\Support\Notifications;

use App\Contracts\Notifications\PushProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ExpoPushProvider implements PushProvider
{
    public function send(array $messages): array
    {
        if (count($messages) < 1 || count($messages) > 100) {
            throw new RuntimeException('Invalid push batch.');
        }
        $data = $this->request('send', $messages);
        $results = [];
        foreach ($messages as $i => $_) {
            $item = $data[$i] ?? [];
            $id = $item['id'] ?? null;
            $results[] = ($item['status'] ?? '') === 'ok' && is_string($id) && strlen($id) <= 200 ? ['status' => 'accepted', 'id' => $id] : ['status' => 'error', 'error' => self::classify($item['details']['error'] ?? null)];
        }

        return $results;
    }

    public function receipts(array $ids): array
    {
        if (count($ids) < 1 || count($ids) > 100) {
            throw new RuntimeException('Invalid receipt batch.');
        }
        $data = $this->request('getReceipts', ['ids' => $ids]);
        $results = [];
        foreach ($ids as $id) {
            if (isset($data[$id])) {
                $item = $data[$id];
                $results[$id] = ($item['status'] ?? '') === 'ok' ? ['status' => 'accepted'] : ['status' => 'error', 'error' => self::classify($item['details']['error'] ?? null)];
            }
        }

        return $results;
    }

    /** @param array<mixed> $body
     * @return array<mixed>
     */
    private function request(string $path, array $body): array
    {
        // No real network in local/test, even if an environment flag is accidentally enabled.
        if (! app()->environment('production') || ! config('notifications.push_enabled')) {
            throw new RuntimeException('Push transport disabled.');
        }
        $request = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
        $access = config('notifications.expo_access_token');
        if (is_string($access) && $access !== '') {
            $request = $request->withToken($access);
        }
        $response = $request->post('https://exp.host/--/api/v2/push/'.$path, $body);
        if (! $response->successful() || ! is_array($response->json('data'))) {
            // Never throw an HTTP exception containing request tokens or provider response bodies.
            throw new RuntimeException('Push transport unavailable.');
        }

        return $response->json('data');
    }

    private static function classify(mixed $code): string
    {
        return match ($code) {
            'DeviceNotRegistered' => 'invalid_device',
            'MessageTooBig', 'MismatchSenderId', 'InvalidCredentials' => 'permanent',
            default => 'transient',
        };
    }
}
