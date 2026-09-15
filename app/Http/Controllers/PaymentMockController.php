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
 * Hash formulas (see infominaAI-BE PaymentGatewayService / FE PaymentUtils):
 *   submission (FE -> gateway): HMAC-SHA256(key, key + detail + amount + order_id)
 *   callback  (gateway -> BE):  HMAC-SHA256(key, key + status_id + order_id + transaction_id + msg)
 * The submission hash is checked like the real gateway does, but a mismatch
 * only shows a warning on the page so the tester can still drive the flow.
 *
 * Keys are per frontend host (config services.senangpay.keys, from
 * SENANGPAY_SECRET_KEYS): the frontend is identified from the return_url
 * field / Origin / Referer, its key signs everything, and a host without a
 * key is refused with 403 - so the key map is also the allowlist.
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
            'origin' => ['nullable', 'string'],
            'statusId' => ['required', 'string'],
            'orderId' => ['required', 'string'],
            'transactionId' => ['required', 'string'],
            'msg' => ['nullable', 'string'],
        ]);

        $origin = filled($data['origin'] ?? null) ? (string) $data['origin'] : $this->resolveReturnOrigin($request);
        if (($forbidden = $this->forbiddenUnlessAllowed($origin)) !== null) {
            return $forbidden;
        }

        return response()->json([
            'hash' => $this->hash($this->keyFor($origin), $data['statusId'], $data['orderId'], $data['transactionId'], $data['msg'] ?? ''),
        ]);
    }

    public function page(Request $request, string $merchantId): View|JsonResponse
    {
        if (($unavailable = $this->secretUnavailable()) !== null) {
            return $unavailable;
        }

        $returnUrl = $this->resolveReturnOrigin($request);
        if (($forbidden = $this->forbiddenUnlessAllowed($returnUrl)) !== null) {
            return $forbidden;
        }

        return view('payment-mock', [
            'submission' => $this->checkSubmissionHash($request, $this->keyFor($returnUrl)),
            'merchantId' => $merchantId,
            'orderId' => (string) $request->input('order_id', ''),
            'transactionId' => $this->newTransactionId(),
            'amount' => (string) $request->input('amount', ''),
            'detail' => (string) $request->input('detail', ''),
            'name' => (string) $request->input('name', ''),
            'email' => (string) $request->input('email', ''),
            'returnUrl' => $returnUrl,
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

        if (($forbidden = $this->forbiddenUnlessAllowed($data['return_url'])) !== null) {
            return $forbidden;
        }

        $msg = self::STATUS_MESSAGES[$data['status_id']];
        $query = http_build_query([
            'status_id' => $data['status_id'],
            'order_id' => $data['order_id'],
            'transaction_id' => $data['transaction_id'],
            'msg' => $msg,
            'hash' => $this->hash($this->keyFor($data['return_url']), $data['status_id'], $data['order_id'], $data['transaction_id'], $msg),
        ]);

        return redirect()->away(rtrim($data['return_url'], '/').'/payment/result?'.$query);
    }

    /**
     * Where to send the browser after "payment". Precedence: an explicit
     * return_url form field, then the frontend origin the browser reports on a
     * cross-origin form POST (Origin, else Referer trimmed to its origin), then
     * MOCK_REDIRECT_URL. Browsers send neither header on an HTTPS->HTTP
     * downgrade (Origin may be the literal "null"), hence the env fallback.
     */
    private function resolveReturnOrigin(Request $request): string
    {
        if (filled($request->input('return_url'))) {
            return (string) $request->input('return_url');
        }

        foreach ([$request->headers->get('Origin'), $request->headers->get('Referer')] as $candidate) {
            if ($origin = $this->originOf($candidate)) {
                return $origin;
            }
        }

        return (string) config('services.senangpay.redirect_url', '');
    }

    private function originOf(?string $url): ?string
    {
        if (blank($url) || $url === 'null') {
            return null;
        }

        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @return array{state: 'verified'|'mismatch'|'missing', expected: ?string} */
    private function checkSubmissionHash(Request $request, string $key): array
    {
        $given = (string) $request->input('hash', '');
        if ($given === '') {
            return ['state' => 'missing', 'expected' => null];
        }

        $expected = hash_hmac(
            'sha256',
            $key.$request->input('detail', '').$request->input('amount', '').$request->input('order_id', ''),
            $key,
        );

        return [
            'state' => hash_equals($expected, $given) ? 'verified' : 'mismatch',
            'expected' => $expected,
        ];
    }

    private function hash(string $key, string $statusId, string $orderId, string $transactionId, string $msg): string
    {
        return hash_hmac('sha256', $key.$statusId.$orderId.$transactionId.$msg, $key);
    }

    /** @return array<string, string> host => secret */
    private function keys(): array
    {
        return (array) config('services.senangpay.keys', []);
    }

    /** Lower-cased host of an origin/URL, or null when it has none. */
    private function hostOf(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function keyFor(string $origin): string
    {
        return $this->keys()[$this->hostOf($origin)] ?? '';
    }

    private function secretUnavailable(): ?JsonResponse
    {
        if ($this->keys() !== []) {
            return null;
        }

        return response()->json(
            ['error' => 'Payment mock is not configured: set SENANGPAY_SECRET_KEYS (host=secret;...) in this app\'s .env.'],
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /** 403 unless the frontend host has a configured key (the key map is the allowlist). */
    private function forbiddenUnlessAllowed(?string $origin): ?JsonResponse
    {
        $host = $this->hostOf($origin);
        if ($host !== null && isset($this->keys()[$host])) {
            return null;
        }

        return response()->json(
            [
                'error' => $host === null
                    ? 'Could not determine the frontend host (no return_url, Origin or Referer); the payment mock only serves configured hosts.'
                    : "Frontend host {$host} is not allowed to use the payment mock (no key configured for it).",
            ],
            Response::HTTP_FORBIDDEN,
        );
    }

    private function newTransactionId(): string
    {
        // Senangpay transaction references are long numeric strings.
        return now()->format('YmdHis').Str::padLeft((string) random_int(0, 999999), 6, '0');
    }
}
