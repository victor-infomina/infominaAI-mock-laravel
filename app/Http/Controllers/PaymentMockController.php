<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Senangpay-protocol payment gateway mock.
 *
 * Flow mirrors the real hosted gateway closely enough for infominaAI-BE/FE:
 *   1. FE form-POSTs the Senangpay fields to POST /payment/{merchantId}.
 *   2. We render a page where the tester picks success / failed / pending.
 *   3. Submitting POSTs to /payment/{merchantId}/complete, which signs the
 *      callback params with the shared secret and 302s the browser to
 *      {return_url}/payment/result?status_id=&order_id=&transaction_id=&msg=&hash=
 *      exactly as Senangpay's return_url redirect does.
 *
 * Hash formula (both directions, see infominaAI-BE PaymentGatewayService):
 *   HMAC-SHA256(key, key + status_id + order_id + transaction_id + msg)
 */
class PaymentMockController extends Controller
{
    private const STATUS_MESSAGES = [
        '1' => 'Payment_was_successful',
        '0' => 'Payment_was_failed',
        '2' => 'Payment_was_successful_pending_authorization',
    ];

    public function generateHash(Request $request): JsonResponse
    {
        if (($unavailable = $this->secretUnavailable()) !== null) {
            return $unavailable;
        }

        $data = $request->validate([
            'statusId' => ['required', 'string'],
            'orderId' => ['required', 'string'],
            'transactionId' => ['required', 'string'],
            'msg' => ['nullable', 'string'],
        ]);

        return response()->json([
            'hash' => $this->hash($data['statusId'], $data['orderId'], $data['transactionId'], $data['msg'] ?? ''),
        ]);
    }

    public function page(Request $request, string $merchantId): View|JsonResponse
    {
        if (($unavailable = $this->secretUnavailable()) !== null) {
            return $unavailable;
        }

        return view('payment-mock', [
            'merchantId' => $merchantId,
            'orderId' => (string) $request->input('order_id', ''),
            'transactionId' => $this->newTransactionId(),
            'amount' => (string) $request->input('amount', ''),
            'detail' => (string) $request->input('detail', ''),
            'name' => (string) $request->input('name', ''),
            'email' => (string) $request->input('email', ''),
            'returnUrl' => (string) ($request->input('return_url') ?: config('services.senangpay.redirect_url', '')),
            'statuses' => self::STATUS_MESSAGES,
        ]);
    }

    public function complete(Request $request, string $merchantId): RedirectResponse|JsonResponse
    {
        if (($unavailable = $this->secretUnavailable()) !== null) {
            return $unavailable;
        }

        // Browser form POST: on invalid input Laravel would 302 "back" to the
        // POST-only page route (a 405 dead end), so answer 422 JSON instead.
        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'string'],
            'transaction_id' => ['required', 'string'],
            'status_id' => ['required', 'string', 'in:'.implode(',', array_keys(self::STATUS_MESSAGES))],
            'return_url' => ['required', 'url'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $data = $validator->validated();

        $msg = self::STATUS_MESSAGES[$data['status_id']];
        $query = http_build_query([
            'status_id' => $data['status_id'],
            'order_id' => $data['order_id'],
            'transaction_id' => $data['transaction_id'],
            'msg' => $msg,
            'hash' => $this->hash($data['status_id'], $data['order_id'], $data['transaction_id'], $msg),
        ]);

        return redirect()->away(rtrim($data['return_url'], '/').'/payment/result?'.$query);
    }

    private function hash(string $statusId, string $orderId, string $transactionId, string $msg): string
    {
        $key = (string) config('services.senangpay.secret_key');

        return hash_hmac('sha256', $key.$statusId.$orderId.$transactionId.$msg, $key);
    }

    private function secretUnavailable(): ?JsonResponse
    {
        if (filled(config('services.senangpay.secret_key'))) {
            return null;
        }

        return response()->json(
            ['error' => 'Payment mock is not configured: set SENANGPAY_SECRET_KEY in this app\'s .env.'],
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function newTransactionId(): string
    {
        // Senangpay transaction references are long numeric strings.
        return now()->format('YmdHis').Str::padLeft((string) random_int(0, 999999), 6, '0');
    }
}
