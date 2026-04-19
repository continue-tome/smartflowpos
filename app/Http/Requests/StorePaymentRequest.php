<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasPermission('payments.create'); }

    public function rules(): array
    {
        return [
            'order_id'     => 'required|exists:orders,id',
            'amount'       => 'required|numeric|min:0.01',
            'method'       => 'required|in:cash,card,wave,orange_money,momo,other',
            'reference'    => 'nullable|string|max:100',
            'amount_given' => 'nullable|numeric|min:0',
        ];
    }
}
