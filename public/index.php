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
use Illuminate\Support;
use Dotenv\Dotenv;

// Старт PHP сессии
session_start();

$container = new Container();

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Соединение с бд
$container->set('db', function () {
    $databaseUrl = $_ENV['DATABASE_URL'];
    $parts = parse_url($databaseUrl);

    if ($parts === false) {
        throw new InvalidArgumentException('Invalid DATABASE_URL');
    }

    // Извлекаем значения с дефолтными значениями
    $host = \Illuminate\Support\Arr::get($parts, 'host', 'localhost');
    $port = \Illuminate\Support\Arr::get($parts, 'port', 5432); // Порт по умолчанию для PostgreSQL
    $dbName = ltrim(\Illuminate\Support\Arr::get($parts, 'path', ''), '/');
    $user = \Illuminate\Support\Arr::get($parts, 'user', '');
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

$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);
$router = $app->getRouteCollector()->getRouteParser();

$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

//1. главная страница
$app->get('/', function (ServerRequest $request, Response $response): Response {

    $messages = $this->get('flash')->getMessages();
    $params = [
        'url' => '',
        'flash' => $messages ?? []
    ];

    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('main');

//2. список страниц
$app->get('/urls', function (ServerRequest $request, Response $response): Response {
    $dataBase = new DataBaseHelper();
    $urlsList = $dataBase->getUrls($this->get('db'));
    $messages = $this->get('flash')->getMessages();

    // Получаем последние проверки для каждого URL одним запросом
    $lastChecks = $dataBase->getLastUrlChecks($this->get('db'));
    $lastChecksByUrlId = \Illuminate\Support\Arr::keyBy($lastChecks, 'url_id');

    $params = [
        'urls' => $urlsList,
        'flash' => $messages,
        'lastChecks' => $lastChecksByUrlId,
    ];

    return $this->get('renderer')->render($response, 'url_check.phtml', $params);
})->setName('url.check');

//3. добавление нового урла в список страниц и в бд
function normalizeUrl(string $url): ?string {
    $url = mb_strtolower(trim($url));
    if (!parse_url($url, PHP_URL_SCHEME)) {
        $url = "http://{$url}";
    }
    $parts = parse_url($url);
    return ($parts && !empty($parts['host'])) 
        ? "{$parts['scheme']}://{$parts['host']}"
        : null;
}

$app->post('/urls', function (ServerRequest $request, Response $response) use ($router): Response {
    $urlData = $request->getParsedBodyParam('url');
    $validator = new Validator();
    $errors = $validator->validate($urlData);

    // Нормализация URL
    $rawUrl = $urlData['name'] ?? '';
    $normalizedUrl = normalizeUrl($rawUrl);

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
        // Проверка существования URL
        $existingUrl = $dataBase->findUrlByName($db, $normalizedUrl);
        
        if ($existingUrl) {
            $this->get('flash')->addMessage('error', 'Страница уже существует!');
            return $response->withRedirect(
                $router->urlFor('urls.show', ['id' => $existingUrl['id']])
            );
        }

        // Добавление нового URL
        $newUrl = $dataBase->addUrl($db, ['name' => $normalizedUrl]);
        
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена :)');
        return $response->withRedirect(
            $router->urlFor('urls.show', ['id' => $newUrl['id']])
        );

    } catch (PDOException $e) {
        // Обработка ошибки дубликата
        $this->get('flash')->addMessage('error', 'Страница уже существует!');
        return $response->withRedirect($router->urlFor('main'));
    }
})->setName('urls.store');


//4. отображение конкретной страницы
$app->get('/urls/{id:\d+}', function (ServerRequest $request, Response $response, $args): Response {

    $id = $args['id'];
    $dataBase = new DataBaseHelper();
    $urls = $dataBase->getUrls($this->get('db'));
    $dbUrls = $this->get('db');
    $urlData = $dataBase->getUrlById($dbUrls, $id);
    $checks = $dataBase->getUrlChecksById($this->get('db'), $id);

    if (!in_array($urlData, $urls)) {
        return $this->get('renderer')->render($response, '404.phtml')->withStatus(404);
    }
    $messages = $this->get('flash')->getMessages();

    $params = [
        'id' => $id,
        'url' => $urlData,
        'checks' => $checks,
        'flash' => $messages,
    ];

    return $this->get('renderer')->render($response, 'urls_show.phtml', $params);
})->setName('urls.show');


//5. добавление новой проверки в список проверок и в бд
$app->post(
    '/urls/{id:\d+}/checks',
    function (ServerRequest $request, Response $response, $args) use ($router): Response {
        $idUrl = $args['id'];
        $dbUrls = $this->get('db');
        $dataBase = new DataBaseHelper();

        $url = $dataBase->getUrlById($dbUrls, $idUrl);
        $addCheck = $dataBase->addUrlCheck($this->get('db'), $idUrl, $url);
        if (!$addCheck) {
            $this->get('flash')->addMessage('error', 'Некорректный URL');
            $messages = $this->get('flash')->getMessages();

            $params = [
                'id' => $idUrl,
                'url' => $url,
                'flash' => $messages,
                'checks' => null,
            ];

            $url = $router->urlFor('urls.show', $params);
            return $response->withStatus(500)->withRedirect($url);
        } else {
            $this->get('flash')->addMessage('success', 'Страница успешно проверена');
            $messages = $this->get('flash')->getMessages();
            $checks = $dataBase->getUrlChecksById($this->get('db'), $idUrl);
            $params = [
                'id' => $idUrl,
                'url' => $url,
                'flash' => $messages,
                'checks' => $checks,
            ];

            $url = $router->urlFor('urls.show', $params);
            return $response->withRedirect($url);
        }
    }
)->setName('url_checks.store');

//запускаем приложение в работу
$app->run();
