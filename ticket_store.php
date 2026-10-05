<?php

const TICKET_STATUSES = ['Open', 'In Progress', 'Waiting on requester', 'Resolved', 'Closed'];
const TICKET_PRIORITIES = ['Low', 'Normal', 'High'];
const TICKET_CATEGORIES = ['Access & accounts', 'Applications & software', 'Hardware & devices', 'Network & connectivity', 'Email & collaboration', 'Security & privacy', 'Facilities & equipment', 'Finance & procurement', 'Teaching & learning', 'Production & operations', 'Other'];
const USER_ROLES = ['user', 'admin', 'super_admin'];
const USER_ROLE_LABELS = ['user' => 'Regular user', 'admin' => 'Administrator', 'super_admin' => 'Super administrator'];
const STORE_FILES = ['tickets', 'users', 'settings', 'audit'];

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('MASIXITSUPPORT');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function store_directory(): string
{
    $configured = getenv('MASIX_DATA_DIR');
    if ($configured !== false && $configured !== '') {
        $isAbsolute = str_starts_with($configured, '/') || str_starts_with($configured, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured) === 1;
        if (!$isAbsolute) {
            throw new InvalidArgumentException('MASIX_DATA_DIR must be an absolute directory path.');
        }
        $directory = $configured;
    } else {
        $directory = __DIR__ . '/data';
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the private application data directory.');
    }
    return $directory;
}

function store_update(string $name, callable $update)
{
    if (!in_array($name, STORE_FILES, true)) {
        throw new InvalidArgumentException('Unknown application data store.');
    }

    $directory = store_directory();
    $path = $directory . '/' . $name . '.json';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the application data store.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the application data store.');
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        $data = $contents === '' ? [] : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if ($contents === '' && $name === 'tickets' && is_file(__DIR__ . '/tickets.json')) {
            $legacy = file_get_contents(__DIR__ . '/tickets.json');
            if ($legacy === false) {
                throw new RuntimeException('Unable to read the legacy ticket store.');
            }
            $data = $legacy === '' ? [] : json_decode($legacy, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($data)) {
            throw new UnexpectedValueException('Application data must contain a JSON array or object.');
        }

        $result = $update($data);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException('Unable to save application data.');
        }
        $encoded = $json . PHP_EOL;
        $offset = 0;
        while ($offset < strlen($encoded)) {
            $written = fwrite($handle, substr($encoded, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to save application data.');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('Unable to flush application data.');
        }

        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function store_read(string $name): array
{
    if (!in_array($name, STORE_FILES, true)) {
        throw new InvalidArgumentException('Unknown application data store.');
    }

    $directory = store_directory();
    $path = $directory . '/' . $name . '.json';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the application data store.');
    }
    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('Unable to lock the application data store.');
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        $data = $contents === '' ? [] : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if ($contents === '' && $name === 'tickets' && is_file(__DIR__ . '/tickets.json')) {
            $legacy = file_get_contents(__DIR__ . '/tickets.json');
            if ($legacy === false) {
                throw new RuntimeException('Unable to read the legacy ticket store.');
            }
            $data = $legacy === '' ? [] : json_decode($legacy, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($data)) {
            throw new UnexpectedValueException('Application data must contain a JSON array or object.');
        }
        return $data;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function ticket_store_read(): array
{
    return store_read('tickets');
}

function ticket_store_update(callable $update)
{
    return store_update('tickets', $update);
}

function ticket_escape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ticket_request_string(array $source, string $key): string
{
    return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : '';
}

function ticket_value(array $ticket, string $key, string $fallback = ''): string
{
    if ($key === 'Issue') {
        return (string) ($ticket['Issue'] ?? $ticket['issue'] ?? $fallback);
    }
    return (string) ($ticket[$key] ?? $fallback);
}

function ticket_priority(array $ticket): string
{
    $priority = ticket_value($ticket, 'priority', 'Normal');
    if ($priority === 'Critical' || $priority === 'High') {
        return 'High';
    }
    if ($priority === 'Low') {
        return 'Low';
    }
    return 'Normal';
}

function ticket_sla_hours(string $priority): int
{
    return match ($priority) {
        'High' => 4,
        'Low' => 72,
        default => 24,
    };
}

function ticket_status_class(string $status): string
{
    return match ($status) {
        'Open' => 'open',
        'In Progress' => 'in-progress',
        'Waiting on requester' => 'waiting-on-requester',
        'Resolved' => 'resolved',
        'Closed' => 'closed',
        default => 'unknown',
    };
}

function ticket_csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function ticket_require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(ticket_csrf_token(), $token)) {
        http_response_code(403);
        exit('Your session expired. Refresh the page and try again.');
    }
}

function current_user(): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_string($userId)) {
        return null;
    }
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 28800) {
        unset($_SESSION['user_id'], $_SESSION['last_activity']);
        return null;
    }
    foreach (store_read('users') as $user) {
        if (($user['id'] ?? '') === $userId && ($user['active'] ?? false) === true) {
            $_SESSION['last_activity'] = time();
            return $user;
        }
    }
    unset($_SESSION['user_id']);
    return null;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    if (($user['password_reset_required'] ?? false) && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'account.php') {
        header('Location: account.php?force=1');
        exit;
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
    return $user;
}

