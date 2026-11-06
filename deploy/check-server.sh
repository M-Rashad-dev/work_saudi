#!/usr/bin/env bash
# READ-ONLY: prints what is installed on the VPS so the deployment fits next to existing projects.
# Changes nothing. Usage:  bash check-server.sh

section() { printf '\n===== %s =====\n' "$1"; }

section "OS / user"
lsb_release -ds 2>/dev/null || cat /etc/os-release | head -2
echo "user: $(whoami)   sudo: $(sudo -n true 2>/dev/null && echo yes || echo 'needs password')"
echo "RAM: $(free -h | awk '/Mem:/{print $2}')   Disk free: $(df -h / | awk 'NR==2{print $4}')"

section "Where is 'landing'?"
for d in /var/www /home/*/ /srv /opt; do find "$d" -maxdepth 3 -type d -name landing 2>/dev/null; done | sort -u

section "PHP"
ls /etc/php 2>/dev/null || echo "no /etc/php"
php -v 2>/dev/null | head -1 || echo "php not installed"
ls /run/php/ 2>/dev/null
php -m 2>/dev/null | grep -iE '^(redis|pcntl|posix|intl|mbstring|mysql|pdo_mysql|curl|xml|zip|bcmath|gd)$' | tr '\n' ' '; echo

section "Web server"
nginx -v 2>&1 || echo "no nginx"
apache2 -v 2>/dev/null | head -1 || true
ls /etc/nginx/sites-enabled/ 2>/dev/null
grep -hE '^\s*(listen|server_name|root)\s' /etc/nginx/sites-enabled/* 2>/dev/null | sed 's/^\s*//' | sort -u

section "Ports in use"
sudo ss -tlnp 2>/dev/null | awk 'NR>1{print $4, $6}' | sed 's/users:((//; s/,pid.*//' | sort -u

section "Database / Redis"
mysql --version 2>/dev/null || echo "no mysql client"
systemctl is-active mysql mariadb 2>/dev/null | paste -sd ' '
redis-cli ping 2>/dev/null || echo "redis not reachable"
redis-cli info keyspace 2>/dev/null | grep '^db' || true

section "Tools"
composer --version 2>/dev/null | head -1 || echo "no composer"
node -v 2>/dev/null || echo "no node"
docker --version 2>/dev/null || echo "no docker"
supervisorctl version 2>/dev/null || echo "no supervisor"
certbot --version 2>/dev/null || echo "no certbot"
git --version

section "Firewall"
sudo ufw status 2>/dev/null | head -10 || echo "ufw not available"

echo -e "\nDone. Copy everything above and send it."
