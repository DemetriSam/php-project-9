<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$twig = Twig::create('../views');
$app->add(TwigMiddleware::create($app, $twig));

if(isset($_ENV['DATABASE_URL'])) {
    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    $username = $databaseUrl['user'];
    $password = $databaseUrl['pass'];
    $host = $databaseUrl['host'];
    $port = $databaseUrl['port'];
    $dbName = ltrim($databaseUrl['path'], '/');
} else {
    $username = 'php-project-9';
    $password = 'password';
    $host = 'localhost';
    $port = '5433';
    $dbname = 'php-project-9';
}

$pdo = connect($host, $port, $dbname, $username, $password);

$app->get('/', function (Request $request, Response $response, array $args) {
    $view = Twig::fromRequest($request);
    return $view->render($response, 'index.html');
})->setName('index');

$app->get('/urls', function (Request $request, Response $response, array $args) {
    $view = Twig::fromRequest($request);
    return $view->render($response, 'urls.html');
})->setName('urls');

$app->post('/urls', function (Request $request, Response $response, array $args) {
    
})->setName('urls.store');

$app->run();
