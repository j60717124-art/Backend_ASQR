<?php
// src/database.php

function getDB(): mysqli {
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            // Crear instancia de mysqli sin conectar aún
            $db = mysqli_init();

            // Si hay certificado SSL, lo activamos
            if (defined('DB_SSL_CA') && file_exists(DB_SSL_CA)) {
                $db->ssl_set(null, null, DB_SSL_CA, null, null);
            }

            // Ahora conectamos
            $db->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
            $db->set_charset('utf8mb4');
        } catch (mysqli_sql_exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]);
            exit;
        }
    }
    return $db;
}