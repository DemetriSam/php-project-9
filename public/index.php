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
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Hexlet\Code\MyCustomErrorRenderer;

require __DIR__ . '/../vendor/autoload.php';

session_start();

$container = new Container();
AppFactory::setContainer($container);

$container->set('view', function () {
    return Twig::create('../views');
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$container->set('db', function () {
    $databaseUrl = parse_url(getenv('DATABASE_URL'));

    $username = Arr::get($databaseUrl, 'user', 'postgres');
    $password = Arr::get($databaseUrl, 'pass', 'postgres');
    $host = Arr::get($databaseUrl, 'host', 'localhost');
    $port = Arr::get($databaseUrl, 'port', '5433');

    $path = Str::of(Arr::get($databaseUrl, 'path'))->ltrim('/');
    $dbname = $path->isEmpty() ? 'php-project-9' : $path;

    return connect($host, $port, $dbname, $username, $password);
});


$app = AppFactory::create();

$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->registerErrorRenderer('text/html', MyCustomErrorRenderer::class);

$app->add(TwigMiddleware::createFromContainer($app));

$app->get('/', function (Request $request, Response $response, array $args) {
    return $this->get('view')->render($response, 'index.html');
})->setName('index');

$app->get('/urls', function (Request $request, Response $response, array $args) {

    $pdo = $this->get('db');
    $query =
        "SELECT nested.id, nested.name, url_checks.status_code, nested.last_check, nested.created_at
        FROM (	
            SELECT urls.id, urls.name, urls.created_at, MAX(url_checks.created_at) as last_check
                    FROM urls 
                    LEFT JOIN url_checks
                    ON urls.id = url_checks.url_id
                    GROUP BY urls.id
        ) AS nested
        LEFT JOIN url_checks
        ON nested.id  = url_checks.url_id and nested.last_check = url_checks.created_at
        ORDER BY nested.created_at DESC";
    $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    return $this->get('view')->render($response, 'urls.html', compact('rows'));
})->setName('urls');

$app->get('/urls/{id}', function (Request $request, Response $response, array $args) {
    $id = $args['id'];
    $messages = $this->get('flash')->getMessages();

    $pdo = $this->get('db');

    $getUrlInfo = "SELECT * FROM urls WHERE id=$id";
    $urlInfo = optional($pdo->query($getUrlInfo))->fetch(PDO::FETCH_ASSOC);

    $getChecks = "SELECT * FROM url_checks WHERE url_id=$id ORDER BY created_at DESC";
    $checks = $pdo->query($getChecks)->fetchAll(PDO::FETCH_ASSOC);

    return $this->get('view')->render($response, 'show.url.html', compact('urlInfo', 'checks', 'messages'));
})->setName('urls.show');
;

$app->post('/urls', function (Request $request, Response $response, array $args) use ($app) {
    $name = $_POST['url']['name'];
    $now = Carbon::now()->toDateTimeString();
    $validator = new Validator($_POST);
    $validator->rule('required', 'url.name');
    $validator->rule('url', 'url.name');
    if (!$validator->validate()) {
        $customMessages = [
            'Url.name is required' => 'URL не должен быть пустым',
            'Url.name is not a valid URL' => 'Некорректный URL',
        ];

        $errors = $validator->errors();
        /** @phpstan-ignore-next-line */
        $messages = $errors ? $errors['url.name'] : [];

        $params = [
            'error' => $customMessages[$messages[0]],
            'oldValue' => $name,
        ];

        return $this->get('view')->render($response, 'index.html', $params)->withStatus(422);
    }

    $pdo = $this->get('db');

    $checkExistence = "SELECT * FROM urls WHERE name='$name'";
    $row = optional($pdo->query($checkExistence))->fetch();

    if (!$row) {
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

$app->post('/urls/{url_id}/checks', function (Request $request, Response $response, array $args) use ($app) {
    $urlId = $args['url_id'];
    $now = Carbon::now()->toDateTimeString();
    $pdo = $this->get('db');
    ;
    $routeParser = $app->getRouteCollector()->getRouteParser();

    $query = "SELECT name FROM urls WHERE id=$urlId";
    $result = optional($pdo->query($query))->fetch();
    $url = $result ? $result['name'] : null;

    $client = new GuzzleHttp\Client();

    try {
        $res = $client->request('GET', $url);
    } catch (\Throwable $e) {
        $this->get('flash')->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => $urlId]))->withStatus(302);
    }

    $statusCode = $res->getStatusCode();

    $document = new \DiDom\Document($url, true);
    $h1Tag = $document->first('h1');
    $titleTag = $document->first('title');
    $metaDescription = $document->first('meta[name=description]');

    $description = Str::between($metaDescription, 'content="', '"');
    $h1 = optional($h1Tag)->text();
    $title = optional($titleTag)->text();

    $query1 = "INSERT INTO url_checks (url_id, created_at, status_code, h1, title, description) ";
    $query2 = "VALUES ('$urlId', '$now', $statusCode, '$h1', '$title', '$description')";
    $pdo->query($query1 . $query2);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    return $response->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => $urlId]))->withStatus(302);
})->setName('checks.make');

$app->run();
