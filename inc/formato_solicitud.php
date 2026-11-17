<?php
// PDF del formato SGSST-AC-F55 "Requerimiento de materiales/suministros", igual al de papel.
// Hoja carta: el formato va en el tercio de arriba (por hoja salen 3) con guia de recorte.
// Abajo a la derecha, en letra diminuta, el codigo y el token de la solicitud.
require_once __DIR__ . '/fpdf/fpdf.php';

class FormatoSolicitud extends FPDF {
    // ovalo (para el logo dibujado)
    function Elipse($x, $y, $rx, $ry, $estilo = 'D') {
        $op = $estilo === 'F' ? 'f' : ($estilo === 'FD' || $estilo === 'DF' ? 'B' : 'S');
        $lx = 4 / 3 * (M_SQRT2 - 1) * $rx; $ly = 4 / 3 * (M_SQRT2 - 1) * $ry;
        $k = $this->k; $h = $this->h;
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c', ($x + $rx) * $k, ($h - $y) * $k, ($x + $rx) * $k, ($h - ($y - $ly)) * $k, ($x + $lx) * $k, ($h - ($y - $ry)) * $k, $x * $k, ($h - ($y - $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', ($x - $lx) * $k, ($h - ($y - $ry)) * $k, ($x - $rx) * $k, ($h - ($y - $ly)) * $k, ($x - $rx) * $k, ($h - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', ($x - $rx) * $k, ($h - ($y + $ly)) * $k, ($x - $lx) * $k, ($h - ($y + $ry)) * $k, $x * $k, ($h - ($y + $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c %s', ($x + $lx) * $k, ($h - ($y + $ry)) * $k, ($x + $rx) * $k, ($h - ($y + $ly)) * $k, ($x + $rx) * $k, ($h - $y) * $k, $op));
    }
    function LineaPunteada($x1, $y, $x2, $trazo = 2, $espacio = 1.5) {
        for ($x = $x1; $x < $x2; $x += $trazo + $espacio) $this->Line($x, $y, min($x + $trazo, $x2), $y);
    }
}

// texto de la base (utf-8) a lo que entiende FPDF
function pdf_txt($s) {
    $s = (string)$s;
    $r = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    return $r === false ? mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8') : $r;
}

// recorta un texto para que quepa en un ancho
function pdf_cabe($pdf, $s, $ancho) {
    $s = pdf_txt($s);
    if ($pdf->GetStringWidth($s) <= $ancho) return $s;
    while ($s !== '' && $pdf->GetStringWidth($s . '...') > $ancho) $s = substr($s, 0, -1);
    return $s . '...';
}

