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
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use RedAnt\TwigComponents\Registry as ComponentsRegistry;
use RedAnt\TwigComponents\Extension as ComponentsExtension;

require __DIR__ . '/../vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$container = new Container();
AppFactory::setContainer($container);

$container->set('view', function () {
    $twig = Twig::create(dirname(__DIR__) . '/views', ['cache' => false]);

    $componentsRegistry = new \RedAnt\TwigComponents\Registry($twig->getEnvironment());
    $componentsRegistry->addComponent('navbar', 'components/navbar.twig');
    $componentsRegistry->addComponent('navlink', 'components/navlink.twig');
    $componentsRegistry->addComponent('flashmsg', 'components/flashmsg.twig');
    $componentsExtension = new ComponentsExtension($componentsRegistry);
    $twig->addExtension($componentsExtension);

    return $twig;
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$container->set('db', function () {
    $databaseUrl = parse_url(Arr::get($_ENV, 'DATABASE_URL', ''));

    $username = Arr::get($databaseUrl, 'user', 'postgres');
    $password = Arr::get($databaseUrl, 'pass', 'postgres');
    $host = Arr::get($databaseUrl, 'host', 'localhost');
    $port = Arr::get($databaseUrl, 'port', '5432');

    $path = Str::of(Arr::get($databaseUrl, 'path'))->ltrim('/');
    $dbname = $path->isEmpty() ? 'php-project-9' : $path;

    return new \PDO("pgsql:host={$host};port={$port};dbname={$dbname};", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
});


$app = AppFactory::create();

$app->addRoutingMiddleware();

$isDebug = Arr::get($_ENV, 'APP_DEBUG', false);
$errorMiddleware = $app->addErrorMiddleware($isDebug, true, true);

$customErrorHandler = function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails,
) use ($app) {
    $actualCode = $exception->getCode();
    $error = $exception->getMessage();
    $line = $exception->getLine();
    $response = $app->getResponseFactory()->createResponse();

    switch ($actualCode) {
        case '404':
            return $this
                    ->get('view')
                    ->render($response, "{$actualCode}.twig", compact('error'))
                    ->withStatus($actualCode);
        default:
            return $this
                    ->get('view')
                    ->render($response, "500.twig", compact('error', 'actualCode', 'line'))
                    ->withStatus(500);
    }
};

$errorHandler = $errorMiddleware->setDefaultErrorHandler($customErrorHandler);

$app->add(TwigMiddleware::createFromContainer($app));

$app->get('/', function (Request $request, Response $response, array $args) {
    return $this->get('view')->render($response, 'index.twig');
})->setName('index');

$app->get('/urls', function (Request $request, Response $response, array $args) {
    $pdo = $this->get('db');

    $statement = $pdo->query('SELECT * FROM urls ORDER BY id DESC;');
    $urls = $statement->fetchAll();
    $checksStatement = $pdo->query('SELECT DISTINCT ON (url_id) * from url_checks order by url_id DESC, id DESC');
    $checks = $checksStatement->fetchAll();

    $params = [
        'urls' => $urls,
        'checks' => Arr::keyBy($checks, 'url_id'),
    ];

    return $this->get('view')->render($response, 'urls.twig', $params);
})->setName('urls.index');

$app->get('/urls/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
    $id = $args['id'];
    $messages = $this->get('flash')->getMessages();

    $pdo = $this->get('db');

    $getUrlInfo = $pdo->prepare("SELECT * FROM urls WHERE id=:id");
    $getUrlInfo->bindParam('id', $id, PDO::PARAM_INT);
    $getUrlInfo->execute();
    $urlInfo = optional($getUrlInfo)->fetch();

    if (!$urlInfo) {
        throw new \Exception("Page not found", 404);
    }

    $getChecks = $pdo->prepare("SELECT * FROM url_checks WHERE url_id=:id ORDER BY created_at DESC");
    $getChecks->bindParam('id', $id, PDO::PARAM_INT);
    $getChecks->execute();
    $checks = optional($getChecks)->fetchAll();

    return $this->get('view')->render($response, 'show.url.twig', compact('urlInfo', 'checks', 'messages'));
})->setName('urls.show');
;

$app->post('/urls', function (Request $request, Response $response, array $args) use ($app) {
    $formData = $request->getParsedBody();
    $now = Carbon::now()->toDateTimeString();

    $validator = new Validator($_POST);
    $validator->rule('required', 'url.name');
    $validator->rule('url', 'url.name');

    if (!$validator->validate()) {
        return $this->get('view')->render($response, 'index.twig', [
            'errors' => $validator->errors(),
            'formData' => $formData,
        ])->withStatus(422);
    }

    $pdo = $this->get('db');

    $name = Arr::get($formData, 'url.name', '');
    $checkExistence = $pdo->prepare("SELECT * FROM urls WHERE name=:name");
    $checkExistence->bindParam('name', $name, PDO::PARAM_INT);
    $checkExistence->execute();
    $row = optional($checkExistence)->fetch();

    if (!$row) {
        $query = "INSERT INTO urls (name, created_at) VALUES (:name, :now)";
        $statement = $pdo->prepare($query);
        $statement->bindParam('name', $name);
        $statement->bindParam('now', $now);
        $statement->execute();
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

$app->post('/urls/{url_id:[0-9]+}/checks', function (Request $request, Response $response, array $args) use ($app) {
    $urlId = $args['url_id'];
    $now = Carbon::now()->toDateTimeString();
    $pdo = $this->get('db');

    $routeParser = $app->getRouteCollector()->getRouteParser();

    $query = "SELECT name FROM urls WHERE id=:urlId";
    $statement = $pdo->prepare($query);
    $statement->bindParam('urlId', $urlId);
    $statement->execute();
    $result = optional($statement)->fetch();
    $url = $result ? $result['name'] : null;

    $client = new GuzzleHttp\Client(['timeout' => 2 ]);

    try {
        $res = $client->request('GET', $url);
    } catch (RequestException $e) {
        $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил с ошибкой');
        return $response->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => $urlId]))->withStatus(302);
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => $urlId]))->withStatus(302);
    } catch (\Throwable $e) {
        $this->get('flash')->addMessage('error', 'Произошла неизвестная ошибка при попытке проверить сайт');
        return $response->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => $urlId]))->withStatus(302);
    }

    $statusCode = $res->getStatusCode();
    $html = file_get_contents($url);

    $document = new \DiDom\Document($url, true);
    $h1Tag = $document->first('h1');
    $titleTag = $document->first('title');

    $description = (string) optional($document->first('meta[name=description]'))->getAttribute('content');
    $h1 = optional($h1Tag)->text();
    $title = optional($titleTag)->text();

    $query1 = "INSERT INTO url_checks (url_id, created_at, status_code, h1, title, description) ";
    $query2 = "VALUES (:urlId, :now, :statusCode, :h1, :title, :description)";
    $statement = $pdo->prepare($query1 . $query2);
    $statement->bindParam('urlId', $urlId, PDO::PARAM_INT);
    $statement->bindParam('now', $now);
    $statement->bindParam('statusCode', $statusCode);
    $statement->bindParam('h1', $h1);
    $statement->bindParam('title', $title);
    $statement->bindParam('description', $description);
    $statement->execute();

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    return $response->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => $urlId]))->withStatus(302);
})->setName('checks.make');

$app->run();
