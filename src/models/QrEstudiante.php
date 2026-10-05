<?php
// QR fijo impreso en el carnet físico del estudiante.
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
// Genera un QR fijo nuevo para el estudiante e invalida cualquier QR vigente anterior (por ejemplo, al emitir un duplicado por pérdida de carnet).
  public function generarNuevo(int $idEstudiante): array
  {
    $anterior = $this->getVigentePorEstudiante($idEstudiante);
    if ($anterior) {
      $upd = $this->db->prepare("UPDATE qr_estudiante SET vigente = 0 WHERE idqr_estudiante = ?");
      $upd->bind_param("i", $anterior['idqr_estudiante']);
      $upd->execute();
    }

    // Intentamos generar un código único (muy improbable que choque, pero validamos igual)
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
    ];
  }

  // Emite un duplicado (alias explícito de generarNuevo, pensado para Secretaría/Dirección)
  public function emitirDuplicado(int $idEstudiante): array
  {
    return $this->generarNuevo($idEstudiante);
  }
}