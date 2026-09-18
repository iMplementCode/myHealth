# Running the pharmacy

What the pharmacy-specific parts do, why they are shaped that way,
and the things somebody operating this needs to know before the
first customer.

---

## The shape of it

| | |
|---|---|
| A **drug** is a `products` row with a generic name, strength, form and route. |
| **Stock** lives in `product_batches` — batch number, expiry, what remains. |
| A **sale** takes stock out of named batches and writes an invoice. |
| A **patient** is who the medicine is for. A **customer** is who pays. |
| A **visit** is the encounter a hospital will hang wards and lab orders off. |

---

## Setting up a shop

### 1. Enter the shelves

**Inventory → Products.** The fields that matter beyond name and
price:

- **Generic name.** The single most valuable field on the form.
  Somebody asks for Panadol, the shelf has Hedex, both are
  paracetamol — the counter search matches on all three. It is also
  what stops a customer being sold a second box of something they
  already took under another name.
- **Strength and form.** "Amoxil 250" and "Amoxil 500" are two
  rows, never one. A shop that records only "Amoxil" cannot count
  its own stock.
- **Prescription only.** The counter refuses to sell these without
  a prescriber recorded. Set it on every antibiotic.
- **Controlled class.** Narcotics and psychotropics. Nothing
  special happens yet beyond the flag — see *What is not built* —
  but recording it from the start means the register has data when
  it arrives.
- **Storage.** Cold-chain stock that spent a night out of the
  fridge is waste, and nobody can tell by looking.

### 2. Receive stock into batches

**Purchasing → GRNs.** Record the batch number and expiry on every
receipt. This is not optional bookkeeping:

> Everything the pharmacy does differently from a shop depends on
> it. Without an expiry, nothing warns you before stock dies.
> Without a batch number, a recall means telephoning every
> customer you have.

A drug with no batches at all still sells, on its plain stock
figure, so a shop part-way through entering its shelves keeps
working. A drug **with** batches must be dispensed from one.

### 3. Register patients as they come

**Patients → Register Patient.** Not required to sell — somebody
buying plasters is not a patient — but needed for anyone on
repeat medication, anyone with allergies, and anyone whose
prescription you are filling.

---

## Selling

**Counter.** Type part of a name, click the result, take the money.

Things it does that are worth knowing:

- **It shows what can be dispensed, not what is in stock.** Those
  differ by whatever has expired. Offering stock the sale then
  refuses wastes the one thing a counter has none of.
- **It dispenses oldest-expiry-first.** Not oldest-arrival. The box
  that came in first is not always the one that dies first.
- **It refuses expired stock** and says how much is sitting there,
  so you know the difference between "order more" and "go and clear
  the shelf".
- **It refuses a prescription-only medicine** with no prescriber
  recorded.
- **It shows a patient's allergies** in red when one is attached.

If the sale fails — short stock, no prescriber, a bad patient —
**nothing happens at all**. No invoice, no stock movement, no
payment, no gap in the invoice numbering.

### The same drug twice

Refused. Put the whole quantity on one line. Merging two lines at
different prices would mean inventing a third price and charging
something nobody chose, and `invoice_items` is uniquely indexed on
(invoice, product) anyway.

---

## Things that will bite you

**The batches and the headline stock figure can drift.** They are
maintained by different code — a stock take adjusts one, a sale the
other. The first anybody notices is a sale refused for stock the
screen says is there. `batch_drift()` in `includes/dispensing.php`
finds them across the catalogue; there is still no screen listing
them all. Per batch, the trace page says so directly: anything that
left without being dispensed or written off is named as a quantity
the recall list is short by.

**Expired stock stays in the batch until somebody writes it off.**
Deliberately: somebody has to physically pull it off the shelf, and
zeroing it in the system would mean nobody ever does. It is excluded
from everything sellable and reported separately. When it has been
destroyed, record that on **Inventory &rarr; Batches & Expiry** &rarr;
the batch &rarr; *Write stock off*, which takes it out of the batch,
out of the headline figure and out of the valuation, and keeps a
dated record of the reason and the witness.

**A returned box goes back to its own batch**, never to whichever
batch is convenient — a returned box has an expiry printed on it,
and moving it would launder that date.

**Two tills, one last box.** Handled: the batch row is locked, so
the second till is told "out of stock" rather than selling the same
box twice. This is tested with two real concurrent processes.

---

## What is **not** built, and should not be assumed

Be straight with customers about these.

| | |
|---|---|
| **Controlled-substances register** | Built — Reports &rarr; Controlled Drugs. Derived from recorded receipts and dispensing, with a running balance. Entries are **not individually signed or witnessed**, so where the law requires a bound register this does not replace it. |
| **Allergy checking** | Allergies are shown, never matched. Free text cannot be matched safely — "pen VK" is penicillin and no substring search will know. |
| **Prescriptions as records** | The counter stores a prescriber name and reference on the invoice. A real prescription — patient, dose, frequency, duration, refills, partial fills — belongs with the patient record and is not here. |
| **Insurance / SHA claims** | The membership number is stored. Nothing batches or submits claims. |
| **Wards, appointments, lab** | Tables are shaped so these attach to `visits` without a rewrite. None of them exist. |

---

## Where the code is

| | |
|---|---|
| `includes/pharmacy.php` | The trade's vocabulary — forms, routes, storage, controlled classes. **Changing a list here means widening a CHECK constraint in the same commit.** |
| `includes/dispensing.php` | Batches in, batches out, FEFO, reversal, drift. |
| `includes/counter.php` | What a sale is allowed to be. |
| `includes/patients.php` | The patient record. |
| `public/counter/index.php` | The screen. Displays; does not judge. |
| `includes/batches.php` | Reading batches and the recall trace. Reads only. |
| `includes/disposals.php` | Writing stock off, and why it — unlike dispensing — moves the headline figure too. |
| `includes/controlled.php` | The controlled drugs register, derived from receipts and issues. |
| `public/modules/inventory/batches.php` | What is on the shelf, what dies when, and where a batch went. |
| `public/reports/controlled_register.php` | The register, per drug, with a running balance. |
| Migrations 060–065 | CCTV removed, drugs, batch allocations, the counter, patients, disposals. |
