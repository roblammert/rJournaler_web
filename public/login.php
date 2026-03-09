<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Auth\Auth;
use App\Auth\LoginService;
use App\Auth\UserRepository;
use App\Core\Database;
use App\Security\AuditLogger;
use App\Security\Crypto;
use App\Security\Csrf;
use App\Security\Totp;
use App\Security\TrustedDeviceManager;

$error = null;
$step = 'credentials';
$appVersion = (string) ($config['version'] ?? '1.0.3');

if (Auth::check()) {
    header('Location: /index.php');
    exit;
}

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    unset($_SESSION['pending_auth']);
}

/**
 * TOTP secret supports encrypted `enc:` values and legacy plain/base32 migration values.
 */
$normalizeTotpSecret = static function (string $raw): string {
    $secret = trim($raw);
    if (str_starts_with($secret, 'plain:')) {
        $secret = substr($secret, 6);
    }
    if (str_starts_with($secret, 'base32:')) {
        $secret = substr($secret, 7);
    }

    return strtoupper(trim($secret));
};

$resolveTotpSecret = static function (string $storedValue) use ($normalizeTotpSecret): string {
    $appKey = (string) env('APP_KEY', '');
    $decrypted = Crypto::decrypt($storedValue, $appKey);
    if (is_string($decrypted) && $decrypted !== '') {
        return $normalizeTotpSecret($decrypted);
    }

    return $normalizeTotpSecret($storedValue);
};

$ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$auditLogger = null;

try {
    $pdo = Database::connection($config['database']);
    $userRepository = new UserRepository($pdo);
    $loginService = new LoginService($pdo);
    $trustedDevices = new TrustedDeviceManager($pdo, $config);
    $auditLogger = new AuditLogger($pdo);
} catch (Throwable $throwable) {
    http_response_code(500);
    $error = 'Authentication service is unavailable.';
}

