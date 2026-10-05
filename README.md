# Deploy Tool

Herramienta local de deploy Angular/Laravel por FTP/FTPS, con Laravel, Angular y MySQL.

## Requisitos

- PHP 8.3 o superior (requisito de Laravel 13 instalado), con `ftp`,
  `openssl`, `pdo_mysql`, `fileinfo` y `mbstring` activadas.
- Composer 2.
- Node compatible con Angular 21 (por ejemplo Node 24.11.1) y npm.
- MySQL/MariaDB iniciado, con la base `deploy_tool` creada con
  `utf8mb4` / `utf8mb4_unicode_ci`.

Verificar con `php -v`, `php -m`, `composer -V` y `node -v`.
Angular CLI se usa desde las dependencias locales, no requiere instalacion global.

## Preparacion

Desde `backend`:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
```

**Si `.env` ya existe, no lo sobrescribas ni regeneres su `APP_KEY`.**
Configura `DB_USERNAME` y `DB_PASSWORD` en `.env` con tus datos locales.
La configuracion prevista usa `DB_CONNECTION=mysql`, `DB_DATABASE=deploy_tool`,
`QUEUE_CONNECTION=database` y `DB_QUEUE_RETRY_AFTER=3700`.
Conserva una copia privada de `.env` fuera de git: cambiar `APP_KEY` impedira
descifrar las contrasenas FTP guardadas.

```powershell
php artisan config:clear
php artisan migrate
```

Las rutas API y Sanctum ya estan instalados. Las migraciones incluyen las
tablas de sesiones, cache y colas (`jobs`, `job_batches`, `failed_jobs`).
La base local existente usa `utf8mb4_0900_ai_ci`; se conserva sin modificar.
La configuracion de Laravel usa `utf8mb4_unicode_ci` para las nuevas tablas.

Desde `frontend`:

```powershell
npm ci
npm run build
```

## Uso local

Con MySQL funcionando, ejecuta `iniciar.bat` en la raiz. Abre dos ventanas:

- Laravel: `http://127.0.0.1:8000`, accesible solo desde esta PC.
- Worker: `php artisan queue:work --timeout=3660 --tries=1`.

El navegador se abre en `http://127.0.0.1:8000/app/` cuando Laravel responde.
Para detener la herramienta, usa Ctrl+C en ambas ventanas.
El lanzador no compila la interfaz ni instala dependencias: vuelve a ejecutar
`npm run build` despues de modificar Angular.

## Angular servido por Laravel

En [angular.json](frontend/angular.json), el build de produccion usa:

```json
"outputPath": { "base": "../backend/public/app", "browser": "" },
"baseHref": "/app/"
```

`browser: ""` evita una subcarpeta `browser`: el HTML queda directamente en
`backend/public/app/index.html`. Nunca uses `backend/public` como salida;
Angular limpia la carpeta de salida y podria borrar el `index.php` de Laravel.
`/` redirige a `/app/`, y las rutas internas `/app/...` sirven el mismo HTML.
Los archivos estaticos los sirve directamente `php artisan serve`.
Si falta el build, Laravel devuelve HTTP 503 con un mensaje explicito.
El router [server.php](backend/server.php), que `artisan serve` detecta
automaticamente, normaliza el script de entrada antes de usar el router de
Laravel. Esto evita que PHP interprete `app/index.html` como el script base
y que las rutas internas de Angular devuelvan 404 en Windows.

## Desarrollo y validacion

En una terminal dentro de `backend`:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

En otra dentro de `frontend`:

```powershell
npm start
```

Abre `http://localhost:4200/`. La configuracion de desarrollo usa `baseHref: "/"`
y [proxy.conf.json](frontend/proxy.conf.json) redirige `/api` a Laravel.
Usa URLs relativas `/api/...` para que tambien funcionen en produccion sin CORS.
Para los builds, inicia tambien el worker indicado arriba.

Pruebas de la integracion (desde `backend`):

