<?php

function connect(string $host, int|string $port, string $dbname, string $user, string $password): PDO
{
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;";

        // make a database connection
        return new PDO(
            $dsn,
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,]
        );
    } catch (PDOException $e) {
        die($e->getMessage());
    }
}
