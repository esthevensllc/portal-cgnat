param(
    [string]$Version = '0.1.0'
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Artifacts = Join-Path $ProjectRoot 'artifacts'
$Bundle = Join-Path $Artifacts "portal-cgnat-images-$Version.tar"
$RuntimeTag = '0.1.0' # Debe coincidir con deploy/quadlet/portal-*.container.

New-Item -ItemType Directory -Path $Artifacts -Force | Out-Null

docker buildx build `
    --platform linux/amd64 `
    --target development `
    --build-arg PHP_VERSION=8.5.9 `
    --build-arg COMPOSER_VERSION=2.10.2 `
    --build-arg NODE_VERSION=24.18.0 `
    --build-arg PHPREDIS_VERSION=6.3.0 `
    --tag "localhost/portal-cgnat/runtime:$RuntimeTag" `
    --file (Join-Path $ProjectRoot 'containers/app/Containerfile') `
    --load `
    $ProjectRoot

docker pull --platform linux/amd64 nginx:1.30.4
docker pull --platform linux/amd64 redis:8.10.0

docker save --output $Bundle `
    "localhost/portal-cgnat/runtime:$RuntimeTag" `
    nginx:1.30.4 `
    redis:8.10.0

$Hash = (Get-FileHash $Bundle -Algorithm SHA256).Hash.ToLowerInvariant()
$ChecksumLine = "$Hash  $(Split-Path $Bundle -Leaf)`n"
[System.IO.File]::WriteAllText("$Bundle.sha256", $ChecksumLine, [System.Text.Encoding]::ASCII)

Write-Host "Paquete offline: $Bundle"
Write-Host "SHA-256: $Hash"
