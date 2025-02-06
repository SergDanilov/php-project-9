<?php

namespace App;

use Carbon\Carbon;
use DiDom\Document;
use GuzzleHttp\Client;

class DataBaseHelper
{
    public function getUrls($db)
    {
        $stmt = $db->query("SELECT * FROM urls ORDER BY created_at DESC");
        $urls = $stmt->fetchAll();
        return $urls;
    }

    public function getUrlChecks($db)
    {
        $stmt = $db->query("SELECT * FROM url_checks ORDER BY created_at DESC");
        $url_checks = $stmt->fetchAll();
        return $url_checks;
    }

    public function getUrlChecksById($db, $urlId)
    {
        $stmt = $db->query("SELECT * FROM url_checks WHERE url_id = $urlId ORDER BY created_at DESC");
        $url_checks = $stmt->fetchAll();
        return $url_checks;
    }

    public function getLastCheckById($db, $urlId)
    {
        $stmt = $db->query("SELECT * FROM url_checks WHERE url_id = $urlId ORDER BY created_at DESC LIMIT 1");
        $last_check = $stmt->fetchAll();
        return $last_check;
    }

    // добавление записи в бд
    public function addUrl($db, $url)
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
    public function addUrlCheck($db, $urlId, $url)
    {

        try {
            // Получаем код ответа
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
            // Получение дескрипшн из документа
            $descriptionElement = $document->find('meta[name="description"]');
            if (isset($descriptionElement)) {
                foreach ($descriptionElement as $element) {
                    $description = $element->content;
                }
            } else {
                $description = null;
            }
            // Получение H1 из документа
            $h1Element = $document->first('body')->firstInDocument('h1');
            if (isset($h1Element)) {
                $h1 = $h1Element->text();
            } else {
                $h1 = null;
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

    public function getUrlById($db, $id)
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
}
