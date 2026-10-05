<?php

function getDB(): mysqli {
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = mysqli_init();

        // Si hay certificado SSL, lo activamos
        if (defined('DB_SSL_CA') && file_exists(DB_SSL_CA)) {
            $db->ssl_set(null, null, DB_SSL_CA, null, null);
        }

        $conectado = @$db->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT);

        if (!$conectado) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            $detalle = (defined('APP_DEBUG') && APP_DEBUG) ? mysqli_connect_error() : 'No se pudo conectar con la base de datos.';
            echo json_encode(['error' => 'Error de conexión: ' . $detalle]);
            exit;
        }

        $db->set_charset('utf8mb4');
    }
    return $db;
}