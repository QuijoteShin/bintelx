#!/usr/bin/env bash
#
# check-deps.sh - Verificar dependencias del sistema
# No reinstala si ya existe, solo sugiere versiones
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_header() { echo -e "\n${BLUE}=== $1 ===${NC}\n"; }
print_success() { echo -e "${GREEN}✓ $1${NC}"; }
print_warning() { echo -e "${YELLOW}⚠ $1${NC}"; }
print_error() { echo -e "${RED}✗ $1${NC}"; }
print_info() { echo -e "${BLUE}ℹ $1${NC}"; }

MISSING_DEPS=()
UPGRADE_SUGGESTIONS=()

print_header "Verificando Dependencias del Sistema"

# 1. PHP
echo -n "Verificando PHP... "
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -r 'echo PHP_VERSION;')
    PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
    PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

    if [[ $PHP_MAJOR -ge 8 ]] && [[ $PHP_MINOR -ge 1 ]]; then
        print_success "PHP $PHP_VERSION (OK)"
    else
        print_warning "PHP $PHP_VERSION encontrado (se requiere >= 8.1)"
        UPGRADE_SUGGESTIONS+=("PHP: actualizar a 8.1+")
    fi
else
    print_error "No instalado"
    MISSING_DEPS+=("php8.4")
fi

# 2. PHP Extensions
echo -e "\nVerificando extensiones PHP:"
REQUIRED_EXTS=("pdo_mysql" "mbstring" "json" "curl" "xml")

for ext in "${REQUIRED_EXTS[@]}"; do
    echo -n "  - $ext... "
    if php -m 2>/dev/null | grep -q "^${ext}$"; then
        print_success "OK"
    else
        print_error "Falta"
        MISSING_DEPS+=("php8.4-${ext}")
    fi
done

# 3. PHP-FPM
echo -e "\nVerificando PHP-FPM... "
if command -v php-fpm8.4 &> /dev/null || command -v php-fpm &> /dev/null; then
    FPM_VERSION=$(php-fpm8.4 -v 2>/dev/null || php-fpm -v 2>/dev/null | head -n1)
    print_success "Instalado: $FPM_VERSION"

    # Check if running
    if systemctl is-active --quiet php8.4-fpm 2>/dev/null || systemctl is-active --quiet php-fpm 2>/dev/null; then
        print_info "Estado: Corriendo"
    else
        print_warning "Estado: Detenido (se iniciará después)"
    fi
else
    print_error "No instalado"
    MISSING_DEPS+=("php8.4-fpm")
fi

# 4. MySQL/MariaDB
echo -e "\nVerificando base de datos... "
if command -v mysql &> /dev/null; then
    MYSQL_VERSION=$(mysql --version | awk '{print $5}' | sed 's/,$//')
    print_success "MySQL/MariaDB: $MYSQL_VERSION"

    if systemctl is-active --quiet mysql 2>/dev/null || systemctl is-active --quiet mariadb 2>/dev/null; then
        print_info "Estado: Corriendo"
    else
        print_warning "Estado: Detenido (debe estar corriendo)"
    fi
else
    print_error "No instalado"
    MISSING_DEPS+=("mysql-server")
fi

# 5. Nginx
echo -e "\nVerificando Nginx... "
if command -v nginx &> /dev/null; then
    NGINX_VERSION=$(nginx -v 2>&1 | awk -F'/' '{print $2}')
    print_success "Nginx: $NGINX_VERSION"

    # Check for HTTP/3 support
    if nginx -V 2>&1 | grep -q "http_v3"; then
        print_info "HTTP/3 (QUIC): Soportado ✓"
    else
        print_warning "HTTP/3 (QUIC): No soportado"
        print_info "Sugerencia: Compilar nginx con soporte QUIC (ver install/build-nginx-quic.sh)"
    fi

    if systemctl is-active --quiet nginx 2>/dev/null; then
        print_info "Estado: Corriendo"
    else
        print_warning "Estado: Detenido (se iniciará después)"
    fi
else
    print_error "No instalado"
    MISSING_DEPS+=("nginx")
fi

# 6. OpenSSL
echo -e "\nVerificando OpenSSL... "
if command -v openssl &> /dev/null; then
    OPENSSL_VERSION=$(openssl version | awk '{print $2}')
    print_success "OpenSSL: $OPENSSL_VERSION"
else
    print_error "No instalado"
    MISSING_DEPS+=("openssl")
fi

# 7. Git (opcional pero recomendado)
echo -e "\nVerificando Git... "
if command -v git &> /dev/null; then
    GIT_VERSION=$(git --version | awk '{print $3}')
    print_success "Git: $GIT_VERSION"
else
    print_warning "No instalado (opcional, pero recomendado para updates)"
fi

# 8. Certbot (opcional para Let's Encrypt)
echo -e "\nVerificando Certbot (Let's Encrypt)... "
if command -v certbot &> /dev/null; then
    CERTBOT_VERSION=$(certbot --version 2>&1 | awk '{print $2}')
    print_success "Certbot: $CERTBOT_VERSION"
else
    print_info "No instalado (opcional, para certificados Let's Encrypt)"
fi

# Resumen
print_header "Resumen de Dependencias"

if [ ${#MISSING_DEPS[@]} -eq 0 ] && [ ${#UPGRADE_SUGGESTIONS[@]} -eq 0 ]; then
    print_success "Todas las dependencias están instaladas y actualizadas"
    echo ""
    exit 0
fi

if [ ${#MISSING_DEPS[@]} -gt 0 ]; then
    echo -e "${RED}Dependencias faltantes:${NC}"
    printf '  %s\n' "${MISSING_DEPS[@]}"
    echo ""

    echo -e "${YELLOW}Para instalar las dependencias faltantes:${NC}"
    echo ""
    echo "  sudo add-apt-repository ppa:ondrej/php -y"
    echo "  sudo apt update"
    echo "  sudo apt install -y ${MISSING_DEPS[*]}"
    echo ""
fi

if [ ${#UPGRADE_SUGGESTIONS[@]} -gt 0 ]; then
    echo -e "${YELLOW}Sugerencias de actualización:${NC}"
    printf '  %s\n' "${UPGRADE_SUGGESTIONS[@]}"
    echo ""
fi

# Preguntar si instalar automáticamente
if [ ${#MISSING_DEPS[@]} -gt 0 ]; then
    read -p "¿Desea instalar las dependencias faltantes ahora? [y/N]: " install_deps
    if [[ "$install_deps" =~ ^[Yy]$ ]]; then
        echo ""
        print_info "Instalando dependencias..."

        sudo add-apt-repository ppa:ondrej/php -y
        sudo apt update
        sudo apt install -y "${MISSING_DEPS[@]}"

        print_success "Dependencias instaladas"
    else
        print_warning "Instalación cancelada. Instale las dependencias manualmente antes de continuar."
        exit 1
    fi
fi
