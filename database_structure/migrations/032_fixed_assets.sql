-- ============================================================
--  032 — Fixed assets: what the business owns and uses
-- ------------------------------------------------------------
--  Everything the daily position counted as an asset was a
--  CURRENT asset — stock, cash, money owed to us. All of it
--  turns into cash within a year, which is what "current"
--  means.
--
--  The desks, the laptops, the ladders, the van: owned, used to
--  run the business, not for sale, and worth real money. None of
--  it appeared anywhere, so the net asset value was understated
--  by whatever the business had spent on equipping itself.
--
--  ── The term ────────────────────────────────────────────────
--  FIXED ASSETS. Under IFRS the heading is "property, plant and
--  equipment"; every bank, landlord and auditor in Kenya will
--  also answer to fixed assets, and that is what the page is
--  called. The opposite of current: held for use, not for
--  resale, and not expected to turn into cash this year.
--
--  ── Cost is not value ───────────────────────────────────────
--  A laptop bought for 120,000 in 2022 is not worth 120,000
--  today, and a register that says so makes the net asset value
--  a slowly growing lie. So an asset is carried at
--
--      net book value = cost − accumulated depreciation
--
--  written down in a straight line over its useful life, floored
--  at whatever it is reckoned to be worth at the end of that
--  life (its residual). Straight line because it is the method
--  an SME can check by hand: cost less residual, divided by the
--  months of life, times the months owned.
--
--  Nothing is stored but the facts — cost, date, life, residual.
--  The depreciation is computed on the way past, like every
--  other figure in this system, so it is right on any date it is
--  asked about and cannot drift.
--
--  Land is the exception and carries a life of zero, meaning it
--  is never written down. It is the one thing that does not wear
--  out.
--
--  ── Disposal ────────────────────────────────────────────────
--  Sold, scrapped or stolen, an asset stops being ours that day
--  and leaves the register from that date — without deleting the
--  row, because what the business owned last March is a question
--  somebody will ask.
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS fixed_assets (
    asset_id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    asset_tag         VARCHAR(40),
    name              VARCHAR(200)  NOT NULL,
    category          VARCHAR(60)   NOT NULL,
    description       TEXT,

    -- What it cost, and when it was bought. Depreciation runs
    -- from the purchase date, not from when somebody got round
    -- to typing it in.
    purchase_date     DATE          NOT NULL,
    cost              NUMERIC(14,2) NOT NULL,

    -- How long it is expected to earn its keep, and what it is
    -- reckoned to be worth at the end of that. A life of 0 means
    -- it is never written down — land, and nothing else.
    useful_life_months INTEGER      NOT NULL DEFAULT 60,
    residual_value     NUMERIC(14,2) NOT NULL DEFAULT 0,

    supplier_id       INTEGER REFERENCES suppliers(supplier_id),
    serial_number     VARCHAR(120),
    location          VARCHAR(120),

    -- Gone. The date it stopped being ours, and what it fetched.
    disposed_on       DATE,
    disposal_amount   NUMERIC(14,2),
    disposal_reason   TEXT,

    notes             TEXT,
    created_by        INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_fixed_assets_cost') THEN
        ALTER TABLE fixed_assets ADD CONSTRAINT ck_fixed_assets_cost
            CHECK (cost >= 0);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_fixed_assets_life') THEN
        ALTER TABLE fixed_assets ADD CONSTRAINT ck_fixed_assets_life
            CHECK (useful_life_months >= 0 AND useful_life_months <= 1200);
    END IF;
    -- A residual above cost would depreciate upwards.
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_fixed_assets_residual') THEN
        ALTER TABLE fixed_assets ADD CONSTRAINT ck_fixed_assets_residual
            CHECK (residual_value >= 0 AND residual_value <= cost);
    END IF;
    -- Disposed before it was bought is a typo, not a transaction.
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_fixed_assets_disposal') THEN
        ALTER TABLE fixed_assets ADD CONSTRAINT ck_fixed_assets_disposal
            CHECK (disposed_on IS NULL OR disposed_on >= purchase_date);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_fixed_assets_owned
    ON fixed_assets (purchase_date, disposed_on);
CREATE INDEX IF NOT EXISTS ix_fixed_assets_category
    ON fixed_assets (category);
CREATE UNIQUE INDEX IF NOT EXISTS ux_fixed_assets_tag
    ON fixed_assets (LOWER(asset_tag)) WHERE asset_tag IS NOT NULL;

DROP TRIGGER IF EXISTS tg_fixed_assets_touch ON fixed_assets;
CREATE TRIGGER tg_fixed_assets_touch
    BEFORE UPDATE ON fixed_assets
    FOR EACH ROW EXECUTE FUNCTION trg_touch_updated_at();

COMMENT ON TABLE  fixed_assets IS
    'Things the business owns and uses rather than sells. Carried at cost less straight-line depreciation.';
COMMENT ON COLUMN fixed_assets.useful_life_months IS
    'Months over which the asset is written down. 0 means never — land only.';
COMMENT ON COLUMN fixed_assets.residual_value IS
    'What it is reckoned to be worth at the end of its life. Depreciation never takes the book value below this.';

-- ─── Net book value on a date, in one place ─────────────────
--  SQL and PHP both need this and must never disagree about it,
--  so the arithmetic is written once here and the PHP asks the
--  database for it.
CREATE OR REPLACE FUNCTION fixed_asset_nbv(
    p_cost NUMERIC, p_residual NUMERIC, p_life_months INTEGER,
    p_purchased DATE, p_as_of DATE
) RETURNS NUMERIC AS $$
DECLARE
    months     INTEGER;
    depreciable NUMERIC;
BEGIN
    IF p_purchased IS NULL OR p_as_of < p_purchased THEN
        RETURN 0;                       -- not owned yet
    END IF;
    IF COALESCE(p_life_months, 0) <= 0 THEN
        RETURN p_cost;                  -- land: never written down
    END IF;

    months := (DATE_PART('year', AGE(p_as_of, p_purchased)) * 12
             + DATE_PART('month', AGE(p_as_of, p_purchased)))::INTEGER;
    depreciable := GREATEST(p_cost - COALESCE(p_residual, 0), 0);

    RETURN ROUND(
        GREATEST(
            p_cost - depreciable * LEAST(months::NUMERIC / p_life_months, 1),
            COALESCE(p_residual, 0)
        ), 2);
END;
$$ LANGUAGE plpgsql IMMUTABLE;

COMMIT;
