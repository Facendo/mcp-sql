# mcp-sql

Servidor **MCP (Model Context Protocol)** escrito en PHP que da a Claude acceso
**de solo lectura** a una base de datos MySQL/MariaDB alojada en un VPS, para
generar reportes a partir de peticiones en lenguaje natural.

El servidor corre en tu máquina con transporte **stdio** y llega a la base de
datos a través de un **túnel SSH**, de modo que el puerto de MySQL nunca queda
expuesto a internet.

```
Claude Code / Claude Desktop
   │ stdio (JSON-RPC)
   ▼
servidor MCP PHP  (tu máquina)
   │ PDO mysql → 127.0.0.1:13306
   ▼
túnel SSH  ────────────────────▶  VPS: MySQL en 127.0.0.1:3306
```

> **Solo lectura, sin excepciones.** El servidor no expone ninguna herramienta
> de escritura y se niega a arrancar si el usuario de base de datos configurado
> tiene permisos de escritura. Ver [Modelo de seguridad](#modelo-de-seguridad).

---

## Instalación

### Requisitos

| Requisito | Versión mínima | Comprobar con |
|---|---|---|
| PHP | 8.2 | `php -v` |
| Extensión `pdo_mysql` | — | `php -r 'print_r(PDO::getAvailableDrivers());'` |
| Composer | 2.x | `composer --version` |
| Cliente SSH | — | `ssh -V` |
| Node.js *(opcional, solo para el Inspector)* | 18 | `node -v` |

---

### Paso 1 — Activar la extensión `pdo_mysql`

En muchas distribuciones (Arch, CachyOS, Manjaro…) la extensión viene instalada
pero **desactivada**. Comprueba primero:

```bash
php -r 'print_r(PDO::getAvailableDrivers());'
```

Si la salida es un array vacío o no aparece `mysql`, actívala.

**Opción A — fichero propio en `conf.d` (recomendado, no toca `php.ini`):**

```bash
echo 'extension=pdo_mysql' | sudo tee /etc/php/conf.d/pdo_mysql.ini
```

**Opción B — descomentar en `php.ini`:**

```bash
sudo sed -i 's/^;extension=pdo_mysql/extension=pdo_mysql/' /etc/php/php.ini
```

Verifica que quedó activa:

```bash
php -r 'print_r(PDO::getAvailableDrivers());'
# Debe mostrar:  Array ( [0] => mysql )
```

> En Debian/Ubuntu la ruta suele ser `/etc/php/8.x/cli/php.ini` y el paquete se
> instala con `sudo apt install php-mysql`. En Fedora/RHEL: `sudo dnf install php-mysqlnd`.

---

### Paso 2 — Instalar las dependencias

```bash
git clone <url-del-repo> mcp-sql   # o entra al directorio si ya lo tienes
cd mcp-sql
composer install
```

---

### Paso 3 — Crear el usuario de solo lectura en el VPS

Conéctate al VPS y crea un usuario **dedicado** para el MCP. No reutilices el
usuario de tu aplicación.

```sql
CREATE USER 'mcp_ro'@'127.0.0.1' IDENTIFIED BY 'una-password-larga-y-aleatoria';
GRANT SELECT, SHOW VIEW ON tu_base_de_datos.* TO 'mcp_ro'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Comprueba que quedó como esperas:

```sql
SHOW GRANTS FOR 'mcp_ro'@'127.0.0.1';
```

Solo deben aparecer `USAGE`, `SELECT` y `SHOW VIEW`. Si ves `INSERT`, `UPDATE`,
`DELETE`, `CREATE`, `DROP`, `ALTER`, `FILE` o `ALL PRIVILEGES`, **el servidor se
negará a arrancar** (y hace bien).

> El host `127.0.0.1` es correcto: las conexiones llegan por el túnel SSH, así
> que MySQL las ve como locales.

---

### Paso 4 — Levantar el túnel SSH

El túnel abre un puerto en **tu máquina** que sale por el VPS hasta MySQL:

```bash
ssh -N -L 13306:127.0.0.1:3306 <alias-del-host>
```

| Parte | Significado |
|---|---|
| `-N` | No ejecutes ningún comando remoto; solo reenvía puertos. Sin esto abriría una shell. |
| `-L` | Reenvío **local**: un puerto tuyo que sale por el VPS. |
| `13306` | Puerto que se abre **en tu máquina**. Es el que pondrás en `DB_PORT`. |
| `127.0.0.1:3306` | Destino **visto desde el VPS**. |

> **La clave conceptual:** `127.0.0.1:3306` se resuelve *dentro del VPS*, no en tu
> máquina. Por eso MySQL ve la conexión como local, y por eso el `GRANT` del
> Paso 3 lleva `'mcp_ro'@'127.0.0.1'`.

> **Por qué 13306 y no 3306.** Si tienes MySQL instalado localmente, el 3306 ya
> está ocupado: el túnel fallaría con *"Address already in use"* o —peor— creerías
> estar consultando el VPS mientras hablas con tu base local. Comprueba antes:
> ```bash
> ss -ltn | grep -E ':(3306|13306)\b'
> ```

---

#### 4.1 — Revisa tus claves SSH

**Permisos.** SSH rechaza claves privadas legibles por otros usuarios. Revisa y
corrige:

```bash
ls -l ~/.ssh/ | grep -vE '\.pub$|known_hosts|config'
chmod 600 ~/.ssh/<tu-clave>          # ninguna clave privada debe ser 644
```

Una clave privada en `644` es legible por **cualquier usuario de la máquina**.
Debe estar en `600` (o `400`).

**Passphrase.** Comprueba si tu clave tiene una:

```bash
ssh-keygen -y -P "" -f ~/.ssh/<tu-clave> >/dev/null 2>&1 \
  && echo "SIN passphrase" || echo "CON passphrase"
```

Esto importa para el paso 4.3: **un servicio systemd desatendido no puede
escribir una passphrase**. Si tu clave tiene una —y debería tenerla—, no la
quites: genera una clave dedicada y restringida, como se explica más abajo.

**Simplifica con `~/.ssh/config`.** Si defines el host ahí, no necesitas repetir
usuario, puerto ni clave en cada comando:

```
Host mi-vps
    HostName 203.0.113.10
    Port 4460
    User miusuario
    IdentityFile ~/.ssh/mi_clave
```

Con eso, el túnel es simplemente `ssh -N -L 13306:127.0.0.1:3306 mi-vps`.

---

#### 4.2 — Prueba el túnel a mano

Antes de automatizar nada, confirma que funciona. En una terminal:

```bash
ssh -N -L 13306:127.0.0.1:3306 <alias-del-host>
```

En otra:

```bash
ss -ltn | grep 13306                         # el puerto debe estar escuchando
mysql -h 127.0.0.1 -P 13306 -u mcp_ro -p     # debe entrar a la base del VPS
```

Si esto funciona, ya puedes usar el servidor MCP. Lo que sigue solo automatiza
el túnel para no tener que acordarte de levantarlo.

---

#### 4.3 — Clave dedicada para el túnel automático

Un servicio desatendido necesita una clave **sin passphrase**. Y una clave sin
passphrase solo es aceptable si está **restringida** para que no sirva para nada
más que este túnel.

**Genera la clave:**

```bash
ssh-keygen -t ed25519 -f ~/.ssh/mcp_sql_tunnel -N "" \
           -C "mcp-sql tunnel: solo port-forward a 127.0.0.1:3306"
chmod 600 ~/.ssh/mcp_sql_tunnel
```

**Instálala en el VPS con restricciones:**

```bash
KEY=$(cat ~/.ssh/mcp_sql_tunnel.pub)
ssh <alias-del-host> "mkdir -p ~/.ssh && chmod 700 ~/.ssh && \
  echo 'restrict,port-forwarding,permitopen=\"127.0.0.1:3306\" $KEY' >> ~/.ssh/authorized_keys && \
  chmod 600 ~/.ssh/authorized_keys && echo INSTALADA"
```

Qué hace cada restricción:

| Opción | Efecto |
|---|---|
| `restrict` | Desactiva **todo**: shell interactiva, agent forwarding, X11, reenvío de puertos. |
| `port-forwarding` | Reactiva únicamente el reenvío de puertos. |
| `permitopen="127.0.0.1:3306"` | Limita ese reenvío a MySQL y nada más. |

Resultado: aunque alguien se hiciera con esa clave sin passphrase, no obtendría
shell en el servidor ni podría alcanzar ningún otro servicio. Solo MySQL, que a
su vez solo acepta `SELECT`. Esto es **mejor** que reutilizar tu clave personal
con un `ssh-agent`: el alcance de la clave es el propio límite.

---

#### 4.4 — Servicio systemd de usuario

Crea `~/.config/systemd/user/mcp-sql-tunnel.service`:

```ini
[Unit]
Description=Túnel SSH para mcp-sql
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/ssh -N \
    -o BatchMode=yes \
    -o ExitOnForwardFailure=yes \
    -o ServerAliveInterval=30 \
    -o ServerAliveCountMax=3 \
    -o StrictHostKeyChecking=accept-new \
    -i %h/.ssh/mcp_sql_tunnel \
    -p <puerto-ssh> \
    -L 13306:127.0.0.1:3306 \
    <usuario>@<host>

# Si el túnel cae (red, reinicio del VPS, timeout), systemd lo relevanta.
Restart=always
RestartSec=10

# Endurecimiento: el proceso no necesita nada más que la red y su clave.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=read-only
ReadOnlyPaths=%h/.ssh

[Install]
WantedBy=default.target
```

Dos opciones que merecen explicación:

- **`ExitOnForwardFailure=yes`** — si el 13306 estuviera ocupado, SSH termina en
  vez de quedarse conectado **sin** el túnel. Ese fallo silencioso es muy difícil
  de diagnosticar después. Combinado con `Restart=always`, systemd reintenta.
- **`ServerAliveInterval=30` + `ServerAliveCountMax=3`** — detecta una conexión
  muerta en ~90 s en vez de quedarse colgada indefinidamente.

**Arráncalo:**

```bash
systemctl --user daemon-reload
systemctl --user enable --now mcp-sql-tunnel.service
systemctl --user status mcp-sql-tunnel.service
journalctl --user -u mcp-sql-tunnel -f      # seguir los logs en vivo
```

Si quieres que siga corriendo aunque cierres sesión:

```bash
sudo loginctl enable-linger $USER
```

---

#### 4.5 — Verifica

```bash
ss -ltn | grep 13306                         # el túnel escucha
mysql -h 127.0.0.1 -P 13306 -u mcp_ro -p     # la base responde
```

Comandos útiles para el día a día:

```bash
systemctl --user restart mcp-sql-tunnel      # reiniciar el túnel
systemctl --user stop mcp-sql-tunnel         # bajarlo
journalctl --user -u mcp-sql-tunnel -n 50    # ver qué pasó
```


---

### Paso 5 — Configurar el `.env`

```bash
cp .env.example .env
chmod 600 .env        # importante: por defecto nace 644 y lo lee cualquier usuario
```

Edita el `.env` y completa **las cinco variables obligatorias**:

```bash
DB_HOST=127.0.0.1     # extremo local del túnel, no el VPS
DB_PORT=13306
DB_NAME=tu_base_de_datos
DB_USER=mcp_ro
DB_PASSWORD=una-password-larga-y-aleatoria
```

Ninguna de esas cinco tiene valor por defecto, a propósito: un fallback
silencioso a `root` o a la base de datos equivocada es justo el fallo que
queremos evitar. Si falta alguna, el servidor muere al arrancar nombrándola.

El resto de variables tienen valores por defecto razonables; consulta
[Configuración](#configuración) para ajustarlas.

---

### Paso 6 — Comprobar que el servidor arranca

```bash
./bin/smoke-test.sh
```

Deberías ver tres respuestas JSON-RPC: el `initialize`, la lista de
herramientas y el resultado de `ping`. Si `ping` reporta
`"pdo_mysql": "activado"`, el Paso 1 quedó bien.

También puedes usar el inspector oficial, con interfaz web:

```bash
npx @modelcontextprotocol/inspector php bin/mcp-sql
```

---

### Paso 7 — Registrar el servidor en tu cliente

**Claude Code** — crea `.mcp.json` en la raíz del proyecto donde quieras usarlo:

```json
{
  "mcpServers": {
    "mcp-sql": {
      "command": "php",
      "args": ["/ruta/absoluta/a/mcp-sql/bin/mcp-sql"]
    }
  }
}
```

O con un solo comando:

```bash
claude mcp add mcp-sql -- php /ruta/absoluta/a/mcp-sql/bin/mcp-sql
```

**Claude Desktop** — edita `claude_desktop_config.json`
(Linux: `~/.config/Claude/`, macOS: `~/Library/Application Support/Claude/`):

```json
{
  "mcpServers": {
    "mcp-sql": {
      "command": "php",
      "args": ["/ruta/absoluta/a/mcp-sql/bin/mcp-sql"]
    }
  }
}
```

Reinicia Claude Desktop después de editarlo.

> **Usa siempre rutas absolutas.** El cliente MCP no hereda tu directorio de trabajo.

<details>
<summary>Alternativa: credenciales fuera del .env</summary>

Si prefieres no tener un archivo `.env`, pásalas por el bloque `env` de la
configuración del cliente. Las variables de entorno reales tienen prioridad
sobre el `.env`:

```json
{
  "mcpServers": {
    "mcp-sql": {
      "command": "php",
      "args": ["/ruta/absoluta/a/mcp-sql/bin/mcp-sql"],
      "env": {
        "DB_NAME": "tu_base_de_datos",
        "DB_USER": "mcp_ro",
        "DB_PASSWORD": "una-password-larga-y-aleatoria"
      }
    }
  }
}
```
</details>

---

## Uso

Con el túnel levantado y el servidor registrado, pídele a Claude lo que
necesites en lenguaje natural:

> «¿Qué tablas hay en la base de datos?»
>
> «Dame las ventas por mes del último año, agrupadas por región.»
>
> «Describe la tabla de pedidos y dime cómo se relaciona con clientes.»

Claude usará las herramientas para explorar el esquema y consultar los datos, y
armará el reporte a partir de lo que encuentre.

---

## Herramientas disponibles

Todas son de solo lectura y están marcadas con `readOnlyHint: true`.

| Herramienta | Qué hace | Estado |
|---|---|---|
| `ping` | Diagnóstico del servidor: versión y estado de `pdo_mysql`. No toca la base de datos. | ✅ |
| `list_tables` | Tablas y vistas del esquema: nombre, tipo, filas aproximadas, comentario. | 🚧 |
| `describe_table` | Columnas (tipo, nullable, default, comentario), clave primaria, índices y claves foráneas. | 🚧 |
| `run_query` | Ejecuta un `SELECT` / `WITH` y devuelve las filas en formato tabular. | 🚧 |
| `sample_rows` | Primeras N filas de una tabla, sin tener que escribir SQL. | 🚧 |
| `explain_query` | `EXPLAIN` de una consulta sin ejecutarla, para revisar su coste antes de lanzarla. | 🚧 |

> ✅ implementada · 🚧 pendiente. El resto de este documento describe el
> comportamiento objetivo del servidor; los pasos de instalación ya son válidos
> tal cual.

---

## Modelo de seguridad

El servidor aplica **cinco capas**, pero conviene tener clara la jerarquía:

> La única capa que **garantiza** el solo-lectura es el `GRANT` de MySQL.
> Las demás son detección temprana, mensajes claros y conveniencia.
> No construyas confianza sobre el validador de PHP.

**1. Usuario MySQL sin permisos de escritura** ← *la frontera real*
Solo `SELECT` y `SHOW VIEW` (Paso 3). Aunque todo lo demás fallara, MySQL
rechaza cualquier escritura.

**2. Verificación de `GRANTS` al arrancar**
Con `ENFORCE_READ_ONLY_USER=true`, lo primero que hace el servidor tras conectar
es ejecutar `SHOW GRANTS FOR CURRENT_USER()`. Si encuentra `INSERT`, `UPDATE`,
`DELETE`, `DROP`, `ALTER`, `CREATE`, `TRUNCATE`, `FILE`, `GRANT OPTION` o
`ALL PRIVILEGES`, **aborta el arranque** con código de salida distinto de cero y
un mensaje que indica el grant ofensivo.
Esto convierte la intención en algo verificable: si algún día apuntas el `.env`
al usuario de tu aplicación por error, el servidor simplemente no arranca.

**3. Sesión de solo lectura**
Al conectar: `SET SESSION TRANSACTION READ ONLY` y
`SET SESSION max_execution_time = <QUERY_TIMEOUT_MS>` (`max_statement_time` en
MariaDB). Ese timeout solo interrumpe `SELECT`, que es exactamente lo que hay.

**4. Validador `SqlGuard` en PHP**
Rechaza: más de una sentencia (`;` fuera de literales); cualquier consulta que no
empiece por `SELECT` o `WITH`; los tokens `INSERT`, `UPDATE`, `DELETE`, `DROP`,
`ALTER`, `CREATE`, `TRUNCATE`, `GRANT`, `REPLACE`, `CALL`, `SET`, `LOCK`,
`FLUSH`, `HANDLER`; y `INTO OUTFILE`, `INTO DUMPFILE`, `LOAD_FILE`.

**5. PDO endurecido**
`ATTR_EMULATE_PREPARES => false`, `MYSQL_ATTR_MULTI_STATEMENTS => false`,
`ATTR_ERRMODE => EXCEPTION`.

### Límites de volumen

Protegen el contexto de Claude, no la base de datos: corte en `MAX_ROWS` leyendo
fila a fila, truncado de celdas a `MAX_CELL_CHARS` y presupuesto total de
`MAX_RESPONSE_BYTES` por respuesta.

---

## Configuración

Todas las variables viven en `.env` (ver `.env.example`).

| Variable | Por defecto | Descripción |
|---|---|---|
| `DB_HOST` | — *(obligatoria)* | Extremo local del túnel, normalmente `127.0.0.1`. |
| `DB_PORT` | — *(obligatoria)* | Puerto local del túnel, normalmente `13306`. |
| `DB_NAME` | — *(obligatoria)* | Base de datos a consultar. |
| `DB_USER` | — *(obligatoria)* | Usuario de solo lectura. |
| `DB_PASSWORD` | — *(obligatoria)* | Su contraseña. |
| `DB_CHARSET` | `utf8mb4` | Juego de caracteres de la conexión. |
| `MAX_ROWS` | `200` | Tope duro de filas por consulta. |
| `MAX_CELL_CHARS` | `500` | Truncado de celdas `TEXT`/`BLOB`. |
| `MAX_RESPONSE_BYTES` | `100000` | Presupuesto total por respuesta. |
| `QUERY_TIMEOUT_MS` | `15000` | `max_execution_time` de la sesión MySQL. |
| `ENFORCE_READ_ONLY_USER` | `true` | Verificar los `GRANTS` al arrancar. |

---

## Desarrollo

### Estructura

```
mcp-sql/
├── bin/
│   ├── mcp-sql                 # entrypoint stdio
│   └── smoke-test.sh           # prueba rápida sin Inspector
├── src/
│   ├── Config.php              # carga y valida el .env
│   ├── Db/Connection.php       # PDO perezoso, reconexión, check de GRANTS
│   ├── Db/Introspector.php     # consultas a information_schema
│   ├── Security/SqlGuard.php   # validación de solo-lectura
│   ├── Format/ResultFormatter.php
│   └── Tools/                  # las herramientas con #[McpTool]
├── tests/
├── .env.example
└── composer.json
```

### Tests

```bash
./vendor/bin/phpunit
```

### ⚠️ La regla de oro del transporte stdio

> **STDOUT pertenece exclusivamente al protocolo JSON-RPC.**

Un solo `echo`, `var_dump`, `print_r` o warning de PHP escrito en STDOUT corrompe
el flujo y el cliente MCP desconecta **sin dar ninguna explicación**. Es la causa
número uno de servidores MCP en PHP que «no funcionan y no se sabe por qué».

Por eso `bin/mcp-sql` arranca con:

```php
ini_set('display_errors', 'stderr');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../mcp-sql.log');
```

Para depurar, usa `fwrite(STDERR, "...")` o el log en `mcp-sql.log`. **Nunca `echo`.**

---

## Resolución de problemas

| Síntoma | Causa probable | Solución |
|---|---|---|
| `ping` dice `pdo_mysql: NO ACTIVADO` | Extensión desactivada | Paso 1 |
| `could not find driver` | Ídem | Paso 1 |
| `Connection refused` en 127.0.0.1:13306 | El túnel SSH no está levantado | Paso 4 |
| `Permission denied (publickey)` | Clave con passphrase sin agente, o no autorizada en el VPS | Pasos 4.1 y 4.3 |
| `UNPROTECTED PRIVATE KEY FILE` | La clave privada tiene permisos demasiado abiertos | `chmod 600 ~/.ssh/<clave>` (Paso 4.1) |
| `Address already in use` al abrir el túnel | El puerto 13306 ya está ocupado | `ss -ltn \| grep 13306` y cierra el túnel anterior |
| El servicio systemd reinicia en bucle | La clave tiene passphrase y `BatchMode=yes` no puede escribirla | Usa la clave dedicada del Paso 4.3 |
| El túnel muere al cerrar sesión | Falta *linger* en systemd | `sudo loginctl enable-linger $USER` |
| `administratively prohibited: open failed` | Falta `permitopen` o `port-forwarding` en `authorized_keys` | Revisa el Paso 4.3 |
| `Faltan variables en .env: ...` | `.env` incompleto o inexistente | Paso 5 |
| `DB_USER tiene permisos de escritura` | El usuario no es de solo lectura | Paso 3 |
| El cliente no lista ninguna herramienta | Ruta relativa en la configuración | Usa rutas absolutas (Paso 7) |
| El cliente desconecta sin error | Algo escribió en STDOUT | Revisa `mcp-sql.log` y busca `echo`/`var_dump` |
| Las consultas tardan y se cortan | `QUERY_TIMEOUT_MS` | Súbelo, o revisa la consulta con `explain_query` |

Los errores del servidor se registran en `mcp-sql.log` en la raíz del proyecto.
Los del túnel, en el journal: `journalctl --user -u mcp-sql-tunnel -n 50`.

Para depurar la conexión SSH en detalle, añade `-v` (o `-vvv`) al comando del túnel:

```bash
ssh -v -N -L 13306:127.0.0.1:3306 <alias-del-host>
```

---

## Stack

- [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk) — SDK oficial de MCP para PHP.
  **Experimental hasta su versión 1.0**: la versión está fijada a `0.10.*` en
  `composer.json`. Revisa el changelog antes de subirla.
- [`vlucas/phpdotenv`](https://github.com/vlucas/phpdotenv) — carga del `.env`.
- `symfony/finder` — requerido por el SDK para el descubrimiento por atributos.

## Licencia

Propietaria.
