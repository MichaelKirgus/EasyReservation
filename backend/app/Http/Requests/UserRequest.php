<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        $isCreate = $this->isMethod('post');

        $required = $isCreate ? 'required' : 'sometimes';
        $role = $this->input('role');

        $tokenRules = ['sometimes', 'string', 'max:255'];
        $tokenRules[] = $role === 'guest' ? 'min:1' : 'min:20';

        return [
            'name' => [$required, 'string', 'max:255'],
            'email' => [$required, 'email', 'max:255', 'unique:users,email'.($userId ? ','.$userId : '')],
            'role' => [$required, 'in:admin,moderator,user,guest,superadmin'],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'api_token' => $tokenRules,
            'api_token_is_hashed' => ['sometimes', 'boolean'],
        ];
    }
}
