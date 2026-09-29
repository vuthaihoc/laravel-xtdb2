# Bitemporal data with XTDB and Laravel

XTDB keeps every version of every row, along two independent time axes. This guide explains the two axes, the
driver's API, and how they solve common application problems that are awkward in a regular database: scheduled
changes, back-dated corrections, "what did we know then?" reports, historical lookups and erasure requests.

## Two kinds of time

| | Valid time | System time |
|---|---|---|
| Question it answers | *When is this true in the real world?* | *When did the database learn it?* |
| Columns | `_valid_from`, `_valid_to` | `_system_from`, `_system_to` |
| Who sets it | You, when you write (defaults to now, unbounded) | XTDB, at commit time; it cannot be changed |
| Typical use | Effective dates, schedules, corrections of the past | Audit, reproducing past reports, debugging |

A row therefore has a **history in valid time** (e.g. a price that was 10 in January and 12 from July) and each of
those versions has a **history in system time** (e.g. "on March 10 we corrected February's value"). Nothing is
overwritten: an update adds a version, a delete ends a validity period. Only `erase()` removes data.

By default a query reads the rows **valid now, as currently recorded**, so ordinary Laravel code keeps working and
only sees the present.

```
valid time →        Jan         Mar    Apr               Jul
price p1       |---- 10 ------|- 11 -|------ 10 ------|------ 12 ------→
```

## API summary

```php
// Reads (the "from" table)
->asOfValidTime($time)              // rows valid at $time
->validBetween($from, $to = null)   // rows valid at some point of the period ($to null: unbounded)
->forAllValidTime()                 // every valid-time version
->asOfSystemTime($time)             // as XTDB had recorded it at $time (alias: asOfTime())
->forAllSystemTime()                // including versions later corrected
->withValidTime()                   // also select _valid_from, _valid_to
->history()                         // forAllValidTime() + withValidTime() + oldest first
->readCurrent()                     // back to "valid now, as currently recorded"

// Writes
->validFrom($from, $to = null)      // insert: store the row for that period;
->validTo($to)                      // update/delete: change only that part of the history
->forAllValidTime()->update([...])  // change every version
->erase()                           // remove the rows and their whole history

// Eloquent (use LaravelXtdb\Eloquent\Bitemporal)
Model::asOfValidTime($t)->find($id);
$model->saveValidFrom($from, $to = null);
$model->deleteValidFrom($from, $to = null);
$model->versions();
$model->erase();
```

Times are a `DateTimeInterface`, a timestamp string (UTC unless it carries an offset) or a relative duration such as
`'-10s'`, `'-5m'`, `'-1h'`.

The examples below use models with both traits:

```php
use Illuminate\Database\Eloquent\Model;
use LaravelXtdb\Eloquent\Bitemporal;
use LaravelXtdb\Eloquent\HasXtdbKey;

class Price extends Model
{
    use Bitemporal, HasXtdbKey;

    protected $guarded = [];
}
```

## Use cases

### 1. Scheduled changes: a price list with effective dates

Publish next month's prices today. Nothing needs to run on the first of the month: queries at that date see the new
price, queries today still see the current one.

```php
$price = Price::where('sku', 'A-100')->firstOrFail();

$price->amount = 12;
$price->saveValidFrom('2026-07-01');                      // effective July 1st, unbounded

Price::where('sku', 'A-100')->value('amount');             // today (June): 10
Price::asOfValidTime('2026-07-15')->where('sku', 'A-100')->value('amount');   // 12

// A promotion for one week only: the price returns to 12 afterwards.
$price->amount = 9;
$price->saveValidFrom('2026-07-20', '2026-07-27');
```

The same pattern fits tax rates, shipping tariffs, feature flags with a launch date, or opening hours.

### 2. Back-dated corrections: salaries and payroll

An employee's raise is approved in March, retroactive to February 1st. Payroll for February was already paid with the
old salary. Valid time records the correction; system time still knows what payroll used.

```php
$payrollRanAt = now();                                     // February payroll: paid 1000

// March 10: the raise is recorded, valid from February 1st.
$salary = Salary::where('employee_id', $id)->firstOrFail();
$salary->amount = 1200;
$salary->saveValidFrom('2026-02-01');

// What is February's salary? (the corrected truth)
Salary::asOfValidTime('2026-02-15')->where('employee_id', $id)->value('amount');   // 1200

// What did payroll believe February's salary was when it ran?
Salary::asOfSystemTime($payrollRanAt)->asOfValidTime('2026-02-15')
    ->where('employee_id', $id)->value('amount');                                    // 1000

// Back pay owed for February: 1200 - 1000 = 200.
```

