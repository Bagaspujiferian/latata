<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectWithdrawalRequest extends FormRequest
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
            'rejection_reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }
}
