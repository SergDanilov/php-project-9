<?php

// Подключение автозагрузки через composer
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
use App\Validator;
use Psr\Container\ContainerInterface;

// Старт PHP сессии
session_start();

$container = new Container();
// Database connection settings
$container->set('db', function (ContainerInterface $c) {
    $settings = [
                    "driver" => "pgsql",
                    "host" => "postgres",
                    "database" => "analyzer_db",
                    "username" => "analyzer_user",
                    "password" => "analyzer_password",
                ];
    
    $dsn = "{$settings['driver']}:host={$settings['host']};dbname={$settings['database']}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    return new PDO($dsn, $settings['username'], $settings['password'], $options);
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

function getUrls($db, $request)
{
    $stmt = $db->query("SELECT * FROM urls");
    $urls = $stmt->fetchAll();
    return $urls;
}

function filterUrlsByName($urls, $term)
{
    return array_filter($urls, fn($url) => str_contains($url['name'], $term) !== false);
}
// добавление записи в бд
function addUrl($db, $url)
{
    
    // Проверяем, существует ли уже запись с данным URL
    $stmt = $db->prepare("SELECT COUNT(*) FROM urls WHERE name = :name");
    $stmt->execute([':name' => $url['name']]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        // Если запись с таким именем уже существует, возвращаем сообщение об ошибке
        return "Запись с {$url['name']} уже существует.";
    } else {
        // Если уникальный, добавляем новую запись
        $stmt = $db->prepare("INSERT INTO urls (name) VALUES (:name)");
        $result = $stmt->execute([
            ':name' => $url['name']
        ]);

        if ($result) {
            return "Запись успешно добавлена.";
        } else {
            return "Ошибка при добавлении записи.";
        }
    }
}

function getUrlById($db, $id)
{
    // Делаем выборку по ID
    $stmt = $db->prepare("SELECT COUNT(*) FROM urls WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $count = $stmt->fetchColumn();
    // Проверяем, существует ли уже запись с данным ID
    if ($count > 0) {
        $stmt = $db->query("SELECT * FROM urls WHERE id = $id");
        $urlData = $stmt->fetch();
        return $urlData;   
    } else {
        return "Запись с ID = {$id} не найдена.";
    }
}

$app->get('/', function ($request, $response) use ($router) {

    $messages = $this->get('flash')->getMessages();
    $params = [
        'url' => '',
        'flash' => $messages ?? []
    ];

    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('main');

$app->get('/urls', function ($request, $response) {

    $urlsList = getUrls($this->get('db'), $request) ?? [];
     
    $messages = $this->get('flash')->getMessages();

    $params = [
      'urls' => $urlsList,
      'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');
// добавление нового урла в таблицу
$app->post('/urls', function ($request, $response) use ($router) {
    
    $urls = getUrls($this->get('db'), $request) ?? [];
    $urlData = $request->getParsedBodyParam('url');

    $validator = new Validator();
    $errors = $validator->validate($urlData);

    if (count($errors) === 0) {
        // $id = uniqid();
        // $url[$id] = $urlData;
        addUrl($this->get('db'), $urlData);

        $encodedUrls = json_encode($urls);

        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
        $params = [
            'urls' => $urls
        ];
        return $this->get('renderer')->render($response, 'urls/index.phtml', $params)
        ->withHeader('Set-Cookie', "urls={$encodedUrls};path=/")
        ->withRedirect($router->urlFor('urls.index'));
    }

    $params = [
        'urlData' => $urlData,
        'errors' => $errors
    ];

    return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', $params);
})->setName('urls.store');
// отображение конкретной страницы
$app->get('/urls/{id}', function ($request, $response, $args) {

    $id = $args['id'];
    $urls = getUrls($this->get('db'), $request) ?? [];

    if (!array_key_exists($id, $urls)) {
        return $response->write('Page not found')->withStatus(404);
    }

    $dbUrls = $this->get('db');
    $url = getUrlById($dbUrls, $id);
    $messages = $this->get('flash')->getMessages();

    $params = [
        'id' => $id,
        'url' => $url,
        'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('urls.show');

$app->run();