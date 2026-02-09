<?php
namespace App\Core;

final class View {
  public static function render(string $view, array $data = []): void {
    extract($data, EXTR_SKIP);
    require __DIR__ . '/../Views/' . $view . '.php';
  }
}
