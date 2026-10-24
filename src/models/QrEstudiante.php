<?php
class QrEstudiante
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getByEstudiante(int $idEstudiante): array
  {
    $stmt = $this->db->prepare(
      "SELECT idqr_estudiante, codigo, fecha_emision, vigente, estudiante_idestudiante
        FROM qr_estudiante WHERE estudiante_idestudiante = ? ORDER BY fecha_emision DESC"
    );
    $stmt->bind_param("i", $idEstudiante);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  public function getVigentePorEstudiante(int $idEstudiante): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT idqr_estudiante, codigo, fecha_emision, vigente, estudiante_idestudiante
        FROM qr_estudiante WHERE estudiante_idestudiante = ? AND vigente = 1
        ORDER BY fecha_emision DESC LIMIT 1"
    );
    $stmt->bind_param("i", $idEstudiante);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function getPorCodigo(string $codigo): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT idqr_estudiante, codigo, fecha_emision, vigente, estudiante_idestudiante
        FROM qr_estudiante WHERE codigo = ? LIMIT 1"
    );
    $stmt->bind_param("s", $codigo);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function generarNuevo(int $idEstudiante): array
  {
    // Advertir si se genera duplicado cuando ya existe uno vigente
    $anterior = $this->getVigentePorEstudiante($idEstudiante);
    if ($anterior) {
      $upd = $this->db->prepare("UPDATE qr_estudiante SET vigente = 0 WHERE idqr_estudiante = ?");
      $upd->bind_param("i", $anterior['idqr_estudiante']);
      $upd->execute();
    }

    do {
      $codigo = generarCodigoQrUnico();
    } while ($this->getPorCodigo($codigo) !== null);

    $stmt = $this->db->prepare(
      "INSERT INTO qr_estudiante (codigo, vigente, estudiante_idestudiante) VALUES (?, 1, ?)"
    );
    $stmt->bind_param("si", $codigo, $idEstudiante);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }

    return [
      'idqr_estudiante' => $this->db->insert_id,
      'codigo' => $codigo,
      'estudiante_idestudiante' => $idEstudiante,
      'qr_anterior_invalidado' => $anterior ? $anterior['codigo'] : null,
    ];
  }

  public function emitirDuplicado(int $idEstudiante): array
  {
    // Aseguramos que el estudiante exista antes de duplicar
    if (!(new Estudiante())->existeId($idEstudiante)) {
      throw new Exception("El estudiante no existe.");
    }
    return $this->generarNuevo($idEstudiante);
  }
}