<?php
require_once __DIR__ . '/../partials/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

require_once __DIR__ . '/../toolshare_db.php';

function toolshareRedirect(string $message, string $type = 'success'): void
{
    $_SESSION['toolshare_flash'] = ['message' => $message, 'type' => $type];
    $allowed = ['overview', 'inventory', 'requests', 'loans', 'reservations', 'history', 'activity', 'notifications', 'profile'];
    $view = (string) ($_POST['return_view'] ?? 'overview');
    header('Location: ../toolshare.php' . (in_array($view, $allowed, true) && $view !== 'overview' ? '?view=' . $view : ''));
    exit();
}

function toolshareRequirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method not allowed.');
    }
    if (!isset($_POST['csrf_token'], $_SESSION['toolshare_csrf'])
        || !hash_equals($_SESSION['toolshare_csrf'], (string) $_POST['csrf_token'])) {
        toolshareRedirect('Your session token expired. Please try that action again.', 'error');
    }
}

function toolshareRequireCustodian(): void
{
    global $conn;
    $stmt = $conn->prepare('SELECT role FROM users_table WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    if ($stmt->fetchColumn() !== 'custodian') {
        toolshareRedirect('This action is only available to a ToolShare custodian.', 'error');
    }
}

function toolshareLog(PDO $db, int $userId, string $action, string $description): void
{
    $stmt = $db->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $action, $description]);
}

