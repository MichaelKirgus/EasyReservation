<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'capacity_override' => ['nullable', 'integer', 'min:0'],
            'active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
            'auto_close_minutes_before' => ['nullable', 'integer', 'min:0'],
            'auto_email_template_id' => ['nullable', 'integer', 'min:1'],
            'auto_email_offset_minutes_before' => ['nullable', 'integer', 'min:0'],
            'auto_email_sent_at' => ['nullable', 'date'],
        ];
    }
}
