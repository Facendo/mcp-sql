#!/usr/bin/env bash
# Smoke test del servidor MCP sin necesidad del Inspector.
# Uso:  ./bin/smoke-test.sh  [nombre_tool]  ['{"arg":"valor"}']
set -euo pipefail
cd "$(dirname "$0")/.."

TOOL="${1:-ping}"
ARGS="${2:-{\}}"

printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2026-07-28","capabilities":{},"clientInfo":{"name":"smoke-test","version":"1.0"}}}' \
  '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"${TOOL}\",\"arguments\":${ARGS}}}" \
  | timeout 30 php bin/mcp-sql
