-- ============================================================
--  A note on one line of a quote
-- ------------------------------------------------------------
--  A quote already carries two blocks of prose — Notes and
--  Terms — and both sit at the bottom, under everything. That
--  works for "prices hold for 30 days". It does not work for
--  the sentence that belongs to one line and no other:
--
--      4 × Dome camera 5MP      ... "two of these go on the
--                                    rear wall, cabling through
--                                    the existing conduit"
--      1 × NVR 8-channel        ... "customer supplies the
--                                    hard drive"
--
--  Put those at the foot of the document and the reader has to
--  work out which line each one is about. On a quote with nine
--  lines they will get it wrong.
--
--  So the note goes on the line, and prints under the item it
--  belongs to.
--
--  ── On all four line tables, not just quotes ────────────────
--  A quote does not stay a quote. It becomes a proforma, which
--  becomes an invoice. Three statements copy lines forward:
--
--      quote_items            -> proforma_invoice_items
--      proforma_invoice_items -> invoice_items
--      sales_order_items      -> invoice_items
--
--  The third is why sales_order_items gets the column too. A
--  sales order builds its own lines and its form has not opted
--  into line notes, so the column sits empty today — but the
--  statement that copies those lines into an invoice is written
--  to carry a note, and a column that is missing on one side of
--  a copy is how the note would be lost the day somebody does
--  opt that form in. It is one nullable column against a silent
--  data loss later. (There is no quote-to-sales-order copy: a
--  sales order records which quote it came from, but its lines
--  are entered, not inherited.)
--
--  Put the column only on quotes and the note survives exactly
--  as long as the quote does. The moment somebody converts it,
--  the sentence explaining the line is gone — and the customer
--  gets an invoice that says less than the quote they agreed
--  to. That is not a feature with a limitation, it is a trap,
--  and the person who falls into it is the one who trusted the
--  field enough to use it.
--
--  Nullable, no default. A line without a note has no note, and
--  the printed document leaves the space empty rather than
--  finding something to say.
-- ============================================================

ALTER TABLE quote_items            ADD COLUMN IF NOT EXISTS note VARCHAR(500);
ALTER TABLE proforma_invoice_items ADD COLUMN IF NOT EXISTS note VARCHAR(500);
ALTER TABLE sales_order_items      ADD COLUMN IF NOT EXISTS note VARCHAR(500);
ALTER TABLE invoice_items          ADD COLUMN IF NOT EXISTS note VARCHAR(500);

COMMENT ON COLUMN quote_items.note IS
    'Free text about this line, printed under the item on the quote.';
COMMENT ON COLUMN proforma_invoice_items.note IS
    'Free text about this line. Copied from the quote it came from.';
COMMENT ON COLUMN sales_order_items.note IS
    'Free text about this line. Copied from the quote it came from.';
COMMENT ON COLUMN invoice_items.note IS
    'Free text about this line, printed under the item. Carried across on conversion.';

-- No index. The column is only ever read as part of the line it
-- belongs to, by a query that already has the document id, and it
-- is never filtered or sorted on.
