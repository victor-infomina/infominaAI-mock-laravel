<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApiClientController extends Controller
{
    public function index(): View
    {
        return view('tokens', [
            'apiClients' => ApiClient::orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['nullable', Rule::in(['', '30', '90', '180', '365'])],
        ]);

        $expiresInDays = ($validated['expires_in_days'] ?? '') !== ''
            ? (int) $validated['expires_in_days']
            : null;

        [$apiClient, $secret] = ApiClient::createWithSecret($validated['label'], $expiresInDays);

        return redirect()->route('tokens.index')->with([
            'generatedKey' => $apiClient->key,
            'generatedSecret' => $secret,
        ]);
    }

    public function revoke(ApiClient $apiClient): RedirectResponse
    {
        $apiClient->update(['revoked_at' => now()]);

        return redirect()->route('tokens.index');
    }
}
