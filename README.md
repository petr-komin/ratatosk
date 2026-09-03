# Ratatosk

Nahrávání testovacích scénářů z prohlížeče a sdílení odkazem. Malý interní
nástroj pro dva lidi — bez měsíčních plateb, data u nás.

## Jak to funguje

```
prohlížeč                          server (PHP-FPM)        R2                cron
─────────                          ────────────────        ──              ────
getDisplayMedia + getUserMedia
        │
        ├─ POST /api/recordings ──▶ založí řádek,
        │                           podepíše PUT URL
        │◀───── {id, uploadUrl} ───┘
        │
        └─ PUT (WebM) ─────────────────────────────────▶ rec/…/source.webm
                                                                │
           POST /…/complete ──────▶ status = uploaded            │
                                                                 │
                                    worker.php ◀─────────────────┴──── každou minutu
                                    ffmpeg → MP4 → rec/…/video.mp4
                                    status = ready

kolega:  GET /w/<128bit id> ──────▶ MP4 přímo z veřejné R2 domény
```

Server není v datové cestě uploadu ani přehrávání. V klidu neběží žádný PHP
proces — jen fpm master.

## Výběr zdrojů

**Obraz si stránka vybrat nesmí.** `getDisplayMedia` vždy otevře systémový
dialog a zdroj v něm klikne uživatel — je to bezpečnostní omezení prohlížeče,
obejít se nedá. Formulář proto nabízí jen *předvolbu* (karta / okno / celá
obrazovka), kterou dialog předvybere, plus možnost tuhle kartu z dialogu
vyřadit, ať omylem nenatočíš samotný Ratatosk.

**Zvuk vybrat jde.** Formulář vypíše dostupné mikrofony a umí konkrétní
vynutit přes `deviceId`. Tlačítko *Vyzkoušet* rozhýbe ukazatel hlasitosti —
zároveň je to způsob, jak si vyžádat permission, bez které prohlížeč názvy
zařízení vůbec neprozradí.

Volitelně jde přibrat i zvuk sdílené plochy. Když dorazí obojí, smíchají se
přes Web Audio API do jedné stopy, protože `MediaRecorder` víc zvukových stop
nepobere. Jestli se zvuk plochy vůbec připojí, rozhoduje prohlížeč a systém
(zvuk karty umí spolehlivě Chrome, systémový zvuk na Linuxu často vůbec) —
po startu proto UI vypíše, co se doopravdy chytlo, ne co bylo zaškrtnuté.

Předvolby si stránka pamatuje v `localStorage`.

## Ochrana obsahu

- **Čtení:** neuhádnutelný odkaz `/w/<32 hex znaků>` = 128 bitů náhody. Bez
  přihlášení, aby kolega nic neřešil.
- **Zápis:** jen přihlášený účet. Registrace je zavřená zvacím kódem
  (`INVITE_CODE` v `.env`), takže si účet nezaloží kdokoli z internetu.
- U každého záznamu je vidět **kdo a kdy** ho nahrál.

## Instalace

### 1. PostgreSQL na hostu

```sql
CREATE USER ratatosk WITH PASSWORD 'nejake-heslo';
CREATE DATABASE ratatosk OWNER ratatosk;
```

Kontejner chodí na host přes `host.docker.internal` (docker bridge). Postgres
musí na tom rozhraní poslouchat a pouštět ho dovnitř:

```ini
# postgresql.conf
listen_addresses = 'localhost,172.17.0.1'
```
```
# pg_hba.conf — rozsah docker bridge sítě
host  ratatosk  ratatosk  172.16.0.0/12  scram-sha-256
```

Pak `sudo systemctl reload postgresql`.

### 2. Konfigurace

```bash
cp .env.example .env
$EDITOR .env                       # DB, R2 klíče, APP_URL
openssl rand -hex 16               # → INVITE_CODE
```

### 3. R2

- Vytvoř bucket a **API token** typu *Object Read & Write* → to jsou
  `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY`.
- Bucketu nastav **veřejný přístup přes vlastní doménu** (Settings → Public
  access → Custom domain) a tu doménu dej do `R2_PUBLIC_BASE_URL`.
