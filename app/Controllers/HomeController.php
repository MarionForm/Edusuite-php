<?php
namespace App\Controllers;

use App\Core\View;

final class HomeController {
  public function index(): void {
    View::render('home');
  }
}
