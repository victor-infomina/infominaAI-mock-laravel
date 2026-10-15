<?php

namespace App\Http\Controllers\SsmGateway;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\SsmGatewayOrder;
use App\Services\SsmGateway\CartItemBuilder;
use App\Services\SsmGateway\CompanyMapper;
use App\Services\SsmGateway\ProductCatalogue;
use App\Services\SsmGatewayInvolvementRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(
        private readonly CompanyMapper $companies,
        private readonly ProductCatalogue $catalogue,
        private readonly SsmGatewayInvolvementRepository $involvements,
        private readonly CartItemBuilder $builder,
    ) {}

    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'integer'],
            'company_id' => ['required'],
            'personal_involvement' => ['nullable', 'boolean'],
        ]);

        $variant = $this->catalogue->findVariant((int) $validated['variant_id']);

        if ($variant === null) {
            return response()->json(['message' => 'Invalid variant'], 422);
        }

        $companyId = (string) $validated['company_id'];

        if ($request->boolean('personal_involvement')) {
            $idValue = SsmGatewayInvolvementRepository::idValueFromRef($companyId);
            $involvement = $idValue !== null ? $this->involvements->find($idValue) : null;

            if ($involvement === null) {
                return response()->json(['message' => 'Invalid selection reference'], 404);
            }

            $attributes = [
                'kind' => SsmGatewayOrder::KIND_INVOLVEMENT,
                'subject_key' => $involvement['key'],
                'reg_no' => $involvement['key'],
                'name' => (string) ($involvement['meta']['name'] ?? $involvement['key']),
            ];
        } else {
            $case = ctype_digit($companyId) ? $this->companies->findById((int) $companyId) : null;

            if ($case === null) {
                return response()->json(['message' => 'Company not found'], 404);
            }

            $attributes = [
                'kind' => SsmGatewayOrder::KIND_COMPANY,
                'subject_key' => $case['key'],
                'reg_no' => (string) ($case['meta']['regNo'] ?? ''),
                'name' => (string) ($case['meta']['companyName'] ?? ''),
            ];
        }

        $order = SsmGatewayOrder::create($attributes + [
            'api_client_id' => $this->clientId($request),
            'variant_id' => (int) $validated['variant_id'],
        ]);

        return response()->json([
            'isOk' => true,
            'cart' => $this->builder->cartItem($order),
            'cart_id' => $order->id,
        ]);
    }

    public function initCheckoutPage(Request $request): JsonResponse
    {
        $order = $this->orderFromCartIds($request);

        if ($order === null) {
            return response()->json(['message' => 'Cart not found'], 404);
        }

        /** @var ApiClient $client */
        $client = $request->attributes->get('ssm_gateway_client');

        return response()->json([
            'is_email_verified' => true,
            'cart' => $this->builder->cartSummary($order),
            'address' => [
                'full_name' => 'SSM Mock',
                'phone' => '0123456789',
                'email' => $client->key,
                'address1' => 'Level 1, Menara Mock',
                'address2' => null,
                'city' => 'Kuala Lumpur',
                'state_code' => 'W',
                'zipcode' => '50000',
                'country_code' => 'MAL',
                'country_label' => 'Malaysia',
                'state_label' => 'W.P. Kuala Lumpur',
            ],
        ]);
    }

    public function payment(Request $request): JsonResponse
    {
        $order = $this->orderFromCartIds($request);

        if ($order === null) {
            return response()->json(['message' => 'Cart not found'], 404);
        }

        if ($order->bill_id === null) {
            $order->update(['bill_id' => 'mockbill'.Str::lower(Str::random(12))]);
        }

        return response()->json([
            'skip_billplz_redirect_url' => '',
            'is_skip_billplz' => true,
            'bill_id' => $order->bill_id,
            'bill_url' => '',
        ]);
    }

    public function initThankYouPage(Request $request): JsonResponse
    {
        $validated = $request->validate(['billplz_id' => ['required', 'string']]);

        $order = $this->ordersFor($request)->where('bill_id', $validated['billplz_id'])->first();

        if ($order === null) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($order->ref_no === null) {
            $order->update(['ref_no' => 'ORDMOCK'.Str::upper(Str::random(8))]);
        }

        $paidAt = (string) $order->updated_at->getTimestampMs();

        return response()->json([
            'order_id' => $order->id,
            'ref_no' => $order->ref_no,
            'receipt_url' => '',
            'is_paid' => 1,
            'paid_amount' => CartItemBuilder::TOTAL,
            'paid_at' => $paidAt,
            'is_gateway_callback' => false,
            'detail' => $this->builder->orderDetail($order),
            'einvoiceStatus' => null,
            'iSparsFailed' => false,
        ]);
    }

    public function initOrderPage(Request $request): JsonResponse
    {
        $validated = $request->validate(['ref_no' => ['required', 'string']]);

        $order = $this->ordersFor($request)->where('ref_no', $validated['ref_no'])->first();

        if ($order === null) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $placedAt = (string) $order->created_at->getTimestampMs();

        return response()->json([
            'paid_amount' => CartItemBuilder::TOTAL,
            'is_paid' => 1,
            'order_id' => $order->id,
            'is_foc' => 0,
            'is_use_point' => 0,
            'ref_no' => $order->ref_no,
            'receipt_url' => '',
            'is_to_pay' => 0,
            'is_complete' => 1,
            'is_refund' => 0,
            'is_cancel' => 0,
            'created_at' => $order->created_at->toIso8601String(),
            'name' => 'SSM Mock',
            'cidp_org_id' => 10,
            'is_einvoice_expired' => 0,
            'placed_at' => $placedAt,
            'paid_at' => $placedAt,
            'detail' => $this->builder->orderDetail($order),
            'is_ispars_success' => 1,
        ]);
    }

    private function clientId(Request $request): int
    {
        return (int) $request->attributes->get('ssm_gateway_client_id');
    }

    private function ordersFor(Request $request)
    {
        return SsmGatewayOrder::where('api_client_id', $this->clientId($request));
    }

    private function orderFromCartIds(Request $request): ?SsmGatewayOrder
    {
        $validated = $request->validate([
            'cart_ids' => ['required', 'array', 'min:1'],
            'cart_ids.0' => ['required', 'integer'],
        ]);

        return $this->ordersFor($request)->find((int) $validated['cart_ids'][0]);
    }
}