```powershell
php artisan test --filter="ExampleTest|AngularRoutingTest"
```

Build de produccion (desde `frontend`): `npm run build`.
El codigo fuente esta separado de la interfaz compilada, que se excluye de git.

## Servidores (etapa 1)

La pantalla `/app/servers` permite crear, editar, eliminar y probar conexiones.
Usa el host sin protocolo ni ruta, un puerto entre 1 y 65535, el usuario FTP
y una carpeta remota absoluta, por ejemplo `/public_html`.
Usa el host FTP indicado por el proveedor (por ejemplo `ftp.tusitio.com`),
no necesariamente el dominio del sitio web. Si el dominio web esta detras
del proxy habitual de Cloudflare, el puerto FTP no pasa por ese proxy:
usa el host FTP o la IP real del hosting. No cambies el DNS del sitio para esto.
FTPS explicito y modo pasivo vienen activados por defecto. FTP sin TLS
transmite las credenciales sin cifrar; la interfaz advierte al desactivarlo.

Las contrasenas usan el cast `encrypted` de Laravel y nunca aparecen en el
listado ni en las respuestas API. Al editar, deja el campo vacio para conservar
la contrasena actual; escribe una nueva solo si queres reemplazarla.
Si cambia `APP_KEY`, la prueba muestra un error explicito: restaura la clave
original o guarda una contrasena nueva.

**Probar conexion** conecta, inicia sesion, configura el modo de transferencia,
entra en la carpeta remota y lista sus entradas, sin subir ni eliminar archivos.
Ignora la IP anunciada por PASV y usa la del host conectado para soportar
servidores con NAT; esa opcion se aplica antes de `ftp_pasv`.
La conexion tiene timeout de 10 segundos para las operaciones FTP. La prueba
es sincrona y puede bloquear brevemente otras solicitudes al servidor local;
los builds usan colas y los deploys se implementaran en la etapa 5.

API local (respuestas de datos envueltas en `data`):

| Metodo | Ruta | Accion |
|---|---|---|
| GET / POST | `/api/servers` | Listar / crear |
| GET / PUT / PATCH / DELETE | `/api/servers/{id}` | Ver / editar / eliminar |
| POST | `/api/servers/{id}/test` | Probar FTP/FTPS |

La validacion devuelve HTTP 422; un servidor inexistente, 404; una prueba de
conexion fallida, 502 con un mensaje sin credenciales. La eliminacion solicita
confirmacion y solo elimina la configuracion local, no archivos del hosting.
La API se usa sin autenticacion porque la herramienta es personal:
mantene Laravel escuchando en `127.0.0.1`, nunca en `0.0.0.0`.

Pruebas de servidores (desde `backend`):

```powershell
php artisan test --filter="ServerApiTest|FtpServiceTest"
```

Pruebas de Angular (desde `frontend`):

```powershell
npm test -- --watch=false
```

Las pruebas PHP usan SQLite en memoria, no borran datos de MySQL ni contactan
servidores reales. Se verifico ademas el flujo completo con un fixture FTP local,
incluyendo una IP PASV privada. El usuario tambien confirmo la conexion y el
listado de `/public_html` en su hosting real usando el host FTP del proveedor.

## Proyectos y configuracion (etapa 2)

1. Abre **Proyectos** (`/app/projects`) y pulsa **Buscar proyectos**.
2. Elige **Configurar** en la aplicacion detectada.
3. Revisa el nombre, carpeta local, salida de build, comando y patrones ignorados.
4. Opcionalmente asocia servidores, con un entorno (`production`, `staging`, etc.)
   y una ruta remota alternativa. Una ruta vacia usa la del servidor.
5. Pulsa **Agregar a mis proyectos**. Luego podras editar o eliminar el registro.

## Builds locales (etapa 3)

Desde **Mis proyectos**, pulsa **Preparar deploy**, o abre `/app/builds`.
Selecciona el proyecto y, opcionalmente, un servidor asociado. El servidor
no se contacta y no es necesario para compilar.

