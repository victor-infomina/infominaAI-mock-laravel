<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SsmGatewayOrder extends Model
{
    public const KIND_COMPANY = 'company';

    public const KIND_INVOLVEMENT = 'involvement';

    protected $table = 'ssm_gateway_order';

    protected $fillable = [
        'api_client_id',
        'kind',
        'subject_key',
        'reg_no',
        'name',
        'variant_id',
        'bill_id',
        'ref_no',
    ];

    protected function casts(): array
    {
        return [
            'variant_id' => 'integer',
            'api_client_id' => 'integer',
        ];
    }

    public function isInvolvement(): bool
    {
        return $this->kind === self::KIND_INVOLVEMENT;
    }
}
