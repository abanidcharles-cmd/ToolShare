<?php
require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/partials/layout.php';

// Database design details are for administrators only.
if (!$isCustodian) {
    $_SESSION['toolshare_flash'] = ['message' => 'The system guide is available to the custodian only.', 'type' => 'error'];
    header('Location: toolshare.php');
    exit();
}

$relationships = [
    ['Categories', 'Tools', 'One category can contain many tools', '1', 'many'],
    ['Users', 'Borrow_Requests', 'One user can submit many borrow requests', '1', 'many'],
    ['Tools', 'Borrow_Requests', 'One tool can appear in many requests over time', '1', 'many'],
    ['Borrow_Requests', 'Borrow_Transactions', 'A request can have zero or one transaction', '1', '0..1'],
    ['Borrow_Transactions', 'Tool_Condition_Logs', 'One transaction can have many condition logs', '1', 'many'],
    ['Users', 'Tool_Condition_Logs', 'One user can record many condition logs', '1', 'many'],
    ['Users', 'Reservations', 'One user can make many reservations', '1', 'many'],
    ['Tools', 'Reservations', 'One tool can have many reservations over time', '1', 'many'],
    ['Users', 'Notifications', 'One user can receive many notifications', '1', 'many'],
    ['Borrow_Transactions', 'Notifications', 'One transaction can have many notifications', '1', 'many'],
    ['Users', 'Activity_Logs', 'One user can have many activity logs', '1', 'many'],
];
$entities = [
    'Users' => [['user_id', 'PK'], ['full_name'], ['email'], ['password'], ['contact_number'], ['role'], ['created_at']],
    'Categories' => [['category_id', 'PK'], ['category_name'], ['description']],
    'Tools' => [['tool_id', 'PK'], ['category_id', 'FK'], ['tool_name'], ['description'], ['quantity'], ['condition_status'], ['availability_status'], ['created_at']],
    'Borrow_Requests' => [['request_id', 'PK'], ['user_id', 'FK'], ['tool_id', 'FK'], ['request_date'], ['borrow_date'], ['due_date'], ['request_status']],
    'Borrow_Transactions' => [['transaction_id', 'PK'], ['request_id', 'FK'], ['actual_return_date'], ['transaction_status']],
    'Tool_Condition_Logs' => [['condition_log_id', 'PK'], ['transaction_id', 'FK'], ['recorded_by', 'FK'], ['condition_before'], ['condition_after'], ['damage_description'], ['recorded_at']],
    'Reservations' => [['reservation_id', 'PK'], ['user_id', 'FK'], ['tool_id', 'FK'], ['reservation_date'], ['start_date'], ['end_date'], ['reservation_status']],
    'Notifications' => [['notification_id', 'PK'], ['user_id', 'FK'], ['transaction_id', 'FK, nullable'], ['message'], ['notification_type'], ['is_read'], ['created_at']],
    'Activity_Logs' => [['activity_id', 'PK'], ['user_id', 'FK'], ['action'], ['description'], ['created_at']],
];
$features = [
    ['CRUD Tool & Equipment Records', 'Add, view, update, and delete equipment records.'],
    ['Borrower Registration & Login', 'Provide secure accounts for residents and the custodian.'],
    ['Dashboard', 'Display available, borrowed, reserved, and overdue items.'],
    ['Search & Filter Tools', 'Search by tool name, category, and availability.'],
    ['Borrow Request & Approval', 'Residents request equipment; the custodian approves or rejects requests.'],
    ['Due Date & Return Tracking', 'Record borrowing dates, due dates, and actual return dates.'],
    ['Condition & Damage Logging', 'Record equipment condition before lending and after return.'],
    ['Overdue Reminders', 'Notify users about upcoming due dates and overdue items.'],
    ['Reservation & Booking', 'Reserve tools for future dates without double-booking.'],
    ['Borrowing History', 'Display current and previous borrowing records for every resident.'],
    ['Reports Generation', 'Generate equipment usage, overdue, and damage reports.'],
    ['Profile Management', 'Let users update contact details and view their activity.'],
];

layout_start('guide', 'System guide', 'Purpose, user persona, features and database design of ToolShare.');
?>
<div class="grid" style="gap:20px">
    <section class="card">
        <div class="card-head"><h2>The problem we solve</h2></div>
        <div class="card-body prose">
            <p><strong>Difficulty tracking borrowed tools and equipment in a shared community stock.</strong></p>
            <p>ToolShare manages a community tool bank. The custodian manages tools and equipment while residents request, reserve and borrow available items. The custodian approves requests and assigns due dates; both parties monitor returns and record equipment condition. Replacing the paper notebook helps prevent lost tools, double-booking, overdue items and disputes over damaged equipment.</p>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2>User persona</h2><small>Lawrence Flores · Volunteer Custodian, Barangay Tool Bank · Age 21</small></div>
        <div class="card-body prose">
            <p>Lawrence has managed his subdivision's shared tool shed for three years. The shed holds around 60 donated tools that residents borrow for free. He records borrowing in a notebook and coordinates requests through a neighbourhood group chat.</p>
            <div class="grid cols-3">
                <div><h4>Goals</h4><p>Know which tools are available, borrowed or overdue; keep accurate borrower records; reduce lost or damaged equipment; spend less time answering availability questions.</p></div>
                <div><h4>Frustrations</h4><p>The notebook gets misplaced; overdue items are hard to spot; disputes over damage; residents assume the same tool is free.</p></div>
                <div><h4>Needs</h4><p>Real-time borrowing records, overdue reminders, before-and-after condition records, history for every resident.</p></div>
            </div>
        </div>
    </section>

    <section>
        <h2 style="margin-bottom:12px">What ToolShare can do</h2>
        <div class="grid cols-3">
            <?php foreach ($features as $i => [$title, $text]): ?>
                <article class="card card-body"><small style="color:var(--guide);font-weight:800;letter-spacing:.08em">FEATURE <?php echo str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?></small><h3 style="margin:4px 0;font-size:.98rem"><?php echo e($title); ?></h3><span style="color:var(--muted)"><?php echo e($text); ?></span></article>
            <?php endforeach; ?>
        </div>
    </section>

    <section>
        <h2 style="margin-bottom:12px">Database design</h2>
        <div class="erd-grid">
            <?php foreach ($entities as $name => $attributes): ?>
                <article class="card entity"><h3><?php echo e($name); ?></h3><ul>
                    <?php foreach ($attributes as $attr): $k = $attr[1] ?? ''; ?>
                        <li><span><?php echo e($attr[0]); ?><?php echo str_contains($k, 'nullable') ? ' <small style="color:var(--muted)">nullable</small>' : ''; ?></span><?php if ($k !== ''): ?><span class="key <?php echo str_starts_with($k, 'PK') ? '' : 'fk'; ?>"><?php echo str_starts_with($k, 'PK') ? 'PK' : 'FK'; ?></span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul></article>
            <?php endforeach; ?>
        </div>
        <div class="rel-list">
            <?php foreach ($relationships as [$from, $to, $label, $a, $b]): ?>
                <div class="rel"><b><?php echo e($from); ?></b> <?php echo e($a); ?> → <?php echo e($b); ?> <b><?php echo e($to); ?></b><span><?php echo e($label); ?></span></div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?php layout_end();
