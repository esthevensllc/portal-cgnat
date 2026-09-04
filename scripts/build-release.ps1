param(
    [string]$Version = (Get-Date -Format 'yyyyMMdd-HHmmss')
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Artifacts = Join-Path $ProjectRoot 'artifacts'
$TemporaryRoot = Join-Path ([System.IO.Path]::GetTempPath()) "portal-cgnat-$Version"
$SourceZip = Join-Path ([System.IO.Path]::GetTempPath()) "portal-cgnat-$Version.zip"
$Archive = Join-Path $Artifacts "portal-cgnat-release-$Version.tar.gz"

if (-not (Test-Path (Join-Path $ProjectRoot '.git'))) {
    throw 'El proyecto debe ser un repositorio Git antes de generar una entrega.'
}

$SafeDirectoryArgument = "safe.directory=$($ProjectRoot.Replace('\\', '/'))"

if (git -c $SafeDirectoryArgument -C $ProjectRoot status --porcelain) {
    throw 'Existen cambios sin registrar. Crea un commit antes de generar la entrega.'
}

New-Item -ItemType Directory -Path $Artifacts -Force | Out-Null
New-Item -ItemType Directory -Path $TemporaryRoot -Force | Out-Null

try {
    git -c $SafeDirectoryArgument -C $ProjectRoot archive --format=zip --output=$SourceZip HEAD
    Expand-Archive -LiteralPath $SourceZip -DestinationPath $TemporaryRoot -Force

    docker run --rm --platform linux/amd64 `
        -v "${TemporaryRoot}:/workspace" `
        -w /workspace `
        composer:2.10.2 `
        composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative

    docker run --rm --platform linux/amd64 `
        -v "${TemporaryRoot}:/workspace" `
        -w /workspace `
        node:24.18.0-bookworm-slim `
        sh -lc 'npm ci --ignore-scripts && npm run build'

    tar -czf $Archive -C $TemporaryRoot --exclude=node_modules .

    $Hash = (Get-FileHash $Archive -Algorithm SHA256).Hash.ToLowerInvariant()
    $ChecksumLine = "$Hash  $(Split-Path $Archive -Leaf)`n"
    [System.IO.File]::WriteAllText("$Archive.sha256", $ChecksumLine, [System.Text.Encoding]::ASCII)

    Write-Host "Release: $Archive"
    Write-Host "SHA-256: $Hash"
}
finally {
    if (Test-Path $TemporaryRoot) {
        Remove-Item -LiteralPath $TemporaryRoot -Recurse -Force
    }

    if (Test-Path $SourceZip) {
        Remove-Item -LiteralPath $SourceZip -Force
    }
}