- Revisa el comando y confirma que es de confianza antes de ejecutarlo.
  Se ejecuta en la carpeta del proyecto y puede borrar/regenerar la salida local.
- Laravel sin comando muestra **Continuar sin build**; no ejecuta Angular ni
  genera un build ficticio. Un comando Laravel como `npm run build` es opcional.
- Angular permite omitir el build para reutilizar archivos existentes, sin
  afirmar que esos archivos fueron verificados o publicados.
- La API devuelve HTTP 202 inmediatamente. El worker procesa el comando;
  la interfaz consulta estado y log cada 1,5 segundos y muestra su duracion.
- Cerrar la pagina no cancela el build. Al seleccionar el proyecto de nuevo
  se recupera su ultimo job. Si falla la consulta, usa **Reanudar seguimiento**.
- Un job **En cola** espera al worker: reinicia las ventanas de `iniciar.bat`
  despues de esta actualizacion. Los workers son procesos de larga vida.
- El proceso tiene timeout de 3600 segundos; el job/worker, 3660; `retry_after`,
  3700. El margen permite detener el proceso y guardar el fallo antes del timeout
  del worker. No bajes `DB_QUEUE_RETRY_AFTER` por debajo de 3660.
- Se capturan stdout y stderr, con guardado periodico y log limitado a 2 MB.
  `npx` al inicio del comando usa la ruta de Configuracion, incluida `npx.cmd`
  con espacios. Los comandos personalizados se conservan.
- No se permiten dos builds activos del mismo proyecto ni eliminarlo mientras
  tenga un build activo. Los comandos/rutas se guardan como una instantanea;
  editar opciones no cambia un job ya encolado.
- Un build Angular con codigo cero solo es exitoso si existe `index.html` en
  su salida configurada. La seleccion esta en el paso 3; la publicacion se confirma
  en el paso 4.
- No incluyas secretos en los comandos ni los imprimas: stdout/stderr quedan
  guardados en la base local.

| Metodo | Ruta | Accion |
|---|---|---|
| POST | `/api/projects/{id}/build` | Crear build en cola |
| GET | `/api/builds/{id}` | Estado, log, duracion y codigo de salida |
| GET | `/api/projects/{id}/builds/latest` | Recuperar ultimo build o `data: null` |

Estados: `queued`, `running`, `success`, `failed`. Conflictos de builds activos
devuelven HTTP 409; sin comando o cola no configurada, HTTP 422.
Las pruebas dirigidas son `php artisan test --filter=BuildTest` y
`npm test -- --watch=false --include=src/app/builds/builds.spec.ts`.

## Seleccion de archivos (etapa 4)

En **Preparar deploy**, elige un servidor asociado. Despues de un build exitoso
o de pulsar **Omitir build / Continuar sin build**, usa **Cargar archivos**
en el paso 3. Si la salida Angular no existe, compila primero o corrige su ruta.

- Arbol con casillas por archivo y carpeta. Las carpetas se expanden bajo demanda
  para no renderizar miles de entradas de `vendor` de una vez.
- Por defecto se seleccionan **solo nuevos/cambiados**. Tambien podes elegir
  **Todos los publicables**, **Ninguno**, o marcar archivos individuales.
- Se muestran cantidad, tamanio total y destinos de los archivos seleccionados.
  Las carpetas vacias no se publican.
- La comparacion es SHA-1 contra los archivos subidos en deploys **exitosos**
  del mismo proyecto, servidor y rutas de destino, no contra FTP. Las subidas
  parciales conservan la ultima referencia exitosa de cada archivo no actualizado;
  fallidos, omitidos y rollbacks no crean referencias nuevas.
- Sin historial exitoso para ese destino, todos son nuevos. Cambiar el destino
  reinicia la referencia. El manifiesto tambien informa `obsolete_files`: rutas
  subidas anteriormente por Deploy Tool que ya no existen en el origen local.
