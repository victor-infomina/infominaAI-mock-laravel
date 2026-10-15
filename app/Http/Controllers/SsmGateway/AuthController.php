<?php

namespace App\Http\Controllers\SsmGateway;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function loginPage(): View
    {
        return view('ssm-gateway.login', [
            'sitekey' => config('ssm_gateway_mock.recaptcha_sitekey'),
        ]);
    }
}
