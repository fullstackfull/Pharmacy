<?php

namespace Modules\AI\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AISettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'api_key' => ['nullable', 'required_if:status,1', 'string'],
            'organization_id' => ['nullable', 'required_if:status,1', 'string'],
            // Which model writes seller content, and how creative it may be. Bounded because a
            // temperature outside 0-2 is refused by the provider anyway, and a rejected call is a
            // worse answer than a refused form.
            'model' => ['nullable', 'string', 'max:96'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
        ];
    }

    public function messages(): array
    {
        return [
            'api_key.required_if' => translate('The API Key is required when status is enabled.'),
            'organization_id.required_if' => translate('The Organization ID is required when status is enabled.'),
        ];
    }
}
