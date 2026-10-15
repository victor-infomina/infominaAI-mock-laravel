<?php

namespace App\Services\SsmGateway;

use App\Models\SsmGatewayOrder;
use App\Services\SsmGatewayInvolvementRepository;

class CartItemBuilder
{
    public const SST_RATE = 6;

    public const SUBTOTAL = 15;

    public const SERVICE = 2;

    public const SST = 0.9;

    public const TOTAL = 17.9;

    public function __construct(
        private readonly CompanyMapper $companies,
        private readonly ProductCatalogue $catalogue,
        private readonly SsmGatewayInvolvementRepository $involvements,
    ) {}

    public function isReportReady(SsmGatewayOrder $order): bool
    {
        $delay = (int) config('ssm_gateway_mock.report_delay_seconds');

        return $order->created_at === null || $order->created_at->copy()->addSeconds($delay)->lte(now());
    }

    public function downloadUrl(SsmGatewayOrder $order): ?string
    {
        if (! $this->isReportReady($order)) {
            return null;
        }

        if ($order->isInvolvement()) {
            $involvement = $this->involvements->find($order->subject_key);

            return $involvement ? $this->involvements->reportUrl($involvement) : null;
        }

        $case = $this->companies->findBySlug($order->subject_key);

        return $case ? $this->companies->reportUrl($case) : null;
    }

    public function cartItem(SsmGatewayOrder $order): array
    {
        $variant = $this->catalogue->findVariant($order->variant_id) ?? [
            'product_id' => 0, 'product_name' => 'Unknown', 'variant_id' => $order->variant_id, 'lang' => 'en', 'is_ctc' => 0,
        ];

        if ($order->isInvolvement()) {
            $involvement = $this->involvements->find($order->subject_key);
            $meta = $involvement['meta'] ?? [];
            $ref = SsmGatewayInvolvementRepository::selectionRefNo($order->subject_key);
            $comId = $ref;
            $comSlug = null;
            $entityType = null;
            $companyId = 0;
            $relationships = ['personal_involvement' => [
                'id' => $order->id,
                'user_id' => 1,
                'id_type' => (string) ($meta['idType'] ?? ''),
                'id_value' => $order->subject_key,
                'selection_ref_no' => $ref,
                'created_at' => optional($order->created_at)->toIso8601String(),
                'label' => $order->name,
            ]];
        } else {
            $case = $this->companies->findBySlug($order->subject_key);
            $companyId = $this->companies->idFor($order->subject_key);
            $comId = (string) $companyId;
            $comSlug = $order->subject_key;
            $entityType = $case ? $this->companies->entityTypeCode($case) : null;
            $relationships = null;
        }

        return [
            'com_id' => $comId,
            'com_slug' => $comSlug,
            'com_name' => $order->name,
            'com_reg_no' => $order->reg_no,
            'com_reg_no_old' => $order->reg_no,
            'relationships' => $relationships,
            'entity_type' => $entityType,
            'children' => [[
                'product_id' => $variant['product_id'],
                'product_name' => $variant['product_name'],
                'variant_id' => $order->variant_id,
                'cart_id' => $order->id,
                'lang' => $variant['lang'],
                'company_id' => $companyId,
                'is_ctc' => $variant['is_ctc'],
                'is_active' => 1,
                'itemSubtotalAmount' => self::SUBTOTAL,
                'sstAmount' => self::SST,
                'serviceAmount' => self::SERVICE,
                'amount' => self::TOTAL,
                'itemOriginalBasePrice' => self::SUBTOTAL,
                'download_url' => $this->downloadUrl($order),
                'gen_error' => null,
                'sample_url' => '[]',
                'amountDetail' => [
                    'price' => ['amount' => self::SUBTOTAL, 'adjustment_type' => '-', 'adjustment_amount' => 0],
                    'service' => ['amount' => self::SERVICE, 'adjustment_type' => '-', 'adjustment_amount' => 0],
                    'sst' => ['amount' => self::SST],
                ],
            ]],
        ];
    }

    public function cartSummary(SsmGatewayOrder $order): array
    {
        return [
            'sstRate' => self::SST_RATE,
            'subtotalAmount' => self::SUBTOTAL,
            'serviceAmount' => self::SERVICE,
            'sstAmount' => self::SST,
            'finalAmount' => self::TOTAL,
            'plusAmountAfterTax' => 0,
            'plusAmountBeforeTax' => 0,
            'minusAmountAfterTax' => 0,
            'minusAmountBeforeTax' => 0,
            'count' => 1,
            'items' => [$this->cartItem($order)],
        ];
    }

    public function orderDetail(SsmGatewayOrder $order): array
    {
        return $this->cartSummary($order) + [
            'fee_map' => [
                'price' => ['label' => 'SSM Price', 'is_waived' => 0],
                'service' => ['label' => 'Service Fee', 'is_waived' => 0],
                'ctc' => ['label' => 'CTC Fee', 'is_waived' => 0],
            ],
            'order_id' => $order->id,
        ];
    }
}
