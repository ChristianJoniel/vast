<?php

declare(strict_types=1);

namespace App\Http\Requests\Revenue;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportRevenueRequest extends FormRequest
{
    /**
     * The import endpoint is intentionally unauthenticated per the assignment.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The wire payload is a bare JSON array of revenue records — validate
     * each element with the `*.field` notation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            '*.location_id' => ['required', 'string', 'max:10'],
            '*.location_name' => ['required', 'string', 'max:255'],
            '*.machine_id' => ['required', 'string', 'max:10'],
            '*.cash_in' => ['required', 'numeric', 'min:0'],
            '*.voucher_in' => ['required', 'numeric', 'min:0'],
            '*.voucher_out' => ['required', 'numeric', 'min:0'],
            '*.net_revenue' => ['required', 'numeric'],
            '*.report_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
