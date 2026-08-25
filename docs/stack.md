#!/usr/bin/env bash

###############################################################################
# CRM STACK - PRODUCTION BOOTSTRAP
#
# Stack:
#   Laravel 13
#   PHP 8.5
#   PostgreSQL 18
#   Livewire (ships Alpine.js bundled — no separate Alpine install needed)
#   Tailwind CSS v4 (via @tailwindcss/vite)
#   Node.js (build-time only, via Docker — not present in the runtime image)
#   Docker Compose
#   Nginx
#   Laravel Queue
#   Laravel Scheduler
#
# Target:
#   Ubuntu VPS
#   aaPanel / external Nginx
#
# Usage:
#   chmod +x setup-stack.sh
#   sudo ./setup-stack.sh
#
###############################################################################

set -Eeuo pipefail

###############################################################################
# CONFIGURATION
###############################################################################

APP_NAME="CRM"
APP_DIR="/opt/crm"

# Directory this script lives in, and the kapaim repo checkout it belongs to
# (docs/stack.md -> repo root is one level up). Used to pull the design
# system into the new Laravel app. If this script has been copied out on its
# own, ASSETS_SRC simply won't exist and the wiring step below is skipped
# with a warning instead of failing.
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SOURCE_DIR}/.." && pwd)"
ASSETS_SRC="${REPO_ROOT}/assets"

APP_PORT="8080"

DB_NAME="crm"
DB_USER="crm"

# Generate a secure database password automatically.
DB_PASSWORD="$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 32)"

###############################################################################
# COLORS
###############################################################################

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

###############################################################################
# HELPERS
###############################################################################

log() {
    echo -e "${BLUE}[CRM]${NC} $1"
}

success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

###############################################################################
# ERROR HANDLING
###############################################################################

trap 'error "Installation failed on line $LINENO."' ERR

###############################################################################
# ROOT CHECK
###############################################################################

if [ "${EUID}" -ne 0 ]; then
    error "Please run this script as root:"
    echo "sudo ./setup-stack.sh"
    exit 1
fi

###############################################################################
# SYSTEM INFORMATION
###############################################################################

log "Starting CRM production stack installation..."

if [ -f /etc/os-release ]; then
    . /etc/os-release
    log "Detected OS: ${PRETTY_NAME:-unknown}"
fi

###############################################################################
# SYSTEM PACKAGES
###############################################################################

log "Updating Ubuntu packages..."

apt-get update -y

log "Installing required system packages..."

apt-get install -y \
    ca-certificates \
    curl \
    git \
    unzip \
    openssl \
    jq \
    rsync \
    nano \
    htop \
    ufw

success "System packages installed."

###############################################################################
# DOCKER
###############################################################################

log "Checking Docker installation..."

if ! command -v docker >/dev/null 2>&1; then

    log "Docker not found. Installing Docker..."

    curl -fsSL https://get.docker.com | sh

else

    success "Docker is already installed."

fi

systemctl enable docker
systemctl start docker

if ! docker compose version >/dev/null 2>&1; then

    error "Docker Compose plugin is not available."

    echo ""
    echo "Please install Docker Compose plugin and run this script again."
    exit 1

fi

success "Docker is ready."

docker --version
docker compose version

###############################################################################
# APPLICATION DIRECTORY
###############################################################################

log "Creating application directory..."

mkdir -p "${APP_DIR}"

cd "${APP_DIR}"

###############################################################################
# LARAVEL INSTALLATION
###############################################################################

if [ ! -f "${APP_DIR}/artisan" ]; then

    log "Installing Laravel 13..."

    docker run --rm \
        -v "${APP_DIR}:/app" \
        -w /app \
        composer:2 \
        create-project \
        laravel/laravel \
        . \
        "^13.0"

else

    success "Laravel installation already exists."

fi

###############################################################################
# LIVEWIRE (bundles Alpine.js — no separate Alpine install needed)
###############################################################################

