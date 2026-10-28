<?php
require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/partials/layout.php';
require_once __DIR__ . '/classes/Participant.php';

// The registry holds personal data of every resident: custodian only.
if (!$isCustodian) {
    $_SESSION['toolshare_flash'] = ['message' => 'The participant registry is restricted to the custodian.', 'type' => 'error'];
    header('Location: toolshare.php');
    exit();
}

$participants = (new Participant($conn))->getAllParticipants();
usort($participants, static fn($a, $b) => strcasecmp(($a->last_name ?? '') . ($a->first_name ?? ''), ($b->last_name ?? '') . ($b->first_name ?? '')));
$linked = count(array_filter($participants, static fn($p) => !empty($p->user_id)));
$cities = count(array_unique(array_filter(array_map(static fn($p) => strtolower(trim($p->city ?? '')), $participants))));

$add = '<button class="btn primary" type="button" data-dialog="dlgPerson" data-fill=\'' . e(json_encode([
    'action' => 'add_participant', 'participant_id' => 0, 'first_name' => '', 'last_name' => '', 'email' => '',
    'gender' => 'Female', 'birthdate' => '', 'city' => '', 'dialog_title' => 'Add participant',
])) . '\'>' . icon('plus') . 'Add participant</button>';

layout_start('registry', 'Participant registry', 'Restricted: personal data is masked in the list and shown in full only when you edit a record.', $add);
?>
<div class="stat-grid">
    <div class="stat s-registry"><div class="row">Participants<span class="chip-icon"><?php echo icon('users'); ?></span></div><strong><?php echo count($participants); ?></strong><small>Registered people</small></div>
    <div class="stat s-registry"><div class="row">With login<span class="chip-icon"><?php echo icon('shield'); ?></span></div><strong><?php echo $linked; ?></strong><small>Linked to an account</small></div>
    <div class="stat s-registry"><div class="row">Communities<span class="chip-icon"><?php echo icon('home'); ?></span></div><strong><?php echo $cities; ?></strong><small>Distinct cities</small></div>
</div>

<section class="card">
    <div class="card-head">
        <div class="toolbar">
            <input type="search" id="participantSearch" placeholder="Search by name or community" aria-label="Search participants">
            <select id="genderFilter" aria-label="Filter by gender">
                <option value="all">All genders</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option>
            </select>
        </div>
        <span class="lock-note"><?php echo icon('lock'); ?>Email &amp; birthdate masked</span>
    </div>
    <?php if (!$participants): ?>
        <div class="empty"><?php echo icon('users'); ?><strong>No participants yet</strong><span>Add the first participant to get started.</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data" id="participantTable">
            <thead><tr><th>Participant</th><th>Community</th><th>Gender</th><th>Age</th><th>Account</th><th class="actions">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($participants as $p):
                $full = trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? ''));
                $gender = strtolower(trim($p->gender ?? 'other'));
                $gender = in_array($gender, ['female', 'male'], true) ? $gender : 'other';
                $self = (int) ($p->user_id ?? 0) === $userId;
                $fill = e(json_encode([
                    'action' => 'update_participant', 'participant_id' => (int) $p->participant_id,
                    'first_name' => $p->first_name, 'last_name' => $p->last_name, 'email' => $p->email,
                    'gender' => ucfirst($gender), 'birthdate' => $p->birthdate, 'city' => $p->city, 'dialog_title' => 'Edit participant',
                ]));
            ?>
                <tr data-search="<?php echo e(strtolower($full . ' ' . ($p->city ?? ''))); ?>" data-gender="<?php echo e($gender); ?>">
                    <td><div class="cell-person"><span class="avatar"><?php echo e(initials($full)); ?></span><div><div class="cell-main"><?php echo e($full ?: 'Participant'); ?><?php echo $self ? ' <span class="chip plain">You</span>' : ''; ?></div><div class="cell-sub masked"><?php echo e(mask_email((string) ($p->email ?? ''))); ?></div></div></div></td>
                    <td><?php echo e($p->city ?: '—'); ?></td>
                    <td><?php echo e(ucfirst($gender)); ?></td>
                    <td><?php echo e(age_from($p->birthdate ?? null)); ?></td>
                    <td><?php echo !empty($p->user_id) ? '<span class="chip ok">Has login</span>' : '<span class="chip plain">No login</span>'; ?></td>
                    <td class="actions"><span class="inline">
                        <button class="btn sm" type="button" data-dialog="dlgPerson" data-fill='<?php echo $fill; ?>'><?php echo icon('edit'); ?>Edit</button>
                        <?php if (!$self): ?><button class="btn sm danger" type="button" data-dialog="dlgDeletePerson" data-fill='<?php echo e(json_encode(['participant_id' => (int) $p->participant_id, 'person_name' => $full])); ?>'>Delete</button><?php endif; ?>
                    </span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="empty" id="noMatch" hidden><?php echo icon('search'); ?><strong>No participants match your search</strong></div>
    </div>
    <?php endif; ?>
