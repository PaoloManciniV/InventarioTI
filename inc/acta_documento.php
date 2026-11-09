<?php
// cuerpo del acta usado en la vista interna en el link publico y al imprimir
// requiere $a cargada con cargar_acta y opcional $token para ver la firma sin sesion

// textos legales del acta se ajustan aqui
$texto_entrega = 'Yo, %s, identificado(a) con cedula %s, declaro que recibo de AGRO-COSTA S.A.S. los elementos relacionados en esta acta, '
    . 'en el estado descrito, para el desarrollo de mis funciones. Me comprometo a cuidarlos, usarlos solo para fines laborales, '
    . 'no prestarlos a terceros sin autorizacion y devolverlos cuando la empresa lo solicite o al terminar mi vinculacion. '
    . 'Cualquier perdida o dano por mal uso se manejara de acuerdo con el reglamento interno de trabajo.';
$texto_devolucion = 'Yo, %s, identificado(a) con cedula %s, hago devolucion a AGRO-COSTA S.A.S. de los elementos relacionados en esta acta, '
    . 'en el estado descrito, y quedan a cargo de la empresa desde la fecha de esta acta.';

$cc = $a['firma_cedula'] ?: ($a['cedula'] ?: '________');
$parrafo = sprintf($a['tipo'] === 'entrega' ? $texto_entrega : $texto_devolucion, '<b>' . e($a['firma_nombre'] ?: $a['colaborador']) . '</b>', '<b>' . e($cc) . '</b>');
$url_firma = $a['firma_archivo'] ? url_archivo($a['firma_archivo']) . (!empty($token) ? '&t=' . urlencode($token) : '') : null;
?>
<div class="tarjeta papel acta-doc">
  <div class="cabecera" style="margin-bottom:10px">
    <div><b style="font-size:15px">AGRO-COSTA S.A.S.</b><br><span class="tenue">Acta de <?= $a['tipo'] === 'entrega' ? 'entrega' : 'devolucion' ?> de elementos</span></div>
    <div style="text-align:right"><b class="mono" style="font-size:15px"><?= e($a['consecutivo']) ?></b><br><span class="tenue"><?= fecha_corta($a['fecha']) ?><?= $a['sede'] ? ' · ' . e($a['sede']) : '' ?></span></div>
  </div>
  <div class="ficha" style="margin-bottom:12px">
    <div><small>Colaborador</small><span><?= e($a['colaborador']) ?></span></div>
    <div><small>Cedula</small><span><?= e($a['cedula'] ?: '—') ?></span></div>
    <div><small>Cargo</small><span><?= e($a['cargo'] ?: '—') ?></span></div>
    <div><small>Area</small><span><?= e($a['area'] ?: '—') ?></span></div>
  </div>
  <div class="tabla-envoltura" style="margin-bottom:12px">
    <table class="tabla"><thead><tr><th>#</th><th>Etiqueta</th><th>Descripcion</th><th>Serial</th><th>Cant.</th><th>Condicion</th></tr></thead><tbody>
    <?php foreach ($a['items'] as $n => $i): ?>
      <tr><td><?= $n + 1 ?></td><td class="mono"><?= e($i['etiqueta'] ?: '—') ?></td><td><?= e($i['descripcion']) ?></td>
        <td class="mono"><?= e($i['serial'] ?: '—') ?></td><td><?= (int)$i['cantidad'] ?></td><td><?= e($i['condicion'] ?: '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <?php if ($a['observaciones']): ?><p style="white-space:pre-line"><small class="tenue">Observaciones</small><br><?= e($a['observaciones']) ?></p><?php endif; ?>
  <p style="text-align:justify;line-height:1.5"><?= $parrafo ?></p>

  <div class="rejilla" style="margin-top:20px;align-items:end">
    <div>
      <?php if ($url_firma): ?><img class="firma-img" src="<?= e($url_firma) ?>" alt="Firma"><br>
      <?php elseif ($a['firmado_modo'] === 'papel'): ?><div class="tenue" style="border-bottom:1px solid #999;padding:30px 0 6px">Firmada en papel (ver soporte)</div>
      <?php else: ?><div style="border-bottom:1px solid #999;height:70px"></div><?php endif; ?>
      <small><?= $a['tipo'] === 'entrega' ? 'Recibe' : 'Entrega' ?>: <?= e($a['firma_nombre'] ?: $a['colaborador']) ?> · CC <?= e($cc) ?></small>
      <?php if ($a['firmado_en'] && $a['firmado_modo'] !== 'papel'): ?>
        <br><small class="tenue">Firmado electronicamente el <?= fecha_hora($a['firmado_en']) ?> (<?= $a['firmado_modo'] === 'link' ? 'por enlace' : 'en pantalla' ?>) · IP <?= e($a['firmado_ip']) ?></small>
      <?php endif; ?>
    </div>
    <div><div style="border-bottom:1px solid #999;padding:46px 0 6px"><?= e($a['creado_por_nombre']) ?></div>
      <small><?= $a['tipo'] === 'entrega' ? 'Entrega' : 'Recibe' ?> por la empresa</small></div>
  </div>
  <?php if ($a['hash_contenido']): ?>
    <p class="tenue mono" style="font-size:10.5px;margin-bottom:0;word-break:break-all">Huella: <?= e($a['hash_contenido']) ?></p>
  <?php endif; ?>
</div>
