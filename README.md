# =============================================================================
# fel-dentall — API RESTful base en PHP 7.4 (Vanilla, POO)
# =============================================================================
---

## 1. Requisitos  

| Componente | Versión mínima | Verificado en |
|---|---|---|
| PHP | 7.4 | 7.4.33 (Apache) y 8.2.12 (CLI) |
| Extensiones | `soap`, `pdo_mysql`, `mbstring`, `json` | `soap` + `pdo_mysql` + `mbstring` |
| MySQL / MariaDB | 5.7 / 10.3 | MariaDB 10.4.8 |
| Apache | 2.4 con `mod_rewrite` y `AllowOverride All` | 2.4.66 |

No requiere Composer, npm ni ninguna otra dependencia.

> **`soap` no es negociable.** Sin ella la API sirve `/api/items` con normalidad
> y falla solo al facturar, con un error de clase inexistente que no apunta al
> módulo que falta. Compruébelo en el servidor antes de desplegar nada:
> `php -m | grep soap`, o desde cPanel en *Select PHP Version → Extensions*.

---

## 2. Instalación

### 2.1. Importar el esquema

```bash
mysql\bin\mysql.exe -u root < sql\schema.sql
```

Desde phpMyAdmin: **Importar** → seleccionar `sql/schema.sql` → **Continuar**.

### 2.2. Configurar el DocumentRoot

Hay tres configuraciones posibles. La aplicación funciona en las tres porque
`App\Core\Request` detecta y descarta el prefijo de instalación automáticamente.

| DocumentRoot | URL resultante | `.htaccess` necesario |
|---|---|---|
| `...\fel-dentall\public` | `http://127.0.0.1/api/items` | `public/.htaccess` |
| `...\fel-dentall` | `http://127.0.0.1/api/items` | `.htaccess` (raíz) |

Para el caso de XAMPP no hay que tocar `httpd.conf`:
`AllowOverride All` ya está activo y ambos `.htaccess` están en el sitio.

### 2.3. Variables de entorno (opcional)

En `local` todo funciona sin configurar nada. Para un servidor desplegado
(`desarrollo` o `produccion`) las credenciales pueden fijarse por entorno:

```bash
set APP_ENV=desarrollo
set DB_HOST=127.0.0.1
set DB_PORT=3306
set DB_NAME=api_fel
set DB_USER=usuario_app
set DB_PASS=clave_secreta
set API_USER=usuario_api
set API_PASS=clave_api
set CORS_ALLOWED_ORIGINS=https://app.ejemplo.com,https://admin.ejemplo.com
```

La precedencia es: **variable de entorno** > `config/config.local.php` >
`config/config.php` > valor por defecto de la llamada.

### 2.4. Alternativa a las variables de entorno: `config.local.php`

En un hosting compartido no siempre es posible fijar variables de entorno de
forma fiable, o el `SetEnv` del `.htaccess` no llega al proceso de PHP según el
handler. Para eso está `config/config.local.php`, excluido por `.gitignore`:

```bash
copy config\config.local.example.php config\config.local.php
```

Acepta las secciones `fel`, `db`, `auth`, `cors` y `billing`, y se fusionan
clave por clave: basta declarar lo que cambia y el resto conserva su valor por
defecto.

**Una cadena vacía no sobreescribe nada.** La plantilla se distribuye con todos
sus campos en blanco y esa es la forma natural de usarla, así que un campo sin
llenar deja el valor del entorno activo intacto. Si una cadena vacía contara como
sobreescritura, copiar la plantilla sin rellenar dejaría `wsdl_url` en `''` y el
cliente SOAP caería a su propio valor por defecto: en `produccion` eso
convertiría una emisión real en una de pruebas, sin ningún síntoma visible.

Un array vacío sí cuenta como valor: `cors.allowed_origins => []` significa "no
permitir ningún origen", que es una decisión, no una omisión.

### 2.5. Despliegue en hosting compartido (cPanel)

El orden importa: un paso que falla detiene el siguiente.

| # | Paso | Dónde | Nota |
|---|---|---|---|
| 1 | Verificar `soap`, `pdo_mysql`, `mbstring` | *Select PHP Version → Extensions* | Bloqueante: sin `soap` no hay facturación |
| 2 | Obtener el código | *Git Version Control → Clone* | Alternativa sin git: ZIP → File Manager |
| 3 | Apuntar el subdominio a `…/public` | *Domains → Subdomains* | **El document root debe ser `public/`** |
| 4 | Activar SSL | *SSL/TLS Status* | Sin HTTPS el Basic Auth viaja en claro |
| 5 | Importar el esquema | phpMyAdmin → Importar | Ver la nota sobre el nombre de la base |
| 6 | Crear `config/config.local.php` | File Manager | Nunca desde el repositorio |
| 7 | Comprobar | `curl -i -u usuario:clave https://…/api/items` | Un 404 aquí es `mod_rewrite` |

El document root debe ser `public/` y no la raíz del proyecto. Así `app/`,
`config/`, `sql/` y `tests/` quedan **fuera del servidor web**: el
`.htaccess` de la raíz ni siquiera se evalúa y `config.local.php` no es
accesible por HTTP. Si además se dejara el document root en la raíz, ese
`.htaccess` pasa a ser la única barrera y dependería de que `AllowOverride`
esté activo en toda la ruta.

El nombre de la base de datos **no tiene por qué ser `fel_dentall_api`**, y sí lo
es en la mayoría de los hostings, pero conviene confirmarlo:

- Si la base se llama `fel_dentall_api`, los cinco `.sql` se importan **sin tocar
  nada**: `schema.sql` y las cuatro migraciones ya llevan ese nombre escrito.
- Si se llama de otra forma, sustituya `fel_dentall_api` en los cinco archivos y
  comenta el `CREATE DATABASE` de `sql/schema.sql` (líneas 25-29), porque en un
  hosting compartido casi nunca hay permiso para crear bases y un `USE` a una
  base inexistente aborta la importación.
- El nombre **también** va en la configuración: sección `db.database`. No basta
  con cambiarlo en el SQL.

Los cinco archivos son idempotentes, de modo que reimportarlos no rompe nada.
Al terminar deben existir `items`, `gf_orders` y `schema_migrations`.

### 2.6. Fijar `APP_ENV` en el servidor

Sin esto, un servidor desplegado arranca en `local`: muestra el detalle técnico
de los errores y trae `root` sin contraseña y `admin`/`admin123` por defecto.
Tres formas, de la más fiable a la menos fiable:

**1. En el panel de cPanel (recomendada).** *Select PHP Version → Options →
Environment Variables*:

```
APP_ENV = desarrollo
```

Escribe en el pool de PHP, así que `getenv()` la ve siempre. No toca ningún
archivo del repositorio y sobrevive a un `git pull` o a un redeploy.

**2. `SetEnv` en `public/.htaccess`, solo en el servidor.** Descomente el bloque
que hay al principio de ese archivo:

```apache
<IfModule mod_env.c>
    SetEnv APP_ENV desarrollo
</IfModule>
```

Apache expone lo que fija con `SetEnv` en `$_SERVER`, y `App\Core\Env` lo lee
desde ahí, de modo que **funciona incluso con PHP como FPM o LSAPI**, donde
`getenv()` no lo vería. Es justo el caso que fallaba en silencio antes de que
`config/config.php` dejara de leer `getenv()` directamente: `config.php` no veía
el valor, resolvía `local`, y la API arrancaba con las credenciales por defecto
de `local` y los errores a la vista, sin dar ningún aviso.

El precio de esta opción es que un redeploy sobrescribe el archivo. Por eso está
comentada en el repositorio y no activa: si lo estuviera, el XAMPP local dejaría
de mostrar errores, que es justo lo que se quiere en un escritorio.

**3. Variables por entorno**, mismo mecanismo que 1:

```
DB_HOST=localhost
DB_NAME=api_fel
DB_USER=usuario_app
DB_PASS=clave_secreta
API_USER=usuario_api
API_PASS=clave_api
```

**Compruebe que funcionó.** No sirve mirar el cuerpo de un `400` de validación:
es idéntico en los dos entornos. Lo que sí distingue `local` de `desarrollo` es la
lista blanca de CORS, que en `local` incluye `http://localhost:5173` y en
`desarrollo` está vacía, y `Response` solo emite `Access-Control-Allow-Origin`
para orígenes que estén en ella:

```bash
curl -i -u USUARIO:CLAVE -H "Origin: http://localhost:5173" \
     https://fel.dentallgt.com/api/items
```

- **No aparece** `Access-Control-Allow-Origin` → el entorno es `desarrollo`. Es lo
  correcto: no se permite ningún origen.
- **Aparece** con ese valor → el entorno sigue siendo `local` y la variable no
  llegó. Revise que `mod_env` esté disponible y que la instrucción esté
  descomentada.

---

## 3. Entornos

Definidos por `APP_ENV`, que reconoce tres valores. Cualquier otro se trata como
`local`: un `APP_ENV` mal escrito nunca desactiva las protecciones.

| | `local` | `desarrollo` | `produccion` |
|---|---|---|---|
| Uso | escritorio del programador | servidor con dominio propio | producción |
| `display_errors` | `true` | `false` (forzado) | `false` (forzado) |
| Detalle en errores 500 | tipo, archivo, línea, traza | solo mensaje genérico | solo mensaje genérico |
| Endpoints FEL | sandbox `feltestSB` | sandbox `feltestSB` | **servicio real** |
| Credenciales BD | `root` / vacío | `config.local.php` o entorno | `config.local.php` o entorno |
| Credenciales Auth | `admin` / `admin123` | `config.local.php` o entorno | `config.local.php` o entorno |
| Orígenes CORS | `localhost:5173`, `localhost:3000`, etc. | lista vacía | lista vacía |
| Registro de errores | pantalla + `error_log` | solo `error_log` | solo `error_log` |

### Por qué existe `desarrollo`

Un servidor de desarrollo con dominio propio es público, y eso lo vuelve
incompatible con los otros dos entornos:

- `local` filtra el detalle técnico de un error 500: tipo de excepción, ruta del
  archivo, número de línea y traza. Es lo que se quiere en un escritorio y lo
  que no se puede dejar en un servidor alcanzable desde Internet.
- `produccion` resuelve ese problema, pero apunta al **servicio real** de
  facturación. Desplegar el código con `APP_ENV=produccion` y credenciales de
  pruebas emitiría facturas de verdad.

`desarrollo` combina lo uno con lo otro: endpoints de sandbox y sin detalle
técnico en las respuestas. No es una tercera opción intermedia, es la única
correcta para un servidor desplegado que emite documentos de prueba.

> **Nunca use `APP_ENV=produccion` con credenciales de `feltest` esperando que
> apunten al sandbox.** Si lo hace, emite contra la SAT.