if (isset($_SESSION['pending_auth']) && is_array($_SESSION['pending_auth'])) {
    $step = 'totp';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    $csrf = $_POST['_csrf'] ?? null;
    if (!Csrf::validate(is_string($csrf) ? $csrf : null)) {
        $error = 'Invalid request token. Please try again.';
        if ($auditLogger instanceof AuditLogger) {
            $auditLogger->log(null, 'auth.csrf.invalid', ['route' => 'login']);
        }
    } else {
        $action = (string) ($_POST['action'] ?? 'credentials');

        if ($action === 'credentials') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $rememberDevice = isset($_POST['remember_device']) && $_POST['remember_device'] === '1';

            if ($username === '' || $password === '') {
                $error = 'Username and password are required.';
            } elseif ($loginService->isRateLimited($username, $ipAddress)) {
                $error = 'Too many login attempts. Please wait and try again.';
                if ($auditLogger instanceof AuditLogger) {
                    $auditLogger->log(null, 'auth.login.rate_limited', ['username' => $username]);
                }
            } else {
                $user = $userRepository->findActiveByUsername($username);
                $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
                $passwordHash = is_array($user) ? (string) ($user['password_hash'] ?? '') : '';
                $isAdmin = is_array($user) ? ((int) ($user['is_admin'] ?? 0) === 1) : false;
                $displayName = is_array($user) ? (string) ($user['display_name'] ?? '') : '';
                $timezonePreference = is_array($user) ? (string) ($user['timezone_preference'] ?? '') : '';
                $interfaceTheme = is_array($user) ? (string) ($user['interface_theme'] ?? '') : '';

                if ($userId <= 0 || $passwordHash === '' || !password_verify($password, $passwordHash)) {
                    $loginService->recordAttempt($username, $ipAddress, false);
                    $error = 'Invalid credentials.';
                    if ($auditLogger instanceof AuditLogger) {
                        $auditLogger->log(null, 'auth.login.invalid_credentials', ['username' => $username]);
                    }
                } elseif ($trustedDevices->hasValidToken($userId)) {
                    $loginService->recordAttempt($username, $ipAddress, true);
                    if ($auditLogger instanceof AuditLogger) {
                        $auditLogger->log($userId, 'auth.login.trusted_device_bypass', ['username' => $username]);
                    }
                    Auth::loginAs(
                        $userId,
                        $isAdmin,
                        $displayName !== '' ? $displayName : $username,
                        $timezonePreference !== '' ? $timezonePreference : null,
                        $interfaceTheme !== '' ? $interfaceTheme : null,
                    );
                    header('Location: /index.php');
                    exit;
                } else {
                    $rawSecret = (string) ($user['totp_secret_encrypted'] ?? '');
                    $totpSecret = $resolveTotpSecret($rawSecret);

                    if ($totpSecret === '') {
                        $error = 'MFA is not configured for this account. Contact an administrator.';
                        if ($auditLogger instanceof AuditLogger) {
                            $auditLogger->log($userId, 'auth.login.mfa_not_configured', ['username' => $username]);
                        }
                    } else {
                        if ($auditLogger instanceof AuditLogger) {
                            $auditLogger->log($userId, 'auth.login.password_ok_totp_required', ['username' => $username]);
                        }
                        $_SESSION['pending_auth'] = [
                            'user_id' => $userId,
                            'username' => $username,
                            'totp_secret' => $totpSecret,
                            'remember_device' => $rememberDevice,
                        ];
                        $step = 'totp';
                    }
                }
            }
        } elseif ($action === 'totp') {
            $pending = $_SESSION['pending_auth'] ?? null;
            $code = (string) ($_POST['totp_code'] ?? '');

            if (!is_array($pending)) {
                $error = 'Session expired. Please log in again.';
                $step = 'credentials';
            } else {
                $pendingUserId = (int) ($pending['user_id'] ?? 0);
                $pendingUsername = (string) ($pending['username'] ?? '');
                $pendingSecret = (string) ($pending['totp_secret'] ?? '');
                $rememberDevice = (bool) ($pending['remember_device'] ?? false);

                if ($pendingUserId <= 0 || $pendingSecret === '' || !Totp::verify($pendingSecret, $code)) {
                    if ($pendingUsername !== '') {
                        $loginService->recordAttempt($pendingUsername, $ipAddress, false);
                    }
                    $error = 'Invalid authentication code.';
                    $step = 'totp';
                    if ($auditLogger instanceof AuditLogger) {
                        $auditLogger->log($pendingUserId > 0 ? $pendingUserId : null, 'auth.login.totp_invalid', ['username' => $pendingUsername]);
                    }
                } else {
                    $loginService->recordAttempt($pendingUsername, $ipAddress, true);
                    if ($rememberDevice) {
                        $trustedDevices->issueToken($pendingUserId, (int) $config['security']['trusted_device_days']);
                    }

                    if ($auditLogger instanceof AuditLogger) {
                        $auditLogger->log($pendingUserId, 'auth.login.success', ['username' => $pendingUsername, 'remember_device' => $rememberDevice]);
                    }

                    unset($_SESSION['pending_auth']);
                    $userData = $userRepository->findActiveByUsername($pendingUsername);
                    $isAdmin = is_array($userData) ? ((int) ($userData['is_admin'] ?? 0) === 1) : false;
                    $displayName = is_array($userData) ? (string) ($userData['display_name'] ?? '') : '';
                    $timezonePreference = is_array($userData) ? (string) ($userData['timezone_preference'] ?? '') : '';
                    $interfaceTheme = is_array($userData) ? (string) ($userData['interface_theme'] ?? '') : '';

                    Auth::loginAs(
                        $pendingUserId,
                        $isAdmin,
                        $displayName !== '' ? $displayName : $pendingUsername,
                        $timezonePreference !== '' ? $timezonePreference : null,
                        $interfaceTheme !== '' ? $interfaceTheme : null,
                    );
                    header('Location: /index.php');
                    exit;
                }
            }
        }
    }
}

$pending = $_SESSION['pending_auth'] ?? null;
if (!is_array($pending)) {
    $step = 'credentials';
}

