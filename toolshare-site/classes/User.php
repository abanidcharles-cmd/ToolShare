<?php
class User {

    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function findByUsername($username) {
        $query = "SELECT * FROM users_table WHERE username = :username LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute(['username' => $username]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }

    public function authenticate($username, $password) {
        $user = $this->findByUsername($username);

        if ($user && password_verify($password, $user->password)) {
            unset($user->password);
            return $user;
        }

        return false;
    }

    public function registerUser($username, $password, $role = 'participant') {

        if ($this->findByUsername($username)) {
            return false;
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $query = "INSERT INTO users_table (username, password, role) VALUES (:username, :password, :role)";
        $stmt = $this->conn->prepare($query);

        $success = $stmt->execute([
            'username' => $username,
            'password' => $hashed,
            'role' => $role
        ]);

        if (!$success) {
            return false;
        }

        return $this->conn->lastInsertId();
    }
}