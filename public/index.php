<?php

$autoloadPath1 = __DIR__ . '/../../autoload.php';
$autoloadPath2 = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoloadPath1)) {
    require_once $autoloadPath1;
} else {
    require_once $autoloadPath2;
}

use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use DI\Container;
use App\DataBaseHelper;
use App\Validator;
use Psr\Container\ContainerInterface;
use Carbon\Carbon;
use DiDom\Document;

// Старт PHP сессии
session_start();

$container = new Container();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Соединение с бд
$container->set('db', function () {
    // Получаем строку подключения из переменной окружения
    $databaseUrl = $_ENV['DATABASE_URL'];

    // Разбираем строку подключения
    $parts = parse_url($databaseUrl);
    if ($parts === false) {
        throw new InvalidArgumentException('Invalid DATABASE_URL');
    }

    // Формируем DSN для PDO
    $dsn = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s",
        $parts['host'],
        $parts['port'],
        ltrim($parts['path'], '/')
    );

    // Опции для PDO
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Создаем подключение
    return new PDO($dsn, $parts['user'], $parts['pass'], $options);
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
$app->get('/', function (ServerRequest $request, Response $response) use ($router) {

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
    $urlsList = $dataBase->getUrls($this->get('db')) ?? [];
    $messages = $this->get('flash')->getMessages();
    $urlIdArray = [];
    $checks = $dataBase->getUrlChecks($this->get('db'));

    foreach ($urlsList as $key => $url) {
        $checkDates = [];
        $checkStatusCode = [];
        foreach ($checks as $check) {
            if ($check['url_id'] == $url['id']) {
                // Добавляем дату проверки в массив
                $checkDates[] = $check['created_at'];
            }
            $checkStatusCode[$check['url_id']] = $check['status_code'];
        }
        // Если массив не пуст, получаем максимальную дату, иначе выводим пустую строку
        $lastCheckDate = !empty($checkDates) ? max($checkDates) : '';
        $checkData[$url['id']] = $lastCheckDate;
    }

    $params = [
      'urls' => $urlsList,
      'urlIdArray' => $urlIdArray,
      'flash' => $messages,
      'checks' => $checks,
      'checkData' => $checkData,
      'checkStatusCode' => $checkStatusCode,
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');

//3. добавление нового урла в список страниц и в бд
$app->post('/urls', function (ServerRequest $request, Response $response) use ($router) {

    $urlData = $request->getParsedBodyParam('url');
    $dataBase = new DataBaseHelper();
    $validator = new Validator();
    $errors = $validator->validate($urlData);

    if (count($errors) === 0) {
        $urlsList = $dataBase->getUrls($this->get('db')) ?? [];
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
            // 'urlsList' => $urlsList,
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
    $urls = $dataBase->getUrls($this->get('db')) ?? [];
    $dbUrls = $this->get('db');
    $url = $dataBase->getUrlById($dbUrls, $id);
    $checks = $dataBase->getUrlChecksById($this->get('db'), $id);

    if (!in_array($url, $urls)) {
        return $response->write('Page not found')->withStatus(404);
    }

    $messages = $this->get('flash')->getMessages();

    $params = [
        'id' => $id,
        'url' => $url,
        'urls' => $urls,
        'checks' => $checks,
        'flash' => $messages,
    ];

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('urls.show');


//5. добавление новой проверки в список проверок и в бд
$app->post('/urls/{id:\d+}/checks', function (ServerRequest $request, Response $response, $args) use ($router) {
    $idUrl = $args['id'];
    $dbUrls = $this->get('db');
    $dataBase = new DataBaseHelper();

    $url = $dataBase->getUrlById($dbUrls, $idUrl);
    $addCheck = $dataBase->addUrlCheck($this->get('db'), $idUrl, $url);
    if (!$addCheck) {
        $response->getBody()->write("Ошибка добавления проверки URL для {$url['name']}");
        return $response->withStatus(500);
    } else {
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    }
    $checks = $dataBase->getUrlChecksById($this->get('db'), $idUrl);
    $messages = $this->get('flash')->getMessages();


    $params = [
        'id' => $idUrl,
        'url' => $url,
        'flash' => $messages,
        'checks' => $checks,
    ];

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('url_checks.store');

//запускаем приложение в работу
$app->run();