Without bitemporality you would need an audit table, a "corrections" table, or a copy of every payroll input.

### 3. "What did we know then?": reproducible reports and audits

A report sent to a regulator on the 1st must be reproducible later, even after data entry errors were fixed. Read the
data as XTDB had recorded it when the report was produced:

```php
$generatedAt = $report->generated_at;

$orders = Order::asOfSystemTime($generatedAt)
    ->whereBetween('placed_at', [$report->period_start, $report->period_end])
    ->get();                                               // exactly the rows the report used
```

For an audit trail of a single row, list every recorded version, including the corrected ones:

```php
DB::table('orders')->where('_id', $id)
    ->forAllValidTime()->forAllSystemTime()
    ->select('*', '_valid_from', '_valid_to', '_system_from', '_system_to')
    ->orderBy('_system_from')
    ->get();
```

### 4. Historical lookups: the address on an invoice

A customer moves. Invoices issued before the move must still show the old address, without copying it onto every
invoice.

```php
$address = Address::where('customer_id', $customer->getKey())->firstOrFail();
$address->fill(['street' => '12 New Street', 'city' => 'Hanoi']);
$address->saveValidFrom($movedOn);

// When printing an old invoice: the address valid on its issue date.
Address::asOfValidTime($invoice->issued_at)
    ->where('customer_id', $invoice->customer_id)
    ->first();
```

The same applies to product descriptions on past orders, an employee's department at the time of an expense, or
exchange rates on a transaction date.

### 5. Coverage periods and gaps: insurance policies and subscriptions

Find every policy that was active at some point during a claim period, and suspend a policy for a month without
losing its history.

```php
// Policies covering any day between the incident and the claim.
Policy::validBetween($incidentOn, $claimedOn)->where('customer_id', $id)->get();

// A one-month suspension: the policy is not valid during March, and valid again from April.
$policy->deleteValidFrom('2026-03-01', '2026-04-01');

Policy::asOfValidTime('2026-03-15')->find($policy->getKey());   // null
Policy::asOfValidTime('2026-04-15')->find($policy->getKey());   // the policy
```

### 6. History, undo and debugging

Show a record's timeline to users, or restore an earlier value.

```php
foreach ($price->versions() as $version) {
    echo "{$version->amount} from {$version->_valid_from} to ".($version->_valid_to ?? '∞');
}

// Restore the value that was valid on March 1st, from now on.
$old = Price::asOfValidTime('2026-03-01')->find($price->getKey());
$price->amount = $old->amount;
$price->saveValidFrom(now());
```

### 7. Deletion vs erasure (GDPR)

`delete()` ends a row's validity: it disappears from current queries but stays in history, which is what audits need.
A data subject's erasure request needs the data gone from every version:

```php
$customer->delete();   // no longer valid; still visible with forAllValidTime() / asOfValidTime(past)
$customer->erase();    // removed from every valid-time and system-time version
```

Erasure is final: `forAllValidTime()->forAllSystemTime()` no longer returns the row.

### 8. Month-end snapshots

Balances, stock levels or headcount at the end of each month, from the same table:

```php
$months = collect(range(1, 12))->map(fn ($month) => Carbon::create(2026, $month)->endOfMonth());

$headcount = $months->mapWithKeys(fn ($end) => [
    $end->format('Y-m') => Employee::asOfValidTime($end)->count(),
]);
```

## Things to keep in mind

- The time clauses apply to the query's `from` table. Joined tables and relation subqueries (`with()`, `whereHas()`)
  read the current rows; query them separately with their own `asOfValidTime()` when needed.
- An update or delete applies to a period: use `validFrom()`/`validTo()` (or `forAllValidTime()`), not
  `asOfValidTime()`, which is for reads.
- `upsert()` cannot take a valid time (XTDB's `PATCH` rejects `_valid_from`); use `saveValidFrom()` or an insert with
  `validFrom()`.
- `withTimestamps()`-style `created_at`/`updated_at` columns are ordinary values; XTDB's `_valid_from` and
  `_system_from` already tell when a version started and when it was recorded.
- `select *` does not return the temporal columns: use `withValidTime()`, `history()` or select them explicitly.
