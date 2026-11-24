<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkImportVaultRequest extends FormRequest
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
            'vault_codes_text' => ['nullable', 'string'],
            'vault_lines' => ['nullable', 'string'],
            'vault_codes_file' => ['nullable', 'file', 'mimes:txt,csv', 'max:512'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hasText = trim((string) ($this->input('vault_codes_text') ?? $this->input('vault_lines') ?? '')) !== '';
                $hasFile = $this->hasFile('vault_codes_file');

                if (! $hasText && ! $hasFile) {
                    $validator->errors()->add(
                        'vault_codes_text',
                        'Masukkan daftar kode brankas pada kolom teks atau unggah file .txt/.csv.',
                    );
                }
            },
        ];
    }

    /**
     * Extract raw lines from either uploaded .txt/.csv file or multiline textarea input.
     *
     * @return list<string>
     */
    public function extractRawLines(): array
    {
        if ($this->hasFile('vault_codes_file')) {
            $file = $this->file('vault_codes_file');
            $realPath = $file?->getRealPath();
            $rawContent = is_string($realPath) && $realPath !== ''
                ? (string) file_get_contents($realPath)
                : '';

            /** @var list<string> $fileLines */
            $fileLines = preg_split('/\r\n|\r|\n/', $rawContent) ?: [];

            return $fileLines;
        }

        $rawText = (string) ($this->input('vault_codes_text') ?? $this->input('vault_lines') ?? '');

        /** @var list<string> $textLines */
        $textLines = preg_split('/\r\n|\r|\n/', $rawText) ?: [];

        return $textLines;
    }
}
