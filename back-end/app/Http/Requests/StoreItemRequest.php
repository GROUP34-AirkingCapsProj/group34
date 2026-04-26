<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreItemRequest extends FormRequest
{
    public function authorize()
    {
        return true; // Or check user permission
    }

    public function rules()
    {
        return [
            'subcategory_id' => 'required|exists:subcategory_lookup,subcategory_id',
            'brand_id' => 'required|exists:brand_lookup,brand_id',
            'model_id' => 'nullable|exists:model_lookup,model_id',
            'product_name' => 'required|max:255',
            'sku' => 'nullable|unique:items,sku|max:100',
            'description' => 'nullable',
            'unit_id' => 'required|exists:unit_lookup,unit_id',
            'standard_cost' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'barcode' => 'nullable|unique:items,barcode|max:100',
        ];
    }

    public function messages()
    {
        return [
            'product_name.required' => 'Product name is required',
            'brand_id.exists' => 'Selected brand does not exist',
            'sku.unique' => 'This SKU is already in use',
        ];
    }
}