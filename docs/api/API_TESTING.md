# MisVales API — Documentación de pruebas

Guía de pruebas manuales para la API v1 de MisVales-Backend. Complementa la colección de Postman
(`docs/api/MisVales.postman_collection.json`) con el detalle de cada endpoint: método, ruta, roles
permitidos, body de ejemplo, respuesta esperada y un `curl` listo para copiar.

## Antes de empezar

- **Base URL**: `http://localhost:8000/api/v1` (ajusta el puerto/host a tu entorno).
- **Auth**: Bearer token de Laravel Sanctum. Todas las rutas protegidas esperan
  `Authorization: Bearer <token>` y `Accept: application/json`.
- **Formato de respuesta** (trait `ApiResponse`, ver `app/Traits/ApiResponse.php`):
  ```json
  // éxito
  { "success": true, "message": "...", "data": { } }

  // error
  { "success": false, "message": "...", "errors": { } }
  ```
- **Errores de validación** (422, generados automáticamente por los `FormRequest`) usan el formato
  estándar de Laravel:
  ```json
  {
    "message": "The email field is required.",
    "errors": { "email": ["The email field is required."] }
  }
  ```
- **Rate limiting**: `throttle:auth` (5/min) en login y MFA; `throttle:authenticated` (120/min) en
  rutas protegidas; `throttle:6,1` en verificación/recuperación de email.
- **reCAPTCHA**: los formularios públicos/pre-auth (login, forgot-password, reset-password,
  mfa/verify, mfa/email/verify, mfa/setup/confirm) aceptan un campo `recaptcha` **opcional**
  (`nullable`, regla `App\Rules\Recaptcha`, ver `app/Rules/Recaptcha.php`). Si se envía, se valida
  contra `https://www.google.com/recaptcha/api/siteverify` (Google reCAPTCHA v3, `score >= 0.5`).
  En `local`/`testing`, si no hay `RECAPTCHA_SECRET_KEY` configurada o se manda
  `"bypass-recaptcha"`, la verificación se omite. **Falta agregar `RECAPTCHA_SECRET_KEY` a
  `.env.production.example`** para activarlo en producción.
- **Roles**: el middleware `role:...` (`app/Http/Middleware/CheckRole.php`) exige que el usuario
  autenticado tenga uno de los roles listados; si no, responde `403`.
- **VPN**: dos endpoints de decisión (edición de cliente y conciliación manual) llevan el
  middleware `vpn` además del rol — en producción solo responden desde la red interna.

### Flujo recomendado para probar de punta a punta

1. `POST /login` → guarda el `token`.
2. `POST /distribuidora/clientes` (rol Distribuidora) → crea un cliente.
3. `POST /productos` (rol Gerente General) → crea un producto del catálogo.
4. `POST /vales` (rol Distribuidora) → solicita un vale con ese cliente/producto.
5. `PUT /vales/{vale}/autorizar` (rol Coordinador/Gerente) → autoriza el vale.
6. `POST /relaciones/generar` (rol Gerente General) → genera el corte de esa distribuidora.
7. `POST /conciliaciones/importar` o el flujo manual (`solicitar-autorizacion` → `decidir` →
   `conciliar-manual`) → concilia el pago y liquida la relación.

---

## 01. Auth

### `POST /login`
Público (rate limit 5/min). Puede devolver token directo, o `requires_mfa`/`requires_setup` si el
rol exige más de un factor.

```bash
curl -X POST "$BASE_URL/login" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"usuario@ejemplo.com","password":"Password123!","recaptcha":"bypass-recaptcha"}'
```
`recaptcha` es opcional; ver nota de reCAPTCHA arriba.

Éxito (login de un solo factor):
```json
{
  "success": true,
  "message": "Login exitoso",
  "data": { "user": { "id": 1, "name": "...", "email": "..." }, "token": "1|abcdef..." }
}
```

Requiere MFA:
```json
{ "success": true, "data": { "requires_mfa": true, "mfa_method_id": "3" }, "message": "Ingresa el código de tu aplicación de autenticación." }
```

Credenciales inválidas → `401` `{"success": false, "message": "Credenciales incorrectas.", ...}`.
Cuenta bloqueada por intentos fallidos → `403`.

### `POST /logout`
Auth requerido. Revoca el token actual (Sanctum).
```bash
curl -X POST "$BASE_URL/logout" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```
`200` `{"success": true, "message": "Sesión cerrada exitosamente."}`