`ExceptionHandler` **siempre** escribe en `error_log`, también en producción:
el operador puede diagnosticar el fallo sin que el cliente reciba nada. Ocultar
el error de la respuesta no lo elimina del registro.

---

## 4. Autenticación

HTTP Basic Auth sobre **todas** las rutas con prefijo `/api/`.

```bash
curl -u admin:admin123 http://127.0.0.1/fel-dentall/api/items
```

Una petición sin credenciales o con credenciales incorrectas recibe:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Basic realm="API REST Items", charset="UTF-8"

{"success":false,"message":"Credenciales inválidas o ausentes. Se requiere autenticación HTTP Basic.","data":null,"errors":null}
```

La comparación usa `hash_equals()` (tiempo constante) y siempre evalúa usuario
**y** contraseña, para que el tiempo de respuesta no revele cuál falló.

> **Producción:** HTTP Basic Auth transmite las credenciales en Base64, que es
> codificación, **no cifrado**. Sin HTTPS, cualquier interceptación las obtiene.
> Es obligatorio terminar TLS delante de esta API.

---

## 5. Endpoints

Todos trabajan con **UUID público**. El `id` interno no aparece en ninguna ruta
ni en ninguna respuesta.

| Método | Ruta | Respuesta | Autenticación |
|---|---|---|---|
| `POST` | `/api/items` | `201` Created | Sí |
| `GET` | `/api/items` | `200` OK + paginación | Sí |
| `GET` | `/api/items/{uuid}` | `200` OK | Sí |
| `PUT` | `/api/items/{uuid}` | `200` OK | Sí |
| `DELETE` | `/api/items/{uuid}` | `200` OK | Sí |
| `OPTIONS` | `/api/items` | `204` No Content | No (preflight) |
| `POST` | `/api/facturacion/ordenes` | `201` Created | Sí |
| `POST` | `/api/facturacion/ordenes/{uuid}/reintento` | `200` OK | Sí |
| `POST` | `/api/facturacion/ordenes/{uuid}/anular` | `200` OK | Sí |
| `GET` | `/api/facturacion/ordenes/{uuid}` | `200` OK | Sí |

Las rutas de facturación se describen en la sección 14.

### Parámetros de listado

| Parámetro | Por defecto | Máximo | Descripción |
|---|---|---|---|
| `limit` | 25 | 50 | Resultados por página |
| `offset` | 0 | — | Registros omitidos |

### Formato de respuesta

Éxito:

```json
{
  "success": true,
  "message": "Item creado correctamente.",
  "data": {
    "uuid": "9c1f2b3a-4d5e-4f60-8a71-b2c3d4e5f607",
    "title": "Primera muestra",
    "description": "Linea uno\nLinea dos",
    "created_at": "2026-10-13 18:24:05"
  },
  "errors": null
}
```

Error de validación:

```json
{
  "success": false,
  "message": "Los datos enviados no son válidos.",
  "data": null,
  "errors": { "title": ["El campo \"title\" no puede superar los 255 caracteres (recibidos: 256)."] }
}
```

El objeto `data` de un item contiene **exactamente** cuatro claves: `uuid`,
`title`, `description`, `created_at`. La lista añade `total`, `limit` y `offset`.

### Códigos de estado

| Código | Cuándo se devuelve |
|---|---|
| `200` | Lectura, actualización y borrado correctos |
| `201` | Recurso creado |
| `204` | Preflight CORS |
| `400` | JSON inválido, `Content-Type` incorrecto, UUID mal formado, campos ausentes o demasiado largos, payload vacío |
| `401` | Credenciales ausentes o incorrectas |
| `404` | UUID bien formado pero inexistente, o ruta inexistente |
| `405` | Verbo no admitido en la ruta (incluye cabecera `Allow`) |
| `409` | Violación de restricción de integridad (UUID duplicado) |
| `500` | Error interno (en `local` incluye el detalle técnico) |

Distinción importante: **`400` = el UUID está mal formado**, **`404` = el UUID
es válido pero no existe**.

### Campos aceptados

| Campo | `POST` | `PUT` | Notas |
|---|---|---|---|
| `title` | obligatorio | opcional | Máx. 255 caracteres, una línea |
| `description` | opcional | opcional | Multilínea; vacío o ausente → `null` |

`uuid`, `created_at` e `id` son **rechazados con `400`** si el cliente los envía,
en lugar de ignorarse en silencio: es más seguro avisar al integrador de que
está intentando escribir un campo de solo lectura.

### Semántica de `PUT`

Actualización **parcial**: basta con enviar `title`, `description` o ambos. Un
cuerpo `{}` devuelve `400`. `uuid` y `created_at` son inmutables.

Si un recurso necesita `PATCH` además de `PUT`, el router ya ofrece el verbo:

```php
$router->patch('/api/items/{uuid}', [ItemController::class, 'update']);
```

Ambas acciones coexistirían; la cabecera `Allow` de un `405` se construye a partir
de las rutas realmente declaradas, así que solo anuncia `PATCH` donde existe.

---

## 6. Dónde se registran las rutas

**Todas las rutas se declaran en `app/bootstrap.php`**, dentro del bloque
marcado como *PUNTO ÚNICO DE REGISTRO DE RUTAS*. Nunca en
`public/index.php` ni en un router propio.

El motivo es que ese es el único punto donde ya están aplicados el autoloader,
la configuración de errores por entorno y el manejador de excepciones.
`Router::registerController()` además exige que la instancia se construya con
sus dependencias, y construirla fuera del contenedor rompe la trazabilidad.

Verbos disponibles: `get()`, `post()`, `put()`, `patch()`, `delete()` y `any()`
(rutas transversales, evaluadas en un segundo paso). Regla de orden: agrupar por
recurso y declarar la ruta genérica `any()` **después** de las rutas del recurso.

---

## 7. Migraciones de base de datos

`sql/schema.sql` describe la instalación **limpia**. Para evolucionar una base ya
desplegada, cada cambio va en un archivo numerado dentro de `sql/migrations/`
(`001_descripcion_corta.sql`), idempotente y aplicable en orden.

**No edites `sql/schema.sql` para cambiar una tabla existente**: en un entorno
ya desplegado el cambio no se aplicaría, y `CREATE TABLE IF NOT EXISTS` ni
siquiera intentaría ejecutarlo.

Migraciones aplicadas:

| Migración | Qué hace |
|-----------|----------|
| `001_gf_orders.sql` | Crea `gf_orders` (órdenes de facturación) y `schema_migrations`. |
| `002_gf_orders_contingencia.sql` | Añade `ambiguous` y `last_error_code` para distinguir el desenlace del último intento. |
| `003_gf_orders_anulacion.sql` | Añade `annulled_at`, `annulment_reason` y `annulment_response`. |

```bash
mysql\bin\mysql.exe -u root fel_dentall_api < sql\migrations\001_gf_orders.sql
```

La tabla `gf_orders` guarda la orden completa como JSON en `payload` y, sobre
todo, el **XML exacto enviado** en `sent_xml`. Esa última columna es la que
permite el reintento con `<Reversion>` y la anulación sin reconstruir el
documento.

Las marcas de tiempo de `gf_orders` se escriben **siempre en UTC** desde la
aplicación (`gmdate`). El servidor de MySQL, en cambio, corre en hora de
Guatemala: sembrar o corregir filas a mano con `NOW()` en vez de
`UTC_TIMESTAMP()` desplazaría seis horas la orden y dispararía la recuperación de
órdenes atascadas.

Ver `sql/migrations/README.md` para la convención y la plantilla.

---

## 8. Ejemplos con cURL

```bash
# Crear
curl -u admin:admin123 -X POST http://127.0.0.1/fel-dentall/api/items \
  -H "Content-Type: application/json" \
  -d '{"title":"Primera muestra","description":"Linea uno\nLinea dos"}'

# Listar
curl -u admin:admin123 "http://127.0.0.1/fel-dentall/api/items?limit=10&offset=0"

# Ver uno
curl -u admin:admin123 http://127.0.0.1/fel-dentall/api/items/9c1f2b3a-4d5e-4f60-8a71-b2c3d4e5f607

# Actualizar
curl -u admin:admin123 -X PUT http://127.0.0.1/fel-dentall/api/items/9c1f... \
  -H "Content-Type: application/json" -d '{"title":"Nuevo título"}'

# Eliminar
curl -u admin:admin123 -X DELETE http://127.0.0.1/fel-dentall/api/items/9c1f...
```

---

## 9. Estructura de archivos

```text
fel-dentall/
├── .gitignore                      Exclusiones de control de versiones
├── .htaccess                       Redirección al front controller (opcional)
├── README.md
├── config/
│   └── config.php                  Configuración por entorno
├── app/
│   ├── bootstrap.php               Autoloader, errores, manejador, contenedor y rutas
│   ├── Core/
│   │   ├── AppConfig.php           Acceso tipado a la configuración
│   │   ├── Auth.php                HTTP Basic Auth
│   │   ├── Database.php            Conexión PDO única
│   │   ├── Env.php                 Lectura de variables de entorno
│   │   ├── ExceptionHandler.php    Errores, excepciones y apagados
│   │   ├── Request.php             Petición y normalización de rutas
│   │   ├── Response.php            Respuestas JSON y CORS
│   │   ├── Router.php              Enrutado por regex
│   │   ├── Sanitizer.php           Normalización de texto
│   │   ├── Uuid.php                Generación y validación UUID v4
│   │   └── Validator.php           Validación acumulativa
│   ├── Controllers/
│   │   ├── ItemController.php
│   │   └── FacturacionController.php
│   ├── Models/
│   │   ├── Item.php
│   │   └── GfOrder.php                         Orden de facturación y estados
│   ├── Repositories/
│   │   ├── ItemRepository.php
│   │   └── GfOrderRepository.php
│   ├── Services/
│   │   ├── ItemService.php
│   │   ├── FacturacionService.php              Orquesta la emisión
│   │   ├── ReintentoService.php                Reenvío seguro del documento
│   │   ├── AnulacionService.php                Anulación de facturas emitidas
│   │   └── RetryPolicy.php                     Espera progresiva y bloqueos
│   ├── Integrations/
│   │   └── Guatefacturas/
│   │       ├── GuatefacturasClient.php        Cliente SOAP
│   │       ├── DocumentEmitter.php            Contrato de emisión
│   │       ├── ProviderResponseClassifier.php  Fallo limpio frente a ambiguo
│   │       ├── GuatefacturasConfig.php        Configuración validada
│   │       ├── GuatefacturasException.php     Base, con enmascarado de secretos
│   │       ├── ConfigurationException.php      Credenciales ausentes
│   │       ├── CommunicationException.php      Fallo de red
│   │       ├── ProviderBusinessException.php   Rechazo del proveedor
│   │       ├── DocumentResult.php              Digest de la respuesta
│   │       ├── DteType.php                     Catálogo de tipos de DTE
│   │       ├── Decimal.php                     Aritmética decimal exacta
│   │       ├── OrderLine.php                   Línea e importes calculados
│   │       ├── Totals.php                      Totales con invariantes
│   │       ├── OrderPayload.php                Contrato de entrada del POS
│   │       └── XmlBuilder.php                  Documento XML del proveedor
├── public/
│   ├── .htaccess                   Front controller
│   └── index.php                   Punto de entrada único
├── sql/
│   ├── schema.sql                  Esquema de instalación limpia
│   └── migrations/
│       ├── README.md               Convención de migraciones
│       ├── 001_gf_orders.sql       Tabla gf_orders
│       └── 002_gf_orders_contingencia.sql  Desenlace del último intento
├── tests/
│   ├── pure_units.php              Pruebas de lógica pura (sin red)
│   ├── contingencia.php            Matriz de fallos del proveedor (sin red)
│   ├── http_matrix.php             Prueba funcional contra Apache
│   ├── guard_confidentialidad.php  Verificación de las barreras de seguridad
│   ├── integration_guatefacturas.php  Prueba contra el sandbox real (opt-in)
├── run_all.php                     Ejecuta todas las suites en un comando
│
└── docs/                          No se versiona: se entrega por separado
    ├── NOTA-DE-ENTREGA.md           Nota de entrega firmada
    ├── historias_de_usuario...md    Historias con criterios de aceptación
    └── guia-operativa.pdf           Manual del proveedor (confidencial)
