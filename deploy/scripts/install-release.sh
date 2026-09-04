#!/usr/bin/env bash
set -Eeuo pipefail

root=/index2/portal-cgnat
archive="${1:?Uso: install-release.sh /ruta/release.tar.gz VERSION}"
version="${2:?Debe indicar la versión de la entrega}"
release="$root/releases/$version"
temporary="$root/releases/.${version}.tmp"

[[ -f "$archive" ]] || { echo "No existe $archive" >&2; exit 1; }
[[ -f "${archive}.sha256" ]] || { echo "Falta ${archive}.sha256" >&2; exit 1; }
[[ ! -e "$release" && ! -e "$temporary" ]] || { echo "La versión ya existe" >&2; exit 1; }

cd "$(dirname "$archive")"
tr -d '\r' < "$(basename "${archive}.sha256")" | sha256sum -c -

install -d -m 0755 "$root/releases" "$root/shared/storage" "$root/shared/bootstrap-cache"
install -d -m 0755 "$temporary"
tar -xzf "$archive" -C "$temporary"
mv "$temporary" "$release"
ln -sfn "$release" "$root/current"

chown -R 33:33 "$root/shared/storage" "$root/shared/bootstrap-cache"
chmod -R u+rwX,g+rwX,o-rwx "$root/shared/storage" "$root/shared/bootstrap-cache"

echo "Versión activa: $version"
