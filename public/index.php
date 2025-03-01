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
    $port = Support\Arr::get($parts, 'port', 5432); // Порт по умолчанию для PostgreSQL
    $dbName = ltrim(Support\Arr::get($parts, 'path', ''), '/');
    $user = Support\Arr::get($parts, 'user', '');
    $pass = Support\Arr::get($parts, 'pass', '');

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
$app->get('/', function (ServerRequest $request, Response $response) {

    $messages = $this->get('flash')->getMessages();
    $params = [
        'url' => '',
        'flash' => $messages ?? []
    ];

    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('main');

//2. список страниц
$app->get('/urls', function (ServerRequest $request, Response $response) {
    $dataBase = new DataBaseHelper();
    $urlsList = $dataBase->getUrls($this->get('db'));
    $messages = $this->get('flash')->getMessages();

    // Получаем последние проверки для каждого URL одним запросом
    $lastChecks = $dataBase->getLastUrlChecks($this->get('db'));
    $lastChecksByUrlId = Support\Arr::keyBy($lastChecks, 'url_id');

    $params = [
        'urls' => $urlsList,
        'flash' => $messages,
        'lastChecks' => $lastChecksByUrlId,
    ];

    return $this->get('renderer')->render($response, 'url_check.phtml', $params);
})->setName('url.check');

//3. добавление нового урла в список страниц и в бд
$app->post('/urls', function (ServerRequest $request, Response $response) use ($router) {

    $urlData = $request->getParsedBodyParam('url');
    $dataBase = new DataBaseHelper();
    $validator = new Validator();
    $errors = $validator->validate($urlData);

    if (count($errors) === 0) {
        $urlsList = $dataBase->getUrls($this->get('db'));
        $newUrl = $dataBase->addUrl($this->get('db'), $urlData);
        $urlIdArray = [];
        foreach ($urlsList as $key => $value) {
            $urlIdArray[] = $value['id'];
        }

        if (in_array($newUrl[0]['id'], $urlIdArray)) {
            $this->get('flash')->addMessage('error', "Страница уже существует!");
            $messages = $this->get('flash')->getMessages();

            $curId = $newUrl[0]['id'];
            $params = [
                'id' => $curId,
                'flash' => $messages,
                'urls' => $urlsList,
                'urlIdArray' => $urlIdArray,
            ];
            $url = $router->urlFor('urls.show', $params);
            // Редирект на страницу конкретного урла с выводом сообщения: "Страница уже существует!"
            return $response->withRedirect($url);
        } else {
            $this->get('flash')->addMessage('success', 'Страница успешно добавлена :)');
        }

        $messages = $this->get('flash')->getMessages();
        // Получаем ID новой записи
        $newId = $newUrl[0]['id'];
        $params = [
            'id' => $newId,
            'flash' => $messages,
        ];
        // Генерируем URL для редиректа
        $url = $router->urlFor('urls.show', $params);
        // Редирект на маршрут с ID новой записи
        return $response->withRedirect($url);
    }

    $params = [
        'urlData' => $urlData,
        'errors' => $errors
    ];

    return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', $params);
})->setName('urls.store');


//4. отображение конкретной страницы
$app->get('/urls/{id:\d+}', function (ServerRequest $request, Response $response, $args) {

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
$app->post('/urls/{id:\d+}/checks', function (ServerRequest $request, Response $response, $args) use ($router) {
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
})->setName('url_checks.store');

//запускаем приложение в работу
$app->run();