```

---

## 10. Modelo de datos

```sql
CREATE TABLE items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Identificador interno (PK/FK)',
  uuid CHAR(36) NOT NULL COMMENT 'Identificador público UUID v4',
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL COMMENT 'Fecha y hora de creación (UTC)',
  UNIQUE KEY uk_items_uuid (uuid),
  INDEX idx_items_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- `id` se usa **solo** en `ORDER BY id DESC` como desempate estable cuando dos
  registros comparten el mismo `created_at`. No se devuelve al cliente.
- `uuid` se genera con `random_bytes()` en el servidor mediante
  `Uuid::v4()`. Un cliente no puede elegirlo.
- `created_at` se genera con `gmdate('Y-m-d H:i:s')` (UTC). La sesión SQL fija
  además `time_zone = '+00:00'` para que cualquier valor por defecto del servidor
  no altere los resultados.
- No hay columna `updated_at`: una actualización no deja rastro temporal. Es una
  decisión consciente del esquema de referencia; añádala si la necesita.

---

## 11. Pruebas

Ninguna requiere PHPUnit: son scripts PHP autónomos.

```bash
# Lógica pura (Uuid, Sanitizer, Validator, Request, Router, Integración FEL)
php tests/pure_units.php

# Funcional: requiere Apache y MySQL en marcha
php tests/http_matrix.php

# Confidentialidad: .gitignore, .htaccess y ausencia de credenciales filtradas
php tests/guard_confidencialidad.php

# Todas las suites anteriores en un solo comando
php tests/run_all.php --no-network

# Integración real con GUATEFACTURAS (solo si hay credenciales configuradas)
php tests/integration_guatefacturas.php
php tests/integration_guatefacturas.php --no-network   # solo validaciones locales
```

Variables opcionales de `http_matrix.php`:

```bash
set API_BASE=http://127.0.0.1/fel-dentall
set API_USER=admin
set API_PASS=admin123
```

> **`http_matrix.php` necesita dos cosas, no solo la URL:** además de las
> peticiones HTTP, abre una conexión PDO a la base de datos para sembrar y
> verificar filas. Si no puede conectarse, **imprime un aviso y sale con código
> `0` omitiendo las 104 aserciones** — un falso verde que parece un éxito.
> Contra un servidor remoto hay que habilitar *Remote MySQL* en cPanel, añadir
> la IP pública de quien ejecuta la prueba a la lista blanca y apuntar `DB_HOST`,
> `DB_NAME`, `DB_USER` y `DB_PASS` a la base del servidor. Compruebe que la
> suite imprime el recuento de aserciones y no el aviso de omisión.

En un hosting compartido sin SSH no hay PHP CLI, así que las suites se ejecutan
siempre desde el equipo del programador, nunca en el servidor.

Comprobación de sintaxis contra el PHP 7.4 real (el `php.exe` de XAMPP Lite solo
arranca si se le pasan las extensiones a mano, porque su `php.ini` usa
`${XAMPP_LITE_ROOT}`):

```bash
php\php.exe -n ^
  -d extension_dir=\php\ext ^
  -d extension=pdo_mysql -d extension=mbstring ^
  -l app\Core\Router.php
```

### Pruebas para QA

La colección de Postman, su guía de ejecución y el manual de QA **no se versionan en
este repositorio**: se entregan por separado como material de pruebas. El manual del
proveedor que sí se conserva es de terceros y nunca entra al control de versiones.

### Suites del repositorio

Estado actual:

| Suite | Resultado | Requiere red |
|---|---|---|
| `tests/pure_units.php` | 265 / 265 | No |
| `tests/contingencia.php` | 214 / 214 | No |
| `tests/http_matrix.php` | 104 / 104 | Apache local |
| `tests/guard_confidencialidad.php` | 51 / 51 | No |
| `tests/integration_guatefacturas.php` | 37 / 37 | Sandbox de GUATEFACTURAS |

Las dos suites funcionales son **idempotentes**: pueden ejecutarse repetidas
veces contra la misma base de datos y terminan con la tabla `items` en 0 filas.
La de integración **se omite sola** si faltan credenciales, de modo que un
entorno de CI sin configuración no falla. Ninguna suite llama al proveedor
salvo `integration_guatefacturas.php`: el sandbox limita el ritmo de emisiones y
una prueba dependiente de él fallaría de forma intermitente.

`tests/contingencia.php` ejercita toda la matriz de fallos del proveedor —caída,
tiempo de espera agotado, respuesta vacía, límite de emisiones, rechazo de
negocio— **y la del reintento seguro**, **sin red**, inyectando un emidor
simulado en los servicios. Es posible porque ambos dependen del contrato
`DocumentEmitter` y no de `GuatefacturasClient`, que es `final`.

---

## 12. Decisiones de seguridad

### Confidentialidad del material de terceros

El proyecto maneja documentación de proveedores marcada como
`CONFIDENCIAL — USO EXCLUSIVO EMISOR`. Se protege con **dos barreras
independientes**:

| Barrera | Protege | Falla si |
|---|---|---|
| `.gitignore` | El repositorio | Se versiona el material o una credencial |
| `.htaccess` | El servidor web | Se accede por HTTP a una carpeta interna |

Ambas son necesarias: un `.gitignore` correcto no evita que un servidor mal
configurado exponga `config/config.php`, y un `.htaccess` correcto no evita un
commit con un `DB_PASS` real.

Reglas aplicadas:

- `docs/*.pdf` excluido del control de versiones.
- `config/config.local.php` y `.env` excluidos; `.env.example` sí se versiona.
- La rama `produccion` de `config/config.php` **no** declara credenciales por
  defecto: deben fijarse con `DB_USER`, `DB_PASS`, `API_USER` y `API_PASS`.
- Los informes de Newman (`report.html`) quedan excluidos: contienen los
  cuerpos completos de las respuestas, con datos fiscales de clientes.
- El contenido del manual **no se copia literalmente a ningún archivo** del
  proyecto. Se documentan contratos, tipos de dato y códigos de error; el
  original puede retirarse del disco sin romper el desarrollo.

`php tests/guard_confidentialidad.php` verifica ambas barreras en cada
ejecución de la suite. Ver `docs/README.md`.

### Endurecimiento de la API

| Medida | Dónde | Por qué |
|---|---|---|
| Prepared statements nativos | `ItemRepository` | `ATTR_EMULATE_PREPARES => false`: los valores viajan tipados al servidor |
| `hash_equals()` en credenciales | `Auth` | Evita filtrar la credencial correcta por temporización |
| Lista blanca de orígenes CORS | `Response` | `Access-Control-Allow-Origin: *` con Basic Auth permitiría que cualquier web llamara la API |
| `sql_mode = STRICT_TRANS_TABLES` | `Database` | Sin esto, un título de 400 caracteres se trunca en silencio y devuelve `201` |
| `SET time_zone = '+00:00'` | `Database` | Refuerza el UTC de `gmdate()` |
| `mb_check_encoding()` | `Sanitizer` | Un body con UTF-8 roto haría que `json_encode()` devolviera `false` |
| Campos no declarados rechazados | `ItemService` | Impide escribir en columnas no previstas (`id`, `uuid`, `created_at`) |
| Bandera `$sent` en `Response` | `Response`, `ExceptionHandler` | Impide una doble salida desde `register_shutdown_function` |
| `.htaccess` bloquea `app/`, `config/`, `sql/`, `docs/` | raíz del proyecto | Esas carpetas no son públicas |
| `nosniff`, `DENY`, `no-store` | `Response` | Endurece la respuesta ante cachés y sniffing |

---

## 13. Desviaciones respecto al prompt original

| # | Desviación | Motivo |
|---|---|---|
| 1 | Namespaces `App\*` | El prompt no los prohíbe; sin ellos el autoloader no es viable |
| 2 | Autoloader SPL en `app/bootstrap.php` | §3 no lo lista, y sin él ninguna de las 13 clases se cargaría |
| 3 | `Request` recorta la ruta base | El prompt asume `DocumentRoot` en `public/`; en XAMPP apunta a `www/` |
| 4 | `Env` con métodos definidos | §5 no asigna ni un método a `app/Core/Env.php` |
| 5 | Envelope JSON definido | §5.5 especifica métodos pero no la forma del cuerpo |
| 6 | `405` + `Allow` y `400` por UUID malformado | No estaban en la lista de códigos del §2.1 |
| 7 | Paginación `limit`/`offset` con tope de 50 | `all()` sin límite devuelve la tabla entera |
| 8 | `hash_equals`, CORS con lista blanca, `sql_mode`, `mb_check_encoding`, `nosniff` | Endurecimiento no cubierto por el §2.2 |
| 9 | `HTTP_AUTHORIZATION` como respaldo de `PHP_AUTH_*` | Algunos servidores lo eliminan al pasar por `mod_rewrite` |
| 10 | `docs/promp-base.md` creado | Es el nombre que pide §3; `docs/base.md` queda intacto |
| 11 | Carpetas `tests/` | El §9 exige verificación; sin framework se usan scripts autónomos |
| 12 | `Router::patch()` añadido | `ALLOWED_METHODS` declaraba `PATCH` sin método de registro: era inejable |
| 13 | `sql/migrations/` | `schema.sql` solo cubre instalación limpia; evolucionar una base exige migraciones |
| 14 | Punto de registro de rutas documentado en `bootstrap.php` | El prompt de fase 2 no indica dónde declarar rutas nuevas |
| 15 | Tests del Router y validador estático de Postman | El router era la pieza sin cobertura; la colección, sin validación |

