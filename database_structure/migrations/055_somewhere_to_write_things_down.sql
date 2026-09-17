-- ============================================================
--  Somewhere to write things down
-- ------------------------------------------------------------
--  Every other table in this system records a transaction: goods
--  moved, money changed hands, a document was raised. None of
--  them hold the sentence somebody needs to remember — what the
--  customer said on the phone, what the site survey found, what
--  was agreed in a meeting, why a price was given.
--
--  That sentence currently lives on paper, or in somebody's
--  phone, and it is the first thing lost when they are not in.
--
--  ── Shape ───────────────────────────────────────────────────
--  A note is a title and a body. Everything else is there to
--  help find it again:
--
--    category    what kind of note it is, for filtering
--    is_pinned   the handful that should stay at the top
--    visibility  'private' or 'shared'
--
--  ── On the word "private" ───────────────────────────────────
--  It means the author and an administrator, and not anybody
--  else signed in. An administrator can read the database
--  directly, so promising more than that would be a lie told by
--  a checkbox. The labels in the application say so plainly.
--
--  A note is deliberately not attached to a customer, an invoice
--  or a job. Attaching it would mean deciding, now, which of
--  those it hangs from — and the note somebody most needs to
--  write is usually the one that does not fit a form yet. When a
--  real pattern shows up in what people actually write, that is
--  the moment to add the link, with evidence for where it goes.
-- ============================================================

CREATE TABLE IF NOT EXISTS notes (
    note_id     SERIAL PRIMARY KEY,
    title       VARCHAR(200)  NOT NULL,
    body        TEXT          NOT NULL,
    category    VARCHAR(40)   NOT NULL DEFAULT 'general',
    visibility  VARCHAR(10)   NOT NULL DEFAULT 'private',
    is_pinned   BOOLEAN       NOT NULL DEFAULT FALSE,
    created_by  INTEGER       REFERENCES users(user_id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),

    CONSTRAINT notes_visibility_check CHECK (visibility IN ('private', 'shared')),
    -- A note with a blank title is one nobody will ever find
    -- again, and a note with a blank body is not a note.
    CONSTRAINT notes_title_not_blank CHECK (btrim(title) <> ''),
    CONSTRAINT notes_body_not_blank  CHECK (btrim(body) <> '')
);

COMMENT ON COLUMN notes.visibility IS
    'private = the author and administrators; shared = anybody signed in.';

-- The list is ordered pinned-first, then most recently touched,
-- and that is the only order it is ever read in.
CREATE INDEX IF NOT EXISTS ix_notes_recent
    ON notes (is_pinned DESC, updated_at DESC);

-- Every list is filtered by who may see it before anything else,
-- and the two halves of that are "mine" and "shared".
CREATE INDEX IF NOT EXISTS ix_notes_author
    ON notes (created_by);
CREATE INDEX IF NOT EXISTS ix_notes_shared
    ON notes (updated_at DESC) WHERE visibility = 'shared';
