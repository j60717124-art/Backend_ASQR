<?php
// Catálogo de roles (Direccion, Secretaria, Docente, ...)
class Rol
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $sql = "SELECT idrol, nombre_rol, descripcion_rol FROM rol ORDER BY nombre_rol";
    $result = $this->db->query($sql);
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare("SELECT idrol, nombre_rol, descripcion_rol FROM rol WHERE idrol = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idrol FROM rol WHERE idrol = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  private function nombreExiste(string $nombre, ?int $idExcluir = null): bool
  {
    if ($idExcluir === null) {
      $stmt = $this->db->prepare("SELECT idrol FROM rol WHERE nombre_rol = ? LIMIT 1");
      $stmt->bind_param("s", $nombre);
    } else {
      $stmt = $this->db->prepare("SELECT idrol FROM rol WHERE nombre_rol = ? AND idrol != ? LIMIT 1");
      $stmt->bind_param("si", $nombre, $idExcluir);
    }
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $nombre = validarTextoObligatorio($data['nombre_rol'] ?? '', 'El nombre del rol', 3, 30);
    $descripcion = validarTextoOpcional($data['descripcion_rol'] ?? null, 'La descripción del rol', 100);

    if ($this->nombreExiste($nombre)) {
      throw new Exception("Ya existe un rol con ese nombre.");
    }

    $stmt = $this->db->prepare("INSERT INTO rol (nombre_rol, descripcion_rol) VALUES (?, ?)");
    $stmt->bind_param("ss", $nombre, $descripcion);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function update(int $id, array $data): bool
  {
    if (!$this->existeId($id)) {
      throw new Exception("El rol no existe.");
    }

    $campos = [];
    $tipos = "";
    $valores = [];

    if (isset($data['nombre_rol'])) {
      $nombre = validarTextoObligatorio($data['nombre_rol'], 'El nombre del rol', 3, 30);
      if ($this->nombreExiste($nombre, $id)) {
        throw new Exception("Ya existe un rol con ese nombre.");
      }
      $campos[] = "nombre_rol = ?";
      $tipos .= "s";
      $valores[] = $nombre;
    }

    if (array_key_exists('descripcion_rol', $data)) {
      $campos[] = "descripcion_rol = ?";
      $tipos .= "s";
      $valores[] = validarTextoOpcional($data['descripcion_rol'], 'La descripción del rol', 100);
    }

    if (empty($campos)) {
      throw new Exception("No hay datos para actualizar.");
    }

    $sql = "UPDATE rol SET " . implode(", ", $campos) . " WHERE idrol = ?";
    $tipos .= "i";
    $valores[] = $id;
    $stmt = $this->db->prepare($sql);
    $stmt->bind_param($tipos, ...$valores);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return true;
  }

  public function delete(int $id): bool
  {
    $stmt = $this->db->prepare("DELETE FROM rol WHERE idrol = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      // Lanzará error de FK si hay cuentas usando este rol
      throw new Exception("No se puede eliminar: hay cuentas asociadas a este rol.");
    }
    return true;
  }
}