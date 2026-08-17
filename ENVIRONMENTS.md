# Ambientes

El proyecto define dos ambientes Docker completos, cada uno con su propio `app` (PHP-FPM),
`nginx` y `mysql`, en puertos distintos para poder correr **ambos al mismo tiempo** en la
misma máquina.

| | Desarrollo | Producción |
|---|---|---|
| Archivo | `docker-compose.yml` | `docker-compose.prod.yml` |
| URL | http://localhost:8080 | http://localhost:8081 |
| MySQL (host) | `localhost:3307` | `localhost:3308` |
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| Código | montado por volumen (cambios en vivo) | copiado a la imagen en build (inmutable) |
| Dependencias | con `--dev` (Pest, PHPStan, Pint...) | `composer install --no-dev --optimize-autoloader` |
| PHP | `docker/php/local.ini` | `docker/php/production.ini` (OPcache activo, `display_errors=Off`, `expose_php=Off`) |

Ambos comparten el mismo `Dockerfile` (multi-stage: `base` → `development` / `production`)
y el mismo `docker/nginx/default.conf`.

## Desarrollo

```bash
cp .env.example .env

docker compose build
docker compose up -d

docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --seed

docker compose run --rm app ./vendor/bin/pest
```

→ `http://localhost:8080`

## Producción

```bash
cp .env.production.example .env.production

# El contenedor de producción no tiene un .env dentro (ver .dockerignore) — la llave se
# genera aparte y se pega en .env.production, no se corre key:generate en el contenedor.
php artisan key:generate --show
# copiar el valor impreso a APP_KEY= dentro de .env.production

# editar el resto de .env.production: APP_URL, DB_PASSWORD, DB_ROOT_PASSWORD,
# CORS_ALLOWED_ORIGINS, MAIL_*...

docker compose -f docker-compose.prod.yml --env-file .env.production build
docker compose -f docker-compose.prod.yml --env-file .env.production up -d

docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
```

→ `http://localhost:8081`

`docker-compose.prod.yml` exige `DB_PASSWORD`, `DB_ROOT_PASSWORD` y `CORS_ALLOWED_ORIGINS`
en `.env.production` (falla al arrancar si faltan, a propósito — nada de defaults débiles
en producción).

## Por qué existen dos

- **Desarrollo**: código en vivo vía volumen, `APP_DEBUG=true` para ver errores completos,
  DB propia en un puerto que no choca con ningún MySQL que ya tengas corriendo localmente.
- **Producción**: imagen inmutable (nada se edita dentro del contenedor), `APP_DEBUG=false`
  y los demás fixes de seguridad de este mismo repo (CORS restringido, `expose_php=Off`,
  el manejador de excepciones que nunca expone detalles técnicos — ver commits de
  seguridad), OPcache sin revalidar timestamps en cada request, `storage/` en un volumen
  con nombre para que logs y cache sobrevivan un `docker compose restart`.

No se incluye TLS/HTTPS a nivel de este `docker-compose.prod.yml` — en un despliegue real
eso lo terminaría un reverse proxy o balanceador delante (Nginx/Traefik/Cloudflare), no la
app misma.
