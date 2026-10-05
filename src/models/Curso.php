<?php
class Curso
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $sql = "
      SELECT c.idcurso, c.nombre, c.colegio_idcolegio, c.nivel_idnivel,
              col.nombre AS colegio_nombre, n.nivel AS nivel_nombre
      FROM curso c
      INNER JOIN colegio col ON col.idcolegio = c.colegio_idcolegio
      INNER JOIN nivel n ON n.idnivel = c.nivel_idnivel
      ORDER BY col.nombre, n.nivel, c.nombre
    ";
    $result = $this->db->query($sql);
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare("SELECT idcurso, nombre, colegio_idcolegio, nivel_idnivel FROM curso WHERE idcurso = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idcurso FROM curso WHERE idcurso = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $nombre = validarTextoObligatorio($data['nombre'] ?? '', 'El nombre del curso', 1, 20);
    $colegioId = validarIdPositivo($data['colegio_idcolegio'] ?? null, 'El colegio');
    $nivelId = validarIdPositivo($data['nivel_idnivel'] ?? null, 'El nivel');

    if (!(new Colegio())->existeId($colegioId)) {
      throw new Exception("El colegio seleccionado no existe.");
    }
    if (!(new Nivel())->existeId($nivelId)) {
        throw new Exception("El nivel seleccionado no existe.");
    }

    $stmt = $this->db->prepare("INSERT INTO curso (nombre, colegio_idcolegio, nivel_idnivel) VALUES (?, ?, ?)");
    $stmt->bind_param("sii", $nombre, $colegioId, $nivelId);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function update(int $id, array $data): bool
  {
    if (!$this->existeId($id)) {
      throw new Exception("El curso no existe.");
    }

    $campos = [];
    $tipos = "";
    $valores = [];

    if (isset($data['nombre'])) {
      $campos[] = "nombre = ?";
      $tipos .= "s";
      $valores[] = validarTextoObligatorio($data['nombre'], 'El nombre del curso', 1, 20);
    }
    if (isset($data['colegio_idcolegio'])) {
      $colegioId = validarIdPositivo($data['colegio_idcolegio'], 'El colegio');
      if (!(new Colegio())->existeId($colegioId)) {
        throw new Exception("El colegio seleccionado no existe.");
      }
      $campos[] = "colegio_idcolegio = ?";
      $tipos .= "i";
      $valores[] = $colegioId;
    }
    if (isset($data['nivel_idnivel'])) {
      $nivelId = validarIdPositivo($data['nivel_idnivel'], 'El nivel');
      if (!(new Nivel())->existeId($nivelId)) {
        throw new Exception("El nivel seleccionado no existe.");
      }
      $campos[] = "nivel_idnivel = ?";
      $tipos .= "i";
      $valores[] = $nivelId;
    }

    if (empty($campos)) {
      throw new Exception("No hay datos para actualizar.");
    }

    $sql = "UPDATE curso SET " . implode(", ", $campos) . " WHERE idcurso = ?";
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
    $stmt = $this->db->prepare("DELETE FROM curso WHERE idcurso = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede eliminar: hay paralelos asociados a este curso.");
    }
    return true;
  }
}