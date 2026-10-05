<?php

// CONFIGURACIÓN DE ZONA HORARIA BOLIVIANA
date_default_timezone_set('America/La_Paz');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/database.php';
require_once __DIR__ . '/../src/helpers.php';

foreach (glob(__DIR__ . '/../src/models/*.php') as $archivoModelo) {
    require_once $archivoModelo;
}

// CONFIGURACIÓN CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

// Obtener todos los headers (compatibilidad X-API-Key en distintos entornos)
$headers = function_exists('apache_request_headers') ? apache_request_headers() : getallheaders();
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

error_log('📋 ' . $method . ' ' . $uri);

try {
    // AUTENTICACIÓN (pública, sin token)
    // POST /api/login  { accion: 'login' | 'solicitar_pin' | 'cambiar_clave', ... }
    if ($uri === '/api/login' && $method === 'POST') {
        $data = getRequestBody();
        $accion = $data['accion'] ?? 'login';
        $cuenta = new Cuenta();

        if ($accion === 'login') {
            $usuarioOCorreo = trim($data['correo'] ?? $data['n_usuario'] ?? $data['usuario'] ?? '');
            $clave = trim($data['clave'] ?? '');

            if (empty($usuarioOCorreo) || empty($clave)) {
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
            $correo = trim($data['correo'] ?? '');
            if (empty($correo)) {
                jsonResponse(['error' => 'El correo es obligatorio'], 422);
            }
            $cuenta->solicitarPinRecuperacion($correo);
            jsonResponse(['message' => 'Si el correo existe, se envió un código de verificación. Revisa tu bandeja de entrada (expira en ' . PIN_RECUPERACION_MINUTOS . ' minutos).']);
        } elseif ($accion === 'cambiar_clave') {
            $correo = trim($data['correo'] ?? '');
            $pin = trim($data['pin'] ?? '');
            $nuevaClave = trim($data['nueva_clave'] ?? '');
            if (empty($correo) || empty($pin) || empty($nuevaClave)) {
                jsonResponse(['error' => 'Correo, código de verificación y nueva contraseña son obligatorios'], 422);
            }
            $cuenta->verificarPinYCambiarClave($correo, $pin, $nuevaClave);
            jsonResponse(['message' => 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.']);
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
        requireAuth('Direccion');
        jsonResponse(['idrol' => (new Rol())->create(getRequestBody()), 'message' => 'Rol creado correctamente'], 201);
    }
    elseif (preg_match('#^/api/roles/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new Rol())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Rol actualizado correctamente']);
    }
    elseif (preg_match('#^/api/roles/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
        (new Rol())->delete((int) $m[1]);
        jsonResponse(['message' => 'Rol eliminado correctamente']);
    }

    // CUENTA (Dirección y Secretaría; las de Docente se crean vía /api/docentes)

    elseif ($uri === '/api/cuentas' && $method === 'GET') {
        requireAuth('Direccion');
        jsonResponse((new Cuenta())->getAll());
    }
    elseif (preg_match('#^/api/cuentas/(\d+)$#', $uri, $m) && $method === 'GET') {
        requireAuth('Direccion');
        $resultado = (new Cuenta())->getById((int) $m[1]);
        $resultado ? jsonResponse($resultado) : jsonResponse(['error' => 'Cuenta no encontrada'], 404);
    }
    elseif ($uri === '/api/cuentas' && $method === 'POST') {
        requireAuth('Direccion');
        jsonResponse(['idcuenta' => (new Cuenta())->create(getRequestBody()), 'message' => 'Cuenta creada correctamente'], 201);
    }
    elseif (preg_match('#^/api/cuentas/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new Cuenta())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Cuenta actualizada correctamente']);
    }
    elseif (preg_match('#^/api/cuentas/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
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
        requireAuth('Direccion');
        jsonResponse(['idcolegio' => (new Colegio())->create(getRequestBody()), 'message' => 'Colegio creado correctamente'], 201);
    }
    elseif (preg_match('#^/api/colegios/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new Colegio())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Colegio actualizado correctamente']);
    }
    elseif (preg_match('#^/api/colegios/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
        (new Colegio())->delete((int) $m[1]);
        jsonResponse(['message' => 'Colegio eliminado correctamente']);
    }

    // NIVEL

    elseif ($uri === '/api/niveles' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Nivel())->getAll());
    }
    elseif ($uri === '/api/niveles' && $method === 'POST') {
        requireAuth('Direccion');
        jsonResponse(['idnivel' => (new Nivel())->create(getRequestBody()), 'message' => 'Nivel creado correctamente'], 201);
    }
    elseif (preg_match('#^/api/niveles/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new Nivel())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Nivel actualizado correctamente']);
    }
    elseif (preg_match('#^/api/niveles/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
        (new Nivel())->delete((int) $m[1]);
        jsonResponse(['message' => 'Nivel eliminado correctamente']);
    }

    // CURSO

    elseif ($uri === '/api/cursos' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Curso())->getAll());
    }
    elseif (preg_match('#^/api/cursos/(\d+)$#', $uri, $m) && $method === 'GET') {
        requireAuth();
        $resultado = (new Curso())->getById((int) $m[1]);
        $resultado ? jsonResponse($resultado) : jsonResponse(['error' => 'Curso no encontrado'], 404);
    }
    elseif ($uri === '/api/cursos' && $method === 'POST') {
        requireAuth('Direccion');
        jsonResponse(['idcurso' => (new Curso())->create(getRequestBody()), 'message' => 'Curso creado correctamente'], 201);
    }
    elseif (preg_match('#^/api/cursos/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new Curso())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Curso actualizado correctamente']);
    }
    elseif (preg_match('#^/api/cursos/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
        (new Curso())->delete((int) $m[1]);
        jsonResponse(['message' => 'Curso eliminado correctamente']);
    }

    // PARALELO

    elseif ($uri === '/api/paralelos' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Paralelo())->getAll());
    }
    elseif ($uri === '/api/paralelos' && $method === 'POST') {
        requireAuth('Direccion');
        jsonResponse(['idparalelo' => (new Paralelo())->create(getRequestBody()), 'message' => 'Paralelo creado correctamente'], 201);
    }
    elseif (preg_match('#^/api/paralelos/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new Paralelo())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Paralelo actualizado correctamente']);
    }
    elseif (preg_match('#^/api/paralelos/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
        (new Paralelo())->delete((int) $m[1]);
        jsonResponse(['message' => 'Paralelo eliminado correctamente']);
    }

    // MATERIA

    elseif ($uri === '/api/materias' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Materia())->getAll());
    }
    elseif ($uri === '/api/materias' && $method === 'POST') {
        requireAuth('Direccion');
        jsonResponse(['idmateria' => (new Materia())->create(getRequestBody()), 'message' => 'Materia creada correctamente'], 201);
    }
    elseif (preg_match('#^/api/materias/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new Materia())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Materia actualizada correctamente']);
    }
    elseif (preg_match('#^/api/materias/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
        (new Materia())->delete((int) $m[1]);
        jsonResponse(['message' => 'Materia eliminada correctamente']);
    }

    // AULA

    elseif ($uri === '/api/aulas' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Aula())->getAll());
    }
    elseif ($uri === '/api/aulas' && $method === 'POST') {
        requireAuth('Direccion');
        jsonResponse(['idaula' => (new Aula())->create(getRequestBody()), 'message' => 'Aula creada correctamente'], 201);
    }
    elseif (preg_match('#^/api/aulas/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new Aula())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Aula actualizada correctamente']);
    }
    elseif (preg_match('#^/api/aulas/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
        (new Aula())->delete((int) $m[1]);
        jsonResponse(['message' => 'Aula eliminada correctamente']);
    }

    // ESTUDIANTE (+ su QR fijo)

    elseif ($uri === '/api/estudiantes' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Estudiante())->getAll());
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)$#', $uri, $m) && $method === 'GET') {
        requireAuth();
        $resultado = (new Estudiante())->getById((int) $m[1]);
        $resultado ? jsonResponse($resultado) : jsonResponse(['error' => 'Estudiante no encontrado'], 404);
    }
    elseif ($uri === '/api/estudiantes' && $method === 'POST') {
        requireAuth('Direccion', 'Secretaria');
        $resultado = (new Estudiante())->create(getRequestBody());
        jsonResponse(array_merge($resultado, ['message' => 'Estudiante registrado correctamente. Se generó su QR de carnet.']), 201);
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion', 'Secretaria');
        (new Estudiante())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Estudiante actualizado correctamente']);
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Secretaria');
        (new Estudiante())->delete((int) $m[1]);
        jsonResponse(['message' => 'Estudiante eliminado correctamente']);
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)/qr$#', $uri, $m) && $method === 'GET') {
        requireAuth('Direccion', 'Secretaria');
        jsonResponse((new QrEstudiante())->getByEstudiante((int) $m[1]));
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)/qr/duplicado$#', $uri, $m) && $method === 'POST') {
        requireAuth('Direccion', 'Secretaria');
        $idEstudiante = (int) $m[1];
        if (!(new Estudiante())->existeId($idEstudiante)) {
            jsonResponse(['error' => 'Estudiante no encontrado'], 404);
        }
        $qr = (new QrEstudiante())->emitirDuplicado($idEstudiante);
        jsonResponse(['message' => 'Se emitió un nuevo QR. El carnet anterior quedó invalidado.', 'qr' => $qr], 201);
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)/horarios$#', $uri, $m) && $method === 'GET') {
        requireAuth();
        jsonResponse((new HorarioEstudiante())->getPorEstudiante((int) $m[1]));
    }
    elseif (preg_match('#^/api/estudiantes/(\d+)/asistencias$#', $uri, $m) && $method === 'GET') {
        requireAuth('Direccion', 'Secretaria');
        jsonResponse((new AsistenciaEstudiante())->getPorEstudiante((int) $m[1]));
    }

    // DOCENTE (crea también su cuenta de acceso)

    elseif ($uri === '/api/docentes' && $method === 'GET') {
        requireAuth('Direccion');
        jsonResponse((new Docente())->getAll());
    }
    elseif (preg_match('#^/api/docentes/(\d+)$#', $uri, $m) && $method === 'GET') {
        requireAuth('Direccion');
        $resultado = (new Docente())->getById((int) $m[1]);
        $resultado ? jsonResponse($resultado) : jsonResponse(['error' => 'Docente no encontrado'], 404);
    }
    elseif ($uri === '/api/docentes' && $method === 'POST') {
        requireAuth('Direccion');
        $iddocente = (new Docente())->create(getRequestBody());
        jsonResponse(['iddocente' => $iddocente, 'message' => 'Docente y cuenta de acceso creados correctamente'], 201);
    }
    elseif (preg_match('#^/api/docentes/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new Docente())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Docente actualizado correctamente']);
    }
    elseif (preg_match('#^/api/docentes/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
        (new Docente())->delete((int) $m[1]);
        jsonResponse(['message' => 'Docente eliminado correctamente']);
    }
    elseif (preg_match('#^/api/docentes/(\d+)/asistencias$#', $uri, $m) && $method === 'GET') {
        requireAuth('Direccion');
        jsonResponse((new AsistenciaDocente())->getPorDocente((int) $m[1]));
    }

    //  Rutas del propio docente autenticado (resuelven su id vía el token)
    elseif ($uri === '/api/mis-horarios' && $method === 'GET') {
        $sesion = requireAuth('Docente');
        $docente = (new Docente())->getPorCuenta((int) $sesion['idcuenta']);
        if (!$docente) {
            jsonResponse(['error' => 'No se encontró el registro de docente para esta cuenta'], 404);
        }
        $dia = $_GET['dia'] ?? null;
        jsonResponse((new Horario())->getPorDocente((int) $docente['iddocente'], $dia));
    }
    elseif ($uri === '/api/mis-asistencias' && $method === 'GET') {
        $sesion = requireAuth('Docente');
        $docente = (new Docente())->getPorCuenta((int) $sesion['idcuenta']);
        if (!$docente) {
            jsonResponse(['error' => 'No se encontró el registro de docente para esta cuenta'], 404);
        }
        jsonResponse((new AsistenciaDocente())->getPorDocente((int) $docente['iddocente']));
    }

    // HORARIO

    elseif ($uri === '/api/horarios' && $method === 'GET') {
        requireAuth();
        jsonResponse((new Horario())->getAll());
    }
    elseif (preg_match('#^/api/horarios/(\d+)$#', $uri, $m) && $method === 'GET') {
        requireAuth();
        $resultado = (new Horario())->getById((int) $m[1]);
        $resultado ? jsonResponse($resultado) : jsonResponse(['error' => 'Horario no encontrado'], 404);
    }
    elseif ($uri === '/api/horarios' && $method === 'POST') {
        requireAuth('Direccion');
        jsonResponse(['idhorario' => (new Horario())->create(getRequestBody()), 'message' => 'Horario creado correctamente'], 201);
    }
    elseif (preg_match('#^/api/horarios/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new Horario())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Horario actualizado correctamente']);
    }
    elseif (preg_match('#^/api/horarios/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
        (new Horario())->delete((int) $m[1]);
        jsonResponse(['message' => 'Horario eliminado correctamente']);
    }
    elseif (preg_match('#^/api/horarios/(\d+)/estudiantes$#', $uri, $m) && $method === 'GET') {
        requireAuth();
        jsonResponse((new HorarioEstudiante())->getPorHorario((int) $m[1]));
    }

    // INSCRIPCIONES (horario_estudiante)

    elseif ($uri === '/api/inscripciones' && $method === 'POST') {
        requireAuth('Direccion', 'Secretaria');
        $idInscripcion = (new HorarioEstudiante())->inscribir(getRequestBody());
        jsonResponse(['idhorario_estudiante' => $idInscripcion, 'message' => 'Estudiante inscrito correctamente'], 201);
    }
    elseif (preg_match('#^/api/inscripciones/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Secretaria');
        (new HorarioEstudiante())->retirar((int) $m[1]);
        jsonResponse(['message' => 'Inscripción eliminada correctamente']);
    }

    // GESTIÓN ACADÉMICA

    elseif ($uri === '/api/gestiones' && $method === 'GET') {
        requireAuth();
        jsonResponse((new GestionAcademica())->getAll());
    }
    elseif ($uri === '/api/gestiones' && $method === 'POST') {
        requireAuth('Direccion');
        jsonResponse(['idgestion_academica' => (new GestionAcademica())->create(getRequestBody()), 'message' => 'Gestión académica creada correctamente'], 201);
    }
    elseif (preg_match('#^/api/gestiones/(\d+)$#', $uri, $m) && $method === 'PUT') {
        requireAuth('Direccion');
        (new GestionAcademica())->update((int) $m[1], getRequestBody());
        jsonResponse(['message' => 'Gestión académica actualizada correctamente']);
    }
    elseif (preg_match('#^/api/gestiones/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion');
        (new GestionAcademica())->delete((int) $m[1]);
        jsonResponse(['message' => 'Gestión académica eliminada correctamente']);
    }

    // ASIGNACIÓN (matrícula estudiante -> paralelo/horario/gestión)

    elseif ($uri === '/api/asignaciones' && $method === 'GET') {
        requireAuth('Direccion', 'Secretaria');
        jsonResponse((new Asignacion())->getAll());
    }
    elseif ($uri === '/api/asignaciones' && $method === 'POST') {
        requireAuth('Direccion', 'Secretaria');
        jsonResponse(['idasignacion' => (new Asignacion())->create(getRequestBody()), 'message' => 'Asignación registrada correctamente'], 201);
    }
    elseif (preg_match('#^/api/asignaciones/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        requireAuth('Direccion', 'Secretaria');
        (new Asignacion())->delete((int) $m[1]);
        jsonResponse(['message' => 'Asignación eliminada correctamente']);
    }

    // ASISTENCIA DE ESTUDIANTES (el docente escanea el carnet QR)

    elseif ($uri === '/api/asistencia-estudiantes' && $method === 'GET') {
        requireAuth();
        $idHorario = validarIdPositivo($_GET['horario_idhorario'] ?? null, 'El horario');
        $fecha = validarFecha($_GET['fecha'] ?? getFechaBolivia(), 'La fecha');
        jsonResponse((new AsistenciaEstudiante())->getPorHorario($idHorario, $fecha));
    }
    elseif ($uri === '/api/asistencia-estudiantes' && $method === 'POST') {
        requireAuth('Docente');
        $data = getRequestBody();
        $codigoQr = $data['codigo_qr'] ?? '';
        $idHorario = validarIdPositivo($data['horario_idhorario'] ?? null, 'El horario');
        $resultado = (new AsistenciaEstudiante())->registrarPorQr($codigoQr, $idHorario);
        jsonResponse(array_merge($resultado, ['message' => 'Asistencia registrada: ' . $resultado['estado']]), 201);
    }
    elseif ($uri === '/api/asistencia-estudiantes/manual' && $method === 'POST') {
        requireAuth('Docente');
        $data = getRequestBody();
        $idHorario = validarIdPositivo($data['horario_idhorario'] ?? null, 'El horario');
        $idEstudiante = validarIdPositivo($data['estudiante_idestudiante'] ?? null, 'El estudiante');
        $motivo = $data['motivo'] ?? '';
        $resultado = (new AsistenciaEstudiante())->registrarManual($idHorario, $idEstudiante, $motivo);
        jsonResponse(array_merge($resultado, ['message' => 'Asistencia manual registrada: ' . $resultado['estado']]), 201);
    }

    // ASISTENCIA DE DOCENTES (QR institucional dinámico + geocerca GPS)

    elseif ($uri === '/api/asistencia-docentes' && $method === 'GET') {
        requireAuth('Direccion');
        jsonResponse((new AsistenciaDocente())->getAll($_GET['fecha_inicio'] ?? null, $_GET['fecha_fin'] ?? null));
    }
    elseif ($uri === '/api/asistencia-docentes' && $method === 'POST') {
        $sesion = requireAuth('Docente');
        $docente = (new Docente())->getPorCuenta((int) $sesion['idcuenta']);
        if (!$docente) {
            jsonResponse(['error' => 'No se encontró el registro de docente para esta cuenta'], 404);
        }

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

    // QR INSTITUCIONAL (pantalla de Dirección)

    elseif ($uri === '/api/qr-institucional' && $method === 'GET') {
        requireAuth('Direccion');
        jsonResponse((new QrInstitucional())->obtenerOGenerar());
    }

    // CONFIGURACIÓN DE GEOCERCA

    elseif ($uri === '/api/configuracion-geocerca' && $method === 'GET') {
        requireAuth('Direccion');
        jsonResponse((new ConfiguracionGeocerca())->obtener());
    }
    elseif ($uri === '/api/configuracion-geocerca' && $method === 'PUT') {
        requireAuth('Direccion');
        jsonResponse((new ConfiguracionGeocerca())->actualizar(getRequestBody()));
    }

    // RUTA NO ENCONTRADA

    else {
        jsonResponse(['error' => 'Ruta no encontrada: ' . $method . ' ' . $uri], 404);
    }
} catch (SesionInvalidaException $e) {
    jsonResponse(['error' => $e->getMessage()], 401);
} catch (AccesoDenegadoException $e) {
    jsonResponse(['error' => $e->getMessage()], 403);
} catch (Exception $e) {
    error_log('❌ Error general: ' . $e->getMessage() . ' en ' . $e->getFile() . ' línea ' . $e->getLine());
    $mensaje = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Error interno del servidor.';
    jsonResponse(['error' => $mensaje], 500);
}