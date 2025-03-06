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

    public function addUrl(array $url, Carbon $dateTime): array
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO urls (name, created_at) VALUES (:name, :created_at)
        ");

        $stmt->execute(
            [':name' => $url['name'],
            ':created_at' => $dateTime,]
        );

        $stmt = $this->pdo->prepare("SELECT * FROM urls WHERE name = :name");
        $stmt->execute([':name' => $url['name']]);
        $createdUrl = $stmt->fetchObject();

        return (array)$createdUrl;
    }


    public function getUrlById(int $id): array|null
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM urls WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            $stmt = $this->pdo->query("SELECT * FROM urls WHERE id = $id");
            $urlData = $stmt->fetchAll();
            return array_shift($urlData);
        } else {
            return null;
        }
    }
}