- Angular usa la salida configurada. Laravel usa su raiz; `vendor` se incluye
  salvo exclusion personalizada. Con public separado, `public/index.php` va al
  destino publico como `index.php`, mientras el backend mantiene su propia ruta.
  Antes de publicar habra que revisar las rutas de autoload/bootstrap de ese
  `index.php`; la herramienta no lo reescribe.
- Los patrones ignorados admiten glob (`*.map`, `tests`, `assets/private/*`);
  se aplican a rutas relativas con `/` y a nombres. No hay patrones de reinclusion.
  Una carpeta excluida no se recorre.
- Proteccion obligatoria aunque borres los patrones: `.env` / `.env.*`, repositorios
  `.git` / `.svn` / `.hg`, `node_modules`, enlaces simbolicos y junctions.
  En Laravel tambien se protegen `storage`, `public/storage`, `bootstrap/cache`
  y bases SQLite locales bajo `database` (incluidos sus archivos auxiliares).
  No se leen ni calculan hashes de estas entradas.
- **Mostrar excluidos** explica cada exclusion, sin habilitar su casilla.
  El contador cuenta carpetas excluidas como una entrada, no todos sus contenidos.
- La seleccion es temporal: cambiar proyecto/servidor, iniciar un build, cargar
  nuevamente o salir de la pantalla descarta la seleccion. No hay uploads,
  borrado remoto ni modificaciones de archivos fuente.
- Un build activo devuelve conflicto. Errores de lectura, rutas invalidas,
  archivos que cambian durante el analisis o limites del arbol se informan:
  no se presenta un resultado parcial como exitoso.
- La lectura local es sincrona, limitada a 50.000 entradas, 64 niveles y 30 segundos.
  Para origenes grandes, exclui lo innecesario; durante el analisis no modifiques
  la carpeta. Los limites de entradas/tiempo se pueden configurar en Laravel con
  `deploy.files.max_entries` y `deploy.files.timeout_seconds`.

API: `GET /api/projects/{id}/files?server_id={id}` devuelve `data` con `files`
(ruta relativa, bytes, hash, changed y remote_path), `tree` (carpetas, archivos
y motivos de exclusion), `summary`, destinos, `obsolete_files` y `last_deploy_id`.
El servidor debe estar asociado al proyecto. Los errores de validacion/lectura
devuelven HTTP 422; un build activo, 409.

Pruebas dirigidas: `php artisan test --filter=ProjectFilesTest` y
`npm test -- --watch=false --include=src/app/files/file-selection.spec.ts`.

## Deploys por FTP/FTPS (etapa 5)

En **Preparar deploy**, selecciona un servidor asociado y carga el arbol actualizado.
Escribe la version y, si quieres, las notas de cambios. Revisa la cantidad de
archivos y marca la confirmacion antes de **Iniciar deploy**. La API devuelve HTTP
202 y el worker hace la transferencia; la pantalla consulta progreso y log cada
1,5 segundos. Si vuelves a abrir el arbol, intenta recuperar un deploy que siga
activo. Un estado **En cola** espera a `php artisan queue:work --timeout=3660 --tries=1`.

- Se valida de nuevo el proyecto, servidor asociado, salida local, hashes,
  seleccion y destinos al crear el job; el job vuelve a verificar hashes y rutas
  antes de cada transferencia.
- Antes de reemplazar cualquier archivo existente, se descarga una copia local a
  `backend/storage/app/backups/{deploy_id}/files/...`. Las copias de obsoletos se
  guardan bajo `.../obsolete/...`; ambas quedan referenciadas en la base local
  para permitir rollback en la etapa 8.
- Los `index.html` e `index.php` se suben despues de los demas archivos de origen.
  Las transferencias FTP reintentan con la configuracion `ftp_retries` y
  `ftp_retry_delay_ms` y nunca escriben las credenciales en logs o respuestas.
