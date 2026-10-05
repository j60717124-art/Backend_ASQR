<?php

class QrInstitucional
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getPorCodigo(string $codigo): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT idqr_institucional, codigo, firma, fecha_emision, fecha_expiracion, usado
        FROM qr_institucional WHERE codigo = ? LIMIT 1"
    );
    $stmt->bind_param("s", $codigo);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  // Devuelve el QR vigente actual (no expirado y no usado), o null si no hay ninguno.
  public function getVigente(): ?array
  {
    $ahora = getFechaHoraBolivia();
    $stmt = $this->db->prepare(
      "SELECT idqr_institucional, codigo, firma, fecha_emision, fecha_expiracion, usado
        FROM qr_institucional
        WHERE fecha_expiracion > ? AND usado = 0
        ORDER BY fecha_emision DESC LIMIT 1"
    );
    $stmt->bind_param("s", $ahora);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function obtenerOGenerar(): array
  {
    $vigente = $this->getVigente();
    if ($vigente) {
      return $vigente;
    }
    return $this->generarNuevo();
  }

  public function generarNuevo(): array
  {
    $config = (new ConfiguracionGeocerca())->obtener();
    $segundos = (int) $config['intervalo_rotacion_segundos'];

    do {
      $codigo = generarCodigoQrUnico();
    } while ($this->getPorCodigo($codigo) !== null);

    $fechaExpiracion = date('Y-m-d H:i:s', strtotime('+' . $segundos . ' seconds'));
    $firma = generarFirmaQR($codigo, $fechaExpiracion);

    $stmt = $this->db->prepare(
      "INSERT INTO qr_institucional (codigo, firma, fecha_expiracion, usado) VALUES (?, ?, ?, 0)"
    );
    $stmt->bind_param("sss", $codigo, $firma, $fechaExpiracion);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }

    return [
      'idqr_institucional' => $this->db->insert_id,
      'codigo' => $codigo,
      'firma' => $firma,
      'fecha_expiracion' => $fechaExpiracion,
      'usado' => 0,
    ];
  }

  // validador de QR institucional para uso en el sistema, verifica que el código exista, que la firma sea válida, que no haya sido usado y que no haya expirado.
  public function validarParaUso(string $codigo, string $firma): array
  {
    $qr = $this->getPorCodigo($codigo);
    if (!$qr) {
      throw new Exception("El código QR no es válido.");
    }

    if (!verificarFirmaQR($codigo, $qr['fecha_expiracion'], $firma)) {
      throw new Exception("El código QR no es válido.");
    }

    if ((int) $qr['usado'] === 1) {
      throw new Exception("Este código QR ya fue utilizado. Escanea el código actual en pantalla.");
    }

    if (strtotime($qr['fecha_expiracion']) < time()) {
      throw new Exception("Este código QR ya expiró. Escanea el código actual en pantalla.");
    }

    return $qr;
  }

  public function marcarComoUsado(int $idQrInstitucional): void
  {
    $stmt = $this->db->prepare("UPDATE qr_institucional SET usado = 1 WHERE idqr_institucional = ?");
    $stmt->bind_param("i", $idQrInstitucional);
    $stmt->execute();
  }
}