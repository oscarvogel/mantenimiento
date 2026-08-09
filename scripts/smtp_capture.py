#!/usr/bin/env python3
"""Minimal one-message SMTP capture server for the local Phase 0A check."""

from __future__ import annotations

import argparse
import socketserver
from pathlib import Path


class ReusableTcpServer(socketserver.TCPServer):
    allow_reuse_address = True

    def __init__(self, server_address: tuple[str, int], output_path: Path):
        self.output_path = output_path
        super().__init__(server_address, SmtpCaptureHandler)


class SmtpCaptureHandler(socketserver.StreamRequestHandler):
    server: ReusableTcpServer

    def handle(self) -> None:
        self._reply("220 localhost Phase0A SMTP capture")
        message_lines: list[bytes] = []
        reading_data = False

        while True:
            line = self.rfile.readline()
            if not line:
                break

            if reading_data:
                if line in {b".\r\n", b".\n"}:
                    self.server.output_path.parent.mkdir(parents=True, exist_ok=True)
                    self.server.output_path.write_bytes(b"".join(message_lines))
                    self._reply("250 2.0.0 captured")
                    reading_data = False
                    continue

                if line.startswith(b".."):
                    line = line[1:]
                message_lines.append(line)
                continue

            command = line.decode("utf-8", errors="replace").strip()
            verb = command.split(" ", 1)[0].upper()

            if verb in {"EHLO", "HELO"}:
                self._reply("250-localhost")
                self._reply("250 SIZE 10485760")
            elif verb in {"MAIL", "RCPT", "RSET", "NOOP"}:
                self._reply("250 2.0.0 OK")
            elif verb == "DATA":
                reading_data = True
                message_lines.clear()
                self._reply("354 End data with <CR><LF>.<CR><LF>")
            elif verb == "QUIT":
                self._reply("221 2.0.0 Bye")
                break
            else:
                self._reply("502 5.5.2 Command not implemented")

    def _reply(self, message: str) -> None:
        self.wfile.write((message + "\r\n").encode("ascii"))
        self.wfile.flush()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=1025)
    parser.add_argument("--output", type=Path, required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    with ReusableTcpServer((args.host, args.port), args.output) as server:
        server.handle_request()
    return 0 if args.output.is_file() else 1


if __name__ == "__main__":
    raise SystemExit(main())
