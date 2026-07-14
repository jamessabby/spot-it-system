<?php
/**
 * S.P.O.T.-IT — Community Service DB
 * Database: spotit_community_db
 * Tables:   announcements, forum_posts, forum_comments, forum_votes
 *
 * MICROSERVICES: This file only connects to spotit_community_db.
 */
require_once __DIR__ . '/../../config/env.php';

function getCommunityDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . COMMUNITY_DB_NAME . ";charset=utf8mb4",
                COMMUNITY_DB_USER,
                COMMUNITY_DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Community DB connection failed']);
            exit();
        }
    }
    return $pdo;
}