log "Installing Livewire..."

docker run --rm \
    -v "${APP_DIR}:/app" \
    -w /app \
    composer:2 \
    require livewire/livewire --no-interaction

success "Livewire installed."

###############################################################################
# TAILWIND CSS v4 + כפיים DESIGN SYSTEM WIRING
###############################################################################

log "Installing Tailwind CSS v4 (Vite plugin)..."

docker run --rm \
    -v "${APP_DIR}:/app" \
    -w /app \
    node:22-alpine \
    npm install -D tailwindcss @tailwindcss/vite

mkdir -p "${APP_DIR}/resources/css"

if [ -d "${ASSETS_SRC}" ]; then

    log "Wiring the כפיים design system into resources/css/..."

    cp "${ASSETS_SRC}/design-tokens.css" "${APP_DIR}/resources/css/design-tokens.css"
    cp "${ASSETS_SRC}/tailwind-theme.css" "${APP_DIR}/resources/css/app.css"

    success "Copied design tokens from ${ASSETS_SRC} (see docs/brand-guidelines.md for the source of truth)."

else

    warning "No assets/ folder found next to this script — resources/css/app.css was NOT wired to the כפיים design system."
    echo "Copy assets/design-tokens.css and assets/tailwind-theme.css from the kapaim repo into"
    echo "${APP_DIR}/resources/css/ manually before building (see docs/brand-guidelines.md)."

fi

cat > "${APP_DIR}/vite.config.js" <<'VITECONFIG'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
VITECONFIG

success "vite.config.js configured for Tailwind CSS v4."

###############################################################################
# DOCKER DIRECTORIES
###############################################################################

log "Creating Docker directories..."

mkdir -p "${APP_DIR}/docker/php"
mkdir -p "${APP_DIR}/docker/nginx"
mkdir -p "${APP_DIR}/backups"
mkdir -p "${APP_DIR}/storage"

###############################################################################
# PHP DOCKERFILE
###############################################################################

log "Creating PHP Dockerfile..."

cat > "${APP_DIR}/docker/php/Dockerfile" <<'DOCKERFILE'
FROM php:8.5-fpm-bookworm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        mbstring \
        bcmath \
        intl \
        zip \
        exif \
        pcntl \
        gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock* ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

COPY . .

RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

RUN chown -R www-data:www-data \
        storage \
        bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm", "-F"]
DOCKERFILE

###############################################################################
# NGINX CONFIG
###############################################################################

log "Creating internal Nginx configuration..."

cat > "${APP_DIR}/docker/nginx/default.conf" <<'NGINX'
server {

    listen 80;

    server_name _;

    root /var/www/html/public;

    index index.php;

    charset utf-8;

    client_max_body_size 25M;

    location / {

        try_files $uri $uri/ /index.php?$query_string;

    }

    location = /favicon.ico {

        access_log off;

        log_not_found off;

    }

    location = /robots.txt {

        access_log off;

        log_not_found off;

    }

    location ~ \.php$ {

        try_files $uri =404;

        include fastcgi_params;

        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        fastcgi_param DOCUMENT_ROOT $document_root;

        fastcgi_pass app:9000;

        fastcgi_read_timeout 120;

    }

    location ~ /\.(?!well-known).* {

        deny all;

    }

}
NGINX

###############################################################################
# DOCKER COMPOSE
###############################################################################

log "Creating docker-compose.yml..."