- **CORS je povinný**, jinak upload z prohlížeče spadne na síťové chybě.
  Vzor je v [`r2-cors.example.json`](r2-cors.example.json) — jen přepiš origin
  za svou doménu a nahraj v Settings → CORS policy.

### 4. Start

```bash
docker compose up -d --build
docker compose exec -T app php bin/migrate.php
```

### 5. nginx na hostu

```bash
sudo cp nginx.example.conf /etc/nginx/sites-available/ratatosk.conf
sudo $EDITOR /etc/nginx/sites-available/ratatosk.conf   # server_name + $app_root
sudo ln -s ../sites-available/ratatosk.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d ratatosk.example.com
```

HTTPS není volitelné — `getDisplayMedia` a `getUserMedia` v nezabezpečeném
kontextu vůbec neexistují.

### 6. Cron na překódování

```cron
* * * * * cd /srv/ratatosk && docker compose exec -T app php bin/worker.php >> /var/log/ratatosk-worker.log 2>&1
```

Worker si drží `flock`, takže **vždy běží nanejvýš jeden ffmpeg**. Když nemá co
dělat, skončí za pár milisekund.

### 7. První účet

Otevři `https://ratatosk.example.com/register` a zaregistruj se zvacím kódem
z `.env`. Kolegovi pošli ten kód, ne heslo.

## Lokální testování

nginx k tomu potřeba není — na localhostu jede vestavěný PHP server a Postgres
v kontejneru:

```bash
cp .env.dev.example .env
docker compose -f compose.dev.yml up -d --build
docker compose -f compose.dev.yml exec -T app php bin/migrate.php
```

Otevři **http://localhost:8099/register**, zvací kód je `dev`.

`getDisplayMedia` a `getUserMedia` fungují i bez HTTPS, protože prohlížeče
berou `http://localhost` jako secure context. Na jakékoli jiné adrese (i na
IP v LAN) už bys HTTPS potřeboval.

Port se mění přes `DEV_PORT` v `.env` — pak přepiš i `APP_URL`, jde do
sdílecích odkazů.

### Co jde otestovat bez R2

Registrace, přihlášení, samotné nahrávání v prohlížeči i přehled záznamů jedou
naprázdno. **Upload spadne** a záznam skončí ve stavu `failed` — což je správné
chování, ne rozbitá appka. JS ti v takovém případě nabídne WebM ke stažení, ať
o záznam nepřijdeš.

### S opravdovým R2

Doplň do `.env` klíče a do CORS policy bucketu přidej origin
`http://localhost:8099` (vzor v `r2-cors.example.json`, `AllowedOrigins` snese
víc položek — produkční doménu i localhost). Pak celý řetěz dojede až do konce:

```bash
docker compose -f compose.dev.yml exec -T app php bin/worker.php
```

Worker je v dev kontejneru ten samý včetně ffmpeg, cron tu jen nahrazuješ ručním
spuštěním.

### Úklid

```bash
docker compose -f compose.dev.yml down -v   # -v smaže i dev databázi
```

## Provoz

```bash
docker compose exec -T app php bin/worker.php   # protlačit frontu ručně
docker compose logs -f app                      # PHP chyby
docker stats ratatosk                           # idle stopa
```

**Idle RAM:** v klidu jen `php-fpm master` (jednotky MB) + nginx na hostu.
PHP workeři se spawnou na request a po 10 s nečinnosti umřou
(`pm = ondemand`, `docker/www.conf`). ffmpeg žije jen po dobu překódování.

## Stavy záznamu

| stav | co znamená |
|---|---|
| `pending` | řádek založen, upload běží (nebo se nedokončil) |
| `uploaded` | WebM je v R2, čeká na workera |
| `transcoding` | běží ffmpeg |
| `ready` | MP4 je v R2 — **teprve teď má smysl posílat odkaz** |
| `failed` | ffmpeg selhal, důvod je ve sloupci `error` |

Sdílecí stránka `/w/<id>` funguje i před dokončením — ukáže „zpracovává se"
a sama se obnoví, jakmile je MP4 na světě. Odkaz tedy jde poslat hned.

## Co tu schválně není

Účty nad rámec e-mail + heslo, workspacy, komentáře, analytika, editace videa,
ORM, message broker, žádný proces běžící 24/7 kromě fpm masteru.
