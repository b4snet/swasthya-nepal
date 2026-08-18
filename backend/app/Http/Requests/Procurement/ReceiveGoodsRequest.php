<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\ApiRequest;

/**
 * POST /purchase-orders/{po}/goods-receipts — receive goods against PO
 * lines: one line per PO line with the received quantity and the received
 * unit price (the two facts the three-way match compares).
 */
class ReceiveGoodsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.poLineId' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unitPriceMinor' => ['required', 'integer', 'min:0'],
        ];
    }
}
