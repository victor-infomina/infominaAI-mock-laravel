<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SSM Search (mock) — Login</title>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body style="font-family: sans-serif; max-width: 40rem; margin: 3rem auto;">
    <h1>SSM Search mock login</h1>
    <p>
        This page exists so infominaAI-BE can detect the captcha sitekey and the admin can
        solve the widget. Credentials are submitted by the BE to <code>POST /ssm-gateway/user/login</code>, not here.
    </p>
    <form>
        <div class="g-recaptcha" data-sitekey="{{ $sitekey }}"></div>
    </form>
</body>
</html>