**`sql/schema.sql` se mantiene literal**, tal como exige el §4.

### Correcciones posteriores a la entrega inicial

Detectadas al preparar la fase de desarrollo:

| Corrección | Causa |
|---|---|
| `Request::json()` usa `JsonException::getCode()` en vez de `json_last_error()` | `JSON_THROW_ON_ERROR` reinicia `json_last_error()` a `NONE`, de modo que un JSON roto informaba literalmente «sin error» |
| Mensaje de JSON sin prefijo duplicado | El controlador anteponía `JSON inválido:` a un mensaje que ya lo incluía |
| La prueba «UUID en mayúsculas» ya no depende de una acción manual | Se resolvía «copiar como cURL y editar a mano», lo que la hace no automatizable |

---

## 14. Integración con GUATEFACTURAS

Cliente SOAP del proveedor de facturación electrónica FEL de Guatemala
(Corporación Caor / DENTALLGT, NIT XXXXXXXXX).

### Configuración de credenciales

Las credenciales se resuelven con esta precedencia, de mayor a menor:

1. Variable de entorno
2. `config/config.local.php` (excluido por `.gitignore`)
3. `config/config.php` (valores por defecto)

| Variable | Parámetro SOAP | Descripción |
|---|---|---|
| `FEL_GUATEFAC_USER` | `pUsuario` | Usuario de negocio |
| `FEL_GUATEFAC_PASSWORD` | `pPassword` | Contraseña de negocio |
| `FEL_EMISOR_NIT` | `pNitEmisor` | NIT del emisor |
| `FEL_ESTABLECIMIENTO` | `pEstablecimiento` | Código del establecimiento |
| `FEL_GUATEFAC_BASIC_USER` | HTTP Basic | Usuario del transporte SOAP |
| `FEL_GUATEFAC_BASIC_PASS` | HTTP Basic | Contraseña del transporte |
| `FEL_WSDL_URL` | — | Sobrescribe la URL del WSDL |
| `FEL_ENDPOINT_URL` | — | Sobrescribe la URL del servicio |
| `FEL_CONNECTION_TIMEOUT` | — | Segundos (por defecto 10) |
| `FEL_RESPONSE_TIMEOUT` | — | Segundos (por defecto 60) |

Las dos últimas filas son opcionales. `local` apunta al **sandbox**
(`feltestSB`); `produccion` al servicio real.

Para desarrollo, copie la plantilla y rellénela:

```bash
copy config\config.local.example.php config\config.local.php
```

El archivo de ejemplo SÍ se versiona y el guardián de confidencialidad verifica
que no contenga nunca valores reales.

### Contrato verificado contra el servicio

Todo lo siguiente se comprobó contra el WSDL y el sandbox, no contra el manual:

| Aspecto | Valor real | Lo que dice el manual de 2008 |
|---|---|---|
| Versión SOAP | **1.1** (`wsdl/soap/`) | No lo especifica; la URL sugiere 1.3 |
| Estilo | `rpc` + `literal` | No lo especifica |
| Contenedor | Oracle Service Bus (OSB) | `oc4j`, Oracle Application Server |
| Estructura XML | `<TAG1>` envuelve a `<Detalles>` | `<Detalles>` directamente, **incorrecto** |
| Estructura XML | Cada línea es su **propio** `<Productos>` | No lo especifica; el nombre en plural sugiere lo contrario |
| Precio unitario | **Sin IVA**: el IVA es `Neto × 12 %` | No lo aclara |
| Fecha del documento | Fecha **local del emisor** (UTC−6) | No lo aclara |
| Errores | Llegan con **HTTP 200** dentro de `<result>` | No lo aclara |
| Límite de emisiones | El sandbox **rechaza con el error 82** documentos válidos si se excede el ritmo | No lo menciona |

El manual de 2008 **ya no coincide con la implementación** en el nivel de anidado
del XML. Construir contra él produce
`ERROR el TAG1 Detalles no existe o viene mal definido en el formato XML`.

### Idempotencia por `Referencia`

Comprobado empíricamente: enviar tres veces el mismo XML con la misma
`<Referencia>` devuelve **la misma serie, el mismo preimpreso y la misma
autorización**. Guatefacturas reconoce la referencia repetida y devuelve el
documento ya emitido en lugar de crear otro.

Es la base del reintento seguro: ante un timeout, reenviar el XML guardado no
puede duplicar la factura.

### Formatos de respuesta

Modo resumen (`pTipoRespuesta = R`):

```xml
<Resultado>
  <Serie>BADD0CD3</Serie>
  <Preimpreso>4067575726</Preimpreso>
  <NumeroAutorizacion>BADD0CD3-F272-47AE-B3F3-66627DE68250</NumeroAutorizacion>
  <Referencia>POS-0001</Referencia>
</Resultado>
```

Modo detalle (`pTipoRespuesta = D`): el mismo envoltorio, con el XML DTE
firmado por SAT incrustado. En ese caso la serie y el número viajan como
**atributos** de `dte:NumeroAutorizacion`, no como etiquetas.

### Formatos de error observados

| Formato | Ejemplo |
|---|---|
| Sin código | `ERROR el TAG1 Detalles no existe o viene mal definido en el formato XML` |
| Con código | `82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO` |
| Error interno | `ERRORES:ORA-01403: No se ha encontrado ningún dato` |

Todos llegan con HTTP 200, por lo que el cliente los detecta por el contenido,
nunca por el código de estado.

### El precio del POS es SIN IVA; el del proveedor, CON IVA

El precio que manda el POS en `precio_unitario` es el importe **sin IVA**, que es
lo que cobra la clínica. **El documento XML no lo envía tal cual.**

La guía operativa de GUATEFACTURAS lo dice explícitamente en la definición del
campo:

```
PRECIO   Precio unitario del producto, IVA incluido
```

Y su propio ejemplo lo confirma. Con `Precio = 10` y `Cantidad = 101`:

| Campo | Valor | Cuenta |
|---|---|---|
| `IMPORTE_BRUTO` | `1010.00` | `10 × 101` |
| `IMPORTE_NETO`  | `901.79`  | `1010 / 1.12` |
| `IMPORTE_IVA`   | `108.21`  | `901.79 × 0.12` |
| `IMPORTE_TOTAL` | `1010.00` | `901.79 + 108.21` |

La conversión la hace `ImportesProveedor`: multiplica el precio por `(1 + tasa)` y
el bruto sale de ahí. Como multiplicar y dividir por el mismo factor se cancela,
**el neto, el IVA y el total que se emiten son los mismos que calculó la API**, así
que la factura cuadra con lo que se guardó y con lo que se devuelve al POS.

### El error 82 es de cálculo, no de límite de emisiones

Tres historias de usuario se programaron contra una conclusión falsa, y conviene
saberlo porque el razonamiento que la produjo era defectuoso:

> «El precio `100.00` con IVA `12.00` se aceptó; el precio `112.00` con el IVA ya
> extraído se rechazó con el error 82. Luego el proveedor calcula el IVA sobre el
> neto y hay que enviar el precio sin IVA.»

Lo que ocurría de verdad: **ambos documentos estaban mal**, pero solo el segundo
llegó a desvelarlo. La conclusión salió de comparar un rechazo con una aceptación
que en realidad era la misma prueba. Después, al quedarse el sandbox sin cupo
aparente, se interpretó el rechazo persistente como un límite del entorno y se
diseñó una espera progresiva en torno a él.

La causa real, medida sobre el valor que declara la guía:

```
Neto esperado = (Precio − Descuentos − Otros) / 1.12
              = (100.00 − 0 − 0) / 1.12 = 89.28
```

El documento declaraba `ImpNeto = 100.00`, no cuadraba, y el proveedor rechazaba.
El mismo síntoma reaparece con otro mensaje cuando la validación llega más lejos:

```
ERROR-1041 [Monto Gravable:100] El contenido de la casilla es diferente del
resultado de la siguiente operación: casilla "Precio" menos las casillas
"descuento" y "otros descuentos" y el resultado de ellas dividido entre el
factor 1.12
```

Con el precio correctamente declarado, la emisión se autoriza. Verificado con dos
facturas reales contra el sandbox.

### Fórmulas de importes

```
línea (base de negocio, lo que se guarda y se devuelve):
  ImpBruto     = Cantidad × PrecioUnitario
  ImpDescuento = ImpBruto × PorcDescuento / 100
  ImpOtros     = (ImpBruto − ImpDescuento) × PorcOtros / 100
  ImpNeto      = ImpBruto − ImpDescuento − ImpOtros
  ImpExento    = ImpNeto si la línea es exenta, 0 en caso contrario
  ImpIva       = (ImpNeto − ImpExento) × Tasa / 100
  ImpTotal     = ImpNeto + ImpIva


cabecera:
  Bruto = Σ ImpBruto      Descuento = Σ ImpDescuento
  Otros = Σ ImpOtros      Exento    = Σ ImpExento
  Neto  = Bruto − Descuento − Otros
  Isr   = 0
  Iva   = Σ ImpIva        Total     = Neto + Iva
```

La retención de ISR se informa en `Otros` y `Isr` se deja a cero: se probó que
el proveedor resta la retención desde `Otros` (neto de 900 sobre bruto de 1000) y
que con `Isr` informado y `Otros` a cero calculaba sobre el bruto íntegro.

### Aritmética entera, nunca coma flotante

Todos los importes se manejan como unidades enteras (centavos) mediante
`Decimal`. El proveedor valida la aritmética de cada documento antes de
firmarlo, y un céntimo de diferencia impide emitir la factura. En PHP,
`0.29 * 100 === 28.999999999999996`; sobre una factura de veinte líneas el error
acumulado sería un rechazo en producción.

### La fecha del documento es local, no UTC

El proveedor rechaza los documentos fechados un día por delante:

```
56-FECHA DE DOCUMENTO NO PUEDE SER MAYOR A LA FECHA DEL SISTEMA
```

Guatemala está seis horas por detrás de UTC, así que usar `gmdate('d/m/Y')`
documentaría el día siguiente entre las 18:00 y las 24:00 hora local, y
**todas las facturas de ese tramo nightly serían rechazadas**. La fecha se toma
con la zona `billing.document_timezone` (`America/Guatemala` por defecto).

### El sandbox limita el ritmo de emisiones

