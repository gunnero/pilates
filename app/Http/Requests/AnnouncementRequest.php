<?php

namespace App\Http\Requests;

final class AnnouncementRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'group_id' => ['nullable', 'uuid'], 'title_mk' => ['required', 'string', 'max:160', 'not_regex:/[<>\x00-\x1F]/u'], 'title_en' => ['required', 'string', 'max:160', 'not_regex:/[<>\x00-\x1F]/u'], 'message_mk' => ['required', 'string', 'max:1000', 'not_regex:/[<>\x00-\x08]/u'], 'message_en' => ['required', 'string', 'max:1000', 'not_regex:/[<>\x00-\x08]/u']];
    }
}
