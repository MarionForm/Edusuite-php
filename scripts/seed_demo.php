<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\DB;

DB::init(__DIR__ . '/../storage/edusuite.sqlite');
$pdo = DB::pdo();

// crea docente demo (id=1)
$email = 'docente@demo.local';
$pass  = password_hash('1234', PASSWORD_DEFAULT);

$pdo->beginTransaction();

$stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
$stmt->execute([$email]);
$exists = $stmt->fetchColumn();

if (!$exists) {
  $ins = $pdo->prepare("INSERT INTO users(name,email,pass_hash,role) VALUES (?,?,?,?)");
  $ins->execute(['Docente Demo', $email, $pass, 'docente']);
  $newId = (int)$pdo->lastInsertId();
  echo "✅ Usuario creado: {$email} (id={$newId}) pass=1234\n";
} else {
  echo "ℹ️ Usuario ya existe: {$email} (id={$exists})\n";
}

$pdo->commit();
