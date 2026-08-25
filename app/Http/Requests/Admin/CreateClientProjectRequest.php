<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateClientProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin && $admin->canCreateClientProject();
    }

    public function rules(): array
    {
        return [
            'client_name' => ['required', 'string', 'max:160'],
            'client_slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('clients', 'slug')],
            'project_name' => ['required', 'string', 'max:160'],
            'project_slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('client_projects', 'slug')],
        ];
    }
}
