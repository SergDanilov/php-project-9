<?php

namespace App\Repository;

use Carbon\Carbon;
use PDO;

class UrlsRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getUrls(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM urls ORDER BY created_at DESC");
        $urls = $stmt->fetchAll();
        return $urls;
    }

    public function findUrlByName(string $url): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM urls WHERE name = ?');
        $stmt->execute([$url]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // добавление URL в бд
    public function addUrl(array $url, Carbon $dateTime): array
    {
        // Подготовка запроса
        $stmt = $this->pdo->prepare("
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
        $stmt = $this->pdo->prepare("SELECT * FROM urls WHERE name = :name");
        $stmt->execute([':name' => $url['name']]);
        $createdUrl = $stmt->fetchObject();

        if ($createdUrl === false) {
            throw new \Exception("Error: URL not found after insertion.");
        }

        return (array)$createdUrl; // Возвращаем добавленный URL в случае успеха
    }


    public function getUrlById(int $id): array|null
    {
        // Делаем выборку из базы по ID
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM urls WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $count = $stmt->fetchColumn();
        // Проверяем, существует ли уже запись с данным ID
        if ($count > 0) {
            $stmt = $this->pdo->query("SELECT * FROM urls WHERE id = $id");
            $urlData = $stmt->fetchAll();
            return array_shift($urlData);
        } else {
            return null;
        }
    }
}