- La limpieza de obsoletos es opcional y viene **desmarcada**. Solo considera
  rutas que esta herramienta subio exitosamente antes al mismo proyecto, servidor
  y destino y que ya no existen en el origen. Nunca borra contenido desconocido
  ni archivos que sigan presentes localmente. Antes de borrar, descarga el archivo
  y compara su SHA-1 con el ultimo registrado; si difiere, lo conserva y declara
  el motivo en el resultado.
- Para habilitar esa limpieza deben estar seleccionados todos los archivos nuevos
  o cambiados del manifiesto actual. El backend vuelve a validar esta regla; una
  limpieza sin subidas tambien se permite cuando no hay cambios y hay obsoletos.
- Al finalizar correctamente, se crea un nombre unico como
  `deploy_v1.4.2_20261004_175900.json` dentro de
  `{destino}/_deploys/`. Se agrega `Require all denied` en `_deploys/.htaccess`
  solo si ese archivo no existia.
- El JSON declara proyecto, version, fecha, servidor y destinos (sin secretos),
  notas, build y commit Git opcional; incluye `files_uploaded`, `files_deleted`,
  `files_already_absent` y `files_preserved`. La base local guarda el detalle,
  backups, estado, duracion y log del job. Una falla no publica un JSON de version
  exitoso ni cambia `projects.current_version`.
- El FTP no es atomico: si un archivo posterior falla, los archivos transferidos
  antes del error permanecen en el hosting. El job marca el deploy como fallido
  y no hace rollback automatico; revisa el log y usa backups cuando este disponible
  la etapa 8.

API:

| Metodo | Ruta | Accion |
|---|---|---|
| POST | `/api/deploys` | Validar snapshot, crear job y devolver HTTP 202 |
| GET | `/api/deploys/{id}` | Estado, progreso, log, archivos y resultado |
| GET | `/api/projects/{id}/deploys/active?server_id={id}` | Recuperar deploy activo asociado |

La cola `database` y `DB_QUEUE_RETRY_AFTER > 3660` son obligatorios para publicar.
El servidor debe estar asociado al proyecto y no puede haber otro deploy activo
del mismo proyecto/servidor. Errores de validacion devuelven HTTP 422 y conflictos
de jobs activos, HTTP 409. El historial navegable y el control de version estan
disponibles en **Historial y versiones (etapa 6)**.

Pruebas dirigidas: `php artisan test --filter="DeployApiTest|DeployJobTest|ProjectFilesTest|FtpServiceTest"` y
`npm test -- --watch=false --include=src/app/files/file-selection.spec.ts`.
El job completo se verifico con un FTP efimero en `127.0.0.1`, incluyendo backup,
`index.html` al final, limpieza y JSON de version. No se contacto el hosting real.

## Historial y versiones (etapa 6)

Abre **Historial** (`/app/history`) para consultar los deploys mas recientes,
filtrarlos por proyecto y avanzar por paginas. La lista incluye el estado,
version, servidor, fecha y conteos de archivos subidos, borrados y ya ausentes;
seleccionar una entrada carga su detalle completo con cambios, destinos,
respaldos, estados por archivo y log. Los registros conservan el nombre del
proyecto y del servidor aunque luego se elimine su configuracion.

En **Proyectos**, **Subir version...** abre una confirmacion para elegir
`patch`, `minor` o `major`. No se permite confirmar mientras haya un build o un
deploy activo:

- **Angular** ejecuta `npm version <tipo> --no-git-tag-version --ignore-scripts`
  en el workspace y sincroniza `projects.current_version` con `package.json`.
  No ejecuta scripts de ciclo de vida ni crea tags de Git; npm puede actualizar
  `package-lock.json` o `npm-shrinkwrap.json`. La interfaz informa los archivos
  de version realmente modificados. Incrementa antes de compilar y desplegar.