Tras una ráfaga de emisiones, el proveedor responde a documentos **válidos**:

```
82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO
```

El mensaje no tiene relación con la aritmética. Es la misma respuesta que
aparece cuando el cálculo sí es incorrecto, de modo que **un rechazo con el
error 82 no permite distinguir un error real de una emisión throttled**. Se
verificó: tras esperar 75 segundos vuelve a aceptar; encadenar peticiones las
rechaza.

Consecuencias para el diseño, que se aplican en HU-4:

- Nunca reintentar de inmediato ante el error 82: se necesita espera y consulta
  previa de la `<Referencia>`.
- La suite funcional **no llama al proveedor**. Si lo hiciera, fallaría de
  forma intermitente y gastaría emisiones reales en cada ejecución.

### Endpoint de emisión

```
POST /api/facturacion/ordenes
GET  /api/facturacion/ordenes/{uuid}
```

| Código | Significado |
|--------|-------------|
| `201`  | Factura autorizada por SAT. |
| `400`  | El JSON no cumple el contrato; se informan **todos** los errores a la vez. |
| `404`  | No existe una orden con ese UUID válido. |
| `409`  | La orden ya está resuelta, está en curso, o agotó sus intentos. |
| `422`  | El proveedor **rechazó** el documento. Los datos hay que corregirlos. |
| `429`  | Todavía no corresponde reintentar: la espera progresiva no ha transcurrido. |
| `503`  | No hubo respuesta concluyente. La orden quedó registrada y es reintentable. |

El UUID con formato válido pero inexistente da `404`; uno mal formado da `400`,
porque la API valida el formato de la ruta antes de tocar la base de datos.

Cuerpo de la petición:

```json
{
  "codigo_orden": "POS-2026-000123",
  "cliente": {
    "nit": "C/F",
    "nombre": "Nombre del cliente",
    "direccion": "Zona 1, calle 2, casa 3",
    "correo": "cliente@ejemplo.com",
    "telefono": "51234567"
  },
  "carrito": [
    {
      "codigo": "SRV-001",
      "descripcion": "Kit de brackets",
      "cantidad": 1,
      "precio_unitario": 100.00,
      "descuento": 0,
      "porcentaje_iva": 12,
      "porcentaje_isr": 0,
      "exento": false
    }
  ],
  "facturacion": {
    "tipo_documento": "FACT",
    "tipo_venta": "S",
    "destino_venta": 1,
    "observaciones": null
  },
  "entrega": {
    "direccion": "Zona 5",
    "telefono": "51234567",
    "observaciones": "Entregar en recepción"
  }
}
```

Solo son obligatorios `codigo_orden`, `cliente.nit`, `cliente.nombre`,
`cliente.direccion`, `facturacion` y un `carrito` con al menos una línea. Los
datos de `entrega` viajan al documento dentro de `<DatosAdicionales>`, que se
omite por completo si no hay nada que enviar.

### Respuestas con contexto cuando la orden sí quedó registrada

Cuando una orden llegó a persistirse pero no pudo completarse, la respuesta
incluye un bloque `contexto` con los datos que el POS necesita para continuar:

```json
{
  "success": false,
  "message": "El proveedor no está disponible. La orden quedó registrada con el UUID …",
  "data": null,
  "errors": null,
  "contexto": {
    "uuid": "de351621-d38b-4bfa-aa7c-9c7021e97945",
    "estado": "PENDIENTE_REINTENTO",
    "reintentable": true,
    "ambiguo": true,
    "orden": { "codigo_orden": "POS-2026-000123", "intentos": 2, "…": "…" }
  }
}
```

El `uuid` viaja como campo y **no** dentro del texto del mensaje. Un mensaje es
para personas, cambia con las traducciones y no es un contrato que se pueda
leer de forma fiable. Cuando la orden ni siquiera llegó a registrarse —una
petición que no cumple el contrato— no se emite `contexto`: no hay nada que
localizar.

### Tres campos que el POS necesita para decidir

| Campo | Para qué sirve |
|-------|----------------|
| `reintentable` | Si puede reenviar la orden sin duplicar la factura. |
| `ambiguo` | Si debe consultar al proveedor antes de reenviar. |
| `codigo_error` | Código del proveedor (`82`, `56`, `ORA-01400`). Clasifica el fallo sin depender del texto, que puede traducirse o cambiar. |

Sin ellos el POS solo tendría el código HTTP, y `503` no dice por sí solo si el
fallo fue una caída del servicio, un límite de emisiones o una credencial
ausente: los tres admiten reintento pero exigen acciones distintas.

### Los fallos no son todos iguales

| Situación | HTTP | Estado persistido | `ambiguo` |
|-----------|------|-------------------|-----------|
| Emisión autorizada | `201` | `FACTURADO` | `false` |
| Rechazo de negocio (`56-…`, `ERROR el TAG1…`) | `422` | `ERROR_PROVEEDOR` | `false` |
| `5xx`, tiempo de espera agotado, red caída | `503` | `PENDIENTE_REINTENTO` | `true` |
| `<Resultado></Resultado>` vacío o truncado | `503` | `PENDIENTE_REINTENTO` | `true` |
| Error `82` (puede ser límite de emisiones) | `503` | `PENDIENTE_REINTENTO` | `true` |
| Credenciales o WSDL inaccesible | `503` | `PENDIENTE_REINTENTO` | `false` |

El `422` y el `503` se distinguen por lo que significan, no por su número:

- **`422`**: el proveedor respondió y rechazó. Los datos están mal y reintentar
  no va a ayudar.
- **`503`**: no se sabe. El documento puede ser perfectamente válido.

La clasificación vive en `ProviderResponseClassifier`, que es una función pura
del texto recibido y se puede ejercitar entera sin red.

### Por qué `502` no es un rechazo

PHP lanza `SoapFault` tanto por un rechazo del emisor como por un `502` de una
pasarela que no existe, un tiempo de espera agotado o un problema de DNS. Antes
de HU-3 todo `SoapFault` se traducía a «el proveedor rechazó la factura» con
código `422`.

El coste no era teórico: el vendedor veía su venta rechazada, revisaba los datos
del cliente, no encontraba el error y perdía la venta. Y el sistema no tenía nada
que reintentar, porque se le había dicho que el documento era inválido.

### Por qué el error 82 no significa lo que dice

`82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO` aparece en dos situaciones
indistinguibles por el mensaje: el importe está realmente mal, o se ha superado
el límite de emisiones. En el segundo caso el documento es válido y el rechazo es
falso.

Reenviar el mismo XML es seguro en ambos casos: si el error era real, vuelve a
fallar igual, y nunca se genera una segunda factura porque la `Referencia` es la
misma. Por eso se clasifica como ambiguo y no como rechazo.

### Reintentar nunca duplica la factura

`ERROR_PROVEEDOR` también es reintentable, aunque el nombre sugiera lo
contrario. El proveedor reconoce la `Referencia` y devuelve el documento ya
emitido en lugar de crear otro. El peor caso de un reintento innecesario es una
respuesta igual de negativa.

Por eso el reintento de una orden con el mismo `codigo_orden` **conserva el mismo
UUID** y suma un intento. El POS puede reenviar sin registrar nada nuevo.

### Dos fallos que solo aparecen en producción

**Una orden atascada en `EN_PROCESO`.** El estado se escribe antes de salir a la
red y se sustituye al registrar la respuesta. Si el proceso muere entre ambas
operaciones —un `max_execution_time`, un apagón— la orden queda bloqueada y
cualquier reintento recibiría un `409` para siempre, sin ninguna vía de salida.
Una orden en `EN_PROCESO` con más de `billing.processing_timeout` segundos se
considera atascada y vuelve a emitirse.

**Un POS que reintenta en bucle.** Sin tope, cada reintento consume una emisión
del proveedor. `billing.max_attempts` (5 por defecto) corta en `409` pidiendo
revisión manual.

### Reintento seguro

```
POST /api/facturacion/ordenes/{uuid}/reintento
```

Reenvía al proveedor **el XML exacto que ya está almacenado**, nunca una
reconstrucción a partir del JSON de la orden.

#### El reenvío es la consulta

El proveedor solo expone `generaDocumento()` y `anulaDocumento()`: **no existe una
operación para consultar el estado de un documento**. La única forma de averiguar
qué pasó con una orden pendiente es reenviarla.

Eso es seguro porque la `<Referencia>` actúa como clave de idempotencia: si el
documento ya se emitió, el proveedor devuelve el mismo serie, preimpreso y número
de autorización en lugar de crear uno nuevo. Los dos desenlaces terminan en
exactamente una factura:

| Estado real del documento | Qué hace el reenvío |
|---|---|
| Nunca se emitió | Lo emite ahora. |
| Ya se emitió, se perdió la respuesta | Devuelve la misma factura. |
| El emisor se equivocó en los datos | Lo rechaza otra vez, con el mismo motivo. |

Por eso el reintento **no reconstruye** el documento. `sent_xml` es la copia exacta
del primer envío; reconstruirlo desde el JSON podría producir un XML que difiere
en un céntimo por redondeo, y el proveedor no lo reconocería como el mismo
documento: emitiera un segundo, que es justo lo que HU-4 existe para evitar.

#### Espera progresiva

El entorno de pruebas rechaza con el error `82` los documentos válidos que llegan
encadenados, de modo que reintentar de inmediato consume una emisión y vuelve a
fallar por un motivo ajeno al documento. La API aplica una espera creciente según
el número de intentos:

| Intento | Espera |
|---------|--------|
| 1.º | 60 s |
| 2.º | 120 s |
| 3.º | 300 s |
| 4.º y siguientes | 600 s |

Un reintento antes de tiempo responde `429` con la cabecera `Retry-After` y el
número de segundos restantes en el contexto. La cabecera está para que un
reintentador automático la respete sin que haya que programarlo.

#### Los cuatro motivos de bloqueo

| `motivo` | `HTTP` | Se resuelve solo |
|---|---|---|
| `ya_facturada` | `409` | No, nunca |
| `anulada` | `409` | No, nunca |
| `en_curso` | `409` | Sí, al terminar la emisión |
| `nunca_enviada` | `409` | No, con `POST /api/facturacion/ordenes` |
| `intentos_agotados` | `409` | No, con revisión manual |
| `demasiado_pronto` | `429` | Sí, esperando |

`nunca_enviada` merece explicación: si la orden nunca salió hacia el proveedor no
hay documento que reenviar, así que el reintento responde `409` indicando dónde
enviarla de nuevo.

#### El POS no tiene que deducir nada

La consulta de una orden incluye su situación de reintento:

```json
{
  "estado": "PENDIENTE_REINTENTO",
  "reintento": {
    "permitido": false,
    "motivo": "demasiado_pronto",
    "esperar_segundos": 60,
    "intentos_restantes": 4,
    "ultimo_intento": false
  }
}
```

### Anulación de facturas

```
POST /api/facturacion/ordenes/{uuid}/anular
{ "motivo": "Error de digitacion en el monto" }
```

Es la segunda y última operación del WSDL del proveedor, y **no se parece a la
emisión**: no recibe el XML, identifica el documento por serie y preimpreso y exige
además el NIT del comprador.

| Dato | De dónde sale |
|---|---|
| Serie y preimpreso | Del registro de la orden, no de la petición |
| NIT del comprador | Del pedido original |
| Fecha | Local del emisor, en `AAAAMMDD` |
| Motivo | De la petición |

Nada de eso se acepta desde el cliente: el POS no puede anular un documento
distinto del que dice. Si la orden no está `FACTURADO`, o le falta la serie, el
preimpreso o el NIT, la API responde `409` sin llamar al proveedor.

#### Lo que el proveedor exige, comprobado

Nada de lo siguiente está en el manual; se midió contra el entorno de pruebas:

| Parámetro | Comportamiento real |
|---|---|
| Fecha `DD/MM/AAAA` o `AAAA-MM-DD` | Rechazada: `ERROR en los datos enviados, campo FECHA_ANULACION` |
| NIT del comprador vacío | `ORA-01400` por inserción nula |
| Motivo de 730 caracteres | `ORA-06502` de Oracle, que llega como **fallo de la llamada**, no como respuesta |

Ese último límite se encontró por bisección: la columna del proveedor admite
exactamente **729** caracteres. La API usa un tope propio de **500**, redondo y
por debajo, para no depender de un límite ajeno que además falla con una excepción
de Oracle poco elegante.

#### Un rechazo que no lleva código

Anular un documento inexistente responde:

```
<RESULTADO>NO EXISTE EL DOCUMENTO QUE SE DESEA ANULAR</RESULTADO>
```

No lleva código numérico ni la palabra `ERROR`, de modo que las reglas de
detección de la emisión lo habrían tomado por **anulación correcta**: la orden
quedaría marcada como `ANULADO` cuando el proveedor acaba de decir que no existe.
Es el peor fallo posible en este dominio, porque es silencioso y además falsifica un
registro fiscal. `ProviderResponseClassifier::isAnnulmentRejection()` cubre el caso.

#### Un fallo de red no anula nada

Si el proveedor no responde durante la anulación, la factura **sigue vigente** y la
orden permanece en `FACTURADO`. Marcarla como anulada sería afirmar que un documento
dejó de estar válido ante la SAT cuando no es así. El `503` lo dice explícitamente.

#### La anulación es terminal

Una orden `ANULADO` no admite reintento ni nueva emisión, y su `codigo_orden` queda
ocupado por la restricción de unicidad. **Para volver a facturar la misma venta el
POS debe usar un código de orden distinto.** Es una consecuencia de negocio que
conviene tener presente antes de que aparezca en campo.

### Las facturas exentas no se pueden emitir todavía

La API acepta `exento: true` en una línea, pero **el proveedor rechaza el
documento**:

```
ERROR-1094 [Frase:] El "Tipo de DTE" contiene uno de los textos: "FACT", "FCAM",
no se incluye la casilla "Exportación", uno o más de los ítems del DTE no
incluyen IVA y el "Tipo de Frase" Exento o no afecto al IVA no está presente en
el XML.
```

La guía operativa **no documenta esa casilla**: su única mención a `<Frase>` está
en el anexo del **catálogo de productos** (anexo 18), donde cada producto declara
`<Exento_IVA>` y su `<Frase>`/`<Escenario>`. Eso sugiere que la frase es un
atributo del producto y no del documento, y que habría que cargar el catálogo antes
de emitir facturas exentas — la historia que aún no está escrita.

Se intentó con `<Frases>` a nivel de documento con `TipoFrase` 3, 4, 5 y 6 y con
la frase dentro de `<TAG1>`: las cinco dan el mismo error. Un documento **sin
exención** sí acepta el bloque `<Frases>`, así que la posición es válida y lo que
falta es el código correcto.

**Hasta resolverlo, una orden con líneas exentas recibirá un `422` con este
motivo.** El mensaje es mejorable y queda anotado como pendiente.

### Anotar sobre la idempotencia

La guía documenta cuatro escenarios para el reenvío con la misma `Referencia`, que
confirman el diseño de HU-4:

| Escenario | Situación | Resultado |
|---|---|---|
| 1 | Misma referencia y **mismos valores** | Devuelve la misma respuesta original |
| 2 | Misma referencia, contingencia, mismos valores | Anula el primero y registra el segundo |
| 3 | Misma referencia, **valores alterados** | Rechaza: posible duplicación |
| 4 | Contingencia con valores alterados | Rechaza: posible duplicación |

El escenario 3 explica por qué el reintento **nunca reconstruye** el XML: si
cambia un céntimo, el proveedor lo trata como otro documento y lo rechaza.

### Notas de operación

- **`soap.wsdl_cache_dir = /tmp`**: XAMPP define una ruta de Unix. El cliente la
  redirige a `sys_get_temp_dir()` antes de construir el `SoapClient`.
- **Los argumentos SOAP se pasan como array plano**, no anidados en una lista.
  Con array anidado el proveedor responde `ORA-01403` en lugar de procesar la
  operación.
- **Los mensajes de excepción se truncan a 400 caracteres.** El XML firmado
  ocupa decenas de kilobytes y llenaría el log; el texto íntegro queda
  disponible en `rawResult()`.
- **Ninguna credencial aparece en los mensajes**, ni en desarrollo. Un `SoapFault`
  puede incluir parte de la petición enviada, así que todo texto pasa por
  `GuatefacturasException::scrub()`.

---

## 15. Notas de implementación

Formato obligatorio al entregar una historia de usuario. Un bloque **por HU**,
nunca un acumulativo al final: con diez historias, un bloque único deja de ser
revisable.

```markdown
## HU-x — Título de la historia

**Criterios de aceptación cubiertos**

| CA | Verificación | Dónde |
|----|--------------|-------|
| CA-1.1 | `400` con detalle en `errors.title` | `ItemController::create()` + petición Postman «400 · title ausente» |

**Archivos creados o modificados**

- `app/Controllers/XController.php` — nuevo
- `app/Repositories/XRepository.php` — nuevo
- `sql/migrations/001_x.sql` — nuevo
- `app/bootstrap.php` — modificado: 3 rutas nuevas (líneas 120-122)

**Decisiones y su motivo**

- **Por qué se eligió X**: alternativa descartada y por qué.
- **Qué supuesto se hizo** sobre el requerimiento, por si necesita revisión.

**Desviaciones respecto a la especificación**

- Ninguna. | Incluya siempre la línea, aunque diga «Ninguna».

**Pendientes o riesgos**

- Lo que quede fuera de alcance y afecte a la HU siguiente.
```

Reglas: cada CA debe apuntar a una verificación **ejecutable** (un nombre de
petición Postman, un `php -l`, una consulta SQL); una HU sin pruebas es una HU
sin criterio de aceptación.

### HU-1 · Cliente SOAP y configuración

**Objetivo.** Fundar la integración con GUATEFACTURAS: resolución de
credenciales, cliente SOAP de las tres operaciones y digest de la respuesta.
No incluye base de datos ni endpoints.

**Archivos creados**

- `config/config.local.example.php` — plantilla de credenciales (versionada)
- `config/config.local.php` — credenciales reales (excluido, no versionado)
- `app/Integrations/Guatefacturas/GuatefacturasClient.php`
- `app/Integrations/Guatefacturas/GuatefacturasConfig.php`
- `app/Integrations/Guatefacturas/GuatefacturasException.php`
- `app/Integrations/Guatefacturas/ConfigurationException.php`
- `app/Integrations/Guatefacturas/CommunicationException.php`
- `app/Integrations/Guatefacturas/ProviderBusinessException.php`
- `app/Integrations/Guatefacturas/DocumentResult.php`
- `app/Integrations/Guatefacturas/DteType.php`
- `tests/integration_guatefacturas.php`

**Archivos modificados**

- `config/config.php` — sección `fel` por entorno + fusión de la capa local
- `app/Core/AppConfig.php` — `getGuatefacturasConfig()`
- `tests/pure_units.php` — 31 aserciones de integración sin red
- `tests/guard_confidentialidad.php` — verificación de la plantilla

**Hallazgos que cambian el diseño**

| # | Hallazgo | Origen | Impacto |
|---|---|---|---|
| 1 | El XML real exige `<TAG1>` envolviendo a `<Detalles>` | `generaDocumento` con XML del manual responde `ERROR el TAG1 Detalles no existe` | El `XmlBuilder` de HU-3 **no** puede seguir el manual |
| 2 | El binding es SOAP **1.1**, no 1.2 | Namespace `wsdl/soap/` en el WSDL | Se corrigió el plan, que asumía 1.2 por el nombre `webservices63` |
| 3 | Los errores llegan con **HTTP 200** | Respuestas observadas | El éxito no se deduce del código de estado, sino del contenido de `<result>` |
| 4 | La idempotencia por `Referencia` **funciona** | 3 llamadas idénticas → misma serie, preimpreso y autorización | HU-04 se apoya en un mecanismo real, no en una suposición del manual |
| 5 | El retorno es `string`, no array | `__getFunctions()` del WSDL | Evita el error «Array to string conversion» |
| 6 | Los argumentos van en array **plano** | Con array anidado responde `ORA-01403` | Error silencioso: el proveedor no procesa la operación |
| 7 | `soap.wsdl_cache_dir = /tmp` | php.ini de XAMPP | El cliente lo redirige a `sys_get_temp_dir()` |
| 8 | En modo detalle los datos van en **atributos** XML | `<dte:NumeroAutorizacion Serie="…" Numero="…">` | `DocumentResult` necesita una segunda ruta de extracción |

**Criterios de aceptación cubiertos**

| CA | Verificación | Dónde |
|---|---|---|
| Ejecuta `generaDocumento` y devuelve `<result>` sin procesar | aserción «la respuesta no está vacía» | `integration_guatefacturas.php` |
| Credenciales solo por variable de entorno o archivo local | 6 tests de precedencia y ausencias | `pure_units.php` |
| URL por entorno | «apunta al sandbox en local» | `integration_guatefacturas.php` |
| Tiempos de espera configurables | «timeout de conexión > 0», «nunca son cero» | ambas suites |
| Fallo de red produce `502` sin filtrar datos | 4 tests de enmascarado de secretos | `pure_units.php` |
| Ninguna credencial en los mensajes, ni en desarrollo | «el error NO contiene credenciales» | `integration_guatefacturas.php` |
| Mensaje de error acotado | «el mensaje de la excepción está acotado» | `integration_guatefacturas.php` |
| WSDL válido al arrancar | emisión real de documento | `integration_guatefacturas.php` |

