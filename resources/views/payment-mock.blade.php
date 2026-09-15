<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mock Payment (infominaAI-ssm-mock)</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
        .card { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.1); width: 100%; max-width: 440px; }
        h1 { margin: 0 0 .25rem; font-size: 1.25rem; }
        .banner { color: #b45309; background: #fef3c7; padding: .5rem .75rem; border-radius: 4px; font-size: .85rem; margin-bottom: 1rem; }
        .ok { color: #166534; background: #dcfce7; }
        .bad { color: #991b1b; background: #fee2e2; }
        .bad code { display: block; margin-top: .25rem; font-size: .75rem; word-break: break-all; }
        dl { background: #f9f9f9; padding: 1rem; border-radius: 4px; font-size: .9rem; margin: 0 0 1.25rem; display: grid; grid-template-columns: auto 1fr; gap: .25rem .75rem; }
        dt { color: #666; } dd { margin: 0; word-break: break-all; }
        label { display: block; font-weight: 500; margin: .75rem 0 .35rem; color: #333; }
        select, input[type=text], input[type=url] { width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        button { margin-top: 1.25rem; width: 100%; background: #4CAF50; color: #fff; padding: 12px; font-size: 16px; border: 0; border-radius: 4px; cursor: pointer; }
        button:hover { background: #45a049; }
    </style>
</head>
<body>
<div class="card">
    <h1>Mock Payment Gateway</h1>
    <div class="banner">Sandbox only. No money moves. Merchant {{ $merchantId }}.</div>

    @if ($submission['state'] === 'verified')
        <div class="banner ok">Submission hash verified.</div>
    @elseif ($submission['state'] === 'mismatch')
        <div class="banner bad">
            Submission hash mismatch: the real Senangpay would reject this request.
            Check the FE's hash inputs (detail, amount, order_id) and that both sides share the same secret key.
            <code>expected {{ $submission['expected'] }}</code>
        </div>
    @else
        <div class="banner bad">Submission hash missing: the real Senangpay would reject this request.</div>
    @endif

    <dl>
        <dt>Order ID</dt><dd>{{ $orderId }}</dd>
        <dt>Amount</dt><dd>RM {{ $amount }}</dd>
        <dt>Detail</dt><dd>{{ $detail }}</dd>
        <dt>Name</dt><dd>{{ $name }}</dd>
        <dt>Email</dt><dd>{{ $email }}</dd>
        <dt>Transaction ID</dt><dd>{{ $transactionId }}</dd>
    </dl>

    <form method="POST" action="{{ url("/payment/{$merchantId}/complete") }}">
        <input type="hidden" name="order_id" value="{{ $orderId }}">
        <input type="hidden" name="transaction_id" value="{{ $transactionId }}">

        <label for="status_id">Payment outcome</label>
        <select id="status_id" name="status_id">
            @foreach ($statuses as $statusId => $msg)
                <option value="{{ $statusId }}" @selected((string) $statusId === '1')>{{ $statusId }} - {{ str_replace('_', ' ', $msg) }}</option>
            @endforeach
        </select>

        <label for="return_url">Return to (frontend origin)</label>
        <input type="url" id="return_url" name="return_url" value="{{ $returnUrl }}" placeholder="http://localhost:4300" required>

        <button type="submit">Pay Now</button>
    </form>
</div>
</body>
</html>
