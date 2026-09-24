<?php

namespace App\Http\Requests;

class NotificationPreferenceRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['reminders_enabled' => ['required', 'boolean:strict'], 'expiry_reminders_enabled' => ['required', 'boolean:strict']];
    }
}
