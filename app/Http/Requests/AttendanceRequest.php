<?php

namespace App\Http\Requests;

class AttendanceRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['member_id' => ['required', 'uuid'], 'status' => ['required', 'in:present,absent,no_show'], 'reason' => [$this->routeIs('attendance.correct') ? 'required' : 'prohibited', 'in:mistake,late_confirmation']];
    }
}
