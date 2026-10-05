<?php
// CONFIGURACIÓN DE ZONA HORARIA BOLIVIANA
date_default_timezone_set('America/La_Paz');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/database.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/usuarios.php';

// CONFIGURACIÓN CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

// Obtener todos los headers
if (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
} else {
    $headers = getallheaders();
}

// Convertir X-API-Key a formato que PHP pueda leer en $_SERVER
foreach (['X-API-Key', 'x-api-key', 'X-Api-Key'] as $key) {
    if (isset($headers[$key]) && !empty($headers[$key])) {
        $_SERVER['HTTP_X_API_KEY'] = trim($headers[$key]);
        break;
    }
}

// PRE-FLIGHT CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// DATOS DE LA SOLICITUD
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// LOG DE DEPURACIÓN
error_log('📋 REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));

try {
    // RUTAS DE USUARIOS (CRUD)
    if ($uri === '/api/usuarios' && $method === 'GET') {
        requireAuth('empleado'); // O el rol que corresponda
        $usuario = new Usuario();
        jsonResponse($usuario->getAll());
    }
    elseif (preg_match('#^/api/usuarios/(\d+)$#', $uri, $matches) && $method === 'GET') {
        $id = (int) $matches[1];
        $usuario = new Usuario();
        $resultado = $usuario->getById($id);
        if (!$resultado) {
            jsonResponse(['error' => 'Usuario no encontrado'], 404);
        }
        jsonResponse($resultado);
    }
    elseif ($uri === '/api/usuarios' && $method === 'POST') {
        requireAuth('empleado');
        $data = getRequestBody();
        $nombreUsuario = trim($data['nombre_usuario'] ?? '');
        $correo = trim($data['correo'] ?? '');
        $clave = trim($data['clave'] ?? '');
        $estado = trim($data['estado'] ?? 'Activo');
        $rolId = $data['rol_idrol'] ?? null;

        if (empty($nombreUsuario) || empty($correo) || empty($clave) || empty($rolId)) {
            jsonResponse(['error' => 'Faltan campos obligatorios: nombre_usuario, correo, clave y rol_idrol'], 422);
        }
        $usuario = new Usuario();
        $newId = $usuario->create($nombreUsuario, $correo, $clave, $estado, (int) $rolId);
        jsonResponse(['idusuario' => $newId, 'message' => 'Usuario creado correctamente'], 201);
    }
    elseif (preg_match('#^/api/usuarios/(\d+)$#', $uri, $matches) && $method === 'PUT') {
        requireAuth('empleado');
        $id = (int) $matches[1];
        $data = getRequestBody();
        $usuario = new Usuario();
        $usuario->update($id, $data);
        jsonResponse(['message' => 'Usuario actualizado correctamente']);
    }
    elseif (preg_match('#^/api/usuarios/(\d+)$#', $uri, $matches) && $method === 'DELETE') {
        requireAuth('empleado');
        $id = (int) $matches[1];
        $usuario = new Usuario();
        $usuario->delete($id);
        jsonResponse(['message' => 'Usuario eliminado correctamente']);
    }
    // RUTA NO ENCONTRADA
    else {
        jsonResponse(['error' => 'Ruta no encontrada: ' . $uri], 404);
    }
} catch (SesionInvalidaException $e) {
    jsonResponse(['error' => $e->getMessage()], 401);
} catch (Exception $e) {
    error_log('❌ Error general: ' . $e->getMessage() . ' en ' . $e->getFile() . ' línea ' . $e->getLine());
    jsonResponse(['error' => 'Error interno del servidor: ' . $e->getMessage()], 500);
}
?>