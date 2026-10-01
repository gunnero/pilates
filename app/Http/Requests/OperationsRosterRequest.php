<?php

namespace App\Http\Requests;

final class OperationsRosterRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['search' => ['sometimes', 'nullable', 'string', 'max:100'], 'kind' => ['sometimes', 'in:reservations,waiting'], 'status' => ['sometimes', 'in:all,active,cancelled'], 'page' => ['sometimes', 'integer', 'between:1,10000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }
}
