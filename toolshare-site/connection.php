<?php
$config = require __DIR__ . '/config.php';

$servername = $config['host'];
$username   = $config['user'];
$password   = $config['pass'];
$dbname     = $config['name'];

try {
  $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
  error_log('Database connection failed: ' . $e->getMessage());
  http_response_code(500);
  die('The service is temporarily unavailable. Please check the database settings in config.php.');
}
?>
