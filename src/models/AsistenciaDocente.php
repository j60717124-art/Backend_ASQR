<?php
// Registro de asistencia del docente: escanea el QR dinámico de Dirección yse valida
// (a) que el QR sea auténtico, esté vigente y no se haya usado antes,
// (b) que sus coordenadas GPS estén dentro del radio de la geocerca.
class AsistenciaDocente
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getPorDocente(int $idDocente): array
  {
    $stmt = $this->db->prepare(
      "SELECT idasistencia_docente, fecha, hora, latitud, longitud, resultado, motivo_rechazo
        FROM asistencia_docente WHERE docente_iddocente = ? ORDER BY fecha DESC, hora DESC"
    );
    $stmt->bind_param("i", $idDocente);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  public function getAll(?string $fechaInicio = null, ?string $fechaFin = null): array
  {
    $sql = "
      SELECT ad.idasistencia_docente, ad.fecha, ad.hora, ad.latitud, ad.longitud,
              ad.resultado, ad.motivo_rechazo, ad.docente_iddocente,
              CONCAT(d.nombres, ' ', d.apellidos) AS docente_nombre
      FROM asistencia_docente ad
      INNER JOIN docente d ON d.iddocente = ad.docente_iddocente
    ";
    $condiciones = [];
    $tipos = "";
    $valores = [];

    if ($fechaInicio) {
      $condiciones[] = "ad.fecha >= ?";
      $tipos .= "s";
      $valores[] = validarFecha($fechaInicio, "La fecha de inicio");
    }
    if ($fechaFin) {
      $condiciones[] = "ad.fecha <= ?";
      $tipos .= "s";
      $valores[] = validarFecha($fechaFin, "La fecha de fin");
    }
    if (!empty($condiciones)) {
      $sql .= " WHERE " . implode(" AND ", $condiciones);
    }
    $sql .= " ORDER BY ad.fecha DESC, ad.hora DESC";

    $stmt = $this->db->prepare($sql);
    if (!empty($valores)) {
      $stmt->bind_param($tipos, ...$valores);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  private function yaRegistroHoyAceptado(int $idDocente, string $fecha): bool
  {
    $stmt = $this->db->prepare(
      "SELECT idasistencia_docente FROM asistencia_docente
        WHERE docente_iddocente = ? AND fecha = ? AND resultado = 'Aceptado' LIMIT 1"
    );
    $stmt->bind_param("is", $idDocente, $fecha);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function registrarAsistencia(int $idDocente, string $codigoQr, string $firmaQr, mixed $latitud, mixed $longitud): array
  {
    $latitud = validarLatitud($latitud, 'La latitud');
    $longitud = validarLongitud($longitud, 'La longitud');
    $codigoQr = limpiarTexto($codigoQr);
    $firmaQr = limpiarTexto($firmaQr);

    if ($codigoQr === '' || $firmaQr === '') {
      throw new Exception("Faltan datos del código QR escaneado.");
    }

    $fecha = getFechaBolivia();
    $hora = getHoraBolivia();

    if ($this->yaRegistroHoyAceptado($idDocente, $fecha)) {
      throw new Exception("Ya registraste tu asistencia hoy.");
    }

    $qrInstitucional = new QrInstitucional();
    $resultado = 'Rechazado';
    $motivo = null;
    $qr = null;

    try {
      $qr = $qrInstitucional->validarParaUso($codigoQr, $firmaQr);
    } catch (Exception $e) {
      $motivo = $e->getMessage();
    }

    if ($qr !== null) {
      $config = (new ConfiguracionGeocerca())->obtener();
      $distancia = distanciaMetros(
        (float) $latitud,
        (float) $longitud,
        (float) $config['latitud_referencia'],
        (float) $config['longitud_referencia']
      );

      if ($distancia <= (float) $config['radio_metros']) {
        $resultado = 'Aceptado';
      } else {
        $motivo = 'Estás a ' . round($distancia) . ' m del punto permitido (máximo ' . $config['radio_metros'] . ' m). Acércate e inténtalo de nuevo.';
      }

      // El código se consume en cualquier caso (aceptado o rechazado por distancia) para impedir reintentos con el mismo QR fotografiado.
      $qrInstitucional->marcarComoUsado((int) $qr['idqr_institucional']);
    }

    $stmt = $this->db->prepare(
      "INSERT INTO asistencia_docente (fecha, hora, latitud, longitud, resultado, motivo_rechazo, docente_iddocente)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssddssi", $fecha, $hora, $latitud, $longitud, $resultado, $motivo, $idDocente);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }

    if ($resultado === 'Rechazado') {
      error_log("🚫 Intento de asistencia rechazado - docente #$idDocente - motivo: $motivo");
    }

    return [
      'idasistencia_docente' => $this->db->insert_id,
      'resultado' => $resultado,
      'motivo_rechazo' => $motivo,
      'fecha' => $fecha,
      'hora' => $hora,
    ];
  }
}
