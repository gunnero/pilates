<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class LifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    /**
     * Reject unknown keys rather than silently accepting role/tenant tampering.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, __('lifecycle.unknown_field'));
            }
        }];
    }
}