cat > "${APP_DIR}/docker-compose.yml" <<'COMPOSE'
services:

  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile

    container_name: crm_app

    restart: unless-stopped

    working_dir: /var/www/html

    volumes:
      - ./:/var/www/html
      - laravel_storage:/var/www/html/storage

    environment:
      APP_ENV: production
      APP_DEBUG: "false"

    depends_on:
      postgres:
        condition: service_healthy

    networks:
      - crm


  nginx:
    image: nginx:1.29-alpine

    container_name: crm_nginx

    restart: unless-stopped

    ports:
      - "127.0.0.1:8080:80"

    volumes:
      - ./:/var/www/html:ro
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
      - laravel_storage:/var/www/html/storage:ro

    depends_on:
      - app

    networks:
      - crm


  postgres:
    image: postgres:18-alpine

    container_name: crm_postgres

    restart: unless-stopped

    environment:
      POSTGRES_DB: ${DB_DATABASE}
      POSTGRES_USER: ${DB_USERNAME}
      POSTGRES_PASSWORD: ${DB_PASSWORD}

    volumes:
      - postgres_data:/var/lib/postgresql/data

    healthcheck:
      test:
        [
          "CMD-SHELL",
          "pg_isready -U ${DB_USERNAME} -d ${DB_DATABASE}"
        ]

      interval: 10s

      timeout: 5s

      retries: 10

      start_period: 10s

    networks:
      - crm


  queue:
    build:
      context: .
      dockerfile: docker/php/Dockerfile

    container_name: crm_queue

    restart: unless-stopped

    working_dir: /var/www/html

    command:
      [
        "php",
        "artisan",
        "queue:work",
        "--sleep=3",
        "--tries=3",
        "--timeout=120",
        "--max-time=3600"
      ]

    volumes:
      - ./:/var/www/html
      - laravel_storage:/var/www/html/storage

    depends_on:
      postgres:
        condition: service_healthy

    networks:
      - crm


  scheduler:
    build:
      context: .
      dockerfile: docker/php/Dockerfile

    container_name: crm_scheduler

    restart: unless-stopped

    working_dir: /var/www/html

    command:
      [
        "sh",
        "-c",
        "while true; do php artisan schedule:run --no-interaction; sleep 60; done"
      ]

    volumes:
      - ./:/var/www/html
      - laravel_storage:/var/www/html/storage

    depends_on:
      postgres:
        condition: service_healthy

    networks:
      - crm


volumes:

  postgres_data:
    driver: local

  laravel_storage:
    driver: local


networks:

  crm:
    driver: bridge
COMPOSE

###############################################################################
# ENVIRONMENT FILE
###############################################################################

log "Creating production .env..."

if [ -f "${APP_DIR}/.env" ]; then

    warning ".env already exists. Keeping existing configuration."

else

    cat > "${APP_DIR}/.env" <<ENV
APP_NAME="${APP_NAME}"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://crm.example.com

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}

SESSION_DRIVER=database
SESSION_LIFETIME=120

CACHE_STORE=database

QUEUE_CONNECTION=database

FILESYSTEM_DISK=local

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="\${APP_NAME}"

SMOVE_ENABLED=false
SMOVE_BASE_URL=
SMOVE_API_KEY=

SUMMIT_ENABLED=false
SUMMIT_BASE_URL=
SUMMIT_API_KEY=
ENV

fi

###############################################################################
# ENVIRONMENT SECURITY
###############################################################################

chmod 600 "${APP_DIR}/.env"

###############################################################################
# DOCKERIGNORE
###############################################################################

log "Creating .dockerignore..."

cat > "${APP_DIR}/.dockerignore" <<'IGNORE'
.git
.gitignore
.env
.env.*
node_modules
vendor
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
backups
docker-compose.override.yml
Dockerfile
IGNORE

###############################################################################
# GITIGNORE
###############################################################################

if [ ! -f "${APP_DIR}/.gitignore" ]; then

