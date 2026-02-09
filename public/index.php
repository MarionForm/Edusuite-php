<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Router;
use App\Core\DB;

DB::init(__DIR__ . '/../storage/edusuite.sqlite');

$router = new Router();

$router->get('/', 'App\\Controllers\\HomeController@index');

$router->get('/docs', 'App\\Controllers\\DocsController@index');
$router->post('/docs/create', 'App\\Controllers\\DocsController@create');
$router->get('/docs/export', 'App\\Controllers\\DocsController@exportPdf');

$router->dispatch();
