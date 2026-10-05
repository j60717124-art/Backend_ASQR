<?php
class Nivel
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $result = $this->db->query("SELECT idnivel, nivel, descripcion FROM nivel ORDER BY nivel");
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare("SELECT idnivel, nivel, descripcion FROM nivel WHERE idnivel = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idnivel FROM nivel WHERE idnivel = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $nivel = validarTextoObligatorio($data['nivel'] ?? '', 'El nombre del nivel', 2, 22);
    $descripcion = validarTextoOpcional($data['descripcion'] ?? null, 'La descripción', 255);
    $stmt = $this->db->prepare("INSERT INTO nivel (nivel, descripcion) VALUES (?, ?)");
    $stmt->bind_param("ss", $nivel, $descripcion);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function update(int $id, array $data): bool
  {
    if (!$this->existeId($id)) {
      throw new Exception("El nivel no existe.");
    }

    $campos = [];
    $tipos = "";
    $valores = [];

    if (isset($data['nivel'])) {
      $campos[] = "nivel = ?";
      $tipos .= "s";
      $valores[] = validarTextoObligatorio($data['nivel'], 'El nombre del nivel', 2, 22);
    }
    if (array_key_exists('descripcion', $data)) {
      $campos[] = "descripcion = ?";
      $tipos .= "s";
      $valores[] = validarTextoOpcional($data['descripcion'], 'La descripción', 255);
    }

    if (empty($campos)) {
      throw new Exception("No hay datos para actualizar.");
    }

    $sql = "UPDATE nivel SET " . implode(", ", $campos) . " WHERE idnivel = ?";
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
    $stmt = $this->db->prepare("DELETE FROM nivel WHERE idnivel = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede eliminar: hay cursos asociados a este nivel.");
    }
    return true;
  }
}