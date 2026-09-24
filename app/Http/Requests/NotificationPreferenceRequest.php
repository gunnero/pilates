<?php

namespace App\Http\Requests;

use App\Support\Notifications\Preferences;

class NotificationPreferenceRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return array_fill_keys(array_keys(Preferences::DEFAULTS), ['sometimes', 'required', 'boolean:strict']);
    }
}
