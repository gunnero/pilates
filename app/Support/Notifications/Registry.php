<?php

namespace App\Support\Notifications;

use Illuminate\Support\Facades\Validator;

final class Registry
{
    /** @param array<string,string> $metadata
     * @return array<string,mixed>
     */
    public static function payload(NotificationType $type, array $metadata): array
    {
        Validator::make(['metadata' => $metadata], ['metadata' => 'array:date,time,timezone,status,starts_on,ends_on', 'metadata.*' => 'string|max:100'])->validate();
        $result = ['type' => $type->value, 'metadata' => (object) $metadata];
        foreach (['mk', 'en'] as $locale) {
            $result['title_'.$locale] = __('notifications.'.str_replace('.', '_', $type->value).'.title', [], $locale);
            $result['message_'.$locale] = __('notifications.'.str_replace('.', '_', $type->value).'.message', $metadata, $locale);
        }

        return $result;
    }
}
