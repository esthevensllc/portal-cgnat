#!/usr/bin/env bash
set -Eeuo pipefail

bundle="${1:?Uso: load-images.sh /ruta/portal-cgnat-images-VERSION.tar}"
checksum="${bundle}.sha256"

if [[ ! -f "$bundle" || ! -f "$checksum" ]]; then
    echo "Falta el TAR o su archivo .sha256" >&2
    exit 1
fi

cd "$(dirname "$bundle")"
tr -d '\r' < "$(basename "$checksum")" | sha256sum -c -
podman load -i "$(basename "$bundle")"
podman images --format 'table {{.Repository}}\t{{.Tag}}\t{{.Size}}'
