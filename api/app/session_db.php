<?php
declare(strict_types=1);
/*
 * Database-backed PHP sessions.
 * Needed on Vercel: every api/*.php file is a separate serverless function with its
 * own /tmp, so file-based sessions created by login.php are invisible to index.php.
 * Including this file registers the handler and starts the session.
 */
require_once __DIR__ . '/functions.php';

final class DbSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    private function ensureTable(): void
    {
        db()->exec('CREATE TABLE IF NOT EXISTS php_sessions (
            id VARCHAR(128) NOT NULL PRIMARY KEY,
            data MEDIUMBLOB NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            INDEX (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function read(string $id): string|false
    {
        try {
            $s = db()->prepare('SELECT data FROM php_sessions WHERE id = ?');
            $s->execute([$id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {   // table missing: create it once
                $this->ensureTable();
                return '';
            }
            throw $e;
        }
        $r = $s->fetchColumn();
        return $r === false ? '' : (string)$r;
    }

    public function write(string $id, string $data): bool
    {
        $s = db()->prepare('REPLACE INTO php_sessions (id, data, updated_at) VALUES (?, ?, ?)');
        return $s->execute([$id, $data, time()]);
    }

    public function destroy(string $id): bool
    {
        return db()->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $s = db()->prepare('DELETE FROM php_sessions WHERE updated_at < ?');
        $s->execute([time() - $max_lifetime]);
        return $s->rowCount();
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_save_handler(new DbSessionHandler(), true);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
