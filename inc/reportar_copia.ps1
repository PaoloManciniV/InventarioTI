# Reporte de copia de seguridad - Inventario TI Agro-Costa
#
# Que hace: SOLO LEE las carpetas de este disco (nombre, cantidad de archivos, tamano y fecha
# del archivo mas reciente) y le manda ese resumen a la plataforma de inventario.
# No copia, no mueve, no borra y no abre ningun archivo. No escribe nada en el disco.
# Lo unico que sale del PC: ese resumen, el nombre del PC, el usuario de Windows y quien hizo la copia.
#
# Va en la raiz del disco externo junto con REPORTAR_COPIA.bat (doble clic a ese .bat).

$URL   = '__URL__'
$CLAVE = '__CLAVE__'

# Carpetas que se revisan. Vacio = las que diga la plataforma (BACKUP_CARPETAS en el .env)
# y si alla tampoco estan fijadas, todas las carpetas que esten en la raiz del disco.
# Tambien se pueden fijar aqui: $CARPETAS = @('Traverse', 'Inventario', 'Otra')
$CARPETAS = @()

# Copias diarias: carpeta del disco donde cada dia se crea una carpeta nueva (03nov, 04nov...).
# Se revisa SOLO la carpeta del dia mas reciente. Vacio = lo que diga la plataforma (BACKUP_BASE).
# Acepta comodin: 'Copias diarias *' toma la del año mas reciente.
$BASE = ''

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$Host.UI.RawUI.WindowTitle = 'Reporte de copia de seguridad'

function Linea($t, $c = 'Gray') { Write-Host $t -ForegroundColor $c }
function Salir($cod) { Write-Host ''; Read-Host 'Presiona Enter para cerrar' | Out-Null; exit $cod }

Clear-Host
Linea '==============================================' 'Yellow'
Linea '  AGRO-COSTA - REPORTE DE COPIA DE SEGURIDAD' 'Yellow'
Linea '==============================================' 'Yellow'
Write-Host ''

# la plataforma dice quienes pueden hacer la copia y, si estan fijadas, cuales son las carpetas
$personas = @()
try {
    $cfg = Invoke-RestMethod -Uri ($URL + '?personas=1') -Headers @{ 'X-Clave' = $CLAVE } -TimeoutSec 20
    $personas = @($cfg.personas)
    if ($CARPETAS.Count -eq 0 -and $cfg.carpetas) { $CARPETAS = @($cfg.carpetas) }
    if (-not $BASE -and $cfg.base) { $BASE = "$($cfg.base)" }
} catch {
    Linea 'Aviso: no pude conectarme a la plataforma. Reviso el disco igual, pero el reporte no se va a poder enviar si no hay internet.' 'Yellow'
    Write-Host ''
}

$raiz = $PSScriptRoot
$unidad = (Get-Item $raiz).PSDrive.Name
Linea "Revisando el disco $($unidad):\ ..."
Write-Host ''

$omitir = @('System Volume Information', '$RECYCLE.BIN', 'Recovery', 'FOUND.000')
$revisar = $raiz
$dia = $null
if ($BASE) {
    $bases = @(Get-ChildItem -Path (Join-Path $raiz $BASE) -Directory -Force -ErrorAction SilentlyContinue | Sort-Object CreationTime, LastWriteTime -Descending)
    if ($bases.Count -gt 0) {
        $dias = @(Get-ChildItem -LiteralPath $bases[0].FullName -Directory -Force -ErrorAction SilentlyContinue | Sort-Object CreationTime, LastWriteTime -Descending)
        if ($dias.Count -gt 0) {
            $dia = $dias[0]
            $revisar = $dia.FullName
            Linea ("Copia mas reciente: {0}\{1}  (creada el {2})" -f $bases[0].Name, $dia.Name, $dia.CreationTime.ToString('dd/MM/yyyy HH:mm')) 'Cyan'
            Write-Host ''
        } else { Linea "La carpeta '$($bases[0].Name)' no tiene copias adentro." 'Red'; Salir 1 }
    } else { Linea "No encontre la carpeta '$BASE' en el disco. Reviso la raiz." 'Yellow'; Write-Host '' }
}
$dirs = Get-ChildItem -LiteralPath $revisar -Directory -Force -ErrorAction SilentlyContinue |
    Where-Object { $omitir -notcontains $_.Name -and -not $_.Name.StartsWith('.') }
if ($CARPETAS.Count -gt 0) {
    $dirs = foreach ($n in $CARPETAS) {
        $d = Get-Item -LiteralPath (Join-Path $revisar $n) -ErrorAction SilentlyContinue
        if ($d) { $d } else { [pscustomobject]@{ Name = $n; FullName = $null } }
    }
}
# si la copia del dia son archivos sueltos (no carpetas), se revisa cada archivo
if (-not $dirs -and $dia) {
    $dirs = @(Get-ChildItem -LiteralPath $revisar -File -Force -ErrorAction SilentlyContinue)
}
if (-not $dirs) { Linea 'No encontre carpetas en este disco. Este archivo debe estar en la raiz del disco de las copias.' 'Red'; Salir 1 }

