#!/usr/bin/env python3
"""Operaciones FTPS reproducibles para el deploy plano de Ferozo.

Las credenciales se leen desde un INI local ignorado por Git. El script nunca
imprime usuario, password ni contenido de archivos sensibles.
"""

from __future__ import annotations

import argparse
import configparser
import ftplib
import os
import pathlib
import ssl
import sys
from dataclasses import dataclass


@dataclass(frozen=True)
class Stats:
    files: int = 0
    directories: int = 0
    bytes: int = 0

    def add(self, *, files: int = 0, directories: int = 0, bytes_count: int = 0) -> "Stats":
        return Stats(self.files + files, self.directories + directories, self.bytes + bytes_count)


def load_credentials(path: pathlib.Path) -> tuple[str, int, str, str]:
    parser = configparser.ConfigParser(interpolation=None)
    with path.open("r", encoding="utf-8-sig") as handle:
        parser.read_file(handle)

    if "ftp" not in parser:
        raise RuntimeError("El archivo de credenciales no contiene la seccion [ftp].")

    section = parser["ftp"]
    host = section.get("host", "").strip()
    user = section.get("user", "").strip()
    password = section.get("password", "").strip()
    port = section.getint("port", fallback=21)
    if not host or not user or not password:
        raise RuntimeError("Las credenciales FTPS estan incompletas.")

    return host, port, user, password


def connect(credentials: pathlib.Path) -> ftplib.FTP_TLS:
    host, port, user, password = load_credentials(credentials)
    client = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=45)
    client.connect(host, port)
    client.auth()
    client.login(user, password)
    client.prot_p()
    client.set_pasv(True)
    return client


def entries(client: ftplib.FTP_TLS, remote: str) -> list[tuple[str, dict[str, str]]]:
    result: list[tuple[str, dict[str, str]]] = []
    for name, facts in client.mlsd(remote, facts=["type", "size"]):
        if name not in {".", ".."}:
            result.append((name, facts))
    return result


def inventory(client: ftplib.FTP_TLS, remote: str = "/") -> Stats:
    stats = Stats()
    for name, facts in entries(client, remote):
        child = remote.rstrip("/") + "/" + name
        kind = facts.get("type", "")
        if kind == "dir":
            stats = stats.add(directories=1)
            stats = merge(stats, inventory(client, child))
        elif kind == "file":
            stats = stats.add(files=1, bytes_count=int(facts.get("size", "0") or 0))
    return stats


def merge(left: Stats, right: Stats) -> Stats:
    return Stats(left.files + right.files, left.directories + right.directories, left.bytes + right.bytes)


def backup(client: ftplib.FTP_TLS, remote: str, local: pathlib.Path) -> Stats:
    local.mkdir(parents=True, exist_ok=True)
    stats = Stats()
    for name, facts in entries(client, remote):
        child_remote = remote.rstrip("/") + "/" + name
        child_local = local / name
        kind = facts.get("type", "")
        if kind == "dir":
            stats = stats.add(directories=1)
            stats = merge(stats, backup(client, child_remote, child_local))
        elif kind == "file":
            with child_local.open("wb") as handle:
                client.retrbinary(f"RETR {child_remote}", handle.write)
            stats = stats.add(files=1, bytes_count=child_local.stat().st_size)
    return stats


def backup_resilient(
    credentials: pathlib.Path,
    remote: str,
    local: pathlib.Path,
    reconnect_every: int = 40,
) -> Stats:
    """Descarga un arbol reanudando cada listado o archivo ante cortes FTPS."""
    local.mkdir(parents=True, exist_ok=True)
    pending: list[tuple[str, pathlib.Path]] = [(remote, local)]
    stats = Stats()
    files_downloaded = 0
    client = connect(credentials)

    def reconnect(current: ftplib.FTP_TLS) -> ftplib.FTP_TLS:
        try:
            current.close()
        finally:
            return connect(credentials)

    try:
        while pending:
            current_remote, current_local = pending.pop()
            current_local.mkdir(parents=True, exist_ok=True)
            listed: list[tuple[str, dict[str, str]]] | None = None
            last_error: Exception | None = None
            for _attempt in range(3):
                try:
                    listed = entries(client, current_remote)
                    last_error = None
                    break
                except (OSError, EOFError, ftplib.Error) as error:
                    last_error = error
                    client = reconnect(client)
            if listed is None:
                if last_error is not None:
                    raise last_error
                raise RuntimeError('No se pudo listar el directorio remoto.')

            for name, facts in listed:
                child_remote = current_remote.rstrip('/') + '/' + name
                child_local = current_local / name
                kind = facts.get('type', '')
                if kind == 'dir':
                    pending.append((child_remote, child_local))
                    stats = stats.add(directories=1)
                    continue
                if kind != 'file':
                    continue

                if files_downloaded > 0 and files_downloaded % reconnect_every == 0:
                    client = reconnect(client)
                last_error = None
                for _attempt in range(3):
                    try:
                        temporary = child_local.with_name(child_local.name + '.part')
                        with temporary.open('wb') as handle:
                            client.retrbinary(f'RETR {child_remote}', handle.write)
                        temporary.replace(child_local)
                        last_error = None
                        break
                    except (OSError, EOFError, ftplib.Error) as error:
                        last_error = error
                        client = reconnect(client)
                if last_error is not None:
                    raise last_error

                files_downloaded += 1
                stats = stats.add(files=1, bytes_count=child_local.stat().st_size)
                if files_downloaded % 100 == 0:
                    print(f'BACKUP_PROGRESS files={files_downloaded}', flush=True)
    finally:
        try:
            client.quit()
        except (OSError, ftplib.Error):
            client.close()

    return stats


