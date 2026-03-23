<?php
/** php-ar by marc at azestudio.net & co. **/

class db {
    private static ?mysqli $conn = null;

    static function init(string $host, string $user, string $pass, string $name): void {
        if (self::$conn) return;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$conn = mysqli_connect($host, $user, $pass, $name);
        mysqli_set_charset(self::$conn, 'utf8mb4');
    }

    static function conn(): mysqli {
        return self::$conn ?? throw new \RuntimeException("db: not initialized");
    }

    static function query(string $sql, array $bind = []): \mysqli_result|bool {
        $stmt = mysqli_prepare(self::conn(), $sql);
        if ($bind) {
            $types = implode('', array_map(
                fn($v) => match(true) { is_int($v) => 'i', is_float($v) => 'd', default => 's' },
                $bind
            ));
            mysqli_stmt_bind_param($stmt, $types, ...$bind);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt) ?: true;
        mysqli_stmt_close($stmt);
        return $result;
    }

    static function exec(string $sql, array $bind = []): int {
        self::query($sql, $bind);
        return mysqli_affected_rows(self::conn());
    }

    static function lastid(): int  { return mysqli_insert_id(self::conn()); }
    static function begin(): void  { mysqli_begin_transaction(self::conn()); }
    static function commit(): void { mysqli_commit(self::conn()); }
    static function rollback(): void { mysqli_rollback(self::conn()); }

    static function fetch(sel|string $q, ?string $as = null): rows {
        [$sql, $bind] = $q instanceof sel ? [$q->sql(), $q->bind] : [$q, []];
        return new rows(self::query($sql, $bind), $as ?: ($q instanceof sel ? $q->class() : null));
    }
}

class sel {
	public array $bind = [], $alias = null;
    private string $from;
    private ?string $cls = null;
    private array $cols=['*'],$where=[],$join=[],$group=[],$order=[];
    private int $lim = 0, $off = 0;
    
    public function __construct(string|sel $from) {
        match(true) {
            $from instanceof sel => $this->init_sub($from),
            class_exists($from) && defined("$from::TBL") => $this->init_cls($from),
            default => $this->from = preg_match('/^\w+$/', $from) ? "`$from`" : $from,
        };
    }

    private function init_sub(sel $sub): void {
        $alias = $sub->alias ?? 'sub_' . uniqid();
        $this->from = "({$sub->sql()}) AS `$alias`";
        $this->bind  = $sub->bind;
    }

    private function init_cls(string $cls): void {
        $this->from = "`".$cls::TBL."`";
        $this->cls  = $cls;
    }

    function class(): ?string { return $this->cls; }

    function as(string $alias): static { $this->alias = $alias; return $this; }
    function select(string ...$cols): static { $this->cols = $cols; return $this; }
    function join(string $expr): static  { $this->join[]  = $expr; return $this; }
    function group(string $expr): static { $this->group[] = $expr; return $this; }
    function order(string $expr): static { $this->order[] = $expr; return $this; }
    function limit(int $n, int $off = 0): static { $this->lim = $n; $this->off = $off; return $this; }

    function whereFk(ar $obj): static {
        return $this->where("`fk_".$obj::TBL."` = ?", $obj->id());
    }

    function where(string $expr, mixed ...$vals): static {
        if (!$vals) { $this->where[] = $expr; return $this; }

        if (substr_count($expr, '?') !== count($vals))
            throw new \InvalidArgumentException("sel: placeholder mismatch in: $expr");

        $parts = explode('?', $expr);
        $out   = '';
        $extra = [];

        foreach ($vals as $i => $val) {
            $out .= $parts[$i];
            match(true) {
                $val instanceof sel => ($out .= "({$val->sql()}")   && array_push($extra, ...$val->bind),
                is_array($val)      => ($out .= implode(',', array_fill(0, count($val), '?'))) && array_push($extra, ...array_values($val)),
                $val instanceof ar  => ($out .= '?')                && ($extra[] = $val->id()),
                default             => ($out .= '?')                && ($extra[] = $val),
            };
        }

        $this->where[] = $out . end($parts);
        array_push($this->bind, ...$extra);
        return $this;
    }

    function sql(): string {
        $sql = 'SELECT ' . implode(',', $this->cols) . ' FROM ' . $this->from;
        if ($this->join)  $sql .= ' ' . implode(' ', $this->join);
        if ($this->where) $sql .= ' WHERE ' . implode(' AND ', $this->where);
        if ($this->group) $sql .= ' GROUP BY ' . implode(',', $this->group);
        if ($this->order) $sql .= ' ORDER BY ' . implode(',', $this->order);
        if ($this->lim)   $sql .= " LIMIT {$this->lim}";
        if ($this->off)   $sql .= " OFFSET {$this->off}";
        return $sql;
    }

    function __toString(): string { return $this->sql(); }

