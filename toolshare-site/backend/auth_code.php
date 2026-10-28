<?php
require_once __DIR__ . '/../partials/session.php';

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Participant.php';

$userObj = new User($conn);
$participantObj = new Participant($conn);

/* =========================================
   REGISTER
========================================= */

if (isset($_POST['register_account'])) {

    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $gender     = (string) ($_POST['gender'] ?? '');
    $birthdate  = (string) ($_POST['birthdate'] ?? '');
    $city       = trim($_POST['city']);

    $username   = trim($_POST['username']);
    $password   = $_POST['password'];
    $confirm    = $_POST['confirm_password'];

    if ($password !== $confirm) {
        header("Location: ../register.php?error=" . urlencode("Passwords do not match."));
        exit();
    }

    if (strlen($password) < 8) {
        header("Location: ../register.php?error=" . urlencode("Password must be at least 8 characters."));
        exit();
    }

    if ($first_name === '' || $last_name === '' || $username === '' || $city === '') {
        header("Location: ../register.php?error=" . urlencode("Please complete every field."));
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: ../register.php?error=" . urlencode("Enter a valid email address."));
        exit();
    }

    if (!in_array($gender, ['Female', 'Male', 'Other'], true)) {
        header("Location: ../register.php?error=" . urlencode("Choose a gender option."));
        exit();
    }

    $birth = DateTime::createFromFormat('!Y-m-d', (string) $birthdate);
    if (!$birth || $birth->format('Y-m-d') !== $birthdate || $birthdate > date('Y-m-d')) {
        header("Location: ../register.php?error=" . urlencode("Enter a valid birthdate."));
        exit();
    }

    $user_id = $userObj->registerUser($username, $password, 'participant');

    if ($user_id === false) {
        header("Location: ../register.php?error=" . urlencode("That username is already taken."));
        exit();
    }

    $participantObj->registerParticipant($user_id, $first_name, $last_name, $email, $gender, $birthdate, $city);

    header("Location: ../login.php?registered=1");
    exit();
}


/* =========================================
   LOGIN
========================================= */

if (isset($_POST['login_user'])) {

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        header("Location: ../login.php?error=" . urlencode("Enter your username and password."));
        exit();
    }

    $user = $userObj->authenticate($username, $password);

    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user_id']  = $user->user_id;
        $_SESSION['username'] = $user->username;
        $_SESSION['role']     = $user->role;

        header("Location: ../toolshare.php");
        exit();
    }

    header("Location: ../login.php?error=" . urlencode("Incorrect username or password."));
    exit();
}

header('Location: ../login.php');
exit();