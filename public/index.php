<?php

use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Carbon\Carbon;
use Valitron\Validator;
use Slim\Routing\RouteParser;

require __DIR__ . '/../vendor/autoload.php';

session_start();

$container = new Container();
AppFactory::setContainer($container);

$container->set('view', function() {
    return Twig::create('../views');
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$app = AppFactory::create();

$app->add(TwigMiddleware::createFromContainer($app));

if (isset($_ENV['DATABASE_URL'])) {
    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    $username = $databaseUrl['user'];
    $password = $databaseUrl['pass'];
    $host = $databaseUrl['host'];
    $port = $databaseUrl['port'];
    $dbname = ltrim($databaseUrl['path'], '/');
} else {
    $username = 'postgres';
    $password = 'postgres';
    $host = 'localhost';
    $port = '5433';
    $dbname = 'php-project-9';
}

$dbconfig = [$host, $port, $dbname, $username, $password];

$app->get('/', function (Request $request, Response $response, array $args) {
    return $this->get('view')->render($response, 'index.html');
})->setName('index');

$app->get('/urls', function (Request $request, Response $response, array $args) use ($dbconfig) {
    $pdo = connect(...$dbconfig);
    $query = "SELECT * FROM urls";
    $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    return $this->get('view')->render($response, 'urls.html', compact('rows'));
})->setName('urls');

$app->get('/urls/{id}', function (Request $request, Response $response, array $args) use ($dbconfig) {
    $id = $args['id'];
    $messages = $this->get('flash')->getMessages();
    $pdo = connect(...$dbconfig);
    $query = "SELECT * FROM urls WHERE id=$id";
    $row = $pdo->query($query)->fetch(PDO::FETCH_ASSOC);
    return $this->get('view')->render($response, 'show.url.html', compact('row', 'messages'));
})->setName('urls.show');;

$app->post('/urls', function (Request $request, Response $response, array $args) use ($dbconfig, $app) {
    $name = $_POST['url']['name'];
    $now = Carbon::now()->toDateTimeString();
    $validator = new Validator($_POST);
    $validator->rule('required', 'url.name');
    $validator->rule('url', 'url.name');
    if(!$validator->validate()) {
        $customMessages = [
            'Url.name is required' => 'URL не должен быть пустым',
            'Url.name is not a valid URL' => 'Некорректный URL',
        ];

        $params = [
            'error' => $customMessages[$validator->errors()['url.name'][0]],
            'oldValue' => $name,
        ];
        
        return $this->get('view')->render($response, 'index.html', $params);
    }
    
    $pdo = connect(...$dbconfig);

    $checkExistence = "SELECT * FROM urls WHERE name='$name'";
    $row = $pdo->query($checkExistence)->fetch();
    
    if(!$row) {
        $query = "INSERT INTO urls (name, created_at) VALUES ('$name', '$now')";
        $pdo->query($query);
        $id = $pdo->lastInsertId();
        $message = 'Страница успешно добавлена';
    } else {
        $id = $row['id'];
        $message = 'Страница уже существует';
    }
    $routeParser = $app->getRouteCollector()->getRouteParser();
    $this->get('flash')->addMessage('success', $message);
    return $response->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => $id]))->withStatus(302);
})->setName('urls.store');

$app->run();