**Decisiones y su motivo**

- **Credenciales en `config/config.local.php` y no solo en variables de
  entorno.** El hosting del cliente no da acceso a `php.ini` ni a la
  configuración de Apache, luego las variables de entorno no son una opción
  viable allí. Un archivo PHP con un único `return` no imprime nada si se
  solicita por HTTP, a diferencia de un `.env`, que Apache serviría en texto
  plano.
- **`<Reversion>` no se expone al POS.** El manual exige reenviar una copia
  exacta del XML con ese campo cambiado; construirlo desde el JSON en cada
  reintento no garantiza identidad byte a byte. Se guardará el XML original en
  la primera emisión (HU-3) y se reenviará tal cual (HU-4).
- **No se distingue fallo limpio de fallo ambiguo.** `Referencia` cubre ambos:
  el proveedor reconoce la referencia repetida y devuelve el documento ya
  emitido. Distinguirlos no aporta seguridad, solo complejidad.

**Desviaciones respecto a la especificación**

- Se asume SOAP 1.1 y el nivel `<TAG1>` adicionalmente al manual, porque el
  manual describe una implementación que ya no es la desplegada. Documentado en
  README §14.

**Pendientes o riesgos**

- La exención de IVA sigue en `0` a la espera de que el cliente confirme si la
  clínica aplica exención por servicios de salud. No bloquea HU-3: las cuatro
  combinaciones de IVA se prueban con datos sintéticos.
- Los documentos emitidos durante las pruebas (`SELFTEST-*`) quedan en el
  sandbox y deben anularse manualmente antes de una demostración al cliente.

---

### HU-2 · Transformación a XML, envío SOAP y respuesta exitosa

**Objetivo.** Convertir el JSON del POS en el documento XML que exige el
proveedor, enviarlo por SOAP y registrar la autorización. Cubre CA2.1, CA2.2 y
CA2.3.

**Archivos creados**

- `sql/migrations/001_gf_orders.sql` — tabla `gf_orders`
- `app/Integrations/Guatefacturas/Decimal.php` — aritmética entera exacta
- `app/Integrations/Guatefacturas/OrderLine.php` — línea con sus importes
- `app/Integrations/Guatefacturas/Totals.php` — totales con invariantes
- `app/Integrations/Guatefacturas/OrderPayload.php` — contrato de entrada
- `app/Integrations/Guatefacturas/XmlBuilder.php` — CA2.1
- `app/Models/GfOrder.php` — entidad y máquina de estados
- `app/Repositories/GfOrderRepository.php` — persistencia
- `app/Services/FacturacionService.php` — CA2.2 y CA2.3
- `app/Controllers/FacturacionController.php` — endpoint

**Archivos modificados**

- `config/config.php` — sección `billing`
- `app/Core/AppConfig.php` — `getBillingConfig()`
- `app/Integrations/Guatefacturas/GuatefacturasClient.php` — detección de errores
- `app/bootstrap.php` — cableado y rutas
- Las cinco suites de prueba

**Hallazgos que cambian el diseño**

| # | Hallazgo | Origen | Impacto |
|---|---|---|---|
| 9 | El precio debe ser **sin IVA** | Documento con precio `112.00` e IVA de `12.00` rechazado con el error 82 | El POS debe enviar importes netos |
| 10 | Cada línea es su **propio `<Productos>`** | Un solo `<Productos>` con dos líneas da `ORA-01400 ID_UNIDAD` | Estructura distinta a la que sugiere el nombre en plural |
| 11 | La fecha es **local**, no UTC | `56-FECHA DE DOCUMENTO NO PUEDE SER MAYOR A LA FECHA DEL SISTEMA` | Entre 18:00 y 24:00 se rechazaban todas las facturas |
| 12 | El sandbox **limita el ritmo** | Ráfaga de emisiones → error 82 en documentos válidos | La suite funcional no puede llamar al proveedor |
| 13 | Los errores pueden no llevar `ERROR` | `56-FECHA DE DOCUMENTO...` no contiene la palabra | La detección se amplía a `^\d{1,3}-\S` |

**Criterios de aceptación cubiertos**

| CA | Verificación | Dónde |
|----|--------------|-------|
| CA2.1 | 17 aserciones de `Decimal`, `OrderLine`, `Totals` y `XmlBuilder` | `pure_units.php` |
| CA2.1 | «el constructor produce XML válido», «cada línea es su propio `<Productos>`» | `pure_units.php`, `integration_guatefacturas.php` |
| CA2.2 | Emisión real contra el sandbox con el XML de producción | `integration_guatefacturas.php` |
| CA2.3 | «una orden ya FACTURADA no se vuelve a facturar → 409», «la orden queda FACTURADA» | `http_matrix.php`, `integration_guatefacturas.php` |

**Decisiones y su motivo**

- **Aritmética entera en vez de `float`.** El proveedor recalcula y rechaza
  con el error 82 cualquier diferencia de un céntimo. La comprobación se hizo
  porque `0.29 * 100` en PHP no es `29`.
- **La orden se guarda completa como JSON.** Reconstruirla desde columnas
  desnormalizadas cambiaría el redondeo y rompería la idempotencia del reintento.
- **El XML se persiste antes de enviarlo.** Es el insumo del reintento con
  `<Reversion>` y de la anulación.
- **`Isr` se informa en cero.** Se comprobó que el proveedor resta la retención
  desde `Otros`, no desde `Isr`.
- **No se distingue fallo limpio de fallo ambiguo todavía.** Ambos dejan la
  orden en `ERROR_PROVEEDOR` y devuelven el UUID al POS. La resolución llega en
  HU-4 mediante la idempotencia de `<Referencia>`.

**Pendientes o riesgos**

- **El error 82 no distingue un cálculo incorrecto de una emisión throttled.**
  Mientras no se resuelva, la API puede informar de un rechazo donde en realidad
  solo había límite de ritmo. HU-4 debe consultar la `<Referencia>` antes de
  reintentar y aplicar espera progresiva.
- **El esquema JSON fue reconstruido, no confirmado.** El acuerdo previo existió
  solo en la conversación y no quedó escrito en ningún archivo. Está documentado
  en README §14, pero conviene contrastarlo con una orden real del POS.
- **La exención se implementa según la especificación de la SAT**
  (`ImpExento = ImpNeto`, `ImpIva = 0`), que coincide con la forma que el
  proveedor aceptó. No se pudo revalidar por el límite de emisiones.
- **La factura especial (tipo 5) no es utilizable** con esta cuenta: el
  proveedor responde `ORA-01400 NIT_VENDEDOR` porque falta configurar el
  catálogo de ventas especiales.

---

### HU-3 · Respuesta al POS y contingencia por fallo del proveedor

**Objetivo.** Devolver al POS una respuesta que diga la verdad sobre lo ocurrido,
y que le permita continuar trabajando tanto si la factura se emitió como si el
proveedor estaba caído. Cubre CA3.1, CA3.2 y CA3.3.

**Archivos creados**

- `app/Integrations/Guatefacturas/DocumentEmitter.php` — contrato de emisión
- `app/Integrations/Guatefacturas/ProviderResponseClassifier.php` — clasificación de desenlaces
- `sql/migrations/002_gf_orders_contingencia.sql` — columnas `ambiguous` y `last_error_code`
- `tests/contingencia.php` — matriz de fallos sin red

**Archivos modificados**

- `app/Integrations/Guatefacturas/GuatefacturasClient.php` — implementa el contrato y clasifica los `SoapFault`
- `app/Integrations/Guatefacturas/CommunicationException.php` — nuevo tipo `rate_limit`
- `app/Models/GfOrder.php` — estado `PENDIENTE_REINTENTO`, ambigüedad y recuperación de atascos
- `app/Repositories/GfOrderRepository.php` — estado explícito y columnas nuevas
- `app/Services/FacturacionService.php` — decisión de estado, contexto y tope de intentos
- `app/Core/Response.php` — bloque `contexto` en las respuestas de error
- `app/Controllers/FacturacionController.php` — contrato documentado
- `config/config.php` — `processing_timeout` y `max_attempts`

**El defecto que introduce esta historia**

No era código ausente, sino código que hacía lo contrario de lo que decía. Una
línea en `invoke()` traducía **todos** los `SoapFault` a
`ProviderBusinessException`, y PHP lanza `SoapFault` por igual ante un rechazo del
emisor y ante un `502` de una pasarela caída:

| Situación | Antes | Ahora |
|-----------|-------|-------|
| `502` o tiempo de espera agotado | `422` «el proveedor rechazó la factura» | `503` + UUID reintentable |
| `<Resultado></Resultado>` vacío | `422` «rechazó» | `503` + UUID reintentable |
| Error `82` (puede ser throttling) | `422` «rechazó» | `503` + UUID reintentable |
| `56-FECHA…` o `ERROR el TAG1…` | `422` «rechazó» | `422` + UUID |

El vendedor veía su venta rechazada por un servicio caído, revisaba los datos
del cliente, no encontraba el error y perdía la venta. Además el sistema no
tenía nada que reintentar, porque se le había dicho que el documento era
inválido.

**Criterios de aceptación cubiertos**

| CA | Verificación | Dónde |
|----|--------------|-------|
| CA3.1 | «el digest expone la serie», «declara el documento autorizado», «incluye un resumen imprimible», «no inventa guiones en la autorización» | `contingencia.php` |
| CA3.2 | 5 modos de fallo × 6 aserciones cada uno: código HTTP, mensaje que no acusa al documento, estado persistido, `reintentable`, UUID e indicador de ambigüedad | `contingencia.php` |
| CA3.3 | «el UUID del contexto es un UUID v4 válido», «el POS puede consultar la orden por UUID», «el reintento reutiliza el MISMO UUID», «el 409 incluye el contexto con su UUID» | `contingencia.php`, `http_matrix.php` |

**Decisiones y su motivo**

- **El estado lo decide el servicio, no el repositorio.** `PENDIENTE_REINTENTO`
  con `ambiguo = true` y `PENDIENTE_REINTENTO` con `ambiguo = false` son fallos
  distintos que comparten estado, así que el estado no se puede deducir de la
  ambigüedad. Lo sabe quien conoce la causa.
- **Se añadió `DocumentEmitter`.** Sin una interfaz, `GuatefacturasClient` es
  `final` y la única forma de probar un fallo del proveedor sería esperar a que
  se caiga. La matriz completa de contingencias se ejecuta ahora en milisegundos
  y sin gastar emisiones.
