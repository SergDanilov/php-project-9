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

// Старт PHP сессии
session_start();
function getUrls($request)
{
    return json_decode($request->getCookieParam('urls') ?? '', true);
}

function filterUrlsByName($urls, $term)
{
    return array_filter($urls, fn($url) => str_contains($url['name'], $term) !== false);
}

$container = new Container();
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

$app->get('/', function ($request, $response) use ($router) {

    $messages = $this->get('flash')->getMessages();
    $params = [
        'url' => '',
        'flash' => $messages ?? []
    ];

    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('main');

$app->get('/urls', function ($request, $response) {
    
    $term = $request->getQueryParam('term') ?? '';
    $urls = getUrls($request) ?? [];
    $urlsList = isset($term) ? filterUrlsByName($urls, $term) : $urls;

    $messages = $this->get('flash')->getMessages();

    $params = [
      'urls' => $urlsList,
      'term' => $term,
      'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');

$app->post('/urls', function ($request, $response) use ($router) {
    $repo = new App\UrlRepository();
    // Извлекаем данные формы
    $urlData = $request->getParsedBodyParam('url');

    $validator = new Validator();
    $errors = $validator->validate($urlData);

    if (count($errors) === 0) {
        $repo->save($urlData);
        // $id = uniqid();
        // $url[$id] = $urlData;

        // $encodedUrls = json_encode($urls);

        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

        return $response->withHeader('Set-Cookie', "urls={$urlData};path=/")
            ->withRedirect($router->urlFor('urls.index'));
    }

    $params = [
        'urlData' => $urlData,
        'errors' => $errors
    ];

    return $this->get('renderer')->render($response->withStatus(422), 'urls/new.phtml', $params);
})->setName('urls.store');

$app->get('/urls/new', function ($request, $response) {

    $params = [
        'urlData' => [],
        'errors' => []
    ];

    return $this->get('renderer')->render($response, 'urls/new.phtml', $params);
})->setName('urls.create');

$app->get('/urls/{id}', function ($request, $response, $args) {

    $id = $args['id'];
    $urls = getUrls($request);

    if (!array_key_exists($id, $urls)) {
        return $response->write('Page not found')->withStatus(404);
    }

    $messages = $this->get('flash')->getMessages();

    $params = [
        'id' => $id,
        'url' => $urls[$id],
        'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('urls.show');

$app->run();