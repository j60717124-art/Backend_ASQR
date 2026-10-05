<?php
class Usuario
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = getDB();
    }

    // ==========================================
    // CRUD BÁSICO
    // ==========================================

    // Listado de usuarios (con su rol)
    public function getAll(): array
    {
        $sql = "
            SELECT u.idusuario, u.nombre_usuario, u.correo, u.estado, u.rol_idrol,
                   r.nombre_rol AS rol_nombre
            FROM usuario u
            INNER JOIN rol r
                ON r.idrol = u.rol_idrol
            ORDER BY u.idusuario DESC
        ";
        $result = $this->db->query($sql);
        if (!$result) {
            throw new Exception($this->db->error);
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    // Obtener usuario por ID
    public function getById(int $id): ?array
    {
        $sql = "
            SELECT u.idusuario, u.nombre_usuario, u.correo, u.estado, u.rol_idrol,
                   r.nombre_rol AS rol_nombre
            FROM usuario u
            INNER JOIN rol r
                ON r.idrol = u.rol_idrol
            WHERE u.idusuario = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception($this->db->error);
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        return $resultado->fetch_assoc() ?: null;
    }

    // Verificar si el nombre de usuario ya existe
    private function usuarioExiste(string $usuario, ?int $idExcluir = null): bool
    {
        if ($idExcluir === null) {
            $sql = "SELECT idusuario FROM usuario WHERE nombre_usuario = ? LIMIT 1";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception($this->db->error);
            }
            $stmt->bind_param("s", $usuario);
        } else {
            $sql = "SELECT idusuario FROM usuario WHERE nombre_usuario = ? AND idusuario != ? LIMIT 1";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception($this->db->error);
            }
            $stmt->bind_param("si", $usuario, $idExcluir);
        }
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    // Verificar si el correo ya existe
    private function correoExiste(string $correo, ?int $idExcluir = null): bool
    {
        if ($idExcluir === null) {
            $sql = "SELECT idusuario FROM usuario WHERE correo = ? LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $correo);
        } else {
            $sql = "SELECT idusuario FROM usuario WHERE correo = ? AND idusuario != ? LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("si", $correo, $idExcluir);
        }
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    // Verificar si el rol existe
    private function rolExiste(int $rolId): bool
    {
        $sql = "SELECT idrol FROM rol WHERE idrol = ? LIMIT 1 ";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception($this->db->error);
        }
        $stmt->bind_param("i", $rolId);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    // Crear una nueva cuenta
    public function create(string $usuario, string $correo, string $clave, string $estado, int $rol_id): int
    {
        $usuario = validarNombreUsuario($usuario);
        $correo = validarCorreo($correo, true);
        $clave = validarClave($clave);
        $estado = validarEnum($estado ?: 'Activo', ['Activo', 'Inactivo'], "El estado de la cuenta");
        $rol_id = validarIdPositivo($rol_id, "El rol");

        if ($this->usuarioExiste($usuario)) {
            throw new Exception("El nombre de usuario ya existe.");
        }
        if ($this->correoExiste($correo)) {
            throw new Exception("Ya existe una cuenta registrada con ese correo.");
        }
        if (!$this->rolExiste($rol_id)) {
            throw new Exception("El rol seleccionado no existe.");
        }

        // Nunca se guarda la contraseña en texto plano
        $claveHash = password_hash($clave, PASSWORD_DEFAULT);

        $sql = "INSERT INTO usuario (nombre_usuario, correo, clave, estado, rol_idrol) VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);

        if (!$stmt) {
            throw new Exception($this->db->error);
        }

        $stmt->bind_param("ssssi", $usuario, $correo, $claveHash, $estado, $rol_id);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        return $this->db->insert_id;
    }

    // Actualizar una cuenta
    public function update(int $id, array $data): bool
    {
        $campos = [];
        $tipos = "";
        $valores = [];

        if (isset($data["nombre_usuario"])) {
            $usuario = validarNombreUsuario($data["nombre_usuario"]);
            if ($this->usuarioExiste($usuario, $id)) {
                throw new Exception("El nombre de usuario ya existe.");
            }
            $campos[] = "nombre_usuario = ?";
            $tipos .= "s";
            $valores[] = $usuario;
        }

        if (isset($data["correo"])) {
            $correo = validarCorreo($data["correo"], true);
            if ($this->correoExiste($correo, $id)) {
                throw new Exception("Ya existe una cuenta registrada con ese correo.");
            }
            $campos[] = "correo = ?";
            $tipos .= "s";
            $valores[] = $correo;
        }

        if (isset($data["estado"])) {
            $estado = validarEnum($data["estado"], ['Activo', 'Inactivo'], "El estado de la cuenta");
            $campos[] = "estado = ?";
            $tipos .= "s";
            $valores[] = $estado;
        }

        if (isset($data["rol_idrol"])) {
            $rol_id = validarIdPositivo($data["rol_idrol"], "El rol");
            if (!$this->rolExiste($rol_id)) {
                throw new Exception("El rol seleccionado no existe.");
            }
            $campos[] = "rol_idrol = ?";
            $tipos .= "i";
            $valores[] = $rol_id;
        }

        if (isset($data["clave"]) && trim($data["clave"]) !== "") {
            $clave = validarClave($data["clave"]);
            $campos[] = "clave = ?";
            $tipos .= "s";
            $valores[] = password_hash($clave, PASSWORD_DEFAULT);
        }

        if (empty($campos)) {
            throw new Exception("No hay datos para actualizar.");
        }

        $sql = "UPDATE usuario SET " . implode(", ", $campos) . " WHERE idusuario = ?";
        $tipos .= "i";
        $valores[] = $id;
        $stmt = $this->db->prepare($sql);

        if (!$stmt) {
            throw new Exception($this->db->error);
        }
        $stmt->bind_param($tipos, ...$valores);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        return true;
    }

    // Eliminar cuenta
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM usuario WHERE idusuario = ?";
        $stmt = $this->db->prepare($sql);

        if (!$stmt) {
            throw new Exception($this->db->error);
        }
        $stmt->bind_param("i", $id);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        return true;
    }

    // ==========================================
    // LOGIN Y SEGURIDAD
    // ==========================================

    // Verificar credenciales para el inicio de sesión.
    public function existeCuenta(string $usuarioOCorreo, string $clave): ?array
    {
        $sql = "
            SELECT u.idusuario, u.nombre_usuario, u.correo, u.clave, u.estado, u.rol_idrol,
                   r.nombre_rol
            FROM usuario u
            INNER JOIN rol r
                ON r.idrol = u.rol_idrol
            WHERE (u.nombre_usuario = ? OR u.correo = ?)
            AND u.estado = 'Activo'
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        if (!$stmt) {
            throw new Exception($this->db->error);
        }

        $stmt->bind_param("ss", $usuarioOCorreo, $usuarioOCorreo);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $fila = $resultado->fetch_assoc();

        if (!$fila) {
            return null;
        }

        $claveValida = password_verify($clave, $fila['clave']);

        // Compatibilidad con contraseñas antiguas guardadas en texto plano
        if (!$claveValida && hash_equals($fila['clave'], $clave)) {
            $claveValida = true;
            $nuevoHash = password_hash($clave, PASSWORD_DEFAULT);
            $upd = $this->db->prepare("UPDATE usuario SET clave = ? WHERE idusuario = ?");
            $upd->bind_param("si", $nuevoHash, $fila['idusuario']);
            $upd->execute();
        }

        if (!$claveValida) {
            return null;
        }

        unset($fila['clave']);
        return $fila;
    }

    // ==========================================
    // RECUPERACIÓN DE CONTRASEÑA (PIN)
    // ==========================================

    /**
     * Genera un PIN de 6 dígitos, lo guarda en BD con expiración de 15 min
     * y devuelve el PIN (o null si el correo no existe).
     */
    public function generarPinRecuperacion(string $correo): ?string
    {
        $correo = validarCorreo($correo, true);

        // Verificar si el usuario existe y está activo
        $sqlCheck = "SELECT idusuario FROM usuario WHERE correo = ? AND estado = 'Activo' LIMIT 1";
        $stmtCheck = $this->db->prepare($sqlCheck);
        $stmtCheck->bind_param("s", $correo);
        $stmtCheck->execute();
        $res = $stmtCheck->get_result();

        if ($res->num_rows === 0) {
            return null; // El correo no existe, pero no se lo decimos al usuario por seguridad
        }

        // Generar PIN aleatorio de 6 dígitos (con ceros a la izquierda si es necesario)
        $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Guardar PIN y fecha de expiración (15 minutos desde ahora)
        $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $sqlUpdate = "UPDATE usuario SET pin_recuperacion = ?, pin_expiracion = ? WHERE correo = ?";
        $stmtUpdate = $this->db->prepare($sqlUpdate);
        $stmtUpdate->bind_param("sss", $pin, $expiracion, $correo);

        if (!$stmtUpdate->execute()) {
            throw new Exception($stmtUpdate->error);
        }

        return $pin;
    }

    /**
     * Verifica el PIN y cambia la contraseña si es correcto y no ha expirado.
     */
    public function verificarPinYCambiarClave(string $correo, string $pin, string $nuevaClave): bool
    {
        $correo = validarCorreo($correo, true);
        $nuevaClave = validarClave($nuevaClave);

        $ahora = date('Y-m-d H:i:s');

        // Buscar usuario con ese correo y PIN válido (no expirado)
        $sql = "SELECT idusuario FROM usuario
                WHERE correo = ?
                AND pin_recuperacion = ?
                AND pin_expiracion > ?
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("sss", $correo, $pin, $ahora);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            return false; // PIN incorrecto o expirado
        }

        $fila = $res->fetch_assoc();
        $idUsuario = (int) $fila['idusuario'];

        // Actualizar contraseña y limpiar el PIN para que no se pueda reusar
        $hash = password_hash($nuevaClave, PASSWORD_DEFAULT);
        $sqlUpdate = "UPDATE usuario SET clave = ?, pin_recuperacion = NULL, pin_expiracion = NULL WHERE idusuario = ?";
        $stmtUpdate = $this->db->prepare($sqlUpdate);
        $stmtUpdate->bind_param("si", $hash, $idUsuario);

        return $stmtUpdate->execute();
    }
}
?>