function installation_ready(): bool
{
    return count(store_read('users')) > 0;
}

function app_settings(): array
{
    return store_read('settings');
}

function organization_id(array $user): string
{
    return (string) ($user['organization_id'] ?? '');
}

function audit_event(array $user, string $action, string $entityType, string $entityId, array $details = []): void
{
    store_update('audit', static function (array &$events) use ($user, $action, $entityType, $entityId, $details): void {
        array_unshift($events, [
            'id' => bin2hex(random_bytes(12)),
            'organization_id' => organization_id($user),
            'actor_id' => (string) ($user['id'] ?? ''),
            'actor_name' => (string) ($user['name'] ?? 'System'),
            'actor_email' => (string) ($user['email'] ?? ''),
            'actor_role' => (string) ($user['role'] ?? 'system'),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'created_at' => date(DATE_ATOM),
        ]);
    });
}

function visible_tickets(array $user): array
{
    $tickets = ticket_store_read();
    $organizationId = organization_id($user);
    return array_values(array_filter($tickets, static function (array $ticket) use ($user, $organizationId): bool {
        if (($ticket['organization_id'] ?? $organizationId) !== $organizationId) {
            return false;
        }
        if (($user['role'] ?? '') === 'user' && ($ticket['submitter_id'] ?? '') !== ($user['id'] ?? '')) {
            return false;
        }
        return true;
    }));
}

function app_header(string $active, array $user): void
{
    $settings = app_settings();
    $organization = (string) ($settings['organization_name'] ?? 'Your organization');
    $links = [
        'dashboard' => ['index.php', 'Overview'],
        'tickets' => ['history.php', 'Tickets'],
    ];
    if (($user['role'] ?? '') !== 'user') {
        $links['users'] = ['users.php', 'People'];
        $links['audit'] = ['audit.php', 'Audit trail'];
    }

    echo '<header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">M</span><span>MASIX <span class="brand-light">IT SUPPORT</span></span></a>';
    echo '<nav class="topbar-actions" aria-label="Main navigation">';
    foreach ($links as $key => [$href, $label]) {
        echo '<a class="nav-link' . ($active === $key ? ' nav-link--active' : '') . '" href="' . ticket_escape($href) . '">' . ticket_escape($label) . '</a>';
    }
    echo '<a class="account-chip" href="account.php" title="' . ticket_escape(USER_ROLE_LABELS[$user['role']] ?? 'User') . '"><span class="account-avatar">' . ticket_escape(strtoupper(substr((string) ($user['name'] ?? 'M'), 0, 1))) . '</span><span class="account-name">' . ticket_escape($user['name'] ?? '') . '</span></a>';
    echo '<form class="logout-form" action="logout.php" method="POST"><input type="hidden" name="csrf_token" value="' . ticket_escape(ticket_csrf_token()) . '"><button class="nav-link logout-button" type="submit">Sign out</button></form>';
    echo '</nav></header><div class="workspace-caption"><span>' . ticket_escape($organization) . '</span><span class="role-label">' . ticket_escape(USER_ROLE_LABELS[$user['role']] ?? 'User') . '</span></div>';
}
