<?php

namespace App\Http\Requests;

use App\Support\StudioSettings;
use Closure;
use Illuminate\Validation\ValidationException;

final class StudioSettingsRequest extends LifecycleRequest
{
    protected function prepareForValidation(): void
    {
        abort_unless($this->isJson(), 415);
        abort_if(strlen($this->getContent()) > 4096, 413);
        // Laravel converts empty strings to null; only an explicit JSON null may reset a setting.
        abort_unless(json_decode($this->getContent()) instanceof \stdClass, 422);
        $raw = json_decode($this->getContent(), true);
        foreach (StudioSettings::catalog() as $key => $definition) {
            if (is_array($raw) && array_key_exists($key, $raw) && $raw[$key] !== null) {
                $value = $raw[$key];
                if ($definition['type'] === 'boolean' ? ! is_bool($value) : ! is_int($value)) {
                    throw ValidationException::withMessages([$key => __('settings.invalid')]);
                }
            }
        }
    }

    public function rules(): array
    {
        $rules = [];
        foreach (StudioSettings::catalog() as $key => $definition) {
            $rules[$key] = ['sometimes', 'nullable', function (string $attribute, mixed $value, Closure $fail) use ($definition): void {
                if ($definition['type'] === 'boolean' ? ! is_bool($value) : (! is_int($value) || $value < $definition['min'] || $value > $definition['max'])) {
                    $fail(__('settings.invalid'));
                }
            }];
        }

        return $rules;
    }
}