- **Laravel** solo incrementa la version semver guardada por Deploy Tool.
  `composer.json` y los archivos locales no se modifican; la version debe tener
  formato numerico `MAJOR.MINOR.PATCH`.

API:

| Metodo | Ruta | Accion |
|---|---|---|
| GET | `/api/deploys?project_id={id}&page={n}&per_page={n}` | Historial paginado, con conteos por estado |
| GET | `/api/deploys/{id}` | Detalle con archivos y log |
| POST | `/api/projects/{id}/bump-version` | Incrementar `patch`, `minor` o `major` |

La lista usa `data` y `meta` (`current_page`, `last_page`, `per_page`, `total`);
`per_page` acepta de 1 a 100 y vale 20 por defecto. La lectura del historial no
incluye el log hasta pedir el detalle.

Pruebas dirigidas (desde `backend`): `php artisan test --filter="DeployApiTest|ProjectVersionTest"`.
Pruebas Angular: `npm test -- --watch=false`. Estas verifican paginacion,
filtros, detalle, cambios semver, bloqueo durante jobs activos y el incremento
real de Angular sin ejecutar scripts de proyecto.

## Explorador remoto de archivos (etapa 7)

Desde **Servidores**, pulsa **Explorador de archivos** en una tarjeta para abrir
el navegador remoto en una pestaña nueva. La pantalla parte de `/`, la raiz que
expone el servidor a esa cuenta FTP, y muestra un arbol lateral expandible con
carga de subdirectorios bajo demanda. `remote_path` no se modifica: sigue siendo
el destino configurado para los deploys. Las rutas de la API son relativas a
`/`; no se aceptan segmentos `..` ni rutas absolutas. El alcance visible depende
de los permisos y del aislamiento (chroot) configurados por el proveedor.
La ubicacion muestra la ruta absoluta reportada por FTP (`PWD`) mas la ruta
seleccionada; si el proveedor oculta la ruta fisica tras un chroot, FTP solo
puede informar la ruta virtual visible para esa cuenta.
Los enlaces simbolicos y entradas cuyo tipo no se pudo determinar se muestran
sin acciones para no seguirlos.

En archivos individuales se puede iniciar una descarga o solicitar su borrado.
El borrado exige confirmacion mostrando la ruta remota, solo acepta archivos
reconocidos en el listado (nunca carpetas ni enlaces) y se bloquea mientras haya
un deploy activo para ese servidor. Estas acciones aplican a cualquier archivo
accesible desde `/` para esa cuenta FTP. La descarga se entrega como adjunto y
el archivo temporal local se elimina al terminar la respuesta.
El buscador filtra nombres del listado de la carpeta abierta; no recorre
subcarpetas y se reinicia al navegar a otra carpeta.

| Metodo | Ruta | Accion |
|---|---|---|
| GET | `/api/servers/{id}/files?path={ruta-relativa}` | Listar una carpeta |
| GET | `/api/servers/{id}/files/download?path={ruta-relativa}` | Descargar un archivo |
| DELETE | `/api/servers/{id}/files` con `{ "path": "ruta/archivo" }` | Borrar un archivo |

Pruebas dirigidas: desde `backend`, `php artisan test --filter="FtpServiceTest|RemoteFileBrowserServiceTest|ServerFilesApiTest"`; desde `frontend`, `npm test -- --watch=false --include=src/app/server-files/server-files.spec.ts --include=src/app/servers/servers.spec.ts`.

### Detalles de los proyectos registrados

Agregar un proyecto significa guardar sus opciones en la base local para
reutilizarlas en cada deploy. No copia ni publica archivos. La deteccion reconoce
Angular y Laravel; no incluye carpetas genericas ni otros frameworks.
Los botones **Solo Laravel**, **Solo Angular** y **Todo** filtran al instante
tanto el resultado del escaneo como Mis proyectos, sin nuevas peticiones ni
cambios en los registros. Todo es la opcion inicial; el filtro se conserva al
recargar las listas o escanear, y se combina con Mostrar ocultos.