    function fetch(?string $as = null): rows  { return db::fetch($this, $as ?? $this->cls); }
    function first(?string $as = null): ?ar   { return $this->limit(1)->fetch($as)->first(); }

    function count(): int {
        $c = (clone $this)->select('COUNT(1) as c');
        $c->order = []; $c->lim = 0; $c->off = 0;
        return (int)($c->first()?->c ?? 0);
    }

    function del(): int {
        $sql = "DELETE FROM {$this->from}";
        if ($this->where) $sql .= ' WHERE ' . implode(' AND ', $this->where);
        return db::exec($sql, $this->bind);
    }
}

class rows implements \Iterator, \Countable, \ArrayAccess{
    private const STRONG_LIMIT = 100;

    private ?\mysqli_result $res;
    private readonly ?string $cls;
    private readonly int $n;
    private int $pos = 0;

    private ?array $pool = null;
    private array $strong = [];
    private array $weak   = [];

    function __construct(\mysqli_result|bool $result, ?string $cls = null) {
        $this->res = $result instanceof \mysqli_result ? $result : null;
        $this->cls = $cls;
        $this->n   = $this->res ? mysqli_num_rows($this->res) : 0;
    }

    function __destruct() { $this->free(); }

    private function free(): void {
        if ($this->res) { mysqli_free_result($this->res); $this->res = null; }
        $this->strong = [];
        $this->weak   = [];
    }

    private function hydrate(array $data): ar {
        $cls = $this->cls ?? ar::class;
        return new $cls($data, true);
    }

    private function load(): void {
        if ($this->pool !== null) return;
        $this->pool = [];
        if (!$this->res) return;
        mysqli_data_seek($this->res, 0);
        while ($row = mysqli_fetch_assoc($this->res))
            $this->pool[] = $this->hydrate($row);
        $this->free();
    }
	public function each(): \Generator{
		if ($this->res) {
			mysqli_data_seek($this->res, 0);
			while ($data = mysqli_fetch_assoc($this->res))
				yield $this->hydrate($data);
			$this->free();
		}
	}

    private function at(int $i): ?ar {
        if ($this->pool !== null) return $this->pool[$i] ?? null;
        if (!$this->res) return null;

        if (isset($this->weak[$i]) && $row = $this->weak[$i]->get()) return $row;

        mysqli_data_seek($this->res, $i);
        if (!($data = mysqli_fetch_assoc($this->res))) return null;

        $row = $this->hydrate($data);
        $this->weak[$i]   = \WeakReference::create($row);
        $this->strong[$i] = $row;
        if (count($this->strong) > self::STRONG_LIMIT) array_shift($this->strong);
        return $row;
    }

    function first(): ?ar   { return $this->n ? $this->at(0) : null; }
    function all(): array   { $this->load(); return $this->pool; }
    function arr(): array   { return array_map(fn($r) => $r->arr(), $this->all()); }

    function col(string $key): array {
        return array_values(array_unique(array_filter(
            $this->pool !== null
                ? array_column($this->pool, $key)
                : array_map(fn($r) => $r->$key, iterator_to_array($this))
        )));
    }

    function ids(): array {
        return $this->col($this->cls::PK ?? throw new \Exception("rows: ids() needs a class"));
    }

    function map(?string $key = null): array {
        $this->load();
        $key ??= $this->cls::PK ?? throw new \Exception("rows: map() needs a key");
        return array_column($this->pool, null, $key);
    }

    function maplist(string $key): array {
        $result = [];
        foreach ($this->all() as $r) $result[$r->$key][] = $r->arr();
        return $result;
    }

    function refs(string $cls, string $fk = null): rows {
        $fk  ??= "fk_".$cls::TBL;
        $ids   = $this->col($fk);
        return $ids ? $cls::sel()->where("`$fk` IN (?)", $ids)->fetch() : new rows(false, $cls);
    }

    function rels(string $cls, string $fk = null): rows {
        $fk  ??= "fk_{$this->cls::TBL}";
        $ids   = $this->ids();
        return $ids ? $cls::sel()->where("`$fk` IN (?)", $ids)->fetch() : new rows(false, $cls);
    }

    // Iterator
    function rewind(): void  { $this->pos = 0; }
    function key(): int      { return $this->pos; }
    function next(): void    { $this->pos++; }
    function current(): ?ar  { return $this->at($this->pos); }
    function valid(): bool   { return $this->pos < $this->n; }
    function count(): int    { return $this->n; }

    // ArrayAccess
    function offsetExists(mixed $i): bool  { return is_int($i) && $i >= 0 && $i < $this->n; }
    function offsetGet(mixed $i): ?ar      { return $this->at($i); }
    function offsetSet(mixed $i, mixed $v): never { throw new \Exception("rows is read-only"); }
    function offsetUnset(mixed $i): never  { throw new \Exception("rows is read-only"); }
}

class ar implements \ArrayAccess{
    const TBL = '';
    const PK  = 'id';

