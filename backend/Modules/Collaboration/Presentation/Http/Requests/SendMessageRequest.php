<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `type` now accepts image/file/voice (Task 3) alongside text (Task 2).
 * `mimes:` cross-checks the file's actual detected content type against the
 * expected type for the given extension — not a bare extension-name check
 * (brief §6/§7 — "do not trust file extension alone"), matching the one
 * existing precedent for this in the codebase
 * (`SupplierInvoiceDocumentController`'s upload rule) rather than inventing
 * a stricter or different convention for Collaboration alone.
 */
final class SendMessageRequest extends FormRequest
{
    private const IMAGE_MAX_KB = 10 * 1024;

    private const FILE_MAX_KB = 25 * 1024;

    private const VOICE_MAX_KB = 15 * 1024;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:text,image,file,voice'],
            'body' => ['required_if:type,text', 'nullable', 'string', 'max:10000'],
            'reply_to_message_id' => ['nullable', 'uuid', Rule::exists('collaboration_messages', 'id')],
            'mentioned_user_ids' => ['sometimes', 'array'],
            'mentioned_user_ids.*' => ['integer', Rule::exists('users', 'id')->whereNull('deleted_at')],

            'file' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('type'), ['image', 'file', 'voice'], true)),
                'nullable',
                'file',
                match ($this->input('type')) {
                    'image' => 'max:'.self::IMAGE_MAX_KB,
                    'voice' => 'max:'.self::VOICE_MAX_KB,
                    default => 'max:'.self::FILE_MAX_KB,
                },
                match ($this->input('type')) {
                    'image' => 'mimes:jpg,jpeg,png,webp,gif',
                    'voice' => 'mimes:webm,ogg,mp3,m4a,wav,mp4',
                    'file' => 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,zip,jpg,jpeg,png',
                    default => 'nullable',
                },
            ],
            'voice_duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3600'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'Message type must be one of: text, image, file, voice.',
            'file.required' => 'A file upload is required for this message type.',
        ];
    }
}