La carpeta raiz se cambia desde **Configuracion** (`/app/settings`); debe
ser una ruta absoluta, existente y legible. Por defecto:
`C:\Users\Joaquin\Desktop\Desarrollo\Angular`.
Se buscan workspaces tambien en subcarpetas (`frontend`, etc.), omitiendo
`node_modules`, `vendor`, `dist`, `build`, `out`, `coverage`, `.git`, `.angular`,
`storage`, enlaces y todo Deploy Tool. Al encontrar un workspace
no se recorren sus subcarpetas. El limite es de 5000 directorios; si se supera,
el escaneo pide una raiz mas especifica.

El escaneo **no importa automaticamente, no sobrescribe ajustes guardados y
no escribe archivos de los proyectos**. Devuelve candidatos e incidencias:
un JSON invalido o un builder no soportado se informa sin ocultar los demas
resultados validos. No hay resultados exitosos vacios para una raiz inexistente.
Los registros eliminados no borran carpetas ni archivos locales.

### Deteccion de build y version

- Se leen `angular.json` y `package.json`, admitiendo comentarios JSONC,
  comas finales y BOM.
- Se reconocen `architect.build` y `targets.build`; las bibliotecas se omiten.
- Las opciones de `configurations.production` prevalecen sobre `options`.
- Builder `application` (`@angular/build` o `@angular-devkit/build-angular`):
  un `outputPath` de texto usa `<outputPath>/browser`; un objeto `{base, browser}`
  usa esos valores. `browser: ""` evita la subcarpeta; si se omite, usa `browser`.
  Si se omite todo `outputPath`, usa `dist/<aplicacion>/browser`.
- Builders `browser` y `browser-esbuild`: salida directa en `outputPath`.
- Las rutas se normalizan a absolutas, sin exigir que la salida exista antes
  del primer build. Puedes ajustar la salida y el comando manualmente.
- La version inicial sale de `package.json`. Los patrones por defecto son
  `["*.map"]`, editables en el formulario, uno por linea.

Se conserva la unicidad de la carpeta local del plan: se agrega **una
aplicacion por workspace Angular**, aunque el escaneo muestre varias opciones.
Se guarda ademas `angular_project`; el comando predeterminado es
`npx ng build "<aplicacion>" --configuration production`, para evitar elegir
implicitamente otra aplicacion. Si cambias de aplicacion mediante la API sin
enviar salida/comando, se usan los defaults de la nueva aplicacion.

Registrar un proyecto guarda su configuracion, pero **no ejecuta comandos ni
sube archivos**. La accion confirmada de versionado modifica `package.json` en
Angular; editar manualmente `current_version` solo cambia la referencia local.
El ultimo deploy se consulta desde el historial.
La ruta `npx_path`, los intentos FTP y la espera se guardan para las etapas 3,
5 y 6, respectivamente.

### API y pruebas

| Metodo | Ruta | Accion |
|---|---|---|
| GET / PUT | `/api/settings` | Leer / guardar configuracion |
| GET | `/api/projects/scan` | Detectar candidatos e incidencias sin registrar |
| GET / POST | `/api/projects` | Listar / registrar |
| GET / PUT / PATCH / DELETE | `/api/projects/{id}` | Ver / editar / eliminar |

Las respuestas usan `data`. El escaneo devuelve
`{data: {projects: [...], issues: [...]}}`. Cada candidato incluye
`registered_id` y `registered_angular_project` para detectar carpetas ya guardadas.
Para agregar alcanza con `local_path` (y `angular_project` si hay varias
aplicaciones Angular); los demas campos tienen defaults detectados y pueden sobrescribirse.
Las asociaciones se envian como `servers: [{server_id, label, remote_path_override, public_remote_path}]`.
Omitir `servers` en una edicion conserva las asociaciones; `servers: []` las quita.
Las contrasenas de los servidores nunca se incluyen en estas respuestas.