function toolshareNotify(PDO $db, int $userId, string $message, string $type = 'info', ?int $transactionId = null): void
{
    $stmt = $db->prepare('INSERT INTO notifications (user_id, transaction_id, message, notification_type) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $transactionId, $message, $type]);
}

function toolshareDate(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function toolshareTool(PDO $db, int $toolId): array
{
    $stmt = $db->prepare('SELECT * FROM tools WHERE tool_id = ? FOR UPDATE');
    $stmt->execute([$toolId]);
    $tool = $stmt->fetch();
    if (!$tool) {
        throw new RuntimeException('The selected tool could not be found.');
    }
    return $tool;
}

function toolshareAvailable(PDO $db, int $toolId, string $startDate, string $endDate, int $quantity): bool
{
    $stmt = $db->prepare(
        "SELECT
            (SELECT COUNT(*) FROM borrow_requests r
                INNER JOIN borrow_transactions t ON t.request_id = r.request_id
                WHERE r.tool_id = ? AND r.request_status = 'approved'
                  AND t.transaction_status IN ('borrowed', 'scheduled')
                  AND ((t.transaction_status = 'borrowed' AND r.borrow_date <= CURDATE())
                       OR (r.borrow_date <= ? AND r.due_date >= ?))) +
            (SELECT COUNT(*) FROM reservations s
                WHERE s.tool_id = ? AND s.reservation_status = 'reserved'
                  AND s.start_date <= ? AND s.end_date >= ?)"
    );
    $stmt->execute([$toolId, $endDate, $startDate, $toolId, $endDate, $startDate]);
    return $quantity > (int) $stmt->fetchColumn();
}

toolshareRequirePost();
$userId = (int) $_SESSION['user_id'];
$action = (string) ($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'create_tool':
        case 'update_tool':
            toolshareRequireCustodian();
            $toolId = (int) ($_POST['tool_id'] ?? 0);
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $name = trim((string) ($_POST['tool_name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            $condition = trim((string) ($_POST['condition_status'] ?? 'Good'));
            if ($name === '' || strlen($name) > 150 || $quantity === false || $quantity < 1 || strlen($condition) > 40) {
                throw new RuntimeException('Enter a tool name, a valid category, a positive quantity, and a condition.');
            }
            $category = $toolShareConn->prepare('SELECT category_id FROM categories WHERE category_id = ?');
            $category->execute([$categoryId]);
            if (!$category->fetchColumn()) {
                throw new RuntimeException('Choose a valid tool category.');
            }
            if ($action === 'create_tool') {
                $stmt = $toolShareConn->prepare('INSERT INTO tools (category_id, tool_name, description, quantity, condition_status) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$categoryId, $name, $description, $quantity, $condition]);
                toolshareLog($toolShareConn, $userId, 'tool_created', 'Added tool: ' . $name);
                toolshareRedirect('Tool added to the inventory.');
            }
            if ($toolId < 1) {
                throw new RuntimeException('Choose an equipment record to update.');
            }
            $toolExists = $toolShareConn->prepare('SELECT tool_id FROM tools WHERE tool_id = ?');
            $toolExists->execute([$toolId]);
            if (!$toolExists->fetchColumn()) {
                throw new RuntimeException('The equipment record could not be found.');
            }
            $reservedCount = $toolShareConn->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM borrow_requests r INNER JOIN borrow_transactions t ON t.request_id = r.request_id
                        WHERE r.tool_id = ? AND t.transaction_status IN ('borrowed', 'scheduled')) +
                    (SELECT COUNT(*) FROM reservations s
                        WHERE s.tool_id = ? AND s.reservation_status = 'reserved' AND s.end_date >= CURDATE())"
            );
            $reservedCount->execute([$toolId, $toolId]);
            if ($quantity < (int) $reservedCount->fetchColumn()) {
                throw new RuntimeException('Quantity cannot be lower than the number of active loans and upcoming reservations.');
            }
            $stmt = $toolShareConn->prepare('UPDATE tools SET category_id = ?, tool_name = ?, description = ?, quantity = ?, condition_status = ? WHERE tool_id = ?');
            $stmt->execute([$categoryId, $name, $description, $quantity, $condition, $toolId]);
            toolshareLog($toolShareConn, $userId, 'tool_updated', 'Updated tool: ' . $name);
            toolshareRedirect('Tool details updated.');

        case 'delete_tool':
            toolshareRequireCustodian();
            $toolId = (int) ($_POST['tool_id'] ?? 0);
            $stmt = $toolShareConn->prepare('DELETE FROM tools WHERE tool_id = ?');
            $stmt->execute([$toolId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('This tool cannot be deleted because it was not found or has borrowing/reservation history.');
            }
            toolshareLog($toolShareConn, $userId, 'tool_deleted', 'Deleted tool record #' . $toolId);
            toolshareRedirect('Tool removed from inventory.');

        case 'request_tool':
        case 'reserve_tool':
            $toolId = (int) ($_POST['tool_id'] ?? 0);
            $start = (string) ($_POST['start_date'] ?? '');
            $end = (string) ($_POST['end_date'] ?? '');
            if (!toolshareDate($start) || !toolshareDate($end) || $end < $start || $start < date('Y-m-d')) {
                throw new RuntimeException('Choose valid dates beginning today or later; the end date must be on or after the start date.');
            }
            $toolShareConn->beginTransaction();
            $tool = toolshareTool($toolShareConn, $toolId);
            if (!toolshareAvailable($toolShareConn, $toolId, $start, $end, (int) $tool['quantity'])) {
                $toolShareConn->rollBack();
                throw new RuntimeException('There is no available unit for those dates. Choose another period.');
            }
            if ($action === 'request_tool') {
                $stmt = $toolShareConn->prepare("INSERT INTO borrow_requests (user_id, tool_id, borrow_date, due_date) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $toolId, $start, $end]);
                toolshareNotify($toolShareConn, $userId, 'Your request for ' . $tool['tool_name'] . ' is waiting for custodian approval.', 'request');
                toolshareLog($toolShareConn, $userId, 'borrow_requested', 'Requested ' . $tool['tool_name']);
                $toolShareConn->commit();
                toolshareRedirect('Borrow request submitted for approval.');
            }
            $stmt = $toolShareConn->prepare("INSERT INTO reservations (user_id, tool_id, start_date, end_date) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $toolId, $start, $end]);
            toolshareLog($toolShareConn, $userId, 'tool_reserved', 'Reserved ' . $tool['tool_name']);
            $toolShareConn->commit();
            toolshareRedirect('Tool reserved for your selected dates.');

        case 'decide_request':
            toolshareRequireCustodian();
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $decision = (string) ($_POST['decision'] ?? '');
            if (!in_array($decision, ['approved', 'rejected'], true)) {
                throw new RuntimeException('Choose approve or reject for this request.');
            }
            $toolShareConn->beginTransaction();
            $requestStmt = $toolShareConn->prepare('SELECT r.*, t.tool_name, t.quantity FROM borrow_requests r INNER JOIN tools t ON t.tool_id = r.tool_id WHERE r.request_id = ? FOR UPDATE');
            $requestStmt->execute([$requestId]);
            $request = $requestStmt->fetch();
            if (!$request || $request['request_status'] !== 'pending') {
                $toolShareConn->rollBack();
                throw new RuntimeException('This request has already been processed or no longer exists.');
            }
            toolshareTool($toolShareConn, (int) $request['tool_id']);
            if ($decision === 'approved' && !toolshareAvailable($toolShareConn, (int) $request['tool_id'], $request['borrow_date'], $request['due_date'], (int) $request['quantity'])) {
                $toolShareConn->rollBack();
                throw new RuntimeException('The tool is no longer available for the requested period.');
            }
            $stmt = $toolShareConn->prepare('UPDATE borrow_requests SET request_status = ? WHERE request_id = ?');
            $stmt->execute([$decision, $requestId]);
            if ($decision === 'approved') {
                $transactionStatus = $request['borrow_date'] > date('Y-m-d') ? 'scheduled' : 'borrowed';
                $stmt = $toolShareConn->prepare('INSERT INTO borrow_transactions (request_id, transaction_status) VALUES (?, ?)');
                $stmt->execute([$requestId, $transactionStatus]);
                $transactionId = (int) $toolShareConn->lastInsertId();
                toolshareNotify($toolShareConn, (int) $request['user_id'], 'Your request for ' . $request['tool_name'] . ' was approved.', 'approved', $transactionId);
            } else {
                toolshareNotify($toolShareConn, (int) $request['user_id'], 'Your request for ' . $request['tool_name'] . ' was declined.', 'rejected');
            }
            toolshareLog($toolShareConn, $userId, 'request_' . $decision, 'Request #' . $requestId . ' ' . $decision);
            $toolShareConn->commit();
            toolshareRedirect('Borrow request ' . $decision . '.');

        case 'return_tool':
            $transactionId = (int) ($_POST['transaction_id'] ?? 0);
            $conditionAfter = trim((string) ($_POST['condition_after'] ?? ''));
            $damage = trim((string) ($_POST['damage_description'] ?? ''));
            if ($conditionAfter === '' || strlen($conditionAfter) > 80) {
                throw new RuntimeException('Enter the returned equipment condition (80 characters maximum).');
            }
            $toolShareConn->beginTransaction();
            $stmt = $toolShareConn->prepare('SELECT t.*, r.user_id, r.tool_id, r.request_id, i.tool_name, i.condition_status FROM borrow_transactions t INNER JOIN borrow_requests r ON r.request_id = t.request_id INNER JOIN tools i ON i.tool_id = r.tool_id WHERE t.transaction_id = ? FOR UPDATE');
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch();
            if (!$transaction || $transaction['transaction_status'] !== 'borrowed') {
                $toolShareConn->rollBack();
                throw new RuntimeException('This borrowing is already returned or could not be found.');
            }
            $isCustodian = false;
            $roleStmt = $conn->prepare('SELECT role FROM users_table WHERE user_id = ? LIMIT 1');
            $roleStmt->execute([$userId]);
            $isCustodian = $roleStmt->fetchColumn() === 'custodian';
            if (!$isCustodian && (int) $transaction['user_id'] !== $userId) {
                $toolShareConn->rollBack();
                toolshareRedirect('You can only return your own borrowed tools.', 'error');
            }
            $today = date('Y-m-d');
            $stmt = $toolShareConn->prepare("UPDATE borrow_transactions SET actual_return_date = ?, transaction_status = 'returned' WHERE transaction_id = ?");
            $stmt->execute([$today, $transactionId]);
            $stmt = $toolShareConn->prepare('INSERT INTO tool_condition_logs (transaction_id, recorded_by, condition_before, condition_after, damage_description) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$transactionId, $userId, $transaction['condition_status'], $conditionAfter, $damage]);
            $stmt = $toolShareConn->prepare('UPDATE tools SET condition_status = ? WHERE tool_id = ?');
            $stmt->execute([$conditionAfter, $transaction['tool_id']]);
            toolshareNotify($toolShareConn, (int) $transaction['user_id'], 'Return recorded for ' . $transaction['tool_name'] . '.', 'return', $transactionId);
            toolshareLog($toolShareConn, $userId, 'tool_returned', 'Returned ' . $transaction['tool_name']);
            $toolShareConn->commit();
            toolshareRedirect('Return and equipment condition recorded.');

        case 'cancel_reservation':
            $reservationId = (int) ($_POST['reservation_id'] ?? 0);
            $stmt = $toolShareConn->prepare("UPDATE reservations SET reservation_status = 'cancelled' WHERE reservation_id = ? AND reservation_status = 'reserved' AND (user_id = ? OR ? = 1)");
            $roleStmt = $conn->prepare('SELECT role FROM users_table WHERE user_id = ? LIMIT 1');
            $roleStmt->execute([$userId]);
            $isCustodian = $roleStmt->fetchColumn() === 'custodian';
            $stmt->execute([$reservationId, $userId, $isCustodian ? 1 : 0]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Reservation could not be cancelled; it may already be inactive.');
            }
            toolshareLog($toolShareConn, $userId, 'reservation_cancelled', 'Cancelled reservation #' . $reservationId);
            toolshareRedirect('Reservation cancelled.');

        case 'mark_notifications_read':
            $stmt = $toolShareConn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
            $stmt->execute([$userId]);
            toolshareRedirect('Notifications marked as read.');

        case 'update_profile':
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $city = trim((string) ($_POST['city'] ?? ''));
            if ($firstName === '' || strlen($firstName) > 100 || $lastName === '' || strlen($lastName) > 100
                || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255 || strlen($city) > 150) {
                throw new RuntimeException('Enter your first name, last name, and a valid email address.');
            }
            $duplicate = $conn->prepare('SELECT participant_id FROM participants WHERE email = ? AND user_id <> ? LIMIT 1');
            $duplicate->execute([$email, $userId]);
            if ($duplicate->fetchColumn()) {
                throw new RuntimeException('That email address is already associated with another account.');
            }
            $stmt = $conn->prepare('UPDATE participants SET first_name = ?, last_name = ?, email = ?, city = ? WHERE user_id = ?');
            $stmt->execute([$firstName, $lastName, $email, $city, $userId]);
            if ($stmt->rowCount() === 0) {
                $check = $conn->prepare('SELECT participant_id FROM participants WHERE user_id = ? LIMIT 1');
                $check->execute([$userId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('No participant profile is linked to this account.');
                }
            }
            toolshareLog($toolShareConn, $userId, 'profile_updated', 'Updated account profile');
            toolshareRedirect('Your profile was updated.');

        default:
            throw new RuntimeException('Unknown ToolShare action.');
    }
} catch (PDOException $exception) {
    if ($toolShareConn->inTransaction()) {
        $toolShareConn->rollBack();
    }
    error_log('ToolShare database action failed: ' . $exception->getMessage());
    toolshareRedirect('The request could not be saved due to a database error. Please try again.', 'error');
} catch (RuntimeException $exception) {
    if ($toolShareConn->inTransaction()) {
        $toolShareConn->rollBack();
    }
    toolshareRedirect($exception->getMessage(), 'error');
}
