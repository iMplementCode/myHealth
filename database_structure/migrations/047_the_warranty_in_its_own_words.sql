-- ============================================================
--  The warranty, in the company's own words
-- ------------------------------------------------------------
--  Migration 046 gave a warranty note three blocks of text:
--  what is covered, what is not, how to claim. That was a guess
--  at the shape of the document. The real one — Equipment
--  Warranty Terms & Conditions — is seventeen numbered sections
--  with lettered sub-headings, an equipment schedule, a
--  signature block and a summary box.
--
--  Three fields cannot hold that. Squeezing it in would mean one
--  enormous textarea per block, no numbering, and no way to edit
--  section 9 without scrolling through the other sixteen.
--
--  ── So the terms become a list of sections ──────────────────
--
--  warranty_template_sections  one row per heading. Ordered,
--                              editable, insertable. The numbers
--                              are DERIVED from the order, so
--                              adding a section in the middle
--                              renumbers the rest instead of
--                              leaving the document lying about
--                              its own structure.
--
--  Four levels, because the document has four:
--      0  no heading  — the opening paragraphs
--      1  numbered    — "1. WARRANTY COVERAGE"
--      2  lettered    — "A. Physical Damage or Misuse"
--      3  plain       — "Equipment Warranty Schedule"
--
--  Four layouts, because a section is not always prose:
--      prose      paragraphs and bullets
--      schedule   the equipment table, then the body under it
--      signature  the sign-off block
--      summary    label/value rows in a box
--
--  ── The copied-terms guarantee still holds ──────────────────
--  A note took a copy of the wording at issue, and that does not
--  change: warranty_notes.terms_json now holds the whole section
--  list as it stood that day. Edit a section next year and a
--  note handed over today still prints what was promised. The
--  three old columns stay, nullable, so the notes issued before
--  this migration keep printing exactly as they did.
-- ============================================================

