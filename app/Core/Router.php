<?php
namespace App\Core;

final class Router {
  private array $routes = ['GET'=>[], 'POST'=>[]];

  public function get(string $path, string $handler): void { $this->routes['GET'][$path] = $handler; }
  public function post(string $path, string $handler): void { $this->routes['POST'][$path] = $handler; }

  public function dispatch(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $handler = $this->routes[$method][$uri] ?? null;
    if (!$handler) { http_response_code(404); echo "404"; return; }

    [$class, $action] = explode('@', $handler);
    $controller = new $class();
    $controller->$action();
  }
}
