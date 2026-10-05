<?php
// CONFIGURACIÓN GENERAL DEL BACKEND

//  BASE DE DATOS (TiDB Cloud)
define('DB_HOST', 'gateway01.us-east-1.prod.aws.tidbcloud.com');
define('DB_PORT', '4000');
define('DB_NAME', 'asqr');
define('DB_USER', 'sPykEGDX5ufBpNk.root');
define('DB_PASS', 'ILTyrcU780YPa3FK');
define('DB_SSL_CA', __DIR__ . '/../certs/isrgrootx1.pem'); // ruta al archivo .pem

//  DEPURACIÓN
// En producción cambiar a false para no filtrar detalles internos en las respuestas.
define('APP_DEBUG', true);

//  SEGURIDAD
// Clave secreta usada para firmar tokens de sesión y los QR institucionales (HMAC).
// IMPORTANTE: cambia este valor por uno propio y largo
define('APP_SECRET', 'AsisteciaQR_gestión2026');

// Duración del token de sesión (en segundos). 8 horas por defecto.
define('SESION_DURACION_SEGUNDOS', 8 * 60 * 60);

// Minutos de vigencia del PIN de recuperación de contraseña.
define('PIN_RECUPERACION_MINUTOS', 5);

// Minutos de tolerancia desde el inicio de la clase antes de marcar "Tardanza" al registrar la asistencia de un estudiante.
define('ASISTENCIA_TOLERANCIA_MINUTOS', 10);

// Intentos fallidos de login permitidos antes de bloquear temporalmente la cuenta.
define('LOGIN_INTENTOS_MAXIMOS', 3);
// Minutos que dura el bloqueo temporal tras superar los intentos máximos.
define('LOGIN_BLOQUEO_MINUTOS', 15);

//  CORREO ELECTRÓNICO (SMTP)
// Datos del servidor SMTP usado para enviar el PIN de recuperación de contraseña.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'tu_correo@gmail.com');
define('SMTP_PASS', 'tu_contrasena_de_aplicacion');
define('SMTP_FROM_EMAIL', 'tu_correo@gmail.com');
define('SMTP_FROM_NOMBRE', 'Sistema de Asistencia QR');
define('SMTP_HABILITADO', true);
