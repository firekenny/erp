<?php
declare(strict_types=1);
/* Microsoft (Entra ID) sign-in with OpenID Connect: authorization code flow + PKCE */

function sso_authority(): string { return defined('SSO_AUTHORITY') ? SSO_AUTHORITY : 'https://login.microsoftonline.com'; }
function sso_enabled(): bool {
    return !is_demo() && setting('sso_enabled') === '1' && setting('sso_tenant') !== '' && setting('sso_client_id') !== '' && setting('sso_secret') !== '';
}
function sso_redirect_uri(): string { return base_url() . '/sso_callback.php'; }
/** 'all' = everyone may still use a password; 'admins' = only administrators (emergency access) */
function sso_password_allowed(array $u): bool {
    if (!sso_enabled() || setting('sso_password_mode', 'all') === 'all') return true;
    return in_array('admin', array_map('trim', explode(',', (string)$u['roles'])), true);
}
function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function sso_start_url(): string {
    $state = bin2hex(random_bytes(16)); $nonce = bin2hex(random_bytes(16)); $verifier = b64url(random_bytes(32));
    $_SESSION['sso'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'at' => time()];
    return sso_authority() . '/' . rawurlencode(setting('sso_tenant')) . '/oauth2/v2.0/authorize?' . http_build_query([
        'client_id' => setting('sso_client_id'), 'response_type' => 'code', 'redirect_uri' => sso_redirect_uri(), 'response_mode' => 'query',
        'scope' => 'openid profile email', 'state' => $state, 'nonce' => $nonce,
        'code_challenge' => b64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256', 'prompt' => 'select_account',
    ]);
}

/** Exchange the code and check the ID token. Returns the claims, or throws with a plain message. */
function sso_finish(string $code, string $state): array {
    $s = $_SESSION['sso'] ?? null; unset($_SESSION['sso']);
    if (!$s || !hash_equals($s['state'], $state) || time() - $s['at'] > 900) throw new RuntimeException('The sign-in took too long or was started elsewhere. Please try again.');
    $ch = curl_init(sso_authority() . '/' . rawurlencode(setting('sso_tenant')) . '/oauth2/v2.0/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query(['client_id' => setting('sso_client_id'), 'client_secret' => setting('sso_secret'), 'grant_type' => 'authorization_code',
            'code' => $code, 'redirect_uri' => sso_redirect_uri(), 'code_verifier' => $s['verifier'], 'scope' => 'openid profile email'])]);
    $body = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($body === false) throw new RuntimeException('The server could not reach Microsoft: ' . $err);
    $res = json_decode((string)$body, true) ?: [];
    if (empty($res['id_token'])) throw new RuntimeException('Microsoft refused the sign-in: ' . ($res['error_description'] ?? $res['error'] ?? 'no token received') );
    $parts = explode('.', $res['id_token']);
    if (count($parts) !== 3) throw new RuntimeException('Invalid token received.');
    // The token comes straight from Microsoft over HTTPS in exchange for our secret, so its origin is proven (OpenID Connect §3.1.3.7); we check its content.
    $c = json_decode(b64url_dec($parts[1]), true) ?: [];
    $tid = setting('sso_tenant');
    if (($c['aud'] ?? '') !== setting('sso_client_id')) throw new RuntimeException('Token not issued for this application.');
    if (strcasecmp((string)($c['tid'] ?? ''), $tid) !== 0) throw new RuntimeException('This Microsoft account belongs to another organisation.');
    if (($c['iss'] ?? '') !== sso_authority() . '/' . ($c['tid'] ?? '') . '/v2.0') throw new RuntimeException('Unexpected token issuer.');
    if (!hash_equals($s['nonce'], (string)($c['nonce'] ?? ''))) throw new RuntimeException('Sign-in could not be verified. Please try again.');
    if ((int)($c['exp'] ?? 0) < time() - 60) throw new RuntimeException('The sign-in expired. Please try again.');
    return $c;
}
