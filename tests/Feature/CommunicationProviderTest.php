<?php

namespace Tests\Feature;

use App\Support\Notifications\ExpoPushProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CommunicationProviderTest extends TestCase
{
    public function test_expo_batches_minimal_payloads_and_classifies_tickets_and_receipts(): void
    {
        Http::preventStrayRequests();
        $this->app['env'] = 'production';
        config(['notifications.push_enabled' => true]);
        Http::fake([
            'exp.host/--/api/v2/push/send' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1'], ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]]),
            'exp.host/--/api/v2/push/getReceipts' => Http::response(['data' => ['ticket-1' => ['status' => 'ok'], 'ticket-2' => ['status' => 'error', 'details' => ['error' => 'MessageRateExceeded']]]]),
        ]);
        $message = ['to' => 'ExpoPushToken[fixture_provider]', 'title' => 'GT Studio', 'body' => 'Open the app.', 'data' => ['url' => 'gtstudio://notification/fixture/fixture']];
        $provider = new ExpoPushProvider;
        $this->assertSame([['status' => 'accepted', 'id' => 'ticket-1'], ['status' => 'error', 'error' => 'invalid_device']], $provider->send([$message, $message]));
        $this->assertSame(['ticket-1' => ['status' => 'accepted'], 'ticket-2' => ['status' => 'error', 'error' => 'transient']], $provider->receipts(['ticket-1', 'ticket-2', 'missing-ticket']));
        Http::assertSentCount(2);
    }

    public function test_provider_errors_do_not_escape_with_raw_response_or_tokens(): void
    {
        Http::preventStrayRequests();
        $this->app['env'] = 'production';
        config(['notifications.push_enabled' => true]);
        Http::fake(['*' => Http::response(['error' => 'sensitive provider body'], 429)]);
        try {
            (new ExpoPushProvider)->receipts(['ticket']);
            $this->fail('Rate limit must not be accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Push transport unavailable.', $e->getMessage());
        }
    }

    public function test_local_push_cannot_send_real_network_even_with_enabled_flag(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['notifications.push_enabled' => true]);
        try {
            (new ExpoPushProvider)->receipts(['ticket']);
            $this->fail('Local provider must remain disabled');
        } catch (\RuntimeException $e) {
            $this->assertSame('Push transport disabled.', $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
