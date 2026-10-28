<?php
/**
 * Shared bootstrap for every signed-in page:
 * session, authentication, role, CSRF token, flash message and common helpers.
 */
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/../toolshare_db.php';   // also loads connection.php ($conn)

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$accountStmt = $conn->prepare('SELECT role, username FROM users_table WHERE user_id = ? LIMIT 1');
$accountStmt->execute([$userId]);
$account = $accountStmt->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit();
}
$isCustodian = $account['role'] === 'custodian';

$csrfToken = $_SESSION['toolshare_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['toolshare_csrf'] = $csrfToken;
$flash = $_SESSION['toolshare_flash'] ?? null;
unset($_SESSION['toolshare_flash']);

$profileStmt = $conn->prepare('SELECT first_name, last_name, email, city FROM participants WHERE user_id = ? LIMIT 1');
$profileStmt->execute([$userId]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: ['first_name' => '', 'last_name' => '', 'email' => '', 'city' => ''];
$displayName = trim($profile['first_name'] . ' ' . $profile['last_name']) ?: $account['username'];

/* ---------- helpers ---------- */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Hidden inputs every state-changing form needs. */
function csrf_fields(string $returnView = 'overview'): string
{
    global $csrfToken;
    return '<input type="hidden" name="csrf_token" value="' . e($csrfToken) . '">'
         . '<input type="hidden" name="return_view" value="' . e($returnView) . '">';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $letters ?: '?';
}

/** lawrence.flores@example.com -> la•••••@e•••••.com (fixed mask length, so the real length is not revealed) */
function mask_email(string $email): string
{
    if (!str_contains($email, '@')) {
        return $email === '' ? '—' : '•••••';
    }
    [$local, $domain] = explode('@', $email, 2);
    $dot = strrpos($domain, '.');
    $host = $dot === false ? $domain : substr($domain, 0, $dot);
    $tld  = $dot === false ? '' : substr($domain, $dot);
    return mb_substr($local, 0, 2) . '•••••@' . mb_substr($host, 0, 1) . '•••••' . $tld;
}

function age_from(?string $birthdate): string
{
    if (!$birthdate) {
        return '—';
    }
    try {
        return (string) (new DateTime($birthdate))->diff(new DateTime('today'))->y;
    } catch (Exception $exception) {
        return '—';
    }
}

function fmt_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : e($date);
}

function fmt_datetime(?string $date): string
{
    $ts = $date ? strtotime($date) : false;
    return $ts ? date('M j, Y · g:i A', $ts) : '—';
}

/** Status text -> coloured chip. */
function chip(string $status, ?string $label = null): string
{
    $map = [
        'pending' => 'warn', 'approved' => 'ok', 'rejected' => 'bad', 'borrowed' => 'info',
        'scheduled' => 'violet', 'returned' => 'plain', 'overdue' => 'bad', 'reserved' => 'violet',
        'cancelled' => 'plain', 'available' => 'ok', 'unavailable' => 'bad',
    ];
    $class = $map[strtolower($status)] ?? 'plain';
    return '<span class="chip ' . $class . '">' . e($label ?? ucfirst($status)) . '</span>';
}

/** Housekeeping that used to run inline: start scheduled loans, create due-date reminders. */
function toolshare_housekeeping(PDO $db, int $userId): void
{
    $db->exec(
        "UPDATE borrow_transactions t
         INNER JOIN borrow_requests r ON r.request_id = t.request_id
         SET t.transaction_status = 'borrowed'
         WHERE t.transaction_status = 'scheduled' AND r.borrow_date <= CURDATE()"
    );
    $due = $db->prepare(
        "SELECT t.transaction_id, i.tool_name, r.due_date
         FROM borrow_transactions t
         INNER JOIN borrow_requests r ON r.request_id = t.request_id
         INNER JOIN tools i ON i.tool_id = r.tool_id
         WHERE r.user_id = ? AND t.transaction_status = 'borrowed'
           AND r.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)"
    );
    $due->execute([$userId]);
    $insert = $db->prepare('INSERT IGNORE INTO notifications (user_id, transaction_id, message, notification_type) VALUES (?, ?, ?, ?)');
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $overdue = $item['due_date'] < date('Y-m-d');
        $insert->execute([
            $userId,
            $item['transaction_id'],
            $overdue ? $item['tool_name'] . ' is overdue. Please return it as soon as possible.'
                     : $item['tool_name'] . ' is due on ' . $item['due_date'] . '.',
            $overdue ? 'overdue' : 'due_soon',
        ]);
    }
}
toolshare_housekeeping($toolShareConn, $userId);

/* counts shown as badges in the sidebar */
$navCounts = ['notifications' => 0, 'requests' => 0, 'overdue' => 0];
$q = $toolShareConn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$q->execute([$userId]);
$navCounts['notifications'] = (int) $q->fetchColumn();
if ($isCustodian) {
    $navCounts['requests'] = (int) $toolShareConn->query("SELECT COUNT(*) FROM borrow_requests WHERE request_status = 'pending'")->fetchColumn();
}
$overdueSql = "SELECT COUNT(*) FROM borrow_transactions t INNER JOIN borrow_requests r ON r.request_id = t.request_id
               WHERE t.transaction_status = 'borrowed' AND r.due_date < CURDATE()" . ($isCustodian ? '' : ' AND r.user_id = ?');
$q = $toolShareConn->prepare($overdueSql);
$q->execute($isCustodian ? [] : [$userId]);
$navCounts['overdue'] = (int) $q->fetchColumn();
