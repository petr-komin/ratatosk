-- Ratatosk — schéma. Idempotentní, dá se pustit opakovaně.

CREATE TABLE IF NOT EXISTS users (
    id            BIGSERIAL PRIMARY KEY,
    email         TEXT        NOT NULL UNIQUE,
    password_hash TEXT        NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- id je zároveň sdílecí token: 128 bitů náhody, hex.
-- Kdo zná /w/<id>, kouká; nic víc k tomu není potřeba.
CREATE TABLE IF NOT EXISTS recordings (
    id           TEXT        PRIMARY KEY,
    user_id      BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title        TEXT        NOT NULL DEFAULT '',
    -- pending   = řádek založen, upload běží nebo se nedokončil
    -- uploaded  = WebM leží v R2, čeká na workera
    -- transcoding
    -- ready     = MP4 v R2, odkaz se smí poslat
    -- failed
    status       TEXT        NOT NULL DEFAULT 'pending',
    source_key   TEXT        NOT NULL,
    mp4_key      TEXT,
    duration_ms  BIGINT,
    size_bytes   BIGINT,
    error        TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    uploaded_at  TIMESTAMPTZ,
    ready_at     TIMESTAMPTZ,
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS recordings_status_idx  ON recordings (status, created_at);
CREATE INDEX IF NOT EXISTS recordings_user_idx    ON recordings (user_id, created_at DESC);

-- Neúspěšné pokusy o přihlášení, kvůli jednoduchému throttlingu.
-- identifier je "email:<adresa>" nebo "ip:<adresa>" — jeden řádek na
-- pokus, počítá se v klouzavém okně, staré řádky se průběžně mažou
-- (viz record_login_failure() v src/auth.php), takže tabulka neroste
-- bez omezení.
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGSERIAL PRIMARY KEY,
    identifier   TEXT        NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS login_attempts_lookup_idx ON login_attempts (identifier, attempted_at);
