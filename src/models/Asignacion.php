<?php
class Asignacion
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $sql = "
      SELECT a.idasignacion, a.fecha_asignacion, a.paralelo_idparalelo,
              a.gestion_academica_idgestion_academica, a.horario_idhorario, a.estudiante_idestudiante,
              CONCAT(e.nombres, ' ', e.apellidos) AS estudiante_nombre,
              p.nombre AS paralelo_nombre, g.nombre AS gestion_nombre
      FROM asignacion a
      INNER JOIN estudiante e ON e.idestudiante = a.estudiante_idestudiante
      INNER JOIN paralelo p ON p.idparalelo = a.paralelo_idparalelo
      INNER JOIN gestion_academica g ON g.idgestion_academica = a.gestion_academica_idgestion_academica
      ORDER BY a.fecha_asignacion DESC
    ";
    $result = $this->db->query($sql);
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT idasignacion, fecha_asignacion, paralelo_idparalelo,
              gestion_academica_idgestion_academica, horario_idhorario, estudiante_idestudiante
        FROM asignacion WHERE idasignacion = ? LIMIT 1"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idasignacion FROM asignacion WHERE idasignacion = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $fecha = validarFecha($data['fecha_asignacion'] ?? getFechaBolivia(), 'La fecha de asignación');
    $paraleloId = validarIdPositivo($data['paralelo_idparalelo'] ?? null, 'El paralelo');
    $gestionId = validarIdPositivo($data['gestion_academica_idgestion_academica'] ?? null, 'La gestión académica');
    $horarioId = validarIdPositivo($data['horario_idhorario'] ?? null, 'El horario');
    $estudianteId = validarIdPositivo($data['estudiante_idestudiante'] ?? null, 'El estudiante');

    if (!(new Paralelo())->existeId($paraleloId)) {
      throw new Exception("El paralelo seleccionado no existe.");
    }
    if (!(new GestionAcademica())->existeId($gestionId)) {
      throw new Exception("La gestión académica seleccionada no existe.");
    }
    if (!(new Horario())->existeId($horarioId)) {
      throw new Exception("El horario seleccionado no existe.");
    }
    if (!(new Estudiante())->existeId($estudianteId)) {
      throw new Exception("El estudiante seleccionado no existe.");
    }

    $stmt = $this->db->prepare(
      "INSERT INTO asignacion (fecha_asignacion, paralelo_idparalelo, gestion_academica_idgestion_academica, horario_idhorario, estudiante_idestudiante)
        VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("siiii", $fecha, $paraleloId, $gestionId, $horarioId, $estudianteId);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function delete(int $id): bool
  {
    $stmt = $this->db->prepare("DELETE FROM asignacion WHERE idasignacion = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return true;
  }
}
