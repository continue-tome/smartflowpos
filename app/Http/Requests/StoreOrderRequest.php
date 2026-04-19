<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasPermission('orders.create'); }

    public function rules(): array
    {
        return [
            'table_id'               => 'nullable|exists:tables,id',
            'type'                   => 'required|in:dine_in,takeaway,delivery',
            'covers'                 => 'integer|min:1|max:50',
            'notes'                  => 'nullable|string|max:500',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1|max:99',
            'items.*.notes'          => 'nullable|string|max:300',
            'items.*.course'         => 'integer|min:1|max:5',
            'items.*.modifier_ids'   => 'nullable|array',
            'items.*.modifier_ids.*' => 'exists:modifiers,id',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'            => 'La commande doit contenir au moins un article.',
            'items.*.product_id.exists' => 'Produit introuvable.',
            'items.*.quantity.min'      => 'La quantité minimum est 1.',
        ];
    }
}
