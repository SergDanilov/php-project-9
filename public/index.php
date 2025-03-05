<?php

$autoloadPath1 = __DIR__ . '/../../autoload.php';
$autoloadPath2 = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoloadPath1)) {
    require_once $autoloadPath1;
} else {
    require_once $autoloadPath2;
}

use Slim\Factory\AppFactory;
use Slim\Http\ServerRequest;
use Slim\Http\Response;
use Slim\Middleware\MethodOverrideMiddleware;
use DI\Container;
use App\DataBaseHelper;
use App\Validator;
use App\Normalizer;
use DiDom\Document;
use GuzzleHttp\Client;
use Illuminate\Support;
use Carbon\Carbon;
use Dotenv\Dotenv;

use function DI\string;

// Старт PHP сессии
session_start();

$container = new Container();
$app = AppFactory::createFromContainer($container);

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeload();


// Соединение с бд
$container->set('db', function () {
    // Используем переменную DATABASE_URL из файла .env, если он есть
    $databaseUrl = (getenv('DATABASE_URL') !== false) ? getenv('DATABASE_URL') : ($_ENV['DATABASE_URL'] ?? null);

    if (empty($databaseUrl)) {
        throw new InvalidArgumentException(
            'DATABASE_URL is not set in the environment variables.'
        );
    }

    $parts = parse_url($databaseUrl);

    if ($parts === false) {
        throw new InvalidArgumentException('Invalid DATABASE_URL');
    }

    // Извлекаем значения с дефолтными значениями
    $host = \Illuminate\Support\Arr::get($parts, 'host', 'localhost');
    $port = \Illuminate\Support\Arr::get($parts, 'port', 5432); // Порт по умолчанию для PostgreSQL
    $dbName = ltrim(\Illuminate\Support\Arr::get($parts, 'path', 'database_9'), '/');
    $user = \Illuminate\Support\Arr::get($parts, 'user', 'analyzer_user');
    $pass = \Illuminate\Support\Arr::get($parts, 'pass', '');

    // Проверяем обязательные параметры
    if (empty($host) || empty($dbName)) {
        throw new InvalidArgumentException('Invalid DATABASE_URL: missing host or database name');
    }

    $dsn = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s",
        $host,
        $port,
        $dbName
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    return new PDO($dsn, $user, $pass, $options);
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$container->set('router', $app->getRouteCollector()->getRouteParser());
$container->set('renderer', function () use ($container) {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    $phpView = new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
    $phpView->addAttribute('router', $container->get('router'));
    $phpView->setLayout('layout.phtml');
    return $phpView;
});




$router = $app->getRouteCollector()->getRouteParser();

$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

//1. главная страница
$app->get('/', function (ServerRequest $request, Response $response): Response {

    $messages = $this->get('flash')->getMessages();
    $params = [
        'url' => '',
        'flash' => $messages ?? [],
        'page' => 'main'
    ];

    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('main');

//2. список страниц
$app->get('/urls', function (ServerRequest $request, Response $response): Response {
    $dataBase = new DataBaseHelper();
    $urlsList = $dataBase->getUrls($this->get('db'));
    $messages = $this->get('flash')->getMessages();

    $lastChecks = $dataBase->getLastUrlChecks($this->get('db'));
    $lastChecksByUrlId = \Illuminate\Support\Arr::keyBy($lastChecks, 'url_id');

    $params = [
        'urls' => $urlsList,
        'flash' => $messages,
        'lastChecks' => $lastChecksByUrlId,
        'page' => 'urls'
    ];

    return $this->get('renderer')->render($response, '/urls/urls_show.phtml', $params);
})->setName('urls.show');

//3. добавление нового урла в список страниц и в бд
$app->post('/urls', function (ServerRequest $request, Response $response) use ($router): Response {
    $urlData = $request->getParsedBodyParam('url');
    $validator = new Validator();
    $errors = $validator->validate($urlData);

    $normalizer = new Normalizer();
    $rawUrl = $urlData['name'] ?? '';
    $normalizedUrl = $normalizer->normalizeUrl($rawUrl);

    if (!$normalizedUrl) {
        $errors['name'] = 'Некорректный URL';
    }

    if (!empty($errors)) {
        return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', [
            'urlData' => $urlData,
            'errors' => $errors
        ]);
    }

    $db = $this->get('db');
    $dataBase = new DataBaseHelper();

    try {

        $existingUrl = $dataBase->findUrlByName($db, $normalizedUrl);

        if ($existingUrl) {
            $this->get('flash')->addMessage('error', 'Страница уже существует!');
            return $response->withRedirect(
                $router->urlFor('url.check', ['id' => $existingUrl['id']])
            );
        }

        $dateTime = Carbon::now();
        $newUrl = $dataBase->addUrl($db, ['name' => $normalizedUrl], $dateTime);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена :)');
        return $response->withRedirect(
            $router->urlFor('url.check', ['id' => $newUrl['id']])
        );
    } catch (Exception $e) {
        $this->get('flash')->addMessage('error', "Ошибка при добавлении страницы {$normalizedUrl} " . $e->getMessage());
        return $response->withRedirect($router->urlFor('main'));
    }
})->setName('urls.store');


//4. отображение конкретной страницы
$app->get('/urls/{id:\d+}', function (ServerRequest $request, Response $response, $args): Response {

    $id = $args['id'];
    $dataBase = new DataBaseHelper();
    $dbUrls = $this->get('db');
    $urlData = $dataBase->getUrlById($dbUrls, $id);
    $checks = $dataBase->getUrlChecksById($this->get('db'), $id);

    if ($urlData === null) {
        return $this->get('renderer')->render($response, '404.phtml')->withStatus(404);
    }
    $messages = $this->get('flash')->getMessages();

    $params = [
        'id' => $id,
        'url' => $urlData,
        'checks' => $checks,
        'flash' => $messages,
        'page' => 'urls'
    ];

    return $this->get('renderer')->render($response, '/urls/url_check.phtml', $params);
})->setName('url.check');


//5. добавление новой проверки в список проверок и в бд
$app->post(
    '/urls/{id:\d+}/checks',
    function (ServerRequest $request, Response $response, $args) use ($router): Response {
        $idUrl = $args['id'];
        $dbUrls = $this->get('db');
        $dataBase = new DataBaseHelper();

        $url = $dataBase->getUrlById($dbUrls, $idUrl);
        $urlName = \Illuminate\Support\Arr::get($url, 'name') ?? '';

        $client = new Client();
        $res = $client->request('GET', $urlName);

        $document = new Document($urlName, true);
        $h1 = optional($document->first('h1'))->text();
        $title = optional($document->first('head title'))->text();
        $description = $document->first('meta[name=description]')?->getAttribute('content');

        $dateTime = Carbon::now();
        $statusCode = $res->getStatusCode();
        // Передаем все в БД
        $addCheck = $dataBase->addUrlCheck($this->get('db'), $idUrl, $h1, $title, $description, $dateTime, $statusCode);
        if (!$addCheck) {
            $this->get('flash')->addMessage('success', 'Страница успешно проверена');
            $messages = $this->get('flash')->getMessages();
            $checks = $dataBase->getUrlChecksById($this->get('db'), $idUrl);
            $params = [
                'id' => $idUrl,
                'url' => $url,
                'flash' => $messages,
                'checks' => $checks,
            ];

            $url = $router->urlFor('url.check', $params);
            return $response->withRedirect($url);
        } else {
            $this->get('flash')->addMessage('error', 'Некорректный URL');
            $messages = $this->get('flash')->getMessages();

            $params = [
                'id' => $idUrl,
                'url' => $url,
                'flash' => $messages,
                'checks' => null,
            ];

            $url = $router->urlFor('url.check', $params);
            return $response->withStatus(500)->withRedirect($url);
        }
    }
)->setName('url_checks.store');

$app->run();
