<?php
class Participant {
    private $conn;

    public function __construct($conn){
        $this->conn = $conn;
    }

    public function getAllParticipants(){
        $query = "SELECT * FROM participants";
        $stmt = $this->conn->query($query);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public function find($participant_id){
        $stmt = $this->conn->prepare("SELECT * FROM participants WHERE participant_id = :id LIMIT 1");
        $stmt->execute(['id' => $participant_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }

    public function emailInUse($email, $exceptParticipantId = 0){
        $stmt = $this->conn->prepare("SELECT participant_id FROM participants WHERE email = :email AND participant_id <> :id LIMIT 1");
        $stmt->execute(['email' => $email, 'id' => $exceptParticipantId]);
        return (bool) $stmt->fetchColumn();
    }

    public function addParticipant($first_name, $last_name, $email, $gender, $birthdate, $city){
        $query = "INSERT INTO participants (first_name, last_name, email, gender, birthdate, city) VALUES (:first_name, :last_name, :email, :gender, :birthdate, :city)";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'gender' => $gender,
            'birthdate' => $birthdate,
            'city' => $city,
        ]);
    }

    public function registerParticipant($user_id, $first_name, $last_name, $email, $gender, $birthdate, $city){
        $query = "INSERT INTO participants (user_id, first_name, last_name, email, gender, birthdate, city) VALUES (:user_id, :first_name, :last_name, :email, :gender, :birthdate, :city)";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            'user_id' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'gender' => $gender,
            'birthdate' => $birthdate,
            'city' => $city,
        ]);
    }

    public function updateParticipant($participant_id, $first_name, $last_name, $email, $gender, $birthdate, $city){
        $query = "UPDATE participants SET first_name = :first_name, last_name = :last_name, email = :email, gender = :gender, birthdate = :birthdate, city = :city WHERE participant_id = :participant_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            'participant_id' => $participant_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'gender' => $gender,
            'birthdate' => $birthdate,
            'city' => $city,
        ]);
    }

    public function deleteParticipant($participant_id){
        $this->conn->beginTransaction();

        try {
            $deleteRegistrations = "DELETE FROM registrations WHERE participant_id = :participant_id";
            $stmtRegistrations = $this->conn->prepare($deleteRegistrations);
            $stmtRegistrations->execute([
                'participant_id' => $participant_id,
            ]);

            $query = "DELETE FROM participants WHERE participant_id = :participant_id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                'participant_id' => $participant_id,
            ]);

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            return false;
        }
    }
}
?>