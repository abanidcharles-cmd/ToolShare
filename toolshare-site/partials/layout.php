<?php
/**
 * layout_start()/layout_end() wrap every page in the same shell.
 * $key picks the sidebar item and the panel accent colour.
 */
function nav_items(bool $isCustodian): array
{
    // key => [label, icon, url, section, custodianOnly]
    return [
        'overview'      => ['Overview', 'home', 'toolshare.php', 'Workspace', false],
        'inventory'     => ['Inventory', 'box', 'toolshare.php?view=inventory', 'Workspace', false],
        'requests'      => [$isCustodian ? 'Requests' : 'My requests', 'inbox', 'toolshare.php?view=requests', 'Lending', false],
        'loans'         => ['Active loans', 'swap', 'toolshare.php?view=loans', 'Lending', false],
        'reservations'  => ['Reservations', 'calendar', 'toolshare.php?view=reservations', 'Lending', false],
        'history'       => ['History', 'clock', 'toolshare.php?view=history', 'Records', false],
        'activity'      => ['Activity log', 'pulse', 'toolshare.php?view=activity', 'Records', false],
        'notifications' => ['Notifications', 'bell', 'toolshare.php?view=notifications', 'Account', false],
        'profile'       => ['My profile', 'user', 'toolshare.php?view=profile', 'Account', false],
        'registry'      => ['Participants', 'users', 'index.php', 'Administration', true],
        'guide'         => ['System guide', 'book', 'about.php', 'Administration', true],
    ];
}

function layout_start(string $key, string $title, string $subtitle, string $headActions = ''): void
{
    global $isCustodian, $displayName, $navCounts, $flash;
    $items = nav_items($isCustodian);
    $accent = [
        'overview' => '#e2e8f0', 'inventory' => '#2dd4bf', 'requests' => '#fbbf24', 'loans' => '#60a5fa',
        'reservations' => '#a78bfa', 'history' => '#94a3b8', 'activity' => '#e879f9', 'notifications' => '#fb923c',
        'profile' => '#34d399', 'registry' => '#818cf8', 'guide' => '#38bdf8',
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php echo e($title); ?> · ToolShare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="shell">
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <a class="brand" href="toolshare.php">
            <span class="brand-mark" aria-hidden="true">T</span>
            <span><strong>ToolShare</strong><small>Community lending</small></span>
        </a>
        <?php
        $sections = [];
        foreach ($items as $k => $item) {
            if ($item[4] && !$isCustodian) { continue; }
            $sections[$item[3]][$k] = $item;
        }
        foreach ($sections as $section => $links): ?>
            <nav class="nav-group" aria-label="<?php echo e($section); ?>">
                <div class="nav-title"><?php echo e($section); ?></div>
                <?php foreach ($links as $k => [$label, $ico, $url]): ?>
                    <a class="nav-link<?php echo $k === $key ? ' active' : ''; ?>" style="--c: <?php echo e($accent[$k]); ?>" href="<?php echo e($url); ?>"<?php echo $k === $key ? ' aria-current="page"' : ''; ?>>
                        <?php echo icon($ico); ?><span><?php echo e($label); ?></span>
                        <?php if ($k === 'notifications' && $navCounts['notifications'] > 0): ?><span class="count"><?php echo (int) $navCounts['notifications']; ?></span><?php endif; ?>
                        <?php if ($k === 'requests' && $navCounts['requests'] > 0): ?><span class="count"><?php echo (int) $navCounts['requests']; ?></span><?php endif; ?>
                        <?php if ($k === 'loans' && $navCounts['overdue'] > 0): ?><span class="count alert" title="Overdue"><?php echo (int) $navCounts['overdue']; ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endforeach; ?>
        <div class="sidebar-foot">
            <div class="who">
                <span class="avatar"><?php echo e(initials($displayName)); ?></span>
                <div><strong><?php echo e($displayName); ?></strong><small><?php echo $isCustodian ? 'Custodian' : 'Resident'; ?></small></div>
            </div>
            <a class="signout" href="logout.php"><?php echo icon('logout'); ?>Sign out</a>
        </div>
    </aside>

    <div>
        <div class="mobile-bar">
            <button type="button" id="navToggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false"><?php echo icon('menu'); ?></button>
            <strong>ToolShare</strong>
        </div>
        <main class="main panel p-<?php echo e($key); ?>">
            <header class="page-head">
                <span class="head-icon"><?php echo icon($items[$key][1]); ?></span>
                <div><h1><?php echo e($title); ?></h1><p><?php echo e($subtitle); ?></p></div>
                <?php if ($headActions): ?><div class="head-actions"><?php echo $headActions; ?></div><?php endif; ?>
            </header>
            <?php if ($flash): ?>
                <div class="flash <?php echo $flash['type'] === 'error' ? 'error' : ''; ?>" role="status"><?php echo icon($flash['type'] === 'error' ? 'alert' : 'check'); ?><?php echo e($flash['message']); ?></div>
            <?php endif; ?>
    <?php
}

function layout_end(): void
{
    ?>
        </main>
    </div>
</div>
<script>
(function () {
    var nav = document.getElementById('sidebar'), btn = document.getElementById('navToggle');
    if (btn) {
        btn.addEventListener('click', function () {
            var open = nav.classList.toggle('open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (ev) {
            if (nav.classList.contains('open') && !nav.contains(ev.target) && !btn.contains(ev.target)) { nav.classList.remove('open'); }
        });
    }
    // Dialog openers: <button data-dialog="id" data-fill='{"field":"value"}'>
    document.querySelectorAll('[data-dialog]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            var dlg = document.getElementById(trigger.dataset.dialog);
            if (!dlg) { return; }
            var data = trigger.dataset.fill ? JSON.parse(trigger.dataset.fill) : {};
            Object.keys(data).forEach(function (name) {
                var field = dlg.querySelector('[name="' + name + '"]');
                if (field) { field.value = data[name]; }
                var label = dlg.querySelector('[data-text="' + name + '"]');
                if (label) { label.textContent = data[name]; }
            });
            dlg.showModal();
        });
    });
    document.querySelectorAll('dialog [data-close]').forEach(function (b) {
        b.addEventListener('click', function () { b.closest('dialog').close(); });
    });
    document.querySelectorAll('dialog').forEach(function (d) {
        d.addEventListener('click', function (ev) { if (ev.target === d) { d.close(); } });
    });
})();
</script>
</body>
</html>
    <?php
}
