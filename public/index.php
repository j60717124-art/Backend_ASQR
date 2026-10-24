<?php

// Imprimen errores de PHP en la respuesta: van al log del servidor.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// CONFIGURACION DE ZONA HORARIA BOLIVIANA
date_default_timezone_set('America/La_Paz');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/database.php';
require_once __DIR__ . '/../src/helpers.php';

foreach (glob(__DIR__ . '/../src/models/*.php') as $archivoModelo) {
    require_once $archivoModelo;
}

if (strlen(APP_SECRET) < 32 || DB_USER === '' || DB_PASS === '') {
    error_log('❌ Configuración incompleta: define APP_SECRET (mínimo 32 caracteres), DB_USER y DB_PASS (ver .env.example).');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'El servidor no está configurado correctamente.']);
    exit();
}

// CORS Y CABECERAS DE SEGURIDAD
$origenesExtra = array_map(fn($o) => rtrim(trim($o), '/'), explode(',', FRONTEND_URL));
$allowedOrigins = array_values(array_unique(array_filter(array_merge([
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:3000',
    'https://frontend-asqr.onrender.com',
], $origenesExtra))));

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} elseif ($origin !== '' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(403);
    echo json_encode(['error' => 'Origen no permitido']);
    exit();
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Access-Control-Max-Age: 86400');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Compatibilidad X-API-Key en distintos entornos (Apache / php -S / XAMPP)
$headersReq = function_exists('getallheaders') ? getallheaders() : [];
foreach ($headersReq as $nombreHeader => $valorHeader) {
    if (strtolower($nombreHeader) === 'x-api-key' && trim($valorHeader) !== '') {
        $_SERVER['HTTP_X_API_KEY'] = trim($valorHeader);
    }
    if (strtolower($nombreHeader) === 'authorization' && empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = trim($valorHeader);
    }
}

// PRE-FLIGHT CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// DATOS DE LA SOLICITUD (ruta y método)
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$posApi = strpos($uri, '/api/');
if ($posApi !== false && $posApi > 0) {
    $uri = substr($uri, $posApi);
}
if ($uri !== '/') {
    $uri = rtrim($uri, '/');
}

if ($method === 'GET' && ($uri === '/' || $uri === '/api')) {
    jsonResponse(['status' => 'ok', 'servicio' => 'ASQR API']);
}

function docenteDeSesion(array $sesion): array
{
    $docente = (new Docente())->getPorCuenta((int) $sesion['idcuenta']);
    if (!$docente) {
        jsonResponse(['error' => 'No se encontró el docente'], 404);
    }
    return $docente;
}

// Un docente solo puede operar sobre sus propias clases (evita pasar lista en clases ajenas).
function exigirHorarioDelDocente(int $idHorario, array $sesion): void
{
    $docente = docenteDeSesion($sesion);
    if (!(new Horario())->perteneceADocente($idHorario, (int) $docente['iddocente'])) {
        throw new AccesoDenegadoException('Esta clase no te pertenece.');
    }
}

try {
    // AUTENTICACION
    if ($uri === '/api/login' && $method === 'POST') {
        $data = getRequestBody();
        $accion = $data['accion'] ?? 'login';
        $cuenta = new Cuenta();

        if ($accion === 'login') {
            $usuarioOCorreo = limpiarTexto($data['correo'] ?? $data['n_usuario'] ?? $data['usuario'] ?? '');
            $clave = is_string($data['clave'] ?? null) ? $data['clave'] : '';

            if ($usuarioOCorreo === '' || $clave === '') {
                jsonResponse(['error' => 'Usuario/correo y contraseña son obligatorios'], 422);
            }

            $resultado = $cuenta->iniciarSesion($usuarioOCorreo, $clave);
            $tipoUsuario = $resultado['nombre_rol'] ?? 'usuario';
            $token = crearSesionToken($tipoUsuario, (int) $resultado['idcuenta']);

            jsonResponse([
                'message' => 'Acceso correcto',
                'tipo' => $tipoUsuario,
                'usuario' => $resultado,
                'token' => $token,
            ]);
        } elseif ($accion === 'solicitar_pin') {
            $correo = limpiarTexto($data['correo'] ?? '');
            if ($correo === '') jsonResponse(['error' => 'El correo es obligatorio'], 422);
            $cuenta->solicitarPinRecuperacion($correo);
            jsonResponse(['message' => 'Si el correo existe, se envió un código de verificación.']);
        } elseif ($accion === 'cambiar_clave') {
            $correo = limpiarTexto($data['correo'] ?? '');
            $pin = limpiarTexto($data['pin'] ?? '');
            $nuevaClave = is_string($data['nueva_clave'] ?? null) ? $data['nueva_clave'] : '';
            if ($correo === '' || $pin === '' || $nuevaClave === '') jsonResponse(['error' => 'Correo, código y nueva contraseña son obligatorios'], 422);
            $cuenta->verificarPinYCambiarClave($correo, $pin, $nuevaClave);
            jsonResponse(['message' => 'Contraseña actualizada correctamente.']);
        } else {
            jsonResponse(['error' => 'Acción no válida'], 400);
        }
    }

    // ROL
    elseif ($uri === '/api/roles' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Rol())->getAll());
    }
    elseif ($uri === '/api/roles' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse(['idrol' => (new Rol())->create(getRequestBody()), 'message' => 'Rol creado correctamente'], 201);
    }
    elseif (preg_match('#^/api/roles/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new Rol())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Rol actualizado correctamente']);
    }
    elseif (preg_match('#^/api/roles/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new Rol())->delete((int) $m[1]);
        jsonResponse(['message' => 'Rol eliminado correctamente']);
    }

    // CUENTA
    elseif ($uri === '/api/cuentas' && $method === 'GET') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse((new Cuenta())->getAll());
    }
    elseif (preg_match('#^/api/cuentas/(\d+)$#', $uri, $m) && $method === 'GET') {
        requireAuth('Direccion', 'Administrador');
        $resultado = (new Cuenta())->getById((int) $m[1]);
        $resultado ? jsonResponse($resultado) : jsonResponse(['error' => 'Cuenta no encontrada'], 404);
    }
    elseif ($uri === '/api/cuentas' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse(['idcuenta' => (new Cuenta())->create(getRequestBody()), 'message' => 'Cuenta creada correctamente'], 201);
    }
    elseif (preg_match('#^/api/cuentas/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new Cuenta())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Cuenta actualizada correctamente']);
    }
    elseif (preg_match('#^/api/cuentas/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        $sesion = requireAuth('Direccion', 'Administrador');
        if ((int) $m[1] === (int) $sesion['idcuenta']) {
            jsonResponse(['error' => 'No puedes eliminar tu propia cuenta.'], 409);
        }
        (new Cuenta())->delete((int) $m[1]);
        jsonResponse(['message' => 'Cuenta eliminada correctamente']);
    }

    // COLEGIO
    elseif ($uri === '/api/colegios' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Colegio())->getAll());
    }
    elseif (preg_match('#^/api/colegios/(\d+)$#', $uri, $m) && $method === 'GET') {
        requireAuth();
        $resultado = (new Colegio())->getById((int) $m[1]);
        $resultado ? jsonResponse($resultado) : jsonResponse(['error' => 'Colegio no encontrado'], 404);
    }
    elseif ($uri === '/api/colegios' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse(['idcolegio' => (new Colegio())->create(getRequestBody()), 'message' => 'Colegio creado correctamente'], 201);
    }
    elseif (preg_match('#^/api/colegios/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new Colegio())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Colegio actualizado correctamente']);
    }
    elseif (preg_match('#^/api/colegios/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new Colegio())->delete((int) $m[1]);
        jsonResponse(['message' => 'Colegio eliminado correctamente']);
    }

    // NIVEL
    elseif ($uri === '/api/niveles' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Nivel())->getAll());
    }
    elseif ($uri === '/api/niveles' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse(['idnivel' => (new Nivel())->create(getRequestBody()), 'message' => 'Nivel creado'], 201);
    }
    elseif (preg_match('#^/api/niveles/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new Nivel())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Nivel actualizado']);
    }
    elseif (preg_match('#^/api/niveles/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new Nivel())->delete((int) $m[1]);
        jsonResponse(['message' => 'Nivel eliminado']);
    }

    // CURSO
    elseif ($uri === '/api/cursos' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Curso())->getAll());
    }
    elseif ($uri === '/api/cursos' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse(['idcurso' => (new Curso())->create(getRequestBody()), 'message' => 'Curso creado'], 201);
    }
    elseif (preg_match('#^/api/cursos/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new Curso())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Curso actualizado']);
    }
    elseif (preg_match('#^/api/cursos/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new Curso())->delete((int) $m[1]);
        jsonResponse(['message' => 'Curso eliminado']);
    }

    //PARALELO
    elseif ($uri === '/api/paralelos' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Paralelo())->getAll());
    }
    elseif ($uri === '/api/paralelos' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse(['idparalelo' => (new Paralelo())->create(getRequestBody()), 'message' => 'Paralelo creado'], 201);
    }
    elseif (preg_match('#^/api/paralelos/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new Paralelo())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Paralelo actualizado']);
    }
    elseif (preg_match('#^/api/paralelos/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new Paralelo())->delete((int) $m[1]);
        jsonResponse(['message' => 'Paralelo eliminado']);
    }

    // MATERIAS
    elseif ($uri === '/api/materias' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Materia())->getAll());
    }
    elseif ($uri === '/api/materias' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse(['idmateria' => (new Materia())->create(getRequestBody()), 'message' => 'Materia creada'], 201);
    }
    elseif (preg_match('#^/api/materias/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new Materia())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Materia actualizada']);
    }
    elseif (preg_match('#^/api/materias/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new Materia())->delete((int) $m[1]);
        jsonResponse(['message' => 'Materia eliminada']);
    }

    // AULA
    elseif ($uri === '/api/aulas' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Aula())->getAll());
    }
    elseif ($uri === '/api/aulas' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse(['idaula' => (new Aula())->create(getRequestBody()), 'message' => 'Aula creada'], 201);
    }
    elseif (preg_match('#^/api/aulas/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new Aula())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Aula actualizada']);
    }
    elseif (preg_match('#^/api/aulas/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new Aula())->delete((int) $m[1]);
        jsonResponse(['message' => 'Aula eliminada']);
    }

    // ESTUDIANTE (+ su QR fijo)
    elseif ($uri === '/api/estudiantes' && $method === 'GET') {
        requireAuth(); jsonResponse((new Estudiante())->getAll());
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)$#', $uri, $m) && $method === 'GET') {
        requireAuth();
        $resultado = (new Estudiante())->getById((int) $m[1]);
        $resultado ? jsonResponse($resultado) : jsonResponse(['error' => 'Estudiante no encontrado'], 404);
    }
    elseif ($uri === '/api/estudiantes' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        $resultado = (new Estudiante())->create(getRequestBody());
        jsonResponse(array_merge($resultado, ['message' => 'Estudiante registrado. Se generó su QR.']), 201);
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        (new Estudiante())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Estudiante actualizado']);
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        (new Estudiante())->delete((int) $m[1]);
        jsonResponse(['message' => 'Estudiante eliminado']);
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)/qr$#', $uri, $m) && $method === 'GET') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        jsonResponse((new QrEstudiante())->getByEstudiante((int) $m[1]));
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)/qr/duplicado$#', $uri, $m) && $method === 'POST') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        $idEstudiante = (int) $m[1];
        if (!(new Estudiante())->existeId($idEstudiante)) jsonResponse(['error' => 'Estudiante no encontrado'], 404);
        $qr = (new QrEstudiante())->emitirDuplicado($idEstudiante);
        jsonResponse(['message' => 'Nuevo QR emitido.', 'qr' => $qr], 201);
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)/horarios$#', $uri, $m) && $method === 'GET') {
        requireAuth();
        jsonResponse((new HorarioEstudiante())->getPorEstudiante((int) $m[1]));
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)/asistencias$#', $uri, $m) && $method === 'GET') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        jsonResponse((new AsistenciaEstudiante())->getPorEstudiante((int) $m[1]));
    }

    // DOCENTE
    elseif ($uri === '/api/docentes' && $method === 'GET') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse((new Docente())->getAll());
    }
    elseif (preg_match('#^/api/docentes/(\d+)$#', $uri, $m) && $method === 'GET') {
        requireAuth('Direccion', 'Administrador');
        $resultado = (new Docente())->getById((int) $m[1]);
        $resultado ? jsonResponse($resultado) : jsonResponse(['error' => 'Docente no encontrado'], 404);
    }
    elseif ($uri === '/api/docentes' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        $iddocente = (new Docente())->create(getRequestBody());
        jsonResponse(['iddocente' => $iddocente, 'message' => 'Docente creado'], 201);
    }
    elseif (preg_match('#^/api/docentes/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new Docente())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Docente actualizado']);
    }
    elseif (preg_match('#^/api/docentes/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new Docente())->delete((int) $m[1]);
        jsonResponse(['message' => 'Docente eliminado']);
    }
    elseif (preg_match('#^/api/docentes/(\d+)/asistencias$#', $uri, $m) && $method === 'GET') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse((new AsistenciaDocente())->getPorDocente((int) $m[1]));
    }

    // Rutas del propio docente autenticado
    elseif ($uri === '/api/mis-horarios' && $method === 'GET') {
        $sesion = requireAuth('Docente');
        $docente = (new Docente())->getPorCuenta((int) $sesion['idcuenta']);
        if (!$docente) jsonResponse(['error' => 'No se encontró el docente'], 404);
        $dia = isset($_GET['dia']) && $_GET['dia'] !== '' ? validarDiaSemana($_GET['dia']) : null;
        jsonResponse((new Horario())->getPorDocente((int) $docente['iddocente'], $dia));
    }
    elseif ($uri === '/api/mis-asistencias' && $method === 'GET') {
        $sesion = requireAuth('Docente');
        $docente = (new Docente())->getPorCuenta((int) $sesion['idcuenta']);
        if (!$docente) jsonResponse(['error' => 'No se encontró el docente'], 404);
        jsonResponse((new AsistenciaDocente())->getPorDocente((int) $docente['iddocente']));
    }

    // HORARIO
    elseif ($uri === '/api/horarios' && $method === 'GET') {
        requireAuth(); jsonResponse((new Horario())->getAll());
    }
    elseif (preg_match('#^/api/horarios/(\d+)$#', $uri, $m) && $method === 'GET') {
        requireAuth();
        $resultado = (new Horario())->getById((int) $m[1]);
        $resultado ? jsonResponse($resultado) : jsonResponse(['error' => 'Horario no encontrado'], 404);
    }
    elseif ($uri === '/api/horarios' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse(['idhorario' => (new Horario())->create(getRequestBody()), 'message' => 'Horario creado'], 201);
    }
    elseif (preg_match('#^/api/horarios/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new Horario())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Horario actualizado']);
    }
    elseif (preg_match('#^/api/horarios/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new Horario())->delete((int) $m[1]);
        jsonResponse(['message' => 'Horario eliminado']);
    }
    elseif (preg_match('#^/api/horarios/(\d+)/estudiantes$#', $uri, $m) && $method === 'GET') {
        requireAuth();
        jsonResponse((new HorarioEstudiante())->getPorHorario((int) $m[1]));
    }
    // RUTA NUEVA PARA EL ESCÁNER: Permite cargar horarios si es Docente o Admin
    elseif ($uri === '/api/horarios-hoy' && $method === 'GET') {
        $sesion = requireAuth('Direccion', 'Administrador', 'Docente');

        if (strtolower($sesion['rol']) === 'docente') {
            $docente = (new Docente())->getPorCuenta((int) $sesion['idcuenta']);
            if (!$docente) jsonResponse(['error' => 'No se encontró el docente'], 404);
            jsonResponse((new Horario())->getPorDocente((int) $docente['iddocente'], getDiaSemanaBolivia()));
        } else {
            // Si es Admin, devolvemos todos (o los del día)
            jsonResponse((new Horario())->getAll());
        }
    }

    // INSCRIPCIONES (horario_estudiante)
    elseif ($uri === '/api/inscripciones' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        $idInscripcion = (new HorarioEstudiante())->inscribir(getRequestBody());
        jsonResponse(['idhorario_estudiante' => $idInscripcion, 'message' => 'Estudiante inscrito'], 201);
    }
    elseif (preg_match('#^/api/inscripciones/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        (new HorarioEstudiante())->retirar((int) $m[1]);
        jsonResponse(['message' => 'Inscripción eliminada']);
    }

    // GESTION ACADEMICA
    elseif ($uri === '/api/gestiones' && $method === 'GET') {
        requireAuth();
        jsonResponse((new GestionAcademica())->getAll());
    }
    elseif ($uri === '/api/gestiones' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse(['idgestion_academica' => (new GestionAcademica())->create(getRequestBody()), 'message' => 'Gestión creada'], 201);
    }
    elseif (preg_match('#^/api/gestiones/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        (new GestionAcademica())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Gestión actualizada']);
    }
    elseif (preg_match('#^/api/gestiones/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new GestionAcademica())->delete((int) $m[1]);
        jsonResponse(['message' => 'Gestión eliminada']);
    }

    // ASIGNACION (matrícula)
    elseif ($uri === '/api/asignaciones' && $method === 'GET') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        jsonResponse((new Asignacion())->getAll());
    }
    elseif ($uri === '/api/asignaciones' && $method === 'POST') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        jsonResponse(['idasignacion' => (new Asignacion())->create(getRequestBody()), 'message' => 'Asignación registrada'], 201);
    }
    elseif (preg_match('#^/api/asignaciones/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador', 'Secretaria');
        (new Asignacion())->delete((int) $m[1]);
        jsonResponse(['message' => 'Asignación eliminada']);
    }

    // ASISTENCIA DE ESTUDIANTES
    elseif ($uri === '/api/asistencia-estudiantes' && $method === 'GET') {
        $sesion = requireAuth('Direccion', 'Administrador', 'Secretaria', 'Docente');
        $idHorario = validarIdPositivo($_GET['horario_idhorario'] ?? null, 'El horario');
        if (strtolower($sesion['rol']) === 'docente') {
            exigirHorarioDelDocente($idHorario, $sesion);
        }
        $fecha = validarFecha($_GET['fecha'] ?? getFechaBolivia(), 'La fecha');
        jsonResponse((new AsistenciaEstudiante())->getPorHorario($idHorario, $fecha));
    }
    elseif ($uri === '/api/asistencia-estudiantes' && $method === 'POST') {
        $sesion = requireAuth('Docente');
        $data = getRequestBody();
        $codigoQr = $data['codigo_qr'] ?? '';
        $idHorario = validarIdPositivo($data['horario_idhorario'] ?? null, 'El horario');
        exigirHorarioDelDocente($idHorario, $sesion);
        $resultado = (new AsistenciaEstudiante())->registrarPorQr($codigoQr, $idHorario);
        jsonResponse(array_merge($resultado, ['message' => 'Asistencia registrada: ' . $resultado['estado']]), 201);
    }
    elseif ($uri === '/api/asistencia-estudiantes/manual' && $method === 'POST') {
        $sesion = requireAuth('Docente');
        $data = getRequestBody();
        $idHorario = validarIdPositivo($data['horario_idhorario'] ?? null, 'El horario');
        exigirHorarioDelDocente($idHorario, $sesion);
        $idEstudiante = validarIdPositivo($data['estudiante_idestudiante'] ?? null, 'El estudiante');
        $motivo = $data['motivo'] ?? '';
        $resultado = (new AsistenciaEstudiante())->registrarManual($idHorario, $idEstudiante, $motivo);
        jsonResponse(array_merge($resultado, ['message' => 'Asistencia manual: ' . $resultado['estado']]), 201);
    }

    // ASISTENCIA DE DOCENTES
    elseif ($uri === '/api/asistencia-docentes' && $method === 'GET') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse((new AsistenciaDocente())->getAll($_GET['fecha_inicio'] ?? null, $_GET['fecha_fin'] ?? null));
    }
    elseif ($uri === '/api/asistencia-docentes' && $method === 'POST') {
        $sesion = requireAuth('Docente');
        $docente = docenteDeSesion($sesion);

        $data = getRequestBody();
        $resultado = (new AsistenciaDocente())->registrarAsistencia(
            (int) $docente['iddocente'],
            $data['codigo_qr'] ?? '',
            $data['firma_qr'] ?? '',
            $data['latitud'] ?? null,
            $data['longitud'] ?? null
        );

        $status = $resultado['resultado'] === 'Aceptado' ? 201 : 422;
        jsonResponse($resultado, $status);
    }
    elseif (preg_match('#^/api/asistencia-docentes/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Administrador');
        (new AsistenciaDocente())->eliminar((int) $m[1]);
        jsonResponse(['message' => 'Registro eliminado correctamente']);
    }

    // QR INSTITUCIONAL
    elseif ($uri === '/api/qr-institucional' && $method === 'GET') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse((new QrInstitucional())->obtenerOGenerar());
    }

    // CONFIGURACIÓN DE GEOCERCA
    elseif ($uri === '/api/configuracion-geocerca' && $method === 'GET') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse((new ConfiguracionGeocerca())->obtener());
    }
    elseif ($uri === '/api/configuracion-geocerca' && $method === 'PUT') {
        requireAuth('Direccion', 'Administrador');
        jsonResponse((new ConfiguracionGeocerca())->actualizar(getRequestBody()));
    }

    // RUTA NO ENCONTRADA
    else {
        jsonResponse(['error' => 'Ruta no encontrada: ' . $method . ' ' . $uri], 404);
    }
} catch (PeticionInvalidaException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
} catch (SesionInvalidaException $e) {
    jsonResponse(['error' => $e->getMessage()], 401);
} catch (AccesoDenegadoException $e) {
    jsonResponse(['error' => $e->getMessage()], 403);
} catch (mysqli_sql_exception $e) {
    // Errores de base de datos: se registran, y al cliente solo se le da un mensaje seguro.
    error_log('❌ Error SQL [' . $e->getCode() . ']: ' . $e->getMessage() . ' en ' . $e->getFile() . ' línea ' . $e->getLine());
    $codigo = (int) $e->getCode();
    if ($codigo === 1451) {
        jsonResponse(['error' => 'No se puede eliminar: el registro tiene información relacionada (horarios, asistencias, inscripciones, etc.).'], 409);
    } elseif ($codigo === 1062) {
        jsonResponse(['error' => 'Ya existe un registro con esos datos.'], 409);
    } elseif ($codigo === 1452) {
        jsonResponse(['error' => 'Se hace referencia a un registro que no existe.'], 422);
    }
    jsonResponse(['error' => APP_DEBUG ? $e->getMessage() : 'Error interno del servidor.'], 500);
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('❌ Error general: ' . $e->getMessage() . ' en ' . $e->getFile() . ' línea ' . $e->getLine());
    jsonResponse(['error' => APP_DEBUG ? $e->getMessage() : 'Error interno del servidor.'], 500);
}