</section>

<dialog id="dlgPerson" aria-labelledby="dlgPersonT"><form method="post" action="backend/participant_code.php" autocomplete="off">
    <?php echo csrf_fields('registry'); ?><input type="hidden" name="action" value="add_participant"><input type="hidden" name="participant_id" value="0">
    <div class="dlg-head"><span class="head-icon"><?php echo icon('user'); ?></span><div><h3 id="dlgPersonT" data-text="dialog_title">Add participant</h3><p>Full personal details are visible here only.</p></div></div>
    <div class="dlg-body"><div class="form-grid">
        <div class="field"><label>First name</label><input name="first_name" maxlength="100" required></div>
        <div class="field"><label>Last name</label><input name="last_name" maxlength="100" required></div>
        <div class="field full"><label>Email</label><input type="email" name="email" maxlength="255" required></div>
        <div class="field"><label>Gender</label><select name="gender" required><option>Female</option><option>Male</option><option>Other</option></select></div>
        <div class="field"><label>Birthdate</label><input type="date" name="birthdate" max="<?php echo date('Y-m-d'); ?>" required></div>
        <div class="field full"><label>City / community</label><input name="city" maxlength="150"></div>
    </div></div>
    <div class="dlg-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn primary" type="submit">Save participant</button></div>
</form></dialog>

<dialog id="dlgDeletePerson" aria-labelledby="dlgDelPT"><form method="post" action="backend/participant_code.php">
    <?php echo csrf_fields('registry'); ?><input type="hidden" name="action" value="delete_participant"><input type="hidden" name="participant_id">
    <div class="dlg-head" style="border-top-color:var(--danger)"><span class="head-icon" style="background:var(--danger)"><?php echo icon('alert'); ?></span><div><h3 id="dlgDelPT">Delete participant?</h3><p data-text="person_name"></p></div></div>
    <div class="dlg-body">This permanently removes the participant and their event registrations. This cannot be undone.</div>
    <div class="dlg-foot"><button type="button" class="btn" data-close>Keep</button><button class="btn danger solid" type="submit">Delete participant</button></div>
</form></dialog>

<script>
(function () {
    var search = document.getElementById('participantSearch'), gender = document.getElementById('genderFilter');
    var rows = document.querySelectorAll('#participantTable tbody tr'), none = document.getElementById('noMatch');
    function apply() {
        var q = search.value.trim().toLowerCase(), g = gender.value, shown = 0;
        rows.forEach(function (r) {
            var ok = (!q || r.dataset.search.indexOf(q) > -1) && (g === 'all' || r.dataset.gender === g);
            r.hidden = !ok; if (ok) { shown++; }
        });
        if (none) { none.hidden = shown !== 0; }
    }
    if (search) { search.addEventListener('input', apply); gender.addEventListener('change', apply); }
})();
</script>
<?php layout_end();