$token = Csrf::token();
$interfaceTheme = strtolower(trim((string) ($_SESSION['interface_theme'] ?? 'neutral')));
if (!in_array($interfaceTheme, ['light', 'neutral', 'dark'], true)) {
    $interfaceTheme = 'neutral';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>rJournaler Web - Login</title>
    <style>
        :root {
            --font-ui: "Segoe UI", "Aptos", "Trebuchet MS", sans-serif;
            --radius-md: 10px;
            --shadow-sm: 0 4px 12px rgba(17, 24, 39, 0.08);
            --shadow-md: 0 12px 28px rgba(17, 24, 39, 0.14);
        }

        body[data-theme="light"] {
            --bg: #f7f9fc;
            --bg-accent: #edf3fb;
            --surface: #ffffff;
            --surface-soft: #f7faff;
            --border: #d6e0ef;
            --text: #1f2f43;
            --text-muted: #5b6f87;
            --heading: #152334;
            --link: #1f5f9a;
            --danger-bg: #fdeef0;
            --danger-border: #d78a95;
            --danger-text: #842533;
            --control-btn-bg: #e8eef6;
            --control-btn-border: #9aabc2;
            --control-btn-text: #334b63;
            --input-bg: #ffffff;
        }

        body[data-theme="neutral"] {
            --bg: #f4f3f1;
            --bg-accent: #eceae6;
            --surface: #fffdf8;
            --surface-soft: #f4f1ea;
            --border: #d8d1c5;
            --text: #2f3432;
            --text-muted: #676d69;
            --heading: #222827;
            --link: #3c5f72;
            --danger-bg: #f8ecec;
            --danger-border: #b88c8c;
            --danger-text: #6f3232;
            --control-btn-bg: #ebeae6;
            --control-btn-border: #aeb1ac;
            --control-btn-text: #3f4944;
            --input-bg: #fffefb;
        }

        body[data-theme="dark"] {
            --bg: #171d23;
            --bg-accent: #1e2730;
            --surface: #222d38;
            --surface-soft: #263340;
            --border: #364757;
            --text: #dbe4ec;
            --text-muted: #98a9b9;
            --heading: #f1f5f8;
            --link: #8fc3f3;
            --danger-bg: #41262b;
            --danger-border: #91535f;
            --danger-text: #f0b5bf;
            --control-btn-bg: #3a4754;
            --control-btn-border: #6f8193;
            --control-btn-text: #dde6ef;
            --input-bg: #1e2832;
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
            --shadow-md: 0 14px 32px rgba(0, 0, 0, 0.35);
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--font-ui);
            background: radial-gradient(circle at 18% 0%, var(--bg-accent), var(--bg) 38%);
            color: var(--text);
            display: grid;
            place-items: start center;
            padding: 1rem;
        }

        main {
            width: min(520px, 100%);
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface);
            box-shadow: var(--shadow-md);
            padding: 0.9rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 0.6rem;
        }

        .page-header h1 {
            margin: 0;
            color: var(--heading);
            font-size: 1.1rem;
        }

        .header-links {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            flex-wrap: wrap;
        }

        .pill {
            display: inline-block;
            padding: 0.12rem 0.52rem;
            border-radius: 999px;
            font-size: 0.82rem;
            border: 1px solid var(--border);
            background: var(--surface-soft);
            color: var(--text);
            box-shadow: var(--shadow-sm);
        }

        .muted { color: var(--text-muted); }
        .error {
            color: var(--danger-text);
            border: 1px solid var(--danger-border);
            background: var(--danger-bg);
            border-radius: 8px;
            padding: 0.45rem 0.55rem;
        }

        form {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-soft);
            padding: 0.72rem;
            display: grid;
            gap: 0.52rem;
        }

        label {
            color: var(--text-muted);
            font-size: 0.86rem;
            font-weight: 600;
        }

        input,
        button {
            font: inherit;
        }

        input[type="text"],
        input[type="password"],
        input[inputmode="numeric"] {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text);
            padding: 0.46rem 0.52rem;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            color: var(--text-muted);
            font-size: 0.86rem;
        }

        button {
            border: 1px solid var(--control-btn-border);
            border-radius: 999px;
            background: var(--control-btn-bg);
            color: var(--control-btn-text);
            font-weight: 600;
            cursor: pointer;
            padding: 0.36rem 0.75rem;
            width: fit-content;
        }

        button:hover { filter: brightness(1.05); }

        a {
            color: var(--link);
            text-decoration: none;
        }

        a:hover { text-decoration: underline; }

        @media (max-width: 560px) {
            body { padding: 0.72rem; }
            main { padding: 0.75rem; }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
    <main>
        <header class="page-header">
            <h1>rJournaler Web Login</h1>
            <div class="header-links">
                <span class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="pill">Step: <?php echo htmlspecialchars($step === 'credentials' ? 'Credentials' : 'MFA', ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </header>
        <?php if ($error !== null): ?>
            <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if ($step === 'credentials'): ?>
            <form method="post" action="/login.php">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="credentials">

                <label for="username">Username</label>
                <input id="username" name="username" type="text" required autofocus>

                <label for="password">Password</label>
                <input id="password" name="password" type="password" required>

                <label class="checkbox-row">
                    <input type="checkbox" name="remember_device" value="1">
                    Trust this computer for 30 days
                </label>

                <button type="submit">Continue</button>
            </form>
        <?php else: ?>
            <p class="muted">Password verified. Enter the 6-digit code from Google Authenticator.</p>
            <form method="post" action="/login.php">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="totp">

                <label for="totp_code">Authentication Code</label>
                <input id="totp_code" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus>

                <button type="submit">Log in</button>
            </form>
            <p class="muted"><a href="/login.php?reset=1">Start over</a></p>
        <?php endif; ?>
    </main>
</body>
</html>
