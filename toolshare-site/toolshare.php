<?php
require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/partials/layout.php';

/* ------------------------------------------------------------------
   CSV reports (custodian only) – sent before any HTML
------------------------------------------------------------------ */
if (isset($_GET['report']) && in_array($_GET['report'], ['usage', 'overdue', 'damage'], true)) {
    if (!$isCustodian) {
        http_response_code(403);
        exit('Custodian access required to download reports.');
    }
    $reportType = (string) $_GET['report'];
    $csvCell = static function ($value): string {
        // Neutralise spreadsheet formula injection from user-entered text.
        $value = (string) $value;
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="toolshare-' . $reportType . '-report-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    $names = [];
    foreach ($conn->query('SELECT user_id, username FROM users_table')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $names[(int) $row['user_id']] = $row['username'];
    }
    if ($reportType === 'usage') {
        fputcsv($output, ['Tool', 'Category', 'Quantity', 'Current condition', 'Borrowed units', 'Reserved today', 'Availability']);
        $report = $toolShareConn->query(
            "SELECT i.tool_name, c.category_name, i.quantity, i.condition_status,
                (SELECT COUNT(*) FROM borrow_requests r INNER JOIN borrow_transactions t ON t.request_id = r.request_id
                    WHERE r.tool_id = i.tool_id AND t.transaction_status = 'borrowed'
                      AND r.borrow_date <= CURDATE()) AS borrowed_units,
                (SELECT COUNT(*) FROM reservations s
                    WHERE s.tool_id = i.tool_id AND s.reservation_status = 'reserved'
                      AND s.start_date <= CURDATE() AND s.end_date >= CURDATE()) AS reserved_units
             FROM tools i INNER JOIN categories c ON c.category_id = i.category_id ORDER BY i.tool_name"
        );
        foreach ($report->fetchAll(PDO::FETCH_ASSOC) as $row) {
            fputcsv($output, [
                $csvCell($row['tool_name']), $csvCell($row['category_name']), $row['quantity'], $csvCell($row['condition_status']),
                $row['borrowed_units'], $row['reserved_units'],
                (int) $row['quantity'] > (int) $row['borrowed_units'] + (int) $row['reserved_units'] ? 'Available' : 'Unavailable',
            ]);
        }
    } elseif ($reportType === 'overdue') {
        fputcsv($output, ['Borrower username', 'Tool', 'Borrow date', 'Due date', 'Days overdue']);
        $report = $toolShareConn->query(
            "SELECT r.user_id, i.tool_name, r.borrow_date, r.due_date, DATEDIFF(CURDATE(), r.due_date) AS days_overdue
             FROM borrow_requests r INNER JOIN borrow_transactions t ON t.request_id = r.request_id
             INNER JOIN tools i ON i.tool_id = r.tool_id
             WHERE t.transaction_status = 'borrowed' AND r.due_date < CURDATE()
             ORDER BY r.due_date"
        );
        foreach ($report->fetchAll(PDO::FETCH_ASSOC) as $row) {
            fputcsv($output, [$csvCell($names[(int) $row['user_id']] ?? 'Unknown user'), $csvCell($row['tool_name']), $row['borrow_date'], $row['due_date'], $row['days_overdue']]);
        }
    } else {
        fputcsv($output, ['Tool', 'Borrower username', 'Condition before', 'Condition after', 'Damage description', 'Recorded at']);
        $report = $toolShareConn->query(
            "SELECT i.tool_name, r.user_id, l.condition_before, l.condition_after, l.damage_description, l.recorded_at
             FROM tool_condition_logs l INNER JOIN borrow_transactions t ON t.transaction_id = l.transaction_id
             INNER JOIN borrow_requests r ON r.request_id = t.request_id
             INNER JOIN tools i ON i.tool_id = r.tool_id
             WHERE l.damage_description <> '' OR l.condition_before <> l.condition_after
             ORDER BY l.recorded_at DESC"
        );
        foreach ($report->fetchAll(PDO::FETCH_ASSOC) as $row) {
            fputcsv($output, [$csvCell($row['tool_name']), $csvCell($names[(int) $row['user_id']] ?? 'Unknown user'), $csvCell($row['condition_before']), $csvCell($row['condition_after']), $csvCell($row['damage_description']), $row['recorded_at']]);
        }
    }
    fclose($output);
    exit();
}

/* ------------------------------------------------------------------
   Routing
------------------------------------------------------------------ */
$views = ['overview', 'inventory', 'requests', 'loans', 'reservations', 'history', 'activity', 'notifications', 'profile'];
$view = (string) ($_GET['view'] ?? 'overview');
if (!in_array($view, $views, true)) {
    $view = 'overview';
}

function usernames_for(PDO $conn, array $rows): array
{
    $ids = array_values(array_unique(array_map('intval', array_column($rows, 'user_id'))));
    if (!$ids) {
        return [];
    }
    $stmt = $conn->prepare('SELECT user_id, username FROM users_table WHERE user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int) $row['user_id']] = $row['username'];
    }
    return $map;
}

function condition_chip(string $condition): string
{
    $c = strtolower($condition);
    $class = $c === 'good' || $c === 'excellent' || $c === 'new' ? 'ok' : (in_array($c, ['fair', 'used'], true) ? 'warn' : 'bad');
    return '<span class="chip ' . $class . '">' . e($condition) . '</span>';
}

function empty_state(string $iconName, string $title, string $text = ''): string
{
    return '<div class="empty">' . icon($iconName) . '<strong>' . e($title) . '</strong>' . ($text ? '<span>' . e($text) . '</span>' : '') . '</div>';
}

$today = date('Y-m-d');

/* ================================================================== */
switch ($view) {

/* ---------------------------- OVERVIEW --------------------------- */
case 'overview':
    $stats = $toolShareConn->query(
        "SELECT
            COALESCE(SUM(i.quantity), 0) AS total_units,
            COALESCE(SUM(GREATEST(i.quantity - COALESCE(a.loan_count, 0) - COALESCE(a.reservation_count, 0), 0)), 0) AS available_units,
            COALESCE(SUM(COALESCE(a.loan_count, 0)), 0) AS borrowed_units,
            COALESCE(SUM(COALESCE(a.reservation_count, 0)), 0) AS reserved_units
         FROM tools i
         LEFT JOIN (
            SELECT i2.tool_id,
                (SELECT COUNT(*) FROM borrow_requests r INNER JOIN borrow_transactions t ON t.request_id = r.request_id
                    WHERE r.tool_id = i2.tool_id AND t.transaction_status = 'borrowed' AND r.borrow_date <= CURDATE()) AS loan_count,
                (SELECT COUNT(*) FROM reservations s WHERE s.tool_id = i2.tool_id AND s.reservation_status = 'reserved'
                    AND s.start_date <= CURDATE() AND s.end_date >= CURDATE()) AS reservation_count
            FROM tools i2
         ) a ON a.tool_id = i.tool_id"
    )->fetch(PDO::FETCH_ASSOC);

    $loanStmt = $toolShareConn->prepare(
        "SELECT r.user_id, i.tool_name, r.due_date, DATEDIFF(CURDATE(), r.due_date) AS days_late
         FROM borrow_transactions t INNER JOIN borrow_requests r ON r.request_id = t.request_id
         INNER JOIN tools i ON i.tool_id = r.tool_id
         WHERE t.transaction_status = 'borrowed' AND r.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)"
         . ($isCustodian ? '' : ' AND r.user_id = ?') . ' ORDER BY r.due_date LIMIT 6'
    );
    $loanStmt->execute($isCustodian ? [] : [$userId]);
    $attentionLoans = $loanStmt->fetchAll(PDO::FETCH_ASSOC);

    $pendingRows = [];
    if ($isCustodian) {
        $pendingRows = $toolShareConn->query(
            "SELECT r.request_id, r.user_id, r.borrow_date, r.due_date, i.tool_name
             FROM borrow_requests r INNER JOIN tools i ON i.tool_id = r.tool_id
             WHERE r.request_status = 'pending' ORDER BY r.request_date LIMIT 6"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    $names = usernames_for($conn, array_merge($attentionLoans, $pendingRows));

    $noteStmt = $toolShareConn->prepare('SELECT message, notification_type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
    $noteStmt->execute([$userId]);
    $recentNotes = $noteStmt->fetchAll(PDO::FETCH_ASSOC);

    layout_start('overview', 'Welcome back, ' . ($profile['first_name'] ?: $account['username']),
        $isCustodian ? 'Inventory health and the items that need your decision today.' : 'Your borrowing at a glance.');
    ?>
    <div class="stat-grid">
        <a class="stat s-inventory" href="?view=inventory"><div class="row">Inventory units<span class="chip-icon"><?php echo icon('box'); ?></span></div><strong><?php echo (int) $stats['total_units']; ?></strong><small>Across all categories</small></a>
        <a class="stat s-available" href="?view=inventory&amp;availability=available"><div class="row">Available now<span class="chip-icon"><?php echo icon('check'); ?></span></div><strong><?php echo (int) $stats['available_units']; ?></strong><small>Ready to borrow</small></a>
        <a class="stat s-loans" href="?view=loans"><div class="row">On loan<span class="chip-icon"><?php echo icon('swap'); ?></span></div><strong><?php echo (int) $stats['borrowed_units']; ?></strong><small>Currently out</small></a>
        <a class="stat s-reservations" href="?view=reservations"><div class="row">Reserved today<span class="chip-icon"><?php echo icon('calendar'); ?></span></div><strong><?php echo (int) $stats['reserved_units']; ?></strong><small>Held for residents</small></a>
        <a class="stat s-overdue" href="?view=loans"><div class="row"><?php echo $isCustodian ? 'Overdue' : 'My overdue'; ?><span class="chip-icon"><?php echo icon('alert'); ?></span></div><strong><?php echo (int) $navCounts['overdue']; ?></strong><small>Need to be returned</small></a>
    </div>

    <div class="grid cols-2">
        <?php if ($isCustodian): ?>
        <section class="card panel p-requests" aria-labelledby="ov-req">
            <div class="card-head"><h2 id="ov-req">Awaiting your decision</h2><a class="btn sm" href="?view=requests">Open requests</a></div>
            <?php if (!$pendingRows): echo empty_state('inbox', 'No pending requests', 'New borrow requests will show up here.'); else: ?>
            <ul class="feed">
                <?php foreach ($pendingRows as $row): ?>
                    <li><span class="dot"></span><div><strong><?php echo e($row['tool_name']); ?></strong><div class="meta"><?php echo e($names[(int) $row['user_id']] ?? 'Resident'); ?> · <?php echo fmt_date($row['borrow_date']); ?> → <?php echo fmt_date($row['due_date']); ?></div></div><span class="when">#<?php echo (int) $row['request_id']; ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card panel p-loans" aria-labelledby="ov-loan">
            <div class="card-head"><h2 id="ov-loan"><?php echo $isCustodian ? 'Overdue &amp; due soon' : 'Due soon'; ?></h2><a class="btn sm" href="?view=loans">Active loans</a></div>
            <?php if (!$attentionLoans): echo empty_state('check', 'Nothing due in the next 3 days'); else: ?>
            <ul class="feed">
                <?php foreach ($attentionLoans as $row): $late = (int) $row['days_late'] > 0; ?>
                    <li><span class="dot" style="<?php echo $late ? 'background:var(--danger)' : ''; ?>"></span><div><strong><?php echo e($row['tool_name']); ?></strong><div class="meta"><?php echo $isCustodian ? e($names[(int) $row['user_id']] ?? 'Resident') . ' · ' : ''; ?>Due <?php echo fmt_date($row['due_date']); ?></div></div><span class="when"><?php echo $late ? chip('overdue', (int) $row['days_late'] . 'd late') : chip('borrowed', 'Due soon'); ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="card panel p-notifications" aria-labelledby="ov-note">
            <div class="card-head"><h2 id="ov-note">Latest notifications</h2><a class="btn sm" href="?view=notifications">View all</a></div>
            <?php if (!$recentNotes): echo empty_state('bell', 'You\'re all caught up'); else: ?>
            <ul class="feed">
                <?php foreach ($recentNotes as $note): ?>
                    <li class="<?php echo (int) $note['is_read'] === 0 ? 'unread' : ''; ?>"><span class="dot"></span><div><strong><?php echo e($note['message']); ?></strong></div><span class="when"><?php echo fmt_date($note['created_at']); ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="card panel p-inventory" aria-labelledby="ov-quick">
            <div class="card-head"><h2 id="ov-quick">Quick actions</h2></div>
            <div class="card-body" style="display:flex;flex-wrap:wrap;gap:10px">
                <a class="btn primary" href="?view=inventory"><?php echo icon('search'); ?>Find equipment</a>
                <a class="btn" href="?view=reservations"><?php echo icon('calendar'); ?>My reservations</a>
                <?php if ($isCustodian): ?>
                    <a class="btn" href="?view=history"><?php echo icon('download'); ?>Download reports</a>
                    <a class="btn" href="index.php"><?php echo icon('users'); ?>Manage participants</a>
                <?php else: ?>
                    <a class="btn" href="?view=profile"><?php echo icon('user'); ?>Update my profile</a>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <?php
    layout_end();
    break;

/* ---------------------------- INVENTORY -------------------------- */
case 'inventory':
    $search = trim((string) ($_GET['search'] ?? ''));
    $categoryFilter = (int) ($_GET['category'] ?? 0);
    $availabilityFilter = ($_GET['availability'] ?? '') === 'available' ? 'available' : '';
    $tools = $toolShareConn->query(
        "SELECT i.*, c.category_name,
            (SELECT COUNT(*) FROM borrow_requests r INNER JOIN borrow_transactions t ON t.request_id = r.request_id
                WHERE r.tool_id = i.tool_id AND t.transaction_status = 'borrowed' AND r.borrow_date <= CURDATE()) AS on_loan,
            (SELECT COUNT(*) FROM reservations s WHERE s.tool_id = i.tool_id AND s.reservation_status = 'reserved'
                AND s.start_date <= CURDATE() AND s.end_date >= CURDATE()) AS reserved_now
         FROM tools i INNER JOIN categories c ON c.category_id = i.category_id ORDER BY i.tool_name"
    )->fetchAll(PDO::FETCH_ASSOC);
    $tools = array_values(array_filter($tools, static function (array $tool) use ($search, $categoryFilter, $availabilityFilter): bool {
        $free = max(0, (int) $tool['quantity'] - (int) $tool['on_loan'] - (int) $tool['reserved_now']);
        $matches = $search === ''
            || stripos($tool['tool_name'], $search) !== false
            || stripos($tool['category_name'], $search) !== false
            || stripos($tool['description'], $search) !== false;
        return $matches && (!$categoryFilter || (int) $tool['category_id'] === $categoryFilter) && ($availabilityFilter !== 'available' || $free > 0);
    }));
    $categories = $toolShareConn->query('SELECT category_id, category_name FROM categories ORDER BY category_name')->fetchAll(PDO::FETCH_ASSOC);

    $actions = $isCustodian
        ? '<button class="btn primary" type="button" data-dialog="dlgTool" data-fill=\'' . e(json_encode(['action' => 'create_tool', 'tool_id' => 0, 'tool_name' => '', 'quantity' => 1, 'condition_status' => 'Good', 'description' => '', 'dialog_title' => 'Add equipment'])) . '\'>' . icon('plus') . 'Add equipment</button>'
        : '';
    layout_start('inventory', 'Equipment inventory', 'Find a tool, check availability, then request or reserve it.', $actions);
    ?>
    <section class="card">
        <div class="card-head">
            <form class="toolbar" method="get" action="toolshare.php" role="search">
                <input type="hidden" name="view" value="inventory">
                <input type="search" name="search" value="<?php echo e($search); ?>" placeholder="Search equipment" aria-label="Search equipment">
                <select name="category" aria-label="Filter by category">
                    <option value="0">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo (int) $category['category_id']; ?>" <?php echo $categoryFilter === (int) $category['category_id'] ? 'selected' : ''; ?>><?php echo e($category['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="availability" aria-label="Filter by availability">
                    <option value="">Any availability</option>
                    <option value="available" <?php echo $availabilityFilter === 'available' ? 'selected' : ''; ?>>Available today</option>
                </select>
                <button class="btn" type="submit">Apply</button>
                <?php if ($search !== '' || $categoryFilter || $availabilityFilter): ?><a class="btn" href="?view=inventory">Reset</a><?php endif; ?>
            </form>
            <small><?php echo count($tools); ?> item<?php echo count($tools) === 1 ? '' : 's'; ?></small>
        </div>
        <?php if (!$tools): echo empty_state('box', $search !== '' || $categoryFilter || $availabilityFilter ? 'No equipment matches those filters' : 'No equipment is listed yet', $isCustodian ? '' : 'Ask your custodian to add inventory.'); else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Equipment</th><th>Category</th><th>Condition</th><th>Availability today</th><th class="actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($tools as $tool):
                    $total = (int) $tool['quantity'];
                    $free = max(0, $total - (int) $tool['on_loan'] - (int) $tool['reserved_now']);
                    $pct = $total > 0 ? (int) round($free / $total * 100) : 0;
                    $barClass = $free === 0 ? 'none' : ($pct <= 34 ? 'low' : '');
                    $toolFill = e(json_encode(['tool_id' => (int) $tool['tool_id'], 'tool_name' => $tool['tool_name']]));
                    $editFill = e(json_encode([
                        'action' => 'update_tool', 'tool_id' => (int) $tool['tool_id'], 'tool_name' => $tool['tool_name'],
                        'category_id' => (int) $tool['category_id'], 'quantity' => $total, 'condition_status' => $tool['condition_status'],
                        'description' => $tool['description'], 'dialog_title' => 'Edit equipment',
                    ]));
                ?>
                    <tr>
                        <td><div class="cell-main"><?php echo e($tool['tool_name']); ?></div><div class="cell-sub"><?php echo e(mb_strimwidth($tool['description'] ?: 'Community equipment available to borrow.', 0, 80, '…')); ?></div></td>
                        <td><?php echo e($tool['category_name']); ?></td>
                        <td><?php echo condition_chip($tool['condition_status']); ?></td>
                        <td><div class="meter"><div class="meter-bar"><span class="<?php echo $barClass; ?>" style="width:<?php echo $pct; ?>%"></span></div><small><?php echo $free; ?> of <?php echo $total; ?> free</small></div></td>
                        <td class="actions"><span class="inline">
                            <button class="btn sm primary" type="button" data-dialog="dlgBorrow" data-fill='<?php echo $toolFill; ?>'>Borrow</button>
                            <button class="btn sm" type="button" data-dialog="dlgReserve" data-fill='<?php echo $toolFill; ?>'>Reserve</button>
                            <?php if ($isCustodian): ?>
                                <button class="btn sm" type="button" data-dialog="dlgTool" data-fill='<?php echo $editFill; ?>' aria-label="Edit <?php echo e($tool['tool_name']); ?>"><?php echo icon('edit'); ?></button>
                                <button class="btn sm danger" type="button" data-dialog="dlgDeleteTool" data-fill='<?php echo $toolFill; ?>'>Delete</button>
                            <?php endif; ?>
                        </span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <dialog id="dlgBorrow" aria-labelledby="dlgBorrowT"><form method="post" action="backend/toolshare_action.php">
        <?php echo csrf_fields('inventory'); ?><input type="hidden" name="action" value="request_tool"><input type="hidden" name="tool_id">
        <div class="dlg-head"><span class="head-icon"><?php echo icon('inbox'); ?></span><div><h3 id="dlgBorrowT">Request to borrow</h3><p data-text="tool_name"></p></div></div>
        <div class="dlg-body"><div class="form-grid">
            <div class="field"><label>Borrow from</label><input type="date" name="start_date" min="<?php echo $today; ?>" value="<?php echo $today; ?>" required></div>
            <div class="field"><label>Due date</label><input type="date" name="end_date" min="<?php echo $today; ?>" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required></div>
            <p class="hint full" style="margin:0;color:var(--muted)">The custodian must approve the request before the tool is released.</p>
        </div></div>
        <div class="dlg-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn primary" type="submit">Send request</button></div>
    </form></dialog>

    <dialog id="dlgReserve" aria-labelledby="dlgReserveT" class="panel p-reservations"><form method="post" action="backend/toolshare_action.php">
        <?php echo csrf_fields('reservations'); ?><input type="hidden" name="action" value="reserve_tool"><input type="hidden" name="tool_id">
        <div class="dlg-head"><span class="head-icon"><?php echo icon('calendar'); ?></span><div><h3 id="dlgReserveT">Reserve dates</h3><p data-text="tool_name"></p></div></div>
        <div class="dlg-body"><div class="form-grid">
            <div class="field"><label>Reserve from</label><input type="date" name="start_date" min="<?php echo $today; ?>" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required></div>
            <div class="field"><label>Through</label><input type="date" name="end_date" min="<?php echo $today; ?>" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required></div>
        </div></div>
        <div class="dlg-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn primary" type="submit">Reserve</button></div>
    </form></dialog>

    <?php if ($isCustodian): ?>
    <dialog id="dlgTool" aria-labelledby="dlgToolT"><form method="post" action="backend/toolshare_action.php">
        <?php echo csrf_fields('inventory'); ?><input type="hidden" name="action" value="create_tool"><input type="hidden" name="tool_id" value="0">
        <div class="dlg-head"><span class="head-icon"><?php echo icon('box'); ?></span><div><h3 id="dlgToolT" data-text="dialog_title">Add equipment</h3><p>Records are visible to every resident.</p></div></div>
        <div class="dlg-body"><div class="form-grid">
            <div class="field full"><label>Tool name</label><input name="tool_name" maxlength="150" required></div>
            <div class="field"><label>Category</label><select name="category_id" required><?php foreach ($categories as $category): ?><option value="<?php echo (int) $category['category_id']; ?>"><?php echo e($category['category_name']); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Quantity</label><input type="number" name="quantity" min="1" value="1" required></div>
            <div class="field full"><label>Condition</label><input name="condition_status" maxlength="40" value="Good" required></div>
            <div class="field full"><label>Description</label><textarea name="description" maxlength="2000"></textarea></div>
        </div></div>
        <div class="dlg-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn primary" type="submit">Save equipment</button></div>
    </form></dialog>

    <dialog id="dlgDeleteTool" aria-labelledby="dlgDelT"><form method="post" action="backend/toolshare_action.php">
        <?php echo csrf_fields('inventory'); ?><input type="hidden" name="action" value="delete_tool"><input type="hidden" name="tool_id">
        <div class="dlg-head" style="border-top-color:var(--danger)"><span class="head-icon" style="background:var(--danger)"><?php echo icon('alert'); ?></span><div><h3 id="dlgDelT">Delete equipment?</h3><p data-text="tool_name"></p></div></div>
        <div class="dlg-body">This removes the record permanently. Equipment that already has lending history cannot be deleted.</div>
        <div class="dlg-foot"><button type="button" class="btn" data-close>Keep it</button><button class="btn danger solid" type="submit">Delete</button></div>
    </form></dialog>
    <?php endif;
    layout_end();
    break;

/* ----------------------------- REQUESTS -------------------------- */
case 'requests':
    $stmt = $toolShareConn->prepare(
        "SELECT r.*, i.tool_name FROM borrow_requests r INNER JOIN tools i ON i.tool_id = r.tool_id"
        . ($isCustodian ? " WHERE r.request_status = 'pending'" : ' WHERE r.user_id = ?')
        . ' ORDER BY r.request_date DESC LIMIT 50'
    );
    $stmt->execute($isCustodian ? [] : [$userId]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = usernames_for($conn, $requests);
    layout_start('requests', $isCustodian ? 'Requests awaiting review' : 'My borrowing requests',
        $isCustodian ? 'Approve or decline pending requests.' : 'Track decisions on your equipment requests.');
    ?>
    <section class="card">
        <?php if (!$requests): echo empty_state('inbox', $isCustodian ? 'Inbox zero' : 'No requests yet', $isCustodian ? 'No pending requests right now.' : 'Borrow something from the inventory to see it here.'); else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Request</th><?php if ($isCustodian): ?><th>Resident</th><?php endif; ?><th>Period</th><th>Status</th><?php if ($isCustodian): ?><th class="actions">Decision</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($requests as $request): ?>
                <tr>
                    <td><div class="cell-main"><?php echo e($request['tool_name']); ?></div><div class="cell-sub">#<?php echo (int) $request['request_id']; ?> · sent <?php echo fmt_date($request['request_date']); ?></div></td>
                    <?php if ($isCustodian): ?><td><?php echo e($names[(int) $request['user_id']] ?? 'Resident'); ?></td><?php endif; ?>
                    <td><?php echo fmt_date($request['borrow_date']); ?> → <?php echo fmt_date($request['due_date']); ?></td>
                    <td><?php echo chip($request['request_status']); ?></td>
                    <?php if ($isCustodian): ?>
                    <td class="actions"><span class="inline">
                        <?php foreach (['approved' => ['Approve', 'btn sm ok'], 'rejected' => ['Decline', 'btn sm danger']] as $decision => [$label, $class]): ?>
                            <form method="post" action="backend/toolshare_action.php"><?php echo csrf_fields('requests'); ?><input type="hidden" name="action" value="decide_request"><input type="hidden" name="request_id" value="<?php echo (int) $request['request_id']; ?>"><input type="hidden" name="decision" value="<?php echo $decision; ?>"><button class="<?php echo $class; ?>" type="submit"><?php echo $label; ?></button></form>
                        <?php endforeach; ?>
                    </span></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <?php
    layout_end();
    break;

/* ------------------------------ LOANS ---------------------------- */
case 'loans':
    $stmt = $toolShareConn->prepare(
        "SELECT t.transaction_id, r.user_id, r.borrow_date, r.due_date, i.tool_name, i.condition_status
         FROM borrow_transactions t INNER JOIN borrow_requests r ON r.request_id = t.request_id
         INNER JOIN tools i ON i.tool_id = r.tool_id
         WHERE t.transaction_status = 'borrowed'" . ($isCustodian ? '' : ' AND r.user_id = ?') . ' ORDER BY r.due_date ASC'
    );
    $stmt->execute($isCustodian ? [] : [$userId]);
    $activeLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = usernames_for($conn, $activeLoans);
    layout_start('loans', 'Active loans', 'Equipment stays here until it is checked back in.');
    ?>
    <section class="card">
        <?php if (!$activeLoans): echo empty_state('swap', 'No active loans', 'Everything is back on the shelf.'); else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Equipment</th><?php if ($isCustodian): ?><th>Borrower</th><?php endif; ?><th>Borrowed</th><th>Due</th><th>Status</th><th class="actions">Action</th></tr></thead>
            <tbody>
            <?php foreach ($activeLoans as $loan):
                $late = $loan['due_date'] < $today;
                $days = $late ? (int) floor((strtotime($today) - strtotime($loan['due_date'])) / 86400) : 0;
            ?>
                <tr>
                    <td><div class="cell-main"><?php echo e($loan['tool_name']); ?></div><div class="cell-sub">Condition at lending: <?php echo e($loan['condition_status']); ?></div></td>
                    <?php if ($isCustodian): ?><td><?php echo e($names[(int) $loan['user_id']] ?? 'Resident'); ?></td><?php endif; ?>
                    <td><?php echo fmt_date($loan['borrow_date']); ?></td>
                    <td><?php echo fmt_date($loan['due_date']); ?></td>
                    <td><?php echo $late ? chip('overdue', 'Overdue · ' . $days . 'd') : chip('borrowed', 'On loan'); ?></td>
                    <td class="actions"><button class="btn sm primary" type="button" data-dialog="dlgReturn" data-fill='<?php echo e(json_encode(['transaction_id' => (int) $loan['transaction_id'], 'tool_name' => $loan['tool_name']])); ?>'>Record return</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <dialog id="dlgReturn" aria-labelledby="dlgRetT"><form method="post" action="backend/toolshare_action.php">
        <?php echo csrf_fields('loans'); ?><input type="hidden" name="action" value="return_tool"><input type="hidden" name="transaction_id">
        <div class="dlg-head"><span class="head-icon"><?php echo icon('swap'); ?></span><div><h3 id="dlgRetT">Record return</h3><p data-text="tool_name"></p></div></div>
        <div class="dlg-body"><div class="form-grid">
            <div class="field full"><label>Condition on return</label><select name="condition_after" required><option>Good</option><option>Fair</option><option>Needs repair</option><option>Damaged</option></select></div>
            <div class="field full"><label>Damage notes (optional)</label><textarea name="damage_description" maxlength="1500" placeholder="Describe any damage or missing parts"></textarea></div>
        </div></div>
        <div class="dlg-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn primary" type="submit">Confirm return</button></div>
    </form></dialog>
    <?php
    layout_end();
    break;

/* --------------------------- RESERVATIONS ------------------------ */
case 'reservations':
    $stmt = $toolShareConn->prepare(
        "SELECT s.*, i.tool_name FROM reservations s INNER JOIN tools i ON i.tool_id = s.tool_id
         WHERE s.reservation_status = 'reserved' AND s.end_date >= CURDATE()" . ($isCustodian ? '' : ' AND s.user_id = ?') . ' ORDER BY s.start_date ASC LIMIT 60'
    );
    $stmt->execute($isCustodian ? [] : [$userId]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = usernames_for($conn, $reservations);
    layout_start('reservations', 'Reservations', 'Future bookings are checked against active loans to prevent double-booking.');
    ?>
    <section class="card">
        <?php if (!$reservations): echo empty_state('calendar', 'No upcoming reservations', 'Reserve equipment from the inventory page.'); else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Equipment</th><?php if ($isCustodian): ?><th>Resident</th><?php endif; ?><th>Dates</th><th>Status</th><th class="actions">Action</th></tr></thead>
            <tbody>
            <?php foreach ($reservations as $reservation): ?>
                <tr>
                    <td><div class="cell-main"><?php echo e($reservation['tool_name']); ?></div><div class="cell-sub">#<?php echo (int) $reservation['reservation_id']; ?></div></td>
                    <?php if ($isCustodian): ?><td><?php echo e($names[(int) $reservation['user_id']] ?? 'Resident'); ?></td><?php endif; ?>
                    <td><?php echo fmt_date($reservation['start_date']); ?> → <?php echo fmt_date($reservation['end_date']); ?></td>
                    <td><?php echo $reservation['start_date'] <= $today ? chip('reserved', 'Active') : chip('reserved', 'Upcoming'); ?></td>
                    <td class="actions"><form method="post" action="backend/toolshare_action.php" onsubmit="return confirm('Cancel this reservation?');"><?php echo csrf_fields('reservations'); ?><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="reservation_id" value="<?php echo (int) $reservation['reservation_id']; ?>"><button class="btn sm danger" type="submit">Cancel</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <?php
    layout_end();
    break;

/* ------------------------------ HISTORY -------------------------- */
case 'history':
    $stmt = $toolShareConn->prepare(
        "SELECT r.request_id, r.user_id, r.borrow_date, r.due_date, r.request_status, i.tool_name, t.actual_return_date, t.transaction_status
         FROM borrow_requests r INNER JOIN tools i ON i.tool_id = r.tool_id
         LEFT JOIN borrow_transactions t ON t.request_id = r.request_id"
         . ($isCustodian ? '' : ' WHERE r.user_id = ?') . ' ORDER BY r.request_date DESC LIMIT 100'
    );
    $stmt->execute($isCustodian ? [] : [$userId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = usernames_for($conn, $history);
    $reports = $isCustodian
        ? '<a class="btn" href="?report=usage">' . icon('download') . 'Usage CSV</a><a class="btn" href="?report=overdue">' . icon('download') . 'Overdue CSV</a><a class="btn" href="?report=damage">' . icon('download') . 'Damage CSV</a>'
        : '';
    layout_start('history', 'Borrowing history', $isCustodian ? 'Every request across the community, newest first.' : 'Your past and current borrowing.', $reports);
    ?>
    <section class="card">
        <?php if (!$history): echo empty_state('clock', 'Nothing here yet', 'Borrowing history will appear here.'); else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Equipment</th><?php if ($isCustodian): ?><th>Resident</th><?php endif; ?><th>Period</th><th>Request</th><th>Returned</th></tr></thead>
            <tbody>
            <?php foreach ($history as $item): ?>
                <tr>
                    <td class="cell-main"><?php echo e($item['tool_name']); ?></td>
                    <?php if ($isCustodian): ?><td><?php echo e($names[(int) $item['user_id']] ?? 'Resident'); ?></td><?php endif; ?>
                    <td><?php echo fmt_date($item['borrow_date']); ?> → <?php echo fmt_date($item['due_date']); ?></td>
                    <td><?php echo chip($item['request_status']); ?></td>
                    <td><?php echo $item['actual_return_date'] ? fmt_date($item['actual_return_date']) : ($item['transaction_status'] === 'borrowed' ? chip('borrowed', 'Still out') : '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <?php
    layout_end();
    break;

/* ------------------------------ ACTIVITY ------------------------- */
case 'activity':
    $stmt = $toolShareConn->prepare('SELECT user_id, action, description, created_at FROM activity_logs' . ($isCustodian ? '' : ' WHERE user_id = ?') . ' ORDER BY created_at DESC LIMIT 50');
    $stmt->execute($isCustodian ? [] : [$userId]);
    $activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = usernames_for($conn, $activity);
    layout_start('activity', 'Activity log', $isCustodian ? 'Audit trail of actions taken by every account.' : 'Actions recorded on your account.');
    ?>
    <section class="card">
        <?php if (!$activity): echo empty_state('pulse', 'No activity yet', 'Actions will be logged here as you use ToolShare.'); else: ?>
        <ul class="feed">
            <?php foreach ($activity as $item): ?>
                <li><span class="dot"></span><div><strong><?php echo e($item['description']); ?></strong><div class="meta"><?php echo e(ucfirst(str_replace('_', ' ', $item['action']))); ?><?php echo $isCustodian ? ' · by ' . e($names[(int) $item['user_id']] ?? 'Unknown') : ''; ?></div></div><span class="when"><?php echo fmt_datetime($item['created_at']); ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php
    layout_end();
    break;

/* --------------------------- NOTIFICATIONS ----------------------- */
case 'notifications':
    $stmt = $toolShareConn->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $markAll = $navCounts['notifications'] > 0
        ? '<form method="post" action="backend/toolshare_action.php">' . csrf_fields('notifications') . '<input type="hidden" name="action" value="mark_notifications_read"><button class="btn" type="submit">' . icon('check') . 'Mark all read</button></form>'
        : '';
    layout_start('notifications', 'Notifications', 'Due-date reminders, approval decisions and return updates.', $markAll);
    ?>
    <section class="card">
        <?php if (!$notifications): echo empty_state('bell', 'You\'re all caught up'); else: ?>
        <ul class="feed">
            <?php foreach ($notifications as $note): $unread = (int) $note['is_read'] === 0; ?>
                <li class="<?php echo $unread ? 'unread' : ''; ?>"><span class="dot"></span><div><strong><?php echo e($note['message']); ?></strong><div class="meta"><?php echo e(ucfirst(str_replace('_', ' ', $note['notification_type']))); ?><?php echo $unread ? ' · New' : ''; ?></div></div><span class="when"><?php echo fmt_datetime($note['created_at']); ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php
    layout_end();
    break;

/* ------------------------------ PROFILE -------------------------- */
case 'profile':
    layout_start('profile', 'My profile', 'Keep your contact information current. Only you and the custodian can see it.');
    ?>
    <div class="grid cols-2" style="align-items:start">
        <section class="card">
            <div class="card-head"><h2>Contact details</h2><span class="lock-note"><?php echo icon('lock'); ?>Private</span></div>
            <form class="card-body" method="post" action="backend/toolshare_action.php">
                <?php echo csrf_fields('profile'); ?><input type="hidden" name="action" value="update_profile">
                <div class="form-grid">
                    <div class="field"><label for="pf1">First name</label><input id="pf1" name="first_name" maxlength="100" value="<?php echo e($profile['first_name']); ?>" required></div>
                    <div class="field"><label for="pf2">Last name</label><input id="pf2" name="last_name" maxlength="100" value="<?php echo e($profile['last_name']); ?>" required></div>
                    <div class="field full"><label for="pf3">Email</label><input id="pf3" type="email" name="email" maxlength="255" value="<?php echo e($profile['email']); ?>" required></div>
                    <div class="field full"><label for="pf4">City / community</label><input id="pf4" name="city" maxlength="150" value="<?php echo e($profile['city']); ?>"></div>
                    <div class="full"><button class="btn primary" type="submit">Save profile</button></div>
                </div>
            </form>
        </section>
        <section class="card">
            <div class="card-head"><h2>Account</h2></div>
            <div class="card-body">
                <div class="cell-person" style="margin-bottom:14px"><span class="avatar" style="width:46px;height:46px"><?php echo e(initials($displayName)); ?></span><div><div class="cell-main"><?php echo e($displayName); ?></div><div class="cell-sub">@<?php echo e($account['username']); ?></div></div></div>
                <p style="margin:0 0 6px;color:var(--muted)">Role</p>
                <span class="chip <?php echo $isCustodian ? 'info' : 'ok'; ?>"><?php echo $isCustodian ? 'Custodian' : 'Resident'; ?></span>
                <p style="margin:16px 0 0;color:var(--muted);font-size:.84rem">Your role is managed by the custodian and cannot be changed here.</p>
            </div>
        </section>
    </div>
    <?php
    layout_end();
    break;
}
