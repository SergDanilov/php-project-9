<?php

namespace App;

use Carbon\Carbon;
use DiDom\Document;
use GuzzleHttp\Client;
use Illuminate\Support;
use PDO;

class DataBaseHelper
{
    public function getUrls(PDO $db): array
    {
        $stmt = $db->query("SELECT * FROM urls ORDER BY created_at DESC");
        $urls = $stmt->fetchAll();
        return $urls;
    }

    public function getUrlChecks(PDO $db): array
    {
        $stmt = $db->query("SELECT * FROM url_checks ORDER BY created_at DESC");
        $url_checks = $stmt->fetchAll();
        return $url_checks;
    }

    public function findUrlByName(PDO $db, string|null $url): ?array
    {
        $stmt = $db->prepare('SELECT * FROM urls WHERE name = ?');
        $stmt->execute([$url]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getUrlChecksById(PDO $db, int $urlId): array
    {
        $stmt = $db->query("SELECT * FROM url_checks WHERE url_id = $urlId ORDER BY created_at DESC");
        $url_checks = $stmt->fetchAll();
        return $url_checks;
    }

    // добавление URL в бд
    public function addUrl(PDO $db, array $url): array
    {
        // Добавление даты и времени создания URL
        $dateTime = Carbon::now();

        // Подготовка запроса
        $stmt = $db->prepare("
            INSERT INTO urls (name, created_at) VALUES (:name, :created_at)
        ");

        // Выполняем запрос с параметрами для внесения в базу
        if (
            !$stmt->execute(
                [':name' => $url['name'],
                ':created_at' => $dateTime,]
            )
        ) {
            // Обработка ошибки вставки
            throw new \Exception("Error: Unable to insert URL.");
        }

        // Получаем добавленный URL
        $stmt = $db->prepare("SELECT * FROM urls WHERE name = :name");
        $stmt->execute([':name' => $url['name']]);
        $createdUrl = $stmt->fetchObject();

        if ($createdUrl === false) {
            throw new \Exception("Error: URL not found after insertion.");
        }

        return (array)$createdUrl; // Возвращаем добавленный URL в случае успеха
    }

    // добавление проверки в бд
    public function addUrlCheck(PDO $db, int $urlId, array|string $url): array|bool
    {

        try {
            // Определяем URL: если $url — строка, используем её, если массив — берем из ключа 'name'
            $urlName = is_string($url) ? $url : (Support\Arr::get($url, 'name') ?? '');
            if (empty($urlName)) {
                throw new \InvalidArgumentException('URL is invalid');
            }

            // Теперь работаем с $urlName как строкой
            $client = new Client();
            $res = $client->request('GET', $urlName); // Используем $urlName вместо $url['name']
            // Получаем статус код
            $statusCode = $res->getStatusCode();

            // Получение тайтла, h1, дескрипшн  из документа
            $document = new Document($urlName, true);
            $h1 = optional($document->first('h1'))->text();
            $title = optional($document->first('head title'))->text();
            $descriptionElement = $document->find('meta[name="description"]');
            if ($descriptionElement) {
                foreach ($descriptionElement as $element) {
                    $description = $element->getAttribute('content');
                }
            } else {
                $description = '-';
            }
            // Добавление даты и времени создания проверки
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
        } catch (\Exception $e) {
            //Логируем ошибку или обрабатываем ее соответствующим образом
            error_log("Ошибка добавления проверки URL: " . $e->getMessage());
            return false; // Возвращаем false в случае неудачи
        }
    }

    public function getUrlById(PDO $db, int $id): array|string
    {
        // Делаем выборку из базы по ID
        $stmt = $db->prepare("SELECT COUNT(*) FROM urls WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $count = $stmt->fetchColumn();
        // Проверяем, существует ли уже запись с данным ID
        if ($count > 0) {
            $stmt = $db->query("SELECT * FROM urls WHERE id = $id");
            $urlData = $stmt->fetchAll();
            return array_shift($urlData);
        } else {
            return "Запись с ID = {$id} не найдена.";
        }
    }

    public function getLastUrlChecks(PDO $db): array
    {
        $sql = "SELECT DISTINCT ON (url_id) url_id, created_at, status_code 
                FROM url_checks 
                ORDER BY url_id, created_at DESC";
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
