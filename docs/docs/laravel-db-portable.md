# laravel-db-portable

The driver requires [laravel-db-portable](https://github.com/vuthaihoc/laravel-db-portable), and its query builder
implements that package's contracts:

- `HistoricalReads`: `asOfTime($time)` reads the `from` table as XTDB had stored it then
  (`FOR SYSTEM_TIME AS OF`; a DateTimeInterface, a timestamp or `'-10s'`), `readCurrent()` undoes it,
  `readStale()` reads current data (reads do not contend with writes).
- `SearchBox`: `whereStartsWith()`, `whereContains()` and `suggest()` compare `lower()` values with `position()`;
  XTDB has no full-text search, so `searchFullText()` and `*FullTextRelevance()` throw `UnsupportedFeatureException`.

```php
Order::query()->asOfTime(now()->subHour())->sum('total');   // the totals as they were an hour ago
Word::suggest('word', $search)->limit(10)->get();
```