-- ── The sections ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS warranty_template_sections (
    section_id  SERIAL PRIMARY KEY,
    template_id INTEGER NOT NULL
                REFERENCES warranty_templates (template_id) ON DELETE CASCADE,

    -- Sparse on purpose (10, 20, 30…) so a section can be dropped
    -- between two others without rewriting the whole list.
    sort_order  INTEGER NOT NULL DEFAULT 0,

    heading     VARCHAR(160),
    level       SMALLINT NOT NULL DEFAULT 1 CHECK (level BETWEEN 0 AND 3),
    layout      VARCHAR(20) NOT NULL DEFAULT 'prose'
                CHECK (layout IN ('prose', 'schedule', 'signature', 'summary')),

    -- Blank line = new paragraph. A line starting "- " is a bullet.
    -- In a summary section, "Label: text" becomes a row.
    body        TEXT,

    created_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_warranty_sections_template
    ON warranty_template_sections (template_id, sort_order);

-- ── The note remembers the whole document, not three fields ──
ALTER TABLE warranty_notes ADD COLUMN IF NOT EXISTS terms_json JSONB;

-- Notes issued before this migration have no terms_json and keep
-- printing from these; notes issued after have no need of them.
ALTER TABLE warranty_notes ALTER COLUMN cover_text DROP NOT NULL;

-- The document asks for both dates and says which one starts the
-- clock: delivery, or installation where we installed. start_date
-- stays the authoritative answer; these two record how it was
-- arrived at, which is what a customer disputing a date will ask.
ALTER TABLE warranty_notes ADD COLUMN IF NOT EXISTS delivery_date DATE;
ALTER TABLE warranty_notes ADD COLUMN IF NOT EXISTS installation_date DATE;

-- Signed-off notes: who accepted it and when, if it comes back.
ALTER TABLE warranty_notes ADD COLUMN IF NOT EXISTS signed_by VARCHAR(160);
ALTER TABLE warranty_notes ADD COLUMN IF NOT EXISTS signed_on DATE;

-- ── The schedule has columns the items table did not ─────────
--  "Brand / Model" is not the product name: the invoice says
--  "4MP Dome Camera" and the box says "Hikvision DS-2CD1143G0".
--  A warranty claim is settled on the second one.
ALTER TABLE warranty_note_items ADD COLUMN IF NOT EXISTS brand_model VARCHAR(160);

--  A per-item start, because a note can cover a camera fitted in
--  March and a recorder swapped in June. Defaults to the note's
--  start date, which is the usual case.
ALTER TABLE warranty_note_items ADD COLUMN IF NOT EXISTS starts_on DATE;

UPDATE warranty_note_items i
   SET starts_on = n.start_date
  FROM warranty_notes n
 WHERE n.warranty_id = i.warranty_id AND i.starts_on IS NULL;

-- The template's own three columns are no longer where the words
-- live. Left in place, nullable, so nothing that still reads them
-- breaks on the way past.
ALTER TABLE warranty_templates ALTER COLUMN cover_text DROP NOT NULL;

-- ============================================================
--  Seed: the company's Equipment Warranty Terms & Conditions
-- ------------------------------------------------------------
--  Every word of this is editable under Settings → Warranty
--  Templates. It is seeded so a new installation has a complete,
--  usable document on day one rather than a blank form.
-- ============================================================
INSERT INTO warranty_template_sections (template_id, sort_order, heading, level, layout, body)
SELECT t.template_id, s.sort_order, s.heading, s.level, s.layout, s.body
  FROM warranty_templates t
 CROSS JOIN (VALUES

 (10, NULL, 0, 'prose',
  'Thank you for purchasing from us. We stand behind the quality of the equipment supplied by our company and provide the warranty described in this document.'
  || chr(10) || chr(10) ||
  'This Warranty Note sets out the terms under which equipment supplied by us is covered against defects in materials and workmanship.'),

 (20, 'Warranty Coverage', 1, 'prose',
  'We warrant that the equipment listed on this Warranty Note will, during the applicable warranty period, be free from defects in materials and workmanship when used under normal operating conditions and for its intended purpose.'
  || chr(10) || chr(10) ||
  'The warranty period applicable to each item is stated in the equipment schedule, invoice, delivery note, or other accompanying documentation.'
  || chr(10) || chr(10) ||
  'Unless otherwise stated in writing, the warranty period begins on the date of delivery to the customer.'
  || chr(10) || chr(10) ||
  'Where we are responsible for installation, the warranty may instead begin on the date of installation, as stated on the relevant installation record.'),

 (25, 'Equipment Warranty Schedule', 3, 'schedule',
  'The warranty applies only to the equipment and warranty period specifically identified in the applicable documentation.'),

 (30, 'What The Warranty Covers', 1, 'prose',
  'During the applicable warranty period, if an item develops a fault resulting from a defect in materials or workmanship, we will, at our discretion:'
  || chr(10) ||
  '- repair the defective equipment;' || chr(10) ||
  '- replace the defective equipment with an equivalent or functionally comparable item; or' || chr(10) ||
  '- where appropriate, provide another reasonable remedy permitted under applicable law.'
  || chr(10) || chr(10) ||
  'There will normally be no charge for parts or labour required to repair a covered defect.'
  || chr(10) || chr(10) ||
  'If the original product is no longer available, we may replace it with an equivalent or better-specification product, subject to availability.'
  || chr(10) || chr(10) ||
  'A repaired or replacement item does not automatically receive a new warranty period. Unless otherwise required by applicable law or expressly agreed in writing, it will remain covered only for the unexpired portion of the original warranty period.'),

 (40, 'Warranty Does Not Cover', 1, 'prose',
  'This warranty does not cover faults, damage or failure caused by:'),

 (41, 'Physical Damage or Misuse', 2, 'prose',
  '- accidental or physical damage;' || chr(10) ||
  '- dropping, impact, crushing or improper handling;' || chr(10) ||
  '- misuse, abuse or negligence;' || chr(10) ||
  '- operation outside the equipment''s specified ratings, specifications or environmental conditions;' || chr(10) ||
  '- use for a purpose for which the equipment was not designed;' || chr(10) ||
  '- unauthorized modification, alteration or adaptation.'),

 (42, 'Electrical and Environmental Damage', 2, 'prose',
  '- lightning strikes;' || chr(10) ||
  '- electrical surges, spikes or unstable power;' || chr(10) ||
  '- incorrect voltage or electrical connection;' || chr(10) ||
  '- faulty power supplies or electrical installations;' || chr(10) ||
  '- flooding, water ingress or excessive moisture;' || chr(10) ||
  '- fire, smoke or excessive heat;' || chr(10) ||
  '- natural disasters or other external events beyond our reasonable control.'
  || chr(10) || chr(10) ||
  'Where applicable, customers are responsible for providing suitable surge protection, voltage regulation, grounding/earthing and other necessary electrical protection appropriate to the equipment.'),

 (43, 'Unauthorized Installation, Opening or Repair', 2, 'prose',
  'The warranty may be void where the equipment has been:'
  || chr(10) ||
  '- opened, dismantled or tampered with;' || chr(10) ||
  '- repaired or modified by a person not authorized by us;' || chr(10) ||
  '- installed, relocated or reinstalled contrary to our instructions or the manufacturer''s requirements;' || chr(10) ||
  '- subjected to unauthorized firmware, software or configuration modifications that cause or contribute to the fault.'
  || chr(10) || chr(10) ||
  'This does not prevent the customer from carrying out ordinary user-level settings or configuration expressly permitted by the manufacturer.'),

 (44, 'Consumables and Limited-Life Components', 2, 'prose',
  'Unless specifically stated otherwise, the warranty does not cover normal wear and tear or consumable items, including where applicable:'
  || chr(10) ||
  '- batteries;' || chr(10) ||
  '- fuses;' || chr(10) ||
  '- lamps;' || chr(10) ||
  '- removable storage media;' || chr(10) ||
  '- connectors or other parts designed to be periodically replaced;' || chr(10) ||
  '- components whose deterioration is a normal consequence of use.'
  || chr(10) || chr(10) ||
  'Where a battery or other consumable is supplied with equipment, any separate warranty period stated by the manufacturer or on the invoice will apply.'),

 (45, 'Third-Party Equipment and Services', 2, 'prose',
  'We are not responsible for faults caused by equipment, accessories, cabling, networks, power systems, software, applications, internet services or other products not supplied by us.'
  || chr(10) || chr(10) ||
  'For example, a failure caused by a defective third-party power supply, network cable, switch, router, internet connection, electrical installation or incompatible software will not normally be treated as a warranty defect in our equipment.'),

 (46, 'Serial Numbers and Identification', 2, 'prose',
  'Warranty coverage may not apply where the equipment''s serial number, identification label, security seal or other identifying mark has been removed, altered, damaged or defaced in a manner that prevents identification of the equipment.'),

 (50, 'Installation and Workmanship', 1, 'prose',
  'Where we provide installation services, installation workmanship is covered separately from the equipment warranty.'
  || chr(10) || chr(10) ||
  'An equipment warranty does not automatically cover damage caused by installation, relocation, alteration or maintenance performed by a third party.'
  || chr(10) || chr(10) ||
  'Where equipment is installed by the customer or another contractor, the customer is responsible for ensuring that the installation complies with the manufacturer''s requirements and applicable technical standards.'
  || chr(10) || chr(10) ||
  'For fixed installations, we may provide on-site warranty service where, in our reasonable assessment, the fault requires on-site attendance.'),

 (60, 'Warranty Claim Procedure', 1, 'prose',
  'To make a warranty claim, the customer should contact us and provide:'
  || chr(10) ||
  '- the Warranty Note Number;' || chr(10) ||
  '- the invoice or proof of purchase, where requested;' || chr(10) ||
  '- the equipment model and serial number;' || chr(10) ||
  '- a description of the fault;' || chr(10) ||
  '- details of when the fault occurred and, where relevant, the circumstances under which it occurs.'
  || chr(10) || chr(10) ||
  'We may carry out reasonable troubleshooting or testing before accepting a warranty claim.'
  || chr(10) || chr(10) ||
  'Please do not attempt to repair, dismantle or open the equipment before contacting us. Unauthorized repair or tampering may affect warranty eligibility.'
  || chr(10) || chr(10) ||
  'Where appropriate, we may issue a return authorization or other instructions before the equipment is brought or sent to us.'),

 (70, 'Inspection and Diagnosis', 1, 'prose',
  'All warranty claims are subject to reasonable inspection and diagnosis.'
  || chr(10) || chr(10) ||
  'If testing confirms that the failure is caused by a defect covered by this warranty, the equipment will be repaired or replaced in accordance with these terms.'
  || chr(10) || chr(10) ||
  'If testing establishes that the equipment is operating normally, or that the fault is caused by an excluded condition, we may return the equipment to the customer and may charge reasonable diagnostic, transport or service costs where these have been communicated to and agreed with the customer in advance.'
  || chr(10) || chr(10) ||
  'A fault that cannot be reproduced during testing may require additional information, testing or observation before a warranty determination can be made.'),

 (80, 'Return, Collection and Transport', 1, 'prose',
  'Unless otherwise agreed in writing, equipment requiring workshop repair must be returned to our premises for inspection and servicing.'
  || chr(10) || chr(10) ||
  'The customer should ensure that equipment is properly packaged to prevent damage during transportation.'
  || chr(10) || chr(10) ||
  'For fixed installations where removal is impractical or where on-site attendance is reasonably necessary, we may arrange an on-site inspection or repair.'
  || chr(10) || chr(10) ||
  'Warranty coverage for the equipment does not automatically include transportation, dismantling, reinstallation, mounting, configuration, civil works or other services unless specifically agreed in writing.'),

 (90, 'On-Site Warranty Service', 1, 'prose',
  'Where on-site warranty service is applicable, our technicians will attend the installation location within a reasonable period, subject to technician availability, location, accessibility and the nature of the fault.'
  || chr(10) || chr(10) ||
  'On-site service may be subject to reasonable limitations where:'
  || chr(10) ||
  '- the site is inaccessible;' || chr(10) ||
  '- the site presents unsafe working conditions;' || chr(10) ||
  '- specialist equipment or access arrangements are required;' || chr(10) ||
  '- the fault is caused by excluded equipment or circumstances;' || chr(10) ||
  '- the equipment must be removed and taken to our premises for further diagnosis.'
  || chr(10) || chr(10) ||
  'Where the fault is found not to be covered by this warranty, any chargeable site visit or service will be communicated to the customer before chargeable work is undertaken, where reasonably practicable.'),

 (100, 'Data and Configuration', 1, 'prose',
  'Customers are responsible for backing up their data, recordings, configurations and other information stored on equipment before submitting equipment for repair.'
  || chr(10) || chr(10) ||
  'We will take reasonable care of equipment submitted to us, but we are not responsible for loss of data, recordings, configurations or software resulting from warranty diagnosis, repair, replacement, firmware updates, resetting or replacement of storage components, except to the extent liability cannot lawfully be excluded.'
  || chr(10) || chr(10) ||
  'This is particularly important for equipment such as NVRs, DVRs, hard drives, storage devices, servers, computers and network equipment.'),

 (110, 'Software, Firmware and Third-Party Services', 1, 'prose',
  'Where equipment depends on software, firmware, cloud services, internet connectivity or third-party platforms, the warranty applies to the physical equipment to the extent described in this Warranty Note.'
  || chr(10) || chr(10) ||
  'Changes, discontinuation, incompatibility or failure of third-party software, cloud services, internet services or applications do not automatically constitute a hardware warranty defect.'
  || chr(10) || chr(10) ||
  'Where appropriate, we may assist the customer with troubleshooting, configuration or firmware updates, but such assistance does not create a warranty for third-party services.'),

 (120, 'Warranty Transfer', 1, 'prose',
  'Unless otherwise agreed in writing, this warranty applies to the original customer named on the relevant sales documentation and is not transferable to another person or business.'
  || chr(10) || chr(10) ||
  'Any transfer of ownership or use should be disclosed to us where warranty support is requested.'),

 (130, 'No Extension of Warranty Period', 1, 'prose',
  'Repair, replacement, inspection or temporary unavailability of equipment does not automatically extend the original warranty period.'
  || chr(10) || chr(10) ||
  'Unless otherwise required by applicable law or agreed in writing, the warranty expires on the original warranty expiry date stated in the applicable documentation.'),

 (140, 'Limitation of Warranty Remedies', 1, 'prose',
  'To the extent permitted by applicable law, our responsibility under this warranty is limited to the repair, replacement or other remedy expressly provided for a covered defect.'
  || chr(10) || chr(10) ||
  'We are not responsible for indirect or consequential losses arising from equipment failure, including loss of business, loss of revenue, loss of opportunity or loss of data, except where such limitation is prohibited by applicable law.'
  || chr(10) || chr(10) ||
  'Nothing in this Warranty Note is intended to exclude or restrict any rights or remedies that cannot lawfully be excluded or restricted.'),

 (150, 'Events Outside Our Control', 1, 'prose',
  'We will not be responsible for delays in warranty service caused by circumstances beyond our reasonable control, including delays in obtaining replacement parts, manufacturer support, transport disruptions, natural disasters, fire, civil disturbance, government restrictions, power failures, telecommunications failures or other events beyond our reasonable control.'
  || chr(10) || chr(10) ||
  'We will, however, make reasonable efforts to complete warranty service as soon as practicable.'),

 (160, 'Manufacturer Warranty', 1, 'prose',
  'Where the equipment is covered by a manufacturer''s warranty, the manufacturer''s warranty terms may also apply.'
  || chr(10) || chr(10) ||
  'The customer''s rights under any manufacturer''s warranty are not affected by this Warranty Note.'
  || chr(10) || chr(10) ||
  'Where appropriate, we may assist the customer in processing a manufacturer warranty claim.'),

 (170, 'Important Customer Responsibilities', 1, 'prose',
  'To help maintain warranty coverage, customers should:'
  || chr(10) ||
  '- operate the equipment according to the manufacturer''s instructions;' || chr(10) ||
  '- use suitable electrical protection;' || chr(10) ||
  '- maintain appropriate environmental conditions;' || chr(10) ||
  '- use compatible accessories and equipment;' || chr(10) ||
  '- retain invoices, receipts and warranty documentation;' || chr(10) ||
  '- keep equipment identification and serial numbers intact;' || chr(10) ||
  '- avoid unauthorized repairs or modifications;' || chr(10) ||
  '- maintain appropriate backups of important data and configurations.'),

 (180, 'Warranty Validation', 1, 'prose',
  'This Warranty Note should be retained together with the original invoice, receipt, delivery note or other applicable proof of purchase.'
  || chr(10) || chr(10) ||
  'Warranty claims may require verification of the equipment''s serial number and purchase records.'),

 (190, NULL, 0, 'signature', NULL),

 (200, 'Warranty Summary', 3, 'summary',
  'Covered: Defects in materials and workmanship occurring during the applicable warranty period under normal and intended use.' || chr(10) ||
  'Not Covered: Physical damage, misuse, unauthorized repair or modification, electrical surges, lightning, environmental damage, consumables, normal wear and tear, third-party equipment/services, and other exclusions stated in this document.' || chr(10) ||
  'How to Claim: Contact us with your Warranty Note Number, proof of purchase where required, serial number and description of the fault.' || chr(10) ||
  'Before Returning Equipment: Contact us first. Do not open, dismantle or attempt to repair the equipment.' || chr(10) ||
  'Repair / Replacement: Covered defects will be repaired or replaced at our discretion, subject to these terms and applicable law.' || chr(10) ||
  'Warranty Period: As stated against each item on the applicable warranty schedule or sales documentation.')

 ) AS s(sort_order, heading, level, layout, body)
 WHERE t.is_default
   AND NOT EXISTS (SELECT 1 FROM warranty_template_sections);
