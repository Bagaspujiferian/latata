<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BindPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:9', 'max:22'],
        ];
    }
}