    protected array $_data  = [];
    protected array $_dirty = [];
    protected bool  $_new   = true;

    function __construct(array $data = [], bool $fromdb = false)  {
        $this->_data = $data;
        $this->_new  = !$fromdb;
    }

    function __get(string $k): mixed  { return $this->_dirty[$k] ?? $this->_data[$k] ?? null; }
    function __set(string $k, mixed $v): void { $this->_dirty[$k] = $v; }
    function __isset(string $k): bool { return isset($this->_dirty[$k]) || isset($this->_data[$k]); }

    function id(): mixed   { return $this->{static::PK}; }
    function arr(): array  { return array_merge($this->_data, $this->_dirty); }
    function dirty(): array { return $this->_dirty; }
    function clean(): bool  { return empty($this->_dirty); }
    function is_new(): bool { return $this->_new; }

    static function sel(): sel         { return new sel(static::class); }
    static function where(string $expr, mixed ...$vals): sel { return static::sel()->where($expr, ...$vals); }
    static function find(mixed $id): ?static { return static::where('`' . static::PK . '` = ?', $id)->first(); }
    static function all(): rows         { return static::sel()->fetch(); }

    function rels(string $cls, string $fk = null): sel
    {
        return $cls::sel()->where('`' . ($fk ?? "fk_" . static::TBL) . '` = ?', $this->id());
    }

    function refs(string $cls, string $fk = null): ?ar
    {
        $fk_val = $this->{$fk ?? "fk_".$cls::TBL};
        return $fk_val ? $cls::find($fk_val) : null;
    }

    function save(): static
    {
        if (!$this->_dirty) return $this;

        if ($this->_new) {
            $cols = array_keys($this->_dirty);
            $sql  = "INSERT INTO `" . static::TBL . "` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ")"
                  . " VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
            db::query($sql, array_values($this->_dirty));
            $this->_data[static::PK] = $this->_dirty[static::PK] ?? db::lastid();
            $this->_new = false;
        } else {
            $sets = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($this->_dirty)));
            db::query(
                "UPDATE `" . static::TBL . "` SET $sets WHERE `" . static::PK . "` = ?",
                [...array_values($this->_dirty), $this->id()]
            );
        }

        $this->_data  = array_merge($this->_data, $this->_dirty);
        $this->_dirty = [];
        return $this;
    }

    function del(): bool
    {
        if ($this->_new) return false;
        db::exec("DELETE FROM `" . static::TBL . "` WHERE `" . static::PK . "` = ?", [$this->id()]);
        return true;
    }

    function reload(): static
    {
        if ($this->_new) throw new \Exception("ar: cannot reload unsaved record");
        $fresh = static::find($this->id()) ?? throw new \Exception("ar: record not found");
        $this->_data = $fresh->_data;
        $this->_dirty = [];
        return $this;
    }

    function mark_clean(): void
    {
        $this->_data  = array_merge($this->_data, $this->_dirty);
        $this->_dirty = [];
    }

    function offsetExists(mixed $k): bool  { return isset($this->_data[$k]); }
    function offsetGet(mixed $k): mixed     { return $this->_data[$k]; }
    function offsetSet(mixed $k, mixed $v): void { $this->_data[$k] = $v; }
    function offsetUnset(mixed $k): void    { unset($this->_data[$k]); }
}

class ar_buffer{
    private array $pool = [];

    function add(ar $record): void {
        if ($record->clean()) return;
        $this->pool[get_class($record)][spl_object_id($record)] = $record;
    }

    function flush(): int {
        if (!$this->pool) return 0;
        $affected = 0;
        db::begin();
        try {
            foreach ($this->pool as $cls => $records)
                $affected += $this->upsert($cls, array_values($records));
            db::commit();
        } catch (\Throwable $e) {
            db::rollback();
            throw $e;
        }
        $this->pool = [];
        return $affected;
    }

    private function upsert(string $cls, array $records): int
    {
        $cols = [];
        foreach ($records as $r)
            foreach (array_keys($r->dirty()) as $k) $cols[$k] = true;
        if (!$cols) return 0;

        $cols     = array_keys($cols);
        $cols_sql = implode(',', array_map(fn($c) => "`$c`", $cols));
        $row_ph   = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $rows_sql = implode(',', array_fill(0, count($records), $row_ph));
        $updates  = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", array_filter($cols, fn($c) => $c !== $cls::PK)));

        $bind = [];
        foreach ($records as $r)
            foreach ($cols as $c) $bind[] = $r->$c ?? null;

        $sql = "INSERT INTO `".$cls::TBL."` ($cols_sql) VALUES $rows_sql"
             . ($updates ? " ON DUPLICATE KEY UPDATE $updates" : '');

        $affected = db::exec($sql, $bind);
        array_walk($records, fn($r) => $r->mark_clean());
        return $affected;
    }
}