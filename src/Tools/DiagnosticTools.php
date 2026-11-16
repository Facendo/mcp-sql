<?php

declare(strict_types=1);

namespace McpSql\Tools;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Capability\Attribute\McpTool;

/**
 * Herramientas de diagnostico del propio servidor.
 *
 * No tocan la base de datos: sirven para confirmar que el transporte stdio
 * funciona antes de meter PDO, el tunel SSH y las credenciales en la ecuacion.
 */
final class DiagnosticTools
{
    /**
     * Comprueba que el servidor MCP responde. Devuelve su version y la de PHP.
     *
     * No accede a la base de datos.
     */
    #[McpTool(
        name: 'ping',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
    )]
    public function ping(): array
    {
        return [
            'status'     => 'ok',
            'server'     => 'mcp-sql 0.1.0',
            'php'        => \PHP_VERSION,
            'pdo_mysql'  => \in_array('mysql', \PDO::getAvailableDrivers(), true)
                ? 'activado'
                : 'NO ACTIVADO: anade extension=pdo_mysql a /etc/php/php.ini',
        ];
    }
}
