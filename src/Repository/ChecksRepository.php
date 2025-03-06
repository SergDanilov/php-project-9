<?php

namespace App\Repository;

use Carbon\Carbon;
use PDO;

class ChecksRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getUrlChecks(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM url_checks ORDER BY created_at DESC");
        $url_checks = $stmt->fetchAll();
        return $url_checks;
    }

    public function getUrlChecksById(int $urlId): array
    {
        $stmt = $this->pdo->query("SELECT * FROM url_checks WHERE url_id = $urlId ORDER BY created_at DESC");
        $url_checks = $stmt->fetchAll();
        return $url_checks;
    }

    public function addUrlCheck(
        int $urlId,
        string|null $h1,
        string|null $title,
        string|null $description,
        Carbon $dateTime,
        int $statusCode
    ): array {

            $stmt = $this->pdo->prepare("
                INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
                VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)
            ");

            $result = $stmt->execute([
                ':url_id' => $urlId,
                ':status_code' => $statusCode,
                ':h1' => $h1,
                ':title' => $title,
                ':description' => $description,
                ':created_at' => $dateTime,
            ]);
            $result = $stmt->fetchAll();
            return array_shift($result);
    }

    public function getLastUrlChecks(): array
    {
        $sql = "SELECT DISTINCT ON (url_id) url_id, created_at, status_code
                FROM url_checks
                ORDER BY url_id, created_at DESC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
