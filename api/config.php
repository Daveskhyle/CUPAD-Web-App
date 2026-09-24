<?php
declare(strict_types=1);

/*
 * CUPAD API - PRIVATE SERVER CONFIGURATION
 * Keep this file outside public Git repositories.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'cupadnam_db');
define('DB_USER', 'cupadnam_db');
define('DB_PASS', 'f2GrjZQCz8E39nCu9eLg');
define('DB_CHARSET', 'utf8mb4');

define('API_KEY', 'FeAaBAJqabZLaJO5USrbh4QjZ0yrAO1-aId4GvzTB7s');
define('JWT_SECRET', 'MA6v_rZ199j-af5iLmcU2hYa4nN9A5U_lI0djOBnnK3zq60eAxwuN0imQP0Iswci');

define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
define('OPENAI_MODEL', getenv('OPENAI_MODEL') ?: 'gpt-5-mini');

date_default_timezone_set('Africa/Lagos');

function getDbConnection(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