function pdf_formato_solicitud($s, $lineas, $aprobador = '', $solicitante = '') {
    $pdf = new FormatoSolicitud('P', 'mm', 'Letter');
    $pdf->SetTitle(pdf_txt('Requerimiento de materiales/suministros ' . codigo_solicitud($s)));
    $pdf->SetAuthor('Sistemas Agro-Costa');
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $x0 = 8; $y0 = 6; $W = 199.9;
    $gris = [205, 205, 205];
    $L = 62; // ancho de la columna de la izquierda (logo y etiquetas)
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.35);

    // ---- encabezado: logo | titulo | codigo
    $hc = 15;
    $pdf->Rect($x0, $y0, $W, $hc);
    $pdf->Line($x0 + $L, $y0, $x0 + $L, $y0 + $hc);
    $pdf->Line($x0 + 150, $y0, $x0 + 150, $y0 + $hc);
    $logo = dirname(__DIR__) . '/assets/logo_agrocosta.png';
    if (is_file($logo)) {
        $pdf->Image($logo, $x0 + 4, $y0 + 1.5, $L - 8, $hc - 3);
    } else {
        // logo dibujado: ovalo amarillo y el nombre
        $cx = $x0 + $L / 2; $cy = $y0 + $hc / 2;
        $pdf->SetDrawColor(245, 196, 0); $pdf->SetLineWidth(1.4);
        $pdf->Elipse($cx, $cy + 0.3, 27, 5.6);
        $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.35);
        $pdf->SetFont('Helvetica', 'BI', 17);
        $pdf->SetXY($x0, $cy - 4); $pdf->Cell($L, 8, 'Agro-Costa', 0, 0, 'C');
        $pdf->SetFont('Helvetica', 'B', 4.5);
        $pdf->SetXY($x0 + $L - 18, $cy + 2.2); $pdf->Cell(10, 2, 'S.A.S.', 0, 0, 'L');
    }
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetXY($x0 + $L, $y0 + 3); $pdf->Cell(150 - $L, 4.6, 'REQUERIMIENTO DE', 0, 2, 'C');
    $pdf->Cell(150 - $L, 4.6, 'MATERIALES/SUMINISTROS', 0, 0, 'C');
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetXY($x0 + 150, $y0); $pdf->Cell($W - 150, $hc, 'SGSST-AC-F55-V0.0', 0, 0, 'C');

    // ---- tipo de compra, dpto, trabajo
    $hf = 5.5;
    $y = $y0 + $hc;
    $tipos = ['herramientas' => 'HERRAMIENTAS', 'repuestos' => 'REPUESTOS', 'consumibles' => 'CONSUMIBLES', 'otros' => 'OTROS'];
    $cols = [[$x0 + $L, 29], [$x0 + $L + 29, 29], [$x0 + $L + 58, 30], [$x0 + 150, $W - 150]];
    $pdf->SetFillColor(...$gris);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetXY($x0, $y); $pdf->Cell($L, $hf, 'TIPO DE COMPRA', 1, 0, 'C', true);
    $i = 0;
    foreach ($tipos as $k => $t) {
        [$cx, $cw] = $cols[$i++];
        $marcado = ($s['tipo_compra'] ?? '') === $k;
        $pdf->SetXY($cx, $y); $pdf->Cell($cw, $hf, '', 1);
        $pdf->SetFont('Helvetica', $marcado ? 'B' : '', 6.8);
        $pdf->SetXY($cx + 0.5, $y); $pdf->Cell($cw - 7, $hf, $t, 0, 0, 'C');
        // casilla
        $pdf->Rect($cx + $cw - 6, $y + 1.2, 3.1, 3.1);
        if ($marcado) { $pdf->SetFont('Helvetica', 'B', 9); $pdf->SetXY($cx + $cw - 6.1, $y + 0.6); $pdf->Cell(3.3, 4.2, 'X', 0, 0, 'C'); }
    }
    $y += $hf;
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetXY($x0, $y); $pdf->Cell($L, $hf, 'DPTO QUE SOLICITA', 1, 0, 'C', true);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(58, $hf, 'SISTEMAS', 1, 0, 'C');
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Cell(30, $hf, 'FECHA:', 1, 0, 'L', true);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell($W - 150, $hf, date('d/m/Y', strtotime($s['fecha'])), 1, 0, 'C');
    $y += $hf;
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetXY($x0, $y); $pdf->Cell($L, $hf, 'TRABAJO REALIZADO', 1, 0, 'C', true);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell($W - $L, $hf, ' ' . pdf_cabe($pdf, $s['trabajo_realizado'] ?? '', $W - $L - 3), 1, 0, 'L');
    $y += $hf;

    // ---- tabla de articulos
    $c = [['ITEM', 11], ['CANTIDAD', 17], ['U. DE MEDIDA', 22], ['NOMBRE DEL ARTÍCULO', 62], ['ESPECIFICACIONES DEL ARTÍCULO', 59], ['COSTO ESTIMADO', $W - 171]];
    $ht = 7;
    $pdf->SetFont('Helvetica', 'B', 7);
    $x = $x0;
    foreach ($c as [$t, $w]) {
        $pdf->SetXY($x, $y); $pdf->Cell($w, $ht, '', 1, 0, 'C', true);
        $partes = $pdf->GetStringWidth(pdf_txt($t)) > $w - 2 ? explode('|', wordwrap($t, $w > 30 ? 18 : 8, '|')) : [$t];
        $yy = $y + ($ht - count($partes) * 2.9) / 2;
        foreach ($partes as $p) { $pdf->SetXY($x, $yy); $pdf->Cell($w, 2.9, pdf_txt($p), 0, 0, 'C'); $yy += 2.9; }
        $x += $w;
    }
    $y += $ht;
    $hr = 5;
    $filas = max(6, count($lineas));
    for ($f = 0; $f < $filas; $f++) {
        $l = $lineas[$f] ?? null;
        $x = $x0;
        $vals = $l ? [(string)($f + 1), (string)(int)$l['cantidad'], (string)($l['unidad'] ?? ''), (string)$l['descripcion'], (string)($l['especificaciones'] ?? ''),
            $l['costo_estimado'] !== null && $l['costo_estimado'] !== '' ? '$' . number_format((float)$l['costo_estimado'], 0, ',', '.') : ''] : ['', '', '', '', '', ''];
        foreach ($c as $i => [$t, $w]) {
            $pdf->SetFont('Helvetica', $i === 3 ? 'B' : '', 8);
            $pdf->SetXY($x, $y);
            $pdf->Cell($w, $hr, ($i >= 3 && $i <= 4 ? ' ' : '') . pdf_cabe($pdf, $vals[$i], $w - 2), 1, 0, $i <= 2 ? 'C' : ($i === 5 ? 'R' : 'L'));
            $x += $w;
        }
        $y += $hr;
    }

    // ---- firmas
    $hs = 11;
    $pdf->Rect($x0, $y, $W, $hs);
    $pdf->Line($x0 + $L, $y, $x0 + $L, $y + $hs);
    $pdf->Line($x0 + 132, $y, $x0 + 132, $y + $hs);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetXY($x0 + 1, $y + 0.8); $pdf->Cell($L - 2, 4, pdf_cabe($pdf, mb_strtoupper($solicitante), $L - 2), 0, 0, 'C');
    $pdf->SetXY($x0 + $L + 1, $y + 0.8); $pdf->Cell(132 - $L - 2, 4, pdf_cabe($pdf, mb_strtoupper($aprobador), 132 - $L - 2), 0, 0, 'C');
    if (!empty($s['aprobado_en'])) {
        $pdf->SetFont('Helvetica', '', 6);
        $pdf->SetXY($x0 + $L + 1, $y + 4.3); $pdf->Cell(132 - $L - 2, 2.5, pdf_txt('Aprobado en el sistema ' . date('d/m/Y g:i a', strtotime($s['aprobado_en']))), 0, 0, 'C');
    }
    $pdf->SetFont('Helvetica', 'B', 7.5);
    $pdf->SetXY($x0, $y + $hs - 4.3); $pdf->Cell($L, 4, 'SOLICITADO POR:', 0, 0, 'C');
    $pdf->SetXY($x0 + $L, $y + $hs - 4.3); $pdf->Cell(132 - $L, 4, 'AUTORIZADO POR:', 0, 0, 'C');
    $pdf->SetXY($x0 + 132, $y + $hs - 4.3); $pdf->Cell($W - 132, 4, pdf_txt('RECIBÍ CONFORME:'), 0, 0, 'C');
    $y += $hs;
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetXY($x0, $y + 0.6); $pdf->Cell($W, 3.2, 'GENERO: MANTENIMIENTO GENERAL', 0, 0, 'C');

    // ---- codigo diminuto (token): casi no se ve, pero identifica la solicitud
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetFont('Courier', '', 3.6);
    $pdf->SetXY($x0 + $W - 40, $y + 0.9); $pdf->Cell(40, 1.6, codigo_solicitud($s) . ' ' . ($s['token'] ?? ''), 0, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);

    // ---- guia de recorte: la hoja carta da para 3 formatos; se usa el de arriba
    $corte = 279.4 / 3;
    $pdf->SetDrawColor(120, 120, 120); $pdf->SetLineWidth(0.25);
    $pdf->LineaPunteada(13, $corte, 212.9);
    // tijeras dibujadas: dos aros y dos hojas cruzadas
    $pdf->SetLineWidth(0.3); $pdf->SetDrawColor(90, 90, 90);
    $pdf->Elipse(6, $corte - 1.5, 1.1, 1.1); $pdf->Elipse(6, $corte + 1.5, 1.1, 1.1);
    $pdf->Line(7, $corte - 0.9, 11.5, $corte + 0.9); $pdf->Line(7, $corte + 0.9, 11.5, $corte - 0.9);
    $pdf->SetDrawColor(120, 120, 120); $pdf->SetLineWidth(0.25);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetFont('Helvetica', '', 6.5); $pdf->SetTextColor(110, 110, 110);
    $pdf->SetXY(91, $corte - 1.6); $pdf->Cell(34, 3.2, pdf_txt('Recortar por aquí'), 0, 0, 'C', true);
    // marcas de corte en las esquinas
    $pdf->Line(212.9, $corte - 3, 212.9, $corte + 3);
    $pdf->SetTextColor(0, 0, 0);

    return $pdf->Output('S');
}