def ensure_remote_directory(client: ftplib.FTP_TLS, remote: str) -> None:
    current = ""
    for part in remote.strip("/").split("/"):
        if not part:
            continue
        current += "/" + part
        try:
            client.mkd(current)
        except ftplib.error_perm as error:
            if not str(error).startswith("550"):
                raise


def upload(client: ftplib.FTP_TLS, local: pathlib.Path, remote: str = "/") -> Stats:
    stats = Stats()
    for child in sorted(local.iterdir(), key=lambda item: (item.is_file(), item.name.lower())):
        remote_child = remote.rstrip("/") + "/" + child.name
        if child.is_dir():
            ensure_remote_directory(client, remote_child)
            stats = stats.add(directories=1)
            stats = merge(stats, upload(client, child, remote_child))
        elif child.is_file():
            with child.open("rb") as handle:
                client.storbinary(f"STOR {remote_child}", handle, blocksize=256 * 1024)
            stats = stats.add(files=1, bytes_count=child.stat().st_size)
    return stats


def upload_resilient(
    credentials: pathlib.Path,
    local: pathlib.Path,
    remote: str = "/",
    reconnect_every: int = 60,
) -> Stats:
    """Sube un arbol reanudable ante los timeouts cortos del FTP de Ferozo."""
    directories = sorted(
        (item for item in local.rglob("*") if item.is_dir()),
        key=lambda item: (len(item.parts), item.as_posix().lower()),
    )
    files = sorted(
        (item for item in local.rglob("*") if item.is_file()),
        key=lambda item: item.as_posix().lower(),
    )
    stats = Stats(directories=len(directories))
    client = connect(credentials)

    def remote_path(item: pathlib.Path) -> str:
        relative = item.relative_to(local).as_posix()
        return remote.rstrip("/") + "/" + relative

    try:
        ensure_remote_directory(client, remote)
        for index, directory in enumerate(directories, start=1):
            if index > 1 and (index - 1) % 40 == 0:
                try:
                    client.quit()
                except (OSError, ftplib.Error):
                    client.close()
                client = connect(credentials)
            try:
                ensure_remote_directory(client, remote_path(directory))
            except (OSError, EOFError, ftplib.Error):
                client.close()
                client = connect(credentials)
                ensure_remote_directory(client, remote_path(directory))
            if index % 50 == 0 or index == len(directories):
                print(f"UPLOAD_PROGRESS dirs={index}/{len(directories)}", flush=True)

        for index, child in enumerate(files, start=1):
            if index > 1 and (index - 1) % reconnect_every == 0:
                try:
                    client.quit()
                except (OSError, ftplib.Error):
                    client.close()
                client = connect(credentials)

            destination = remote_path(child)
            last_error: Exception | None = None
            for _attempt in range(3):
                try:
                    with child.open("rb") as handle:
                        client.storbinary(f"STOR {destination}", handle, blocksize=256 * 1024)
                    last_error = None
                    break
                except (OSError, EOFError, ftplib.Error) as error:
                    last_error = error
                    try:
                        client.close()
                    finally:
                        client = connect(credentials)
            if last_error is not None:
                raise last_error

            stats = stats.add(files=1, bytes_count=child.stat().st_size)
            if index % 100 == 0 or index == len(files):
                print(f"UPLOAD_PROGRESS files={index}/{len(files)}", flush=True)
    finally:
        try:
            client.quit()
        except (OSError, ftplib.Error):
            client.close()

    return stats


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=["inventory", "backup", "upload"])
    parser.add_argument("--credentials", default="ferozo-credentials")
    parser.add_argument("--local")
    parser.add_argument("--remote", default="/")
    args = parser.parse_args()

    credentials = pathlib.Path(args.credentials).resolve()
    if not credentials.is_file():
        raise RuntimeError("No se encontro el archivo local de credenciales.")

    if args.operation == "upload":
        if not args.local:
            raise RuntimeError("La operacion requiere --local.")
        local = pathlib.Path(args.local).resolve()
        if not local.is_dir():
            raise RuntimeError("El directorio local de release no existe.")
        stats = upload_resilient(credentials, local, args.remote)
    else:
        client = connect(credentials)
        try:
            if args.operation == "inventory":
                stats = inventory(client, args.remote)
            else:
                if not args.local:
                    raise RuntimeError("La operacion requiere --local.")
                local = pathlib.Path(args.local).resolve()
                if local.exists() and any(local.iterdir()):
                    raise RuntimeError("El directorio de backup debe estar vacio.")
                try:
                    client.quit()
                except (OSError, ftplib.Error):
                    client.close()
                stats = backup_resilient(credentials, args.remote, local)
                client = None
        finally:
            if client is not None:
                try:
                    client.quit()
                except (OSError, ftplib.Error):
                    client.close()

    print(f"{args.operation.upper()}_OK files={stats.files} dirs={stats.directories} bytes={stats.bytes}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(f"ERROR: {type(error).__name__}: {error!r}", file=sys.stderr)
        raise SystemExit(1)