### `GET /me`
Auth requerido. Devuelve el usuario autenticado (`UserResource`).
```bash
curl "$BASE_URL/me" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

### `POST /email/resend`
Auth requerido (`throttle:6,1`). Body: `{ "email": "..." }`.

### `POST /email/verify/{id}/{hash}`
Auth requerido, ruta **firmada** (`middleware: signed`) — la URL completa con `expires`/`signature`
la genera Laravel al enviar el correo de verificación; no se arma a mano.

### `POST /forgot-password`
Público (`throttle:6,1`). Body: `{ "email": "...", "recaptcha": "..." (opcional) }`. Envía el
enlace de restablecimiento.

### `POST /reset-password`
Público (`throttle:6,1`).
```bash
curl -X POST "$BASE_URL/reset-password" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"token":"...","email":"usuario@ejemplo.com","password":"NuevaPassword123!","password_confirmation":"NuevaPassword123!","recaptcha":"bypass-recaptcha"}'
```
Token inválido/expirado → `400` con mensaje descriptivo.

---

## 02. MFA

### `POST /mfa/verify`
Público (`throttle:auth`). Body: `{ "mfa_method_id": "3", "code": "123456", "recaptcha": "..." (opcional) }`.
Puede requerir un tercer factor (`requires_email_otp`) o devolver el token final.

### `POST /mfa/email/verify`
Público. Body: `{ "user_id": 1, "code": "123456", "recaptcha": "..." (opcional) }`. Segundo paso
del reto (OTP por correo).

### `GET /mfa/setup`
Público, ruta **firmada**. Query: `email`, `expires`, `signature`. La URL completa la entrega el
login cuando `requires_setup: true`. Devuelve el secreto/QR para vincular la app TOTP.

### `POST /mfa/setup/confirm`
Auth requerido. Body: `{ "mfa_method_id": "3", "code": "123456", "recaptcha": "..." (opcional) }`.
Valida el primer código generado por la app del usuario y marca el método como verificado.

---

## 03. Usuarios

### `GET /usuarios?filter[role]=Verificador`
Roles: Coordinador, Verificador, Gerente de Sucursal, Gerente General. Gerente General/Administrador
ven todas las sucursales; el resto solo la suya.

---

## 04. Alta de Proveedor

### `GET /alta-proveedor/solicitudes`
Roles: Coordinador, Verificador, Gerente de Sucursal, Gerente General. Soporta `filter[estado]`,
`filter[decision_gerente]`, `filter[sucursal_id]`, `filter[coordinador_id]`, `filter[verificador_id]`,
`include=datosPersonales.direccion,sucursal,coordinador,verificador,gerente,evidencias,logs`,
`sort=-created_at`.

### `GET /alta-proveedor/solicitudes/{solicitud}`
Mismos roles; restringido a la sucursal del usuario salvo Gerente General/Administrador.

### `POST /alta-proveedor/solicitudes`
Roles: Coordinador, Gerente de Sucursal, Gerente General. Ver `CrearSolicitudProveedorRequest`
para la lista completa de campos (razón social, RFC, datos personales, dirección, datos extra,
`verificador_id` opcional, `evidencias[]` opcional).

```bash
curl -X POST "$BASE_URL/alta-proveedor/solicitudes" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"razon_social":"Abarrotes El Sol SA de CV","rfc":"AES010101AB1","nombre":"Juan","apellido_paterno":"Pérez","apellido_materno":"Gómez","curp":"PEGJ800101HDFRRN01","calle":"Av. Reforma 123","colonia":"Centro","numero_ext":"123","codigo_postal":"06000","estado":"CDMX","ciudad":"Ciudad de México"}'
```
`201` con `SolicitudProveedorResource`.

### `POST /alta-proveedor/solicitudes/{solicitud}/verificar`
Roles: Verificador, Gerente de Sucursal, Gerente General. Body: `cumple` (bool),
`comentario_verificador`, opcionalmente `datos_personales{}`/`direccion{}` editados y `evidencias[]`.

### `POST /alta-proveedor/solicitudes/{solicitud}/aprobar`
Roles: Gerente de Sucursal, Gerente General. `decision`: `aprobado` | `rechazado`. Si es `aprobado`,
son requeridos `limite_credito_asignado`, `email`, `password` (se crea el acceso de la distribuidora).

### `POST /alta-proveedor/solicitudes/{solicitud}/evidencias`
Roles: Coordinador, Verificador, Gerente de Sucursal, Gerente General. `multipart/form-data`:
`archivo` (jpg/jpeg/png/pdf, máx 5MB), `tipo_documento`.
```bash
curl -X POST "$BASE_URL/alta-proveedor/solicitudes/1/evidencias" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -F "archivo=@/ruta/identificacion.jpg" -F "tipo_documento=identificacion"
```

---

## 05. Distribuidora — Clientes

### `GET /distribuidora/perfil`
Roles: Distribuidora, Gerente General. Devuelve (creando si hace falta) el perfil de distribuidora
del usuario autenticado.

### `GET /distribuidora/clientes`
Roles: Distribuidora, Gerente General, Gerente de Sucursal, Cajera. Lista paginada
(`ClienteResource`), filtrada por la distribuidora/sucursal del usuario.

### `POST /distribuidora/clientes`
Roles: Distribuidora, Gerente General.
```bash
curl -X POST "$BASE_URL/distribuidora/clientes" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"nombre":"María","apellido_paterno":"López","apellido_materno":"Ramírez","curp":"LORM900202MDFPMR05","calle":"Calle 5 de Mayo 45","colonia":"Centro","numero_ext":"45","codigo_postal":"72000","estado":"Puebla","ciudad":"Puebla"}'
```
`curp` duplicado → `422` con mensaje personalizado ("Este CURP ya está registrado...").

### `GET /distribuidora/clientes/ediciones`
Roles: Cajera, Coordinador, Gerente de Sucursal, Gerente General. **Debe declararse antes de
`clientes/{id}` en las rutas** (ver comentario en `routes/api/v1.php`) para que `ediciones` no se
interprete como un `{id}`.

### `GET /distribuidora/clientes/{id}`
Roles: Distribuidora, Gerente General, Gerente de Sucursal, Cajera.

### `PUT /distribuidora/clientes/{id}`
Roles: Distribuidora, Gerente General. Body parcial: `datos_personales{}`, `direccion{}`.

### `PATCH /distribuidora/clientes/{id}/estado`
Roles: Distribuidora, Gerente General. Body: `{ "estado": false }`.

### `POST /distribuidora/clientes/{id}/solicitar-edicion`
Rol: Cajera. Body: `datos_personales{}`, `direccion{}`, `motivo` (requerido). Queda pendiente de
autorización — no aplica el cambio de inmediato.

### `PUT /distribuidora/clientes/ediciones/{solicitud}/decidir`
Roles: Coordinador, Gerente de Sucursal, Gerente General **+ VPN**. Body:
`{ "decision": "aprobada"|"rechazada", "comentario": "..." }`.

### `PUT /distribuidora/clientes/{id}/editar-datos`
Rol: Cajera. Body: `{ "solicitud_id": <id de una solicitud ya aprobada> }`. Aplica los cambios
propuestos al cliente.

---

## 06. Configuraciones

Reglas de negocio (clave-valor) y fechas de corte, versionadas por vigencia: cada cambio cierra la
versión anterior (`vigente_hasta`) e inserta una nueva fila vigente.

### `GET /configuraciones`
Roles: Gerente General, Gerente de Sucursal. Lista las reglas vigentes hoy.

### `POST /configuraciones`
Rol: Gerente General.
```bash
curl -X POST "$BASE_URL/configuraciones" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"clave":"comision_base_pct","valor":"10","tipo_dato":"decimal"}'
```
`tipo_dato`: `decimal` | `int` | `boolean` | `string`.

### `GET /configuraciones/historial/{clave}`
Rol: Gerente General. Versiones pasadas de una clave.

### `GET /configuraciones/fechas?sucursal_id=`
Roles: Gerente General, Gerente de Sucursal. Sin `sucursal_id` devuelve todas las reglas vigentes
(incluida la global); con `sucursal_id` devuelve la vigente para esa sucursal (con fallback a la
regla global si no tiene una propia).

### `POST /configuraciones/fechas`
Rol: Gerente General. Body: `sucursal_id` (null = regla global), `dia_corte`, `dia_limite_pago`,
`dias_pago_anticipado`.

### `GET /configuraciones/fechas/historial?sucursal_id=`
Rol: Gerente General.

---

## 07. Distribuidoras — Historial de Estado

### `GET /distribuidoras/{id}/historial-estado`
Roles: Gerente General, Gerente de Sucursal, Distribuidora. Bitácora de transiciones de estado
(`HistorialEstadoDistribuidoraResource`). El cambio de estado en sí vive en el módulo 10.

---

## 08. Productos

Catálogo de vales (monto, quincenas, variante). Solo el Gerente General escribe.

### `GET /productos?activos=true`
Abierto a cualquier usuario autenticado. `activos=false` incluye desactivados (para reactivarlos).

### `GET /productos/{producto}`
Rol: Gerente General.

### `POST /productos`
Rol: Gerente General. `monto` debe ser múltiplo de 100 y único por combinación
`monto`+`quincenas`+`variante`.
```bash
curl -X POST "$BASE_URL/productos" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"monto":2000,"quincenas":4,"variante":"estandar","descripcion":"Vale digital de 2000 a 4 quincenas"}'
```

### `PUT /productos/{producto}`
Rol: Gerente General.

### `DELETE /productos/{producto}`
Rol: Gerente General. Baja lógica (`activo=false`), no borra el registro.

---

## 09. Categorías de Distribuidora

### `GET /categorias-distribuidoras`
Roles: Gerente de Sucursal, Gerente General. Catálogo usado por `PUT distribuidoras/{id}/credito`.

---

## 10. Distribuidoras — Gestión Avanzada

La creación de distribuidoras **no** ocurre aquí — solo vía el flujo de alta de proveedor
(captura → verificación → aprobación de gerencia).

### `GET /distribuidoras`
Roles: Coordinador, Verificador, Gerente de Sucursal, Gerente General, Cajera. Filtrado por rol en
el `DistribuidoraService` (p. ej. Coordinador solo ve las suyas).

### `GET /distribuidoras/{distribuidora}`
Roles: Coordinador, Verificador, Gerente de Sucursal, Gerente General.

### `PUT /distribuidoras/{distribuidora}`
Roles: Coordinador, Gerente de Sucursal, Gerente General. Acepta `datos_personales{}` y
`datos_extras{}` anidados además de los campos propios de la distribuidora.

### `DELETE /distribuidoras/{distribuidora}`
Rol: Gerente General. Desactiva lógicamente (`estado=INACTIVO`).

### `PUT /distribuidoras/{distribuidora}/estado`
Cada estado destino exige un rol distinto (autorización por política + `DistribuidoraEstadoRequest`):
- `EN_VERIFICACION` → solo Verificador
- `RECHAZADO` → Verificador o Gerente de Sucursal
- `ACTIVO` / `MOROSO` → Gerente de Sucursal o Gerente General

### `PUT /distribuidoras/{distribuidora}/credito`
Roles: Gerente de Sucursal, Gerente General. Body: `limite_credito`, `categoria_id`. Si la
distribuidora seguía en `EN_CAPTURA`/`PENDIENTE_APROBACION`, la pasa a `ACTIVO` automáticamente.

### `GET /distribuidoras/{distribuidora}/saldo-disponible`
Roles: Cajera, Distribuidora, Gerente de Sucursal, Gerente General.
```json
{ "success": true, "data": 4500.0 }
```

### `GET /distribuidoras/{distribuidora}/puntos`
Roles: Cajera, Distribuidora, Gerente de Sucursal, Gerente General. Historial de movimientos
(`generado` / `redimido` / `penalizado`).

### `POST /distribuidoras/{distribuidora}/puntos/canjear`
Rol: Cajera. Body: `cantidad` (int > 0), `motivo`. Falla con `422`/mensaje de negocio si
`cantidad > puntos_acumulados`.

---

## 11. Vales

Prerequisito de Relaciones/Conciliación: sin vales autorizados no hay nada que cortar.

### `GET /vales`
Roles: Distribuidora, Cajera, Coordinador, Gerente de Sucursal, Gerente General. Query:
`distribuidora_id`, `estado`, `per_page`.

### `POST /vales`
Rol: Distribuidora.
```bash
curl -X POST "$BASE_URL/vales" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"cliente_id":1,"producto_id":1,"tipo":"pre-vale"}'
```
`201` con `ValeResource`, `estado: "solicitado"`. Falla `422`/`403` si el cliente no pertenece a la
distribuidora o si excede el crédito disponible / el límite del primer vale.

### `PUT /vales/{vale}/autorizar`
Roles: Coordinador, Gerente de Sucursal, Gerente General. Sin body. Pasa `estado` a `autorizado` —
desde aquí cuenta contra el crédito disponible.

### `PUT /vales/{vale}/desactivar`
Rol: Distribuidora (dueña del vale). Solo mientras sigue en `solicitado`.

### `PUT /vales/{vale}/activar`
Rol: Distribuidora (dueña del vale).

---

## 12. Relaciones (Cortes / Estado de cuenta)

### `GET /relaciones`
Roles: Cajera, Coordinador, Gerente de Sucursal, Gerente General. Query: `distribuidora_id`,
`estado`, `referencia_pago` (búsqueda parcial), `per_page`.

### `GET /relaciones/{relacion}`
Mismos roles. Incluye `detalles` (una cuota por vale del corte).

### `POST /relaciones/generar`
Rol: Gerente General. Body opcional: `distribuidora_id` (si se omite, genera el corte del día para
todas las distribuidoras que correspondan según `ConfiguracionFechas`), `fecha_corte`.
```bash
curl -X POST "$BASE_URL/relaciones/generar" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" -d '{"distribuidora_id":1}'
```

### `POST /relaciones/{relacion}/perdonar`
Roles: Gerente de Sucursal, Gerente General. Body opcional: `motivo`. Si se alcanza el límite de
perdones configurado, la relación se marca como `en_perdida` en vez de perdonarse.

---

## 13. Conciliación Bancaria

### `GET /conciliaciones`
Roles: Cajera, Coordinador, Gerente de Sucursal, Gerente General. Query: `estado`, `relacion_id`,
`per_page`.

### `POST /conciliaciones/importar`
Roles: Cajera, Gerente de Sucursal, Gerente General. `multipart/form-data`: `archivo`
(xlsx/xls/csv, máx 10MB), `convenio_bancario_id` opcional.
```bash
curl -X POST "$BASE_URL/conciliaciones/importar" -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -F "archivo=@/ruta/movimientos_banco.xlsx"
```
```json
{ "success": true, "data": { "procesadas": 12, "conciliadas": 9, "sin_coincidencia": 3, "errores": [] } }
```

### `GET /conciliaciones/autorizaciones`
Roles: Cajera, Coordinador, Gerente de Sucursal, Gerente General.

### `POST /conciliaciones/{abono}/solicitar-autorizacion`
Rol: Cajera. Body: `relacion_id`, `motivo`. Solo aplica a abonos en `sin_coincidencia`.

### `PUT /conciliaciones/autorizaciones/{solicitud}/decidir`
Roles: Coordinador, Gerente de Sucursal, Gerente General **+ VPN**. Body:
`{ "decision": "aprobada"|"rechazada", "comentario": "..." }`.

### `POST /conciliaciones/{abono}/conciliar-manual`
Rol: Cajera (debe ser quien solicitó la autorización). Body: `{ "solicitud_id": <id aprobada> }`.

---

## 14. Reportes

### `GET /reportes/morosos`
Roles: Cajera, Coordinador, Gerente de Sucursal, Gerente General. "Distribuidoras Morosas y
saldos" — Gerente de Sucursal solo ve su sucursal; Coordinador solo las suyas.
```json
{
  "success": true,
  "data": [
    {
      "distribuidora_id": 1,
      "numero_distribuidora": "D-0001",
      "sucursal": "Matriz",
      "estado_distribuidora": "MOROSO",
      "saldo_pendiente_total": 3200.5,
      "relaciones_vencidas": 1,
      "relaciones_en_perdida": 0
    }
  ]
}
```

---

## 15. Notificaciones

### `GET /notificaciones`
Roles: Gerente de Sucursal, Gerente General, Administrador. Feed de acciones del sistema
(`AuditLogObserver`); Gerente de Sucursal ve solo su sucursal.

---

## 16. Admin

Solo el Administrador; únicamente lectura de logs, no puede operar el resto de la API.

### `GET /admin/historical-data`
Historial de intentos de login de los últimos 10 días.

### `GET /admin/logs?per_page=&user_id=&action=`
Log de auditoría paginado y filtrable.

---

## Casos de error comunes a probar

| Escenario | Código | Cómo provocarlo |
|---|---|---|
| Sin token / token inválido | 401 | Omitir `Authorization` en cualquier ruta protegida |
| Rol sin permiso | 403 | Llamar un endpoint con un usuario de rol no listado |
| Recurso de otra sucursal/distribuidora | 403 | `GET /distribuidora/clientes/{id}` con un cliente de otra distribuidora |
| Validación de body | 422 | Omitir un campo `required` (ej. `POST /vales` sin `cliente_id`) |
| Regla de negocio violada | 422 | `POST /vales` excediendo el crédito disponible de la distribuidora |
| Transición de estado inválida | 422 | `PUT /vales/{vale}/autorizar` sobre un vale ya autorizado |
| Cuenta bloqueada | 403 | 5 intentos de login fallidos seguidos |
| Rate limit | 429 | Más de 5 `POST /login` en un minuto |

## Suite automatizada existente

El repo ya trae tests Pest en `tests/Feature/` que cubren buena parte de este flujo (Auth, MFA,
Vale, Relacion, Distribuidora, Notificacion, AltaProveedor, AuditLog). Para correrlos:

```bash
php artisan test
# o un subconjunto:
php artisan test --filter=ValeActivacionTest
```

No hay tests de Feature todavía para **Configuracion**, **Producto** y **Reporte** — son buenos
candidatos si se quiere ampliar la suite automatizada más allá de esta guía manual.
