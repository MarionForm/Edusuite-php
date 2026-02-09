<?php
namespace App\Core;

use PDO;

final class DB {
  private static ?PDO $pdo = null;

  public static function init(string $sqlitePath): void {
    self::$pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    self::$pdo->exec('PRAGMA foreign_keys = ON;');
  }

  public static function pdo(): PDO {
    if (!self::$pdo) throw new \RuntimeException("DB no inicializada");
    return self::$pdo;
  }
}