Las migraciones crean `projects`, `project_server` y `settings`. Para una copia
nueva, ejecuta `php artisan migrate` antes de abrir estas pantallas.

Pruebas de esta etapa (desde `backend`):

```powershell
php artisan test --filter="ProjectScannerTest|ProjectApiTest"
```

Desde `frontend`: `npm test -- --watch=false`.
Se verifican builders antiguos y nuevos, overrides de produccion, rutas Windows,
JSONC, exclusiones, duplicados, persistencia de ajustes y asociaciones,
errores de configuracion y conservacion de los archivos fuente.

### Laravel: backend y public separados

Un proyecto Laravel se reconoce por `artisan` y `composer.json` con
`laravel/framework`. Su nombre inicial es el de la carpeta local. El origen es
la raiz del proyecto; no requiere `angular.json`, `package.json` ni un build.
Si necesita compilar assets, puedes guardar un comando opcional.
La version inicial sale de `composer.json.version`, o `0.0.0` cuando no existe;
es editable como metadata local sin modificar el archivo.

Para cada servidor asociado:

- **Ruta remota del backend:** obligatoria, fuera de `public_html`, por ejemplo
  `/munivirasoroapi`. Se propone el nombre de la carpeta local. Confirma la
  ruta tal como la ve la cuenta FTP.
- **Destino separado del contenido de public:** opcional, por ejemplo
  `/public_html/api`. Si se configura, en la etapa de deploy los archivos de
  `public/` iran alli sin el prefijo `public/`; el resto ira al backend.
  Si se deja vacio, `public` permanece dentro de la estructura Laravel.
- Se rechazan destinos iguales, anidados entre si, raiz `/` y segmentos
  `.` o `..`, para evitar mezclar codigo del backend con archivos publicos.

Por defecto se excluyen `.env`, `.env.*`, `.git`, `node_modules`, datos de
`storage`, caches PHP de `bootstrap/cache`, `public/storage`, tests y logs.
`vendor` no se excluye por defecto: puede ser necesario si el hosting no
permite ejecutar Composer. Los patrones se pueden revisar antes de guardar.
Estas exclusiones son configuracion preparada para el futuro selector, no una
subida ya implementada.

La seleccion de archivos concretos y la opcion de subir todos los archivos
publicables corresponden a las etapas 4 y 5. Una primera instalacion tambien
requiere preparar el `.env` de produccion, directorios/permisos de Laravel y,
si se mueve `public`, las rutas de su `index.php`. FTP no ejecutara migraciones,
Composer ni comandos Artisan en el hosting.

### Ocultar mobile, escritorio o proyectos individuales

En Configuracion puedes editar los nombres de carpetas a ocultar (uno por
linea). Por defecto: `mobile`, `desktop`, `escritorio`. Se comparan nombres
completos **debajo de la carpeta raiz**, no el `Desktop` de Windows.
La deteccion de Ionic/Capacitor/Cordova y Electron/Tauri tambien oculta estos
proyectos por defecto; puede desactivarse. Una aplicacion Ionic que tambien
publiques como web puede recuperarse individualmente.

Cada candidato o proyecto guardado tiene **Ocultar proyecto**. Esto persiste
su visibilidad sin eliminar el registro ni los archivos. **Mostrar ocultos**
muestra los filtrados y permite **Volver a mostrar**; la recuperacion individual
tiene prioridad sobre los filtros automaticos. Si habia un escaneo visible,
cambiar esta opcion vuelve a escanear automaticamente.

La API admite `?include_hidden=1` en los listados y el escaneo, devuelve
`hidden_reason` y, en el escaneo, `hidden_count`. La visibilidad se actualiza
con `POST /api/projects/visibility`, enviando `{local_path, hidden}`.
Las carpetas ocultas todavia se inspeccionan para permitir su recuperacion.
Los tipos nuevos se guardan como `type: "angular" | "laravel"`; Laravel usa
`angular_project: null` y permite `build_command: null`.
