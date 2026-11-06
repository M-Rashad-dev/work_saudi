# رفع المشروع على الـ VPS (بجوار مشروع landing)

المشروع هيتحط في `/var/www/work` جنب `/var/www/landing`، **ومش هنلمس أي حاجة تخص landing**:
- PHP 8.3 بيتسطّب **جنب** أي نسخة PHP موجودة (مش بدلها)، وليه FPM خاص بيه.
- قاعدة بيانات ومستخدم MySQL خاصين بـ work.
- Redis مشترك لكن بأرقام قواعد وبادئات مختلفة (`REDIS_DB=2/3`, `work_`).
- ملف Nginx منفصل (`work`) — ملف landing زي ما هو.
- SearXNG على `127.0.0.1:8888` بس (مش ظاهر للإنترنت).

> لو landing مش في `/var/www`، قولّي مكانه وأعدّل المسارات.
> كل الأوامر بتتنفذ على السيرفر من مستخدم عنده `sudo`.

---

## 0) فحص السيرفر (اختياري لكن مهم)
```bash
curl -fsSL https://raw.githubusercontent.com/<USER>/<REPO>/main/deploy/check-server.sh -o /tmp/check.sh  # أو انسخ الملف يدوياً
bash /tmp/check.sh
```
ابعت الناتج — بيبيّن نسخة Ubuntu، الـ PHP الموجود، المنافذ المستخدمة، وإعدادات Nginx بتاعة landing.
(لو الـ repo private الأمر ده مش هيشتغل قبل خطوة 2 — انسخ محتوى `deploy/check-server.sh` والصقه في `nano /tmp/check.sh`.)

---

## 1) تسطيب المتطلبات
كل أمر هنا آمن لو الحاجة متسطّبة أصلاً (بيتخطاها أو بيحدّثها).

```bash
sudo apt update
sudo apt install -y software-properties-common curl git unzip ufw

# PHP 8.3 (جنب أي نسخة موجودة)
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd

# Nginx + Redis + Supervisor
sudo apt install -y nginx redis-server supervisor

# MySQL — فقط لو مش متسطّب (check-server بيقولك). لو landing بيستخدم MariaDB سيبه وخلاص.
sudo apt install -y mysql-server

# Composer (لو مش موجود)
command -v composer || { curl -sS https://getcomposer.org/installer | php8.3 -- --install-dir=/usr/local/bin --filename=composer; }

# Node 20 (لو مش موجود أو أقدم من 18)
node -v 2>/dev/null || { curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs; }

# Docker (لـ SearXNG)
command -v docker || curl -fsSL https://get.docker.com | sudo sh
```

تأكد إن Redis و MySQL شغالين على localhost بس (ده الافتراضي):
```bash
sudo ss -tlnp | grep -E ':6379|:3306'     # لازم يظهر 127.0.0.1 مش 0.0.0.0
```

---

## 2) ربط السيرفر بـ GitHub وتنزيل المشروع
الـ repo private، فهنعمل **Deploy key** (مفتاح للقراءة فقط لهذا الـ repo):
```bash
ssh-keygen -t ed25519 -C "vps-work-deploy" -f ~/.ssh/work_deploy -N ""
cat ~/.ssh/work_deploy.pub
```
انسخ الناتج → GitHub → الـ repo → **Settings → Deploy keys → Add deploy key** → الصقه (من غير Allow write).

```bash
cat >> ~/.ssh/config <<'EOF'
Host github-work
    HostName github.com
    User git
    IdentityFile ~/.ssh/work_deploy
    IdentitiesOnly yes
EOF

sudo mkdir -p /var/www/work
sudo chown "$USER":www-data /var/www/work
git clone git@github-work:<USER>/<REPO>.git /var/www/work
cd /var/www/work
```

---

## 3) قاعدة البيانات
```bash
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=')" && echo "احفظ الباسورد: $DB_PASS"
sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS saudi_dev_daily CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'saudi_dev_daily'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON saudi_dev_daily.* TO 'saudi_dev_daily'@'localhost';
FLUSH PRIVILEGES;
SQL
```
(لو `sudo mysql` طلب باسورد: `sudo mysql -u root -p`.)

---

## 4) ملف `.env`
```bash
cd /var/www/work
cp .env.example .env
nano .env
```
عدّل:
| المفتاح | القيمة |
|---|---|
| `APP_URL` | `https://work.your-domain.com` أو `http://IP:8080` |
| `DB_PASSWORD` | الباسورد من خطوة 3 |
| `GEMINI_API_KEY` | من https://aistudio.google.com/apikey (مجاني، بدون فيزا) |
| `CRAWLER_ALLOW_INSECURE_TLS` | `true` (متفعّل افتراضياً) |
| `SESSION_SECURE_COOKIE` | `true` بعد تفعيل https (خطوة 10) |

باقي القيم جاهزة (`QUEUE_CONNECTION=redis`, `APP_TIMEZONE=Africa/Cairo`…).

---