cat > "${APP_DIR}/.gitignore" <<'GITIGNORE'
/.phpunit.result.cache
/node_modules
/public/build
/public/hot
/public/storage
/storage/*.key
/storage/framework/cache/*
/storage/framework/sessions/*
/storage/framework/testing/*
/storage/framework/views/*
/storage/logs/*
/vendor
.env
.env.backup
.env.production
.phpunit.cache
Homestead.json
Homestead.yaml
npm-debug.log
yarn-error.log
/.idea
/.vscode
GITIGNORE

fi

###############################################################################
# LARAVEL DATABASE CONFIGURATION
###############################################################################

log "Preparing Laravel..."

cd "${APP_DIR}"

###############################################################################
# FRONTEND BUILD (Vite + Tailwind CSS v4, compiled on the host via Docker so
# Node.js never has to exist in the runtime PHP image)
###############################################################################

log "Installing npm dependencies..."

docker run --rm \
    -v "${APP_DIR}:/app" \
    -w /app \
    node:22-alpine \
    npm install

log "Building frontend assets..."

docker run --rm \
    -v "${APP_DIR}:/app" \
    -w /app \
    node:22-alpine \
    npm run build

success "Frontend assets built to public/build."

###############################################################################
# FIRST BUILD
###############################################################################

log "Building Docker images..."

docker compose build --no-cache

###############################################################################
# DATABASE START
###############################################################################

log "Starting PostgreSQL..."

docker compose up -d postgres

log "Waiting for PostgreSQL..."

for i in $(seq 1 60); do

    if docker compose exec -T postgres \
        pg_isready \
        -U "${DB_USER}" \
        -d "${DB_NAME}" >/dev/null 2>&1; then

        success "PostgreSQL is ready."

        break

    fi

    if [ "$i" -eq 60 ]; then

        error "PostgreSQL did not become ready."

        docker compose logs postgres

        exit 1

    fi

    sleep 2

done

###############################################################################
# APPLICATION CONTAINER
###############################################################################

log "Starting Laravel application..."

docker compose up -d app

sleep 5

###############################################################################
# APP KEY
###############################################################################

if grep -q "^APP_KEY=$" "${APP_DIR}/.env"; then

    log "Generating Laravel APP_KEY..."

    docker compose exec -T app \
        php artisan key:generate --force

else

    success "APP_KEY already exists."

fi

###############################################################################
# DATABASE MIGRATIONS
###############################################################################

log "Running database migrations..."

docker compose run --rm app \
    php artisan migrate --force

###############################################################################
# STORAGE LINK
###############################################################################

log "Creating Laravel storage link..."

docker compose run --rm app \
    php artisan storage:link || true

###############################################################################
# OPTIMIZATION
###############################################################################

log "Optimizing Laravel..."

docker compose run --rm app \
    php artisan optimize

###############################################################################
# START ALL SERVICES
###############################################################################

log "Starting all CRM services..."

docker compose up -d

###############################################################################
# SERVICE STATUS
###############################################################################

sleep 5

log "Checking Docker services..."

docker compose ps

###############################################################################
# HEALTH CHECK
###############################################################################

log "Checking local HTTP endpoint..."

if curl -fsS \
    --max-time 10 \
    http://127.0.0.1:${APP_PORT} >/dev/null; then

    success "CRM application is responding on port ${APP_PORT}."

else

    warning "CRM HTTP check failed."

    echo ""
    echo "Check logs with:"
    echo "docker compose -f ${APP_DIR}/docker-compose.yml logs -f"
    echo ""

fi

###############################################################################
# BACKUP SCRIPT
###############################################################################

log "Creating PostgreSQL backup script..."

cat > "${APP_DIR}/backup-database.sh" <<'BACKUP'
#!/usr/bin/env bash

set -Eeuo pipefail

APP_DIR="/opt/crm"
BACKUP_DIR="/opt/crm/backups"

mkdir -p "${BACKUP_DIR}"

TIMESTAMP="$(date '+%Y-%m-%d_%H-%M-%S')"

BACKUP_FILE="${BACKUP_DIR}/crm_${TIMESTAMP}.dump"

cd "${APP_DIR}"

docker exec crm_postgres \
    pg_dump \
    -U "${DB_USERNAME:-crm}" \
    -d "${DB_DATABASE:-crm}" \
    -Fc \
    > "${BACKUP_FILE}"

find "${BACKUP_DIR}" \
    -type f \
    -name "*.dump" \
    -mtime +14 \
    -delete

echo "Backup created:"
echo "${BACKUP_FILE}"
BACKUP

chmod +x "${APP_DIR}/backup-database.sh"

###############################################################################
# BACKUP CRON
###############################################################################

log "Configuring daily database backup..."

CRON_LINE="0 3 * * * cd ${APP_DIR} && ${APP_DIR}/backup-database.sh >> ${APP_DIR}/backups/backup.log 2>&1"

(
    crontab -l 2>/dev/null || true
    echo "${CRON_LINE}"
) | sort -u | crontab -

###############################################################################
# FIREWALL
###############################################################################

log "Configuring firewall..."

ufw allow OpenSSH >/dev/null 2>&1 || true

# aaPanel/Nginx normally owns public HTTP/HTTPS.
ufw allow 80/tcp >/dev/null 2>&1 || true
ufw allow 443/tcp >/dev/null 2>&1 || true

ufw --force enable >/dev/null 2>&1 || true

###############################################################################
# FINAL PERMISSIONS
###############################################################################

log "Applying permissions..."

chown -R root:root "${APP_DIR}"

chmod 600 "${APP_DIR}/.env"

###############################################################################
# FINAL STATUS
###############################################################################

echo ""
echo "======================================================================"
echo " CRM STACK INSTALLATION COMPLETE"
echo "======================================================================"
echo ""
echo "Application directory:"
echo "  ${APP_DIR}"
echo ""
echo "Application URL through local proxy:"
echo "  http://127.0.0.1:${APP_PORT}"
echo ""
echo "Database:"
echo "  PostgreSQL 18"
echo ""
echo "Database name:"
echo "  ${DB_NAME}"
echo ""
echo "Database user:"
echo "  ${DB_USER}"
echo ""
echo "Database password:"
echo "  ${DB_PASSWORD}"
echo ""
echo "IMPORTANT:"
echo "  Save the database password securely."
echo ""
echo "Docker services:"
echo "  crm_app"
echo "  crm_nginx"
echo "  crm_postgres"
echo "  crm_queue"
echo "  crm_scheduler"
echo ""
echo "Useful commands:"
echo ""
echo "  cd ${APP_DIR}"
echo "  docker compose ps"
echo "  docker compose logs -f"
echo "  docker compose logs -f app"
echo "  docker compose logs -f queue"
echo "  docker compose restart"
echo ""
echo "Database backup:"
echo ""
echo "  ${APP_DIR}/backup-database.sh"
echo ""
echo "======================================================================"
echo ""

###############################################################################
# aaPanel REMINDER
###############################################################################

warning "Configure aaPanel reverse proxy:"
echo ""
echo "Domain:"
echo "  crm.example.com"
echo ""
echo "Proxy target:"
echo "  http://127.0.0.1:${APP_PORT}"
echo ""
echo "SSL:"
echo "  Enable HTTPS through aaPanel."
echo ""

###############################################################################
# NEXT DEVELOPMENT STEP
###############################################################################

echo "======================================================================"
echo " NEXT DEVELOPMENT STEP"
echo "======================================================================"
echo ""
echo "The infrastructure is ready."
echo ""
echo "Next we should build the CRM domain:"
echo ""
echo "  Lead"
echo "    -> Customer"
echo "    -> Deal"
echo "    -> Subscription"
echo "    -> Payment"
echo "    -> Revenue Recognition"
echo "    -> Delivery"
echo ""
echo "  Expenses"
echo "    -> Vendor"
echo "    -> Invoice"
echo ""
echo "  Documents"
echo "    -> Quote"
echo "    -> Order Form"
echo "    -> Contract"
echo "    -> Invoice"
echo ""
echo "  Activity Log"
echo "    -> Immutable audit trail"
echo ""
echo "  Integrations"
echo "    -> Smove"
echo "    -> Summit"
echo ""
echo "======================================================================"