- **El UUID va como campo, no en el mensaje.** CA3.3 pide que el POS pueda
  localizar la operación; parsear un mensaje no es un contrato.
- **El número de autorización no se reformatea.** No hay una convención
  verificable a la que atenerse, y un número inventado es peor que ninguno.
- **`ERROR_PROVEEDOR` también es reintentable.** El nombre sugiere lo contrario,
  pero reenviar el mismo XML con la misma `Referencia` no puede duplicar la
  factura.

**Pendientes o riesgos**

- **La consulta por `<Referencia>` antes de reenviar es HU-4.** Ahora un fallo
  ambiguo se marca como tal y el POS puede reenviar, pero lo prudente es
  preguntar primero al proveedor. Sin eso, un documento emitido cuya respuesta se
  perdió solo se recupera cuando el POS reintenta.
- **El `503` por credenciales ausentes es ambiguo en apariencia, pero no lo
  es.** Está persistido como `ambiguo = false` porque no se llegó a emitir nada.
  El POS ve el mismo código HTTP que una caída y debe leer `ambiguo` para
  distinguirlos.
- **La recuperación de órdenes atascadas depende del reloj.** Si el reloj de la
  aplicación y el de la base de datos no coinciden,
  `processing_timeout` se calcula mal. Por eso las marcas de tiempo van
  siempre en UTC desde la aplicación, nunca desde `NOW()`.

---

### HU-4 · Reintento seguro y resolución de desenlaces ambiguos

**Objetivo.** Poder reenviar una orden cuyo resultado quedó incierto, reutilizando
el documento exacto ya enviado, para resolver el desenlace sin duplicar la factura.

**Aviso de origen.** Esta historia **no figura en el documento de historias**
entregado por el cliente, que termina en HU-03. Fue derivada de los hallazgos de
HU-03 y se anotó en `docs/historias_de_usuario_api_de_facturacion_pos.md` con sus
criterios de aceptación, pendiente de validación con el cliente.

**Archivos creados**

- `app/Services/RetryPolicy.php` — espera progresiva y motivos de bloqueo
- `app/Services/ReintentoService.php` — reenvío y resolución del desenlace

**Archivos modificados**

- `app/Controllers/FacturacionController.php` — endpoint de reintento
- `app/Core/Response.php` — cabecera `Retry-After`
- `app/Services/FacturacionService.php` — la consulta expone la situación de reintento
- `app/bootstrap.php` — cableado y ruta
- `tests/contingencia.php` — 62 aserciones nuevas

**El hallazgo que cambió el diseño**

Al preparar la historia comprobé el WSDL del proveedor, y expone **solo dos
operaciones**:

```
- string generaDocumento(...)
- string anulaDocumento(...)
```

**No hay `consultaDocumento`.** Durante HU-02 y HU-03 se dejó anotado que «HU-4
debe consultar la `<Referencia>` antes de reenviar». Eso estaba asumido y no
verificado, y no tiene operación que lo respalde.

Lo que sí está verificado, desde la integración de HU-01, es que **reenviar el
mismo XML con la misma `Referencia` devuelve el documento ya emitido**. Es decir:
el reenvío *es* la consulta. La historia se escribió sobre esa base, no sobre la
suposición anterior.

**Criterios de aceptación cubiertos**

| CA | Verificación | Dónde |
|----|--------------|-------|
| CA4.1 | «el reintento envía el XML exactamente como se guardó», «conserva la Referencia original» | `contingencia.php` |
| CA4.2 | «el proveedor devuelve la misma serie», «no se crea una segunda factura» | `contingencia.php` |
| CA4.3 | «la ambigüedad queda resuelta», «conserva el mismo UUID» | `contingencia.php` |
| CA4.4 | «un reintento antes de tiempo responde 429», «la espera es monótona creciente» | `contingencia.php`, `http_matrix.php` |
| CA4.5 | `FACTURADO`, `ANULADO` y `EN_PROCESO` bloqueados; la atascada sí pasa | `contingencia.php`, `http_matrix.php` |
| CA4.6 | «el 409 pide revisión manual», «no quedan intentos» | `contingencia.php`, `http_matrix.php` |
| CA4.7 | «el 409 indica dónde reenviarla» | `contingencia.php`, `http_matrix.php` |
| CA4.8 | El fallo del reintento se persiste con su código y su ambigüedad | `contingencia.php` |
| CA4.9 | «la consulta incluye la situación de reintento» | `http_matrix.php` |

**Decisiones y su motivo**

- **Reenviar el XML almacenado, nunca reconstruirlo.** Es el criterio CA4.1 y la
  razón por la que la columna `sent_xml` existía desde HU-02.
- **La espera se mide desde `updated_at`, no desde el primer envío.** Medirla
  desde el envío daría por satisfecha una espera que en realidad no ha empezado.
- **`Retry-After` además del campo en el cuerpo.** El estándar la define para
  esto y cualquier cliente automático la respeta. El POS lee el campo para pintar
  un temporizador; la cabecera evita que un reintento automático golpee antes.
- **La política vive en su propia clase.** Tiene tres entradas —estado, intentos y
  antigüedad— y gobierna seis salidas. Mezclada en el servicio sería imposible de
  probar sin base de datos.

**Pendientes o riesgos**

- **HU-4 no cubre la anulación.** `anulaDocumento()` está implementado en el
  cliente y sin usar; es la historia siguiente más natural.
- **Un reintento sincrónico y bloqueante.** Con la espera aplicada, cada reintento
  puede tardar hasta 90 segundos (el `response_timeout` de producción). Si el POS
  reintenta desde un dispositivo con timeout corto, la conexión se caerá antes de
  tiempo. Conviene que el cliente use un timeout holgado o mover el reintento a un
  proceso programado.
- **La espera progresiva es por orden, no global.** Dos órdenes distintas pueden
  reintentarse a la vez y volver a chocar con el límite de emisiones del entorno.

---

### HU-5 · Anulación de facturas emitidas

**Objetivo.** Poder anular una factura ya autorizada, indicando el motivo y
dejando constancia, para corregir documentos erróneos.

**Aviso de origen.** Igual que HU-04, no figura en el documento original: se deriva de
`anulaDocumento()`, la única operación del proveedor que quedó implementada sin usar.

**Archivos creados**

- `app/Services/AnulacionService.php`
- `sql/migrations/003_gf_orders_anulacion.sql`

**Hallazgos verificados contra el proveedor**

No se programó contra el manual sino contra el servicio real:

| Comportamiento | Cómo se descubrió |
|---|---|
| La fecha solo admite `AAAAMMDD` | Se probaron los tres formatos contra un serie inexistente y solo uno pasó la validación |
| El NIT del comprador es obligatorio | Vacío produce `ORA-01400` |
| El motivo admite **exactamente 729** caracteres | Bisección entre 725 y 730, tras un `ORA-06502` |
| Un documento inexistente responde sin código ni `ERROR` | Anulación contra un serie inventada |

**El fallo que esta historia evita**

`NO EXISTE EL DOCUMENTO QUE SE DESEA ANULAR` no lleva código numérico ni la palabra
`ERROR`. Las reglas de detección de la emisión lo habrían clasificado como
**anulación correcta**, y la orden habría quedado marcada `ANULADO` mientras el
proveedor decía que ese documento no existe. Un fallo silencioso que además falsifica
un registro fiscal.

**Criterios de aceptación cubiertos**

| CA | Verificación | Dónde |
|----|--------------|-------|
| CA5.1 | Tres estados no facturables rechazados sin llamar al proveedor | `contingencia.php`, `http_matrix.php` |
| CA5.2 | La segunda anulación da `409` sin consumir una llamada | `contingencia.php` |
| CA5.3 | Cinco motivos inválidos rechazados; el límite propio está bajo el del proveedor | `contingencia.php`, `http_matrix.php` |
| CA5.4 | Serie, preimpreso y NIT salen del registro, ignorando los campos impuestos | `contingencia.php`, `http_matrix.php` |
| CA5.5 | La fecha va en `AAAAMMDD` y es la local del emisor | `contingencia.php` |
| CA5.6 | Los cuatro rechazos sin código se detectan y no pasan por éxito | `contingencia.php` |
| CA5.7 | Constancia con motivo, fecha y respuesta íntegra | `contingencia.php` |
| CA5.8 | Una orden anulada no se vuelve a facturar | `contingencia.php` |

**Decisiones y su motivo**

- **El tope del motivo es 500, no 729.** Es un número redondo y deja margen. Depender
  del límite ajeno sería depender de un comportamiento que además falla con una
  excepción de Oracle en vez de un mensaje de negocio.
- **Un fallo de red no cambia el estado.** La factura sigue vigente; marcarla
  anulada sería mentir sobre un documento que sigue siendo válido ante la SAT.
- **El cliente del proveedor valida antes de llamar.** Fecha, NIT y longitud del
  motivo se comprueban en `anulaDocumento()` para no gastar llamadas en errores que
  ya se conocen.

**Pendientes o riesgos**

- **La respuesta de anulación correcta no se pudo observar.** El entorno de pruebas
  agotó su cupo de emisiones durante el desarrollo, y anular exige un documento
  emitido. `isAnnulmentRejection()` decide **por descarte**: solo considera rechazo
  lo que coincide con patrones negativos conocidos. En cuanto se confirme la forma
  del mensaje de éxito, lo correcto es exigirla de forma positiva.
- **El cupado del sandbox parece diario, no por minuto.** Cinco intentos espaciados
  80 s fallaron todos con el error 82, frente a los 75 s que bastaban en HU-02. Una
  prueba A/B descartó que fuera un error de cálculo: ambas variantes fallaron igual.
- **La anulación no genera notificación al cliente ni ajuste de stock.** Fuera del
  alcance de esta historia, pero cualquier flujo que reste inventario debería
  enterarse del cambio de estado.

---

## 16. Notas de mantenimiento

- **Añadir un recurso** (por ejemplo `patients`): crear `Model`, `Repository`,
  `Service` y `Controller` siguiendo el patrón de `Item`; declarar la tabla en
  `sql/schema.sql` (si es nueva) o en `sql/migrations/` (si ya existe); registrar
  las rutas en `app/bootstrap.php`.
- **Cambiar a un UUID no v4**: `Uuid::isValidV4()` y el patrón `{uuid}` del router
  son los dos únicos puntos a tocar.
- **Auditoría de escrituras**: actualmente solo existe `created_at`. Añadir
  `updated_at` obliga a tocar el esquema, `Item::fromDb()`, `toArrayPublic()` y
  `ItemRepository::updateByUuid()`.
- **Logout de sesión**: HTTP Basic Auth no admite cierre de sesión. Para
  aplicaciones web reales, migrar a tokens de corta duración (Bearer) o a
  autenticación por cookie de sesión.
