<?php
class Colegio
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $result = $this->db->query("SELECT idcolegio, nombre, telefono, ubicacion FROM colegio ORDER BY nombre");
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare("SELECT idcolegio, nombre, telefono, ubicacion FROM colegio WHERE idcolegio = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idcolegio FROM colegio WHERE idcolegio = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $nombre = validarTextoObligatorio($data['nombre'] ?? '', 'El nombre del colegio', 3, 100);
    $telefono = validarTelefono($data['telefono'] ?? null, false);
    $ubicacion = validarTextoOpcional($data['ubicacion'] ?? null, 'La ubicación', 255);

    $stmt = $this->db->prepare("INSERT INTO colegio (nombre, telefono, ubicacion) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $nombre, $telefono, $ubicacion);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function update(int $id, array $data): bool
  {
    if (!$this->existeId($id)) {
      throw new Exception("El colegio no existe.");
    }

    $campos = [];
    $tipos = "";
    $valores = [];

    if (isset($data['nombre'])) {
      $campos[] = "nombre = ?";
      $tipos .= "s";
      $valores[] = validarTextoObligatorio($data['nombre'], 'El nombre del colegio', 3, 100);
    }
    if (array_key_exists('telefono', $data)) {
      $campos[] = "telefono = ?";
      $tipos .= "s";
      $valores[] = validarTelefono($data['telefono'], false);
    }
    if (array_key_exists('ubicacion', $data)) {
      $campos[] = "ubicacion = ?";
      $tipos .= "s";
      $valores[] = validarTextoOpcional($data['ubicacion'], 'La ubicación', 255);
    }

    if (empty($campos)) {
      throw new Exception("No hay datos para actualizar.");
    }

    $sql = "UPDATE colegio SET " . implode(", ", $campos) . " WHERE idcolegio = ?";
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
    $stmt = $this->db->prepare("DELETE FROM colegio WHERE idcolegio = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede eliminar: hay aulas o cursos asociados a este colegio.");
    }
    return true;
  }
}
