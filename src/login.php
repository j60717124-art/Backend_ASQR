<?php
// CONFIGURACIÓN DE ZONA HORARIA BOLIVIANA
date_default_timezone_set('America/La_Paz');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/database.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/usuarios.php';

// CONFIGURACIÓN CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        $data = getRequestBody();

        // Detectamos la acción: login, solicitar_pin o cambiar_clave
        $accion = $data['accion'] ?? 'login';

        // --- ACCIÓN: LOGIN ---
        if ($accion === 'login') {
            $usuarioOCorreo = trim($data['correo'] ?? $data['nombre_usuario'] ?? $data['usuario'] ?? '');
            $clave = trim($data['clave'] ?? '');

            if (empty($usuarioOCorreo) || empty($clave)) {
                jsonResponse(['error' => 'Usuario/Correo y contraseña son obligatorios'], 422);
            }

            $usuario = new Usuario();
            $resultado = $usuario->existeCuenta($usuarioOCorreo, $clave);

            if (!$resultado) {
                jsonResponse(['error' => 'Usuario/Correo o contraseña incorrectos'], 401);
            }

            $tipoUsuario = $resultado['rol_nombre'] ?? 'usuario';
            $token = crearSesionToken($tipoUsuario, (int) $resultado['idusuario']);

            jsonResponse([
                'message' => 'Acceso correcto',
                'tipo' => $tipoUsuario,
                'usuario' => $resultado,
                'token' => $token
            ]);
        }

        // --- ACCIÓN: SOLICITAR PIN DE RECUPERACIÓN ---
        elseif ($accion === 'solicitar_pin') {
            $correo = trim($data['correo'] ?? '');

            if (empty($correo)) {
                jsonResponse(['error' => 'El correo es obligatorio'], 422);
            }

            $usuario = new Usuario();
            $pin = $usuario->generarPinRecuperacion($correo);

            if ($pin === null) {
                // Por seguridad, no revelamos si el correo existe o no
                jsonResponse(['message' => 'Si el correo existe, recibirás un PIN de recuperación.']);
            }

            // ENVÍO DE CORREO ELECTRÓNICO
            $asunto = "Recuperación de Contraseña - Sistema Academico";
            $mensaje = "Hola,\n\n";
            $mensaje .= "Has solicitado restablecer tu contraseña.\n";
            $mensaje .= "Tu código PIN de verificación es: " . $pin . "\n\n";
            $mensaje .= "Este código expirará en 15 minutos.\n";
            $mensaje .= "Si no solicitaste esto, ignora este mensaje.\n\n";
            $mensaje .= "Atentamente,\nEl equipo de soporte.";

            $headers = "From: no-reply@tu-sistema.com\r\n";
            $headers .= "Reply-To: soporte@tu-sistema.com\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            // Nota: mail() requiere configuración en php.ini. Si falla, el PIN se guardó igual.
            @mail($correo, $asunto, $mensaje, $headers);

            jsonResponse(['message' => 'Se ha enviado un PIN a tu correo electrónico.']);
        }

        // --- ACCIÓN: CAMBIAR CLAVE CON PIN ---
        elseif ($accion === 'cambiar_clave') {
            $correo = trim($data['correo'] ?? '');
            $pin = trim($data['pin'] ?? '');
            $nuevaClave = trim($data['nueva_clave'] ?? '');

            if (empty($correo) || empty($pin) || empty($nuevaClave)) {
                jsonResponse(['error' => 'Correo, PIN y nueva contraseña son obligatorios'], 422);
            }

            $usuario = new Usuario();
            $exito = $usuario->verificarPinYCambiarClave($correo, $pin, $nuevaClave);

            if (!$exito) {
                jsonResponse(['error' => 'PIN incorrecto o expirado'], 401);
            }

            jsonResponse(['message' => 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.']);
        }

        else {
            jsonResponse(['error' => 'Acción no válida'], 400);
        }
    } else {
        jsonResponse(['error' => 'Método no permitido. Use POST.'], 405);
    }

} catch (Exception $e) {
    error_log('❌ Error en login: ' . $e->getMessage());
    jsonResponse(['error' => 'Error interno del servidor: ' . $e->getMessage()], 500);
}
?>