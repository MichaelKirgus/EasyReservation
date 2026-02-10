<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FormFieldRequest;
use App\Models\FormField;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class FormFieldController extends Controller
{
    private array $protectedKeys = ['name', 'email'];

    public function index(): JsonResponse
    {
        $this->ensureDefaults();
        $fields = FormField::query()->orderBy('order')->get();

        return response()->json($fields);
    }

    public function store(FormFieldRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (in_array($data['key'] ?? '', $this->protectedKeys, true)) {
            throw new BadRequestHttpException('Key is reserved.');
        }

        $field = FormField::create($data);

        return response()->json($field, 201);
    }

    public function update(FormFieldRequest $request, FormField $formField): JsonResponse
    {
        $data = $request->validated();

        if (in_array($formField->key, $this->protectedKeys, true)) {
            unset($data['key']);
        } elseif (isset($data['key']) && in_array($data['key'], $this->protectedKeys, true)) {
            throw new BadRequestHttpException('Key is reserved.');
        }

        $formField->update($data);

        return response()->json($formField);
    }

    public function destroy(FormField $formField): JsonResponse
    {
        if (in_array($formField->key, $this->protectedKeys, true)) {
            throw new BadRequestHttpException(__('form_field_reserved'));
        }

        $formField->delete();

        return response()->json(['message' => __('form_field_deleted')]);
    }

    private function ensureDefaults(): void
    {
        $defaults = [
            [
                'key' => 'name',
                'label' => 'Name',
                'type' => 'text',
                'required' => true,
                'order' => 0,
                'active' => true,
                'visible_public' => true,
                'visible_admin' => true,
                'is_email' => false,
                'options' => [],
                'placeholder' => null,
                'help_text' => null,
                'text_align' => 'left',
                'min_length' => null,
                'max_length' => null,
                'pattern' => null,
            ],
            [
                'key' => 'email',
                'label' => 'E-Mail',
                'type' => 'email',
                'required' => false,
                'order' => 1,
                'active' => true,
                'visible_public' => false,
                'visible_admin' => true,
                'is_email' => true,
                'options' => [],
                'placeholder' => null,
                'help_text' => null,
                'text_align' => 'left',
                'min_length' => null,
                'max_length' => null,
                'pattern' => null,
            ],
        ];

        foreach ($defaults as $field) {
            FormField::query()->firstOrCreate(['key' => $field['key']], $field);
        }
    }
}