## 5) تجهيز التطبيق
```bash
cd /var/www/work
php8.3 $(command -v composer) install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php8.3 artisan key:generate --force
php8.3 artisan migrate --seed --force
php8.3 artisan outreach:create-user          # الإيميل والباسورد اللي هتدخل بيهم
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
php8.3 artisan optimize
```

---

## 6) SearXNG (محرك البحث)
```bash
cd /var/www/work/docker/searxng
echo "SEARXNG_SECRET=$(openssl rand -hex 32)" > .env
sudo docker compose up -d
sleep 5
curl -s "http://127.0.0.1:8888/search?q=software+company+riyadh&format=json" | head -c 300; echo
```
لازم يطلع JSON فيه `"results"`. (لو `docker compose` مش موجود: `sudo apt install -y docker-compose-plugin`.)

---

## 7) Nginx — اختار طريقة واحدة

### (أ) عندك دومين → subdomain (الأفضل)
1. من لوحة الدومين: سجل **A** باسم `work` يشاور على IP السيرفر.
2. ```bash
   sudo cp /var/www/work/deploy/nginx-work.conf /etc/nginx/sites-available/work
   sudo sed -i 's/SERVER_NAME/work.your-domain.com/' /etc/nginx/sites-available/work
   ```

### (ب) مفيش دومين → IP على منفذ 8080
```bash
sudo cp /var/www/work/deploy/nginx-work.conf /etc/nginx/sites-available/work
sudo sed -i 's/SERVER_NAME/_/; s/listen 80;/listen 8080;/' /etc/nginx/sites-available/work
sudo ufw allow 8080/tcp
```
⚠️ بدون https الباسورد بيتبعت غير مشفّر — يُفضّل (أ) + خطوة 10.

### بعدها (للطريقتين)
```bash
sudo ln -sf /etc/nginx/sites-available/work /etc/nginx/sites-enabled/work
sudo nginx -t && sudo systemctl reload nginx     # لو nginx -t فشل: متعملش reload وابعتلي الخطأ
```

---

## 8) Horizon (الـ queue) + المهام المجدولة
```bash
sudo cp /var/www/work/deploy/supervisor-work-horizon.conf /etc/supervisor/conf.d/work-horizon.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status work-horizon           # لازم RUNNING

sudo cp /var/www/work/deploy/cron-work /etc/cron.d/work
sudo chmod 644 /etc/cron.d/work
cd /var/www/work && php8.3 artisan schedule:list  # discover 03:00 + build-batch 09:00
```
لوحة Horizon: `https://work.your-domain.com/horizon` (بعد تسجيل الدخول).

---

## 9) Browsershot (اختياري — للمواقع المبنية بـ JavaScript)
```bash
sudo apt install -y libnss3 libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libxkbcommon0 libxcomposite1 \
  libxdamage1 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 fonts-noto-core
sudo apt install -y libasound2t64 2>/dev/null || sudo apt install -y libasound2
sudo PUPPETEER_CACHE_DIR=/opt/puppeteer npm install -g puppeteer@22.15.0
sudo chmod -R o+rx /opt/puppeteer
CHROME="$(find /opt/puppeteer -type f -name chrome | head -1)"; echo "$CHROME"
```
في `.env`:
```
BROWSERSHOT_ENABLED=true
BROWSERSHOT_NODE_PATH=/usr/bin/node
BROWSERSHOT_NODE_MODULES_PATH=/usr/lib/node_modules
BROWSERSHOT_CHROME_PATH=<الناتج من echo فوق>
```
ثم: `php8.3 artisan optimize && php8.3 artisan horizon:terminate`

---

## 10) HTTPS (لو عندك دومين)
```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d work.your-domain.com
```
وفي `.env`: `SESSION_SECURE_COOKIE=true` و `APP_URL=https://…` ثم `php8.3 artisan optimize`.

---

## 11) التجربة النهائية
```bash
cd /var/www/work
php8.3 artisan outreach:discover --limit=3 --no-delay   # لازم "new companies" > 0
sudo supervisorctl status work-horizon
tail -n 30 storage/logs/laravel.log
```
بعد دقايق (Horizon بيعمل crawl + تحليل): افتح الموقع → **الشركات** → لازم تلاقي شركات `مؤهلة` → **اليوم** → "جهّز دفعة اليوم".

---

## التحديثات بعد كده
على جهازك: `git push`. على السيرفر:
```bash
cd /var/www/work && bash deploy/deploy.sh
```

## مشاكل شائعة
| المشكلة | الحل |
|---|---|
| صفحة 502 | `sudo systemctl status php8.3-fpm` و `tail /var/log/nginx/work.error.log` |
| 500 / صفحة بيضاء | `tail -n 50 storage/logs/laravel.log` — غالباً صلاحيات: أعد أوامر chgrp/chmod |
| الشركات مش بتتحلل | `sudo supervisorctl status work-horizon` + `tail storage/logs/horizon.log` |
| البحث بيرجع فاضي | `sudo docker logs searxng --tail 50` — محركات بتحظر IP السيرفر؛ Overpass بيكمّل |
| غيّرت `.env` ومفيش تأثير | `php8.3 artisan optimize && php8.3 artisan horizon:terminate` |
