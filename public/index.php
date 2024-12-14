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
use App\Validator;
use Psr\Container\ContainerInterface;
use Carbon\Carbon;
use GuzzleHttp\Client;
use DiDom\Document;

// Старт PHP сессии
session_start();

$container = new Container();
// Соединение с бд
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

/**********функции*********/
function getUrls($db, $request)
{
    $stmt = $db->query("SELECT * FROM urls ORDER BY created_at DESC");
    $urls = $stmt->fetchAll();
    return $urls;
}

function getUrlChecks($db)
{
    $stmt = $db->query("SELECT * FROM url_checks ORDER BY created_at DESC");
    $url_checks = $stmt->fetchAll();
    return $url_checks;
}

function getUrlChecksById($db, $urlId)
{
    $stmt = $db->query("SELECT * FROM url_checks WHERE url_id = $urlId ORDER BY created_at DESC");
    $url_checks = $stmt->fetchAll();
    return $url_checks;
}

function getLastCheckById($db, $urlId)
{
    $stmt = $db->query("SELECT * FROM url_checks WHERE url_id = $urlId ORDER BY created_at DESC LIMIT 1");
    $last_check = $stmt->fetchAll();
    return $last_check;
}

// добавление записи в бд
function addUrl($db, $url)
{
    // Проверяем, существует ли уже запись с данным URL
    $stmt = $db->prepare("SELECT COUNT(*) FROM urls WHERE name = :name");
    $stmt->execute([':name' => $url['name']]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        $stmt = $db->prepare("SELECT * FROM urls WHERE name = :name");
        $stmt->execute([':name' => $url['name']]);
        $currentUrl = $stmt->fetchAll();

        return $currentUrl;
    } else {
        // Если уникальный, добавляем новую запись.
        /*добавление даты и времени создания урла*/
        $dateTime = Carbon::now();

        //подготовка запроса
        $stmt = $db->prepare("
            INSERT INTO urls (name, created_at) VALUES (:name, :created_at)
        ");

        // Выполняем запрос с параметрами для внесения в базу
        $result = $stmt->execute([
            ':name' => $url['name'],
            ':created_at' => $dateTime,
        ]);

        // $stmt = $db->prepare("INSERT INTO urls (name, ) VALUES (:name)");
        // $result = $stmt->execute([':name' => $url['name']]);

        if ($result) {
            // Получаем добавленный URL
            $stmt = $db->prepare("SELECT * FROM urls WHERE name = :name");
            $stmt->execute([':name' => $url['name']]);
            $createdUrl = $stmt->fetchAll();
            return $createdUrl; // Возвращаем добавленный URL в случае успеха
        } else {
            // Обработка ошибки вставки
            return "Error: Unable to insert URL.";
        }
    }
}

// добавление проверки в бд
function addUrlCheck($db, $urlId, $url)
{

    try {
        //Получаем код ответа
        // Создаем клиент для выполнения запроса
        $client = new Client();
        // Отправляем GET-запрос
        $res = $client->request('GET', $url['name']);
        // Получаем статус код
        $statusCode = $res->getStatusCode();

        // Получение тайтла из документа
        $document = new Document($url['name'], true);
        $titleElement = $document->first('head')->firstInDocument('title');
        if (isset($titleElement)) {
            $title = $titleElement->text();
        } else {
            $title = null;
        }
        /*получение дескрипшн из документа*/
        $descriptionElement = $document->find('meta[name="description"]');
        if (isset($descriptionElement)) {
            foreach ($descriptionElement as $element) {
                $description = $element->content;
            }
        } else {
            $description = null;
        }
        /*получение H1 из документа*/
        $h1Element = $document->first('body')->firstInDocument('h1');
        if (isset($h1Element)) {
            $h1 = $h1Element->text();
        } else {
            $h1 = null;
        }
        /*добавление даты и времени создания проверки*/
        $dateTime = Carbon::now();

        // Подготавливаем запрос к базе данных
        $stmt = $db->prepare("
            INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
            VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)
        ");

        // Выполняем запрос с параметрами для внесения в базу
        $result = $stmt->execute([
            ':url_id' => $urlId,
            ':status_code' => $statusCode,
            ':h1' => $h1,
            ':title' => $title,
            ':description' => $description,
            ':created_at' => $dateTime,
        ]);
        return $result; // Возвращаем результат выполнения запроса
    } catch (Exception $e) {
        // Логируем ошибку или обрабатываем ее соответствующим образом
        error_log("Ошибка добавления проверки URL: " . $e->getMessage());
        return false; // Возвращаем false в случае неудачи
    }
}

function getUrlById($db, $id)
{
    // Делаем выборку из базы по ID
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
/**********функции-окончание*********/


//1. главная страница
$app->get('/', function ($request, $response) use ($router) {

    $messages = $this->get('flash')->getMessages();
    $params = [
        'url' => '',
        'flash' => $messages ?? []
    ];

    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('main');



//2. список страниц
$app->get('/urls', function ($request, $response) {

    $urlsList = getUrls($this->get('db'), $request) ?? [];
    $messages = $this->get('flash')->getMessages();
    $urlIdArray = [];
    $checks = getUrlChecks($this->get('db'));

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
$app->post('/urls', function ($request, $response) use ($router) {

    $urlData = $request->getParsedBodyParam('url');

    $validator = new Validator();
    $errors = $validator->validate($urlData);

    if (count($errors) === 0) {
        $urlsList = getUrls($this->get('db'), $request) ?? [];
        $newUrl = addUrl($this->get('db'), $urlData);
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
$app->get('/urls/{id}', function ($request, $response, $args) {

    $id = $args['id'];
    $urls = getUrls($this->get('db'), $request) ?? [];
    $dbUrls = $this->get('db');
    $url = getUrlById($dbUrls, $id);
    $checks = getUrlChecksById($this->get('db'), $id);

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
$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {
    $idUrl = $args['id'];
    $dbUrls = $this->get('db');


    $url = getUrlById($dbUrls, $idUrl);
    $addCheck = addUrlCheck($this->get('db'), $idUrl, $url);
    if (!$addCheck) {
        return "Error: Unable to insert URLCHECK.";
    }
    $checks = getUrlChecksById($this->get('db'), $idUrl);
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