$carpetas = @()
foreach ($d in $dirs) {
    Write-Host ("  {0,-30}" -f $d.Name) -NoNewline
    if (-not $d.FullName) {
        $carpetas += @{ nombre = $d.Name; archivos = 0; mb = 0; ultimo = $null }
        Linea 'NO ESTA EN EL DISCO' 'Red'; continue
    }
    if ($d -is [IO.FileInfo]) { $arch = @($d) }
    else { $arch = @(Get-ChildItem -LiteralPath $d.FullName -File -Recurse -Force -ErrorAction SilentlyContinue) }
    $bytes = ($arch | Measure-Object -Property Length -Sum).Sum
    if (-not $bytes) { $bytes = 0 }
    $ult = $arch | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    $mb = [math]::Round($bytes / 1MB, 2)
    $fecha = if ($ult) { $ult.LastWriteTime.ToString('yyyy-MM-dd HH\:mm\:ss') } else { $null }
    $carpetas += @{ nombre = $d.Name; archivos = $arch.Count; mb = $mb; ultimo = $fecha }
    if ($arch.Count -eq 0) { Linea 'VACIA' 'Red' }
    else { Linea ("{0,6} archivos  {1,10:N0} MB  ultimo: {2}" -f $arch.Count, $mb, $ult.LastWriteTime.ToString('dd/MM/yyyy HH:mm')) 'Green' }
}

$vol = $null
try { $vol = Get-Volume -DriveLetter $unidad -ErrorAction SilentlyContinue } catch { }
$disco = @{ etiqueta = ''; total_gb = 0; libre_gb = 0 }
if ($vol) { $disco = @{ etiqueta = "$($vol.FileSystemLabel)"; total_gb = [math]::Round($vol.Size / 1GB, 0); libre_gb = [math]::Round($vol.SizeRemaining / 1GB, 0) } }
if ($dia) { $disco.dia = $dia.Name; $disco.dia_creada = $dia.CreationTime.ToString('yyyy-MM-dd HH\:mm\:ss') }

# quien hizo la copia: la lista sale de la plataforma (personal de sistemas)
Write-Host ''
$persona = ''
if ($personas.Count -gt 0) {
    Linea 'Quien hizo la copia?' 'Yellow'
    for ($i = 0; $i -lt $personas.Count; $i++) { Linea ("  {0}. {1}" -f ($i + 1), $personas[$i]) 'White' }
    Linea ("  {0}. Otra persona" -f ($personas.Count + 1)) 'White'
    while (-not $persona) {
        $op = Read-Host 'Escribe el numero'
        $n = 0
        if ([int]::TryParse($op, [ref]$n)) {
            if ($n -ge 1 -and $n -le $personas.Count) { $persona = $personas[$n - 1] }
            elseif ($n -eq $personas.Count + 1) { $persona = (Read-Host 'Nombre completo').Trim() }
        }
    }
} else {
    while (-not $persona) { $persona = (Read-Host 'Quien hizo la copia? (nombre completo)').Trim() }
}
$nota = (Read-Host 'Alguna nota? (Enter para seguir sin nota)').Trim()

$datos = @{
    persona         = $persona
    pc              = $env:COMPUTERNAME
    usuario_windows = $env:USERNAME
    disco           = $disco
    carpetas        = $carpetas
    nota            = $nota
}
$json = $datos | ConvertTo-Json -Depth 5 -Compress

Write-Host ''
Linea 'Enviando el reporte...'
try {
    $r = Invoke-RestMethod -Method Post -Uri $URL -Headers @{ 'X-Clave' = $CLAVE } -TimeoutSec 60 `
        -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($json))
} catch {
    Linea 'NO SE PUDO ENVIAR el reporte.' 'Red'
    Linea ("Motivo: " + $_.Exception.Message) 'Red'
    Linea 'Revisa que haya internet y vuelve a darle doble clic. Si sigue fallando, avisale a sistemas.' 'Yellow'
    Salir 1
}

Write-Host ''
if ($r.estado -eq 'ok') {
    Linea '  LISTO: copia reportada, todo en orden.' 'Green'
} else {
    Linea '  Copia reportada, PERO hay que revisar:' 'Red'
    foreach ($a in $r.alertas) { Linea ("   - " + $a) 'Red' }
}
if ($r.correo) { Linea '  Ya les llego el correo a los encargados.' 'Green' }
else { Linea '  (El reporte quedo guardado en la plataforma, pero el correo no salio.)' 'Yellow' }
Salir 0
