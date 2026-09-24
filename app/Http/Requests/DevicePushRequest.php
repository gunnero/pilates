<?php

namespace App\Http\Requests;

final class DevicePushRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:200', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_-]{10,160}\]$/D'], 'platform' => ['required', 'in:ios,android'], 'installation_id' => ['required', 'uuid']];
    }
}
