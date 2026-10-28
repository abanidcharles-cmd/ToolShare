<?php
require_once __DIR__ . '/../partials/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../classes/Participant.php';

function registryRedirect(string $message, string $type = 'success'): void
{
    $_SESSION['toolshare_flash'] = ['message' => $message, 'type' => $type];
    header('Location: ../index.php');
    exit();
}

// Only the custodian may touch the participant registry.
$roleStmt = $conn->prepare('SELECT role FROM users_table WHERE user_id = ? LIMIT 1');
$roleStmt->execute([(int) $_SESSION['user_id']]);
if ($roleStmt->fetchColumn() !== 'custodian') {
    http_response_code(403);
    $_SESSION['toolshare_flash'] = ['message' => 'Only the custodian can manage participants.', 'type' => 'error'];
    header('Location: ../toolshare.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit();
}
if (!isset($_POST['csrf_token'], $_SESSION['toolshare_csrf'])
    || !hash_equals($_SESSION['toolshare_csrf'], (string) $_POST['csrf_token'])) {
    registryRedirect('Your session token expired. Please try that action again.', 'error');
}

$participant = new Participant($conn);
$action = (string) ($_POST['action'] ?? '');

/** Trim and validate the shared participant fields; returns [data, error]. */
function readParticipantInput(): array
{
    $data = [
        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
        'last_name'  => trim((string) ($_POST['last_name'] ?? '')),
        'email'      => trim((string) ($_POST['email'] ?? '')),
        'gender'     => trim((string) ($_POST['gender'] ?? '')),
        'birthdate'  => trim((string) ($_POST['birthdate'] ?? '')),
        'city'       => trim((string) ($_POST['city'] ?? '')),
    ];
    if ($data['first_name'] === '' || $data['last_name'] === '' || mb_strlen($data['first_name']) > 100 || mb_strlen($data['last_name']) > 100) {
        return [$data, 'Enter a first and last name (100 characters maximum each).'];
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 255) {
        return [$data, 'Enter a valid email address.'];
    }
    if (!in_array($data['gender'], ['Female', 'Male', 'Other'], true)) {
        return [$data, 'Choose a gender option.'];
    }
    $date = DateTime::createFromFormat('!Y-m-d', $data['birthdate']);
    if (!$date || $date->format('Y-m-d') !== $data['birthdate'] || $data['birthdate'] > date('Y-m-d')) {
        return [$data, 'Enter a valid birthdate that is not in the future.'];
    }
    if (mb_strlen($data['city']) > 150) {
        return [$data, 'City / community is too long (150 characters maximum).'];
    }
    return [$data, null];
}

try {
    switch ($action) {
        case 'add_participant':
            [$d, $error] = readParticipantInput();
            if ($error) { registryRedirect($error, 'error'); }
            if ($participant->emailInUse($d['email'])) { registryRedirect('That email is already registered to another participant.', 'error'); }
            $participant->addParticipant($d['first_name'], $d['last_name'], $d['email'], $d['gender'], $d['birthdate'], $d['city']);
            registryRedirect('Participant added.');

        case 'update_participant':
            $id = (int) ($_POST['participant_id'] ?? 0);
            if (!$participant->find($id)) { registryRedirect('That participant no longer exists.', 'error'); }
            [$d, $error] = readParticipantInput();
            if ($error) { registryRedirect($error, 'error'); }
            if ($participant->emailInUse($d['email'], $id)) { registryRedirect('That email is already registered to another participant.', 'error'); }
            $participant->updateParticipant($id, $d['first_name'], $d['last_name'], $d['email'], $d['gender'], $d['birthdate'], $d['city']);
            registryRedirect('Participant updated.');

        case 'delete_participant':
            $id = (int) ($_POST['participant_id'] ?? 0);
            $target = $participant->find($id);
            if (!$target) { registryRedirect('That participant no longer exists.', 'error'); }
            if ((int) $target->user_id === (int) $_SESSION['user_id']) {
                registryRedirect('You cannot delete your own participant profile.', 'error');
            }
            $participant->deleteParticipant($id)
                ? registryRedirect('Participant deleted.')
                : registryRedirect('The participant could not be deleted.', 'error');

        default:
            registryRedirect('Unknown action.', 'error');
    }
} catch (PDOException $exception) {
    error_log('Participant action failed: ' . $exception->getMessage());
    registryRedirect('The change could not be saved due to a database error.', 'error');
}
