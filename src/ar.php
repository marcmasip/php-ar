<?php
/** php-ar by marc at azestudio.net & co. **/
class db
{
    private static ?mysqli $conn = null;

    public static function init(string $host, string $user, string $pass, string $name): void
    {
        if (self::$conn) return;
        
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        
        try {
            self::$conn = mysqli_connect($host, $user, $pass, $name);
            mysqli_set_charset(self::$conn, 'utf8mb4');
        } catch (\mysqli_sql_exception $e) {
            throw new Exception("db: connection failed - " . $e->getMessage());
        }
    }

    public static function conn(): mysqli
    {
        if (!self::$conn) throw new Exception("db: not initialized");
        return self::$conn;
    }

    public static function safe(string $str): string
    {
        return mysqli_real_escape_string(self::conn(), $str);
    }

    public static function query(string $sql, array $bind = []): mysqli_result|bool
    {
        $stmt = mysqli_prepare(self::conn(), $sql);
        
        if (!empty($bind)) {
            $types = '';
            foreach ($bind as $val) {
                if (is_int($val)) $types .= 'i';
                elseif (is_float($val)) $types .= 'd';
                else $types .= 's'; 
            }
            mysqli_stmt_bind_param($stmt, $types, ...$bind);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt) ?: true;
        
        mysqli_stmt_close($stmt);

        return $result;
    }

    public static function exec(string $sql, array $bind = []): int
    {
        self::query($sql, $bind);
        return mysqli_affected_rows(self::conn());
    }

    public static function lastid(): int
    {
        return mysqli_insert_id(self::conn());
    }

    public static function fetch(sel|string $q, ?string $as = null): rows
    {
        $sql = $q instanceof sel ? $q->sql() : $q;
        $bind = $q instanceof sel ? $q->bind : [];
      
        $result = self::query($sql, $bind);
        return new rows($result, $as ?: false, $q instanceof sel ? $q : null);
    }

    public static function begin(): void
    {
        mysqli_begin_transaction(self::conn());
    }

    public static function commit(): void
    {
        mysqli_commit(self::conn());
    }

    public static function rollback(): void
    {
        mysqli_rollback(self::conn());
    }
}

class sel {
   private string $from;
    public ?string $alias = null;
    private array $cols = ["*"];
    private array $where = [];
    private array $join = [];
    private array $group = [];
    private array $order = [];
    private int $lim = 0;
    private int $off = 0;
    
    public array $bind = [];
	
	public function as(string $alias): self
    {
        $this->alias = $alias;
        return $this;
    }

   public function __construct(string|sel $from)
    {
        if ($from instanceof sel) {
            $alias = $from->alias ?? 'sub_' . uniqid(); 
            $this->from = "(" . $from->sql() . ") AS `$alias`";
            $this->bind = $from->bind; //heredamos params de subconsulta
            
        } elseif (class_exists($from) && defined("$from::TBL")) {
            $this->from = "`" . $from::TBL . "`";
            
        } else {
            $this->from = preg_match('/^[a-zA-Z0-9_]+$/', $from) ? "`$from`" : $from;
        }
    }

    public function select(string ...$cols): self{
        $this->cols = $cols;
        return $this;
    }

    public function whereFk(ar $obj): self
    {
        $fk = "fk_" . $obj::TBL;
        $this->where[] = "`$fk` = ?";
        $this->bind[] = $obj->id();
        return $this;
    }

    public function where(string $expr, mixed ...$vals): self
    {
        if (empty($vals)) {
            $this->where[] = $expr;
            return $this;
        }

        $expected_params = substr_count($expr, '?');
        if ($expected_params !== count($vals)) {
            throw new InvalidArgumentException("sel: El número de '?' ($expected_params) no coincide con los valores pasados (" . count($vals) . ") en: $expr");
        }

        $parts = explode('?', $expr);
        $final_expr = '';
        $final_bind = [];

        foreach ($vals as $i => $val) {
            $final_expr .= $parts[$i];

            if ($val instanceof sel) {
                $final_expr .= '(' . $val->sql() . ')';
                array_push($final_bind, ...$val->bind);
                
            } elseif (is_array($val)) {
                if (empty($val)) throw new InvalidArgumentException("sel: No se puede pasar un array vacío a un placeholder '?'");
                $final_expr .= implode(',', array_fill(0, count($val), '?'));
                array_push($final_bind, ...array_values($val));
                
            } elseif ($val instanceof ar) {
                $final_expr .= '?';
                $final_bind[] = $val->id();
                
            } else {
                $final_expr .= '?';
                $final_bind[] = $val;
            }
        }

        $final_expr .= end($parts);

        $this->where[] = $final_expr;
        array_push($this->bind, ...$final_bind);

        return $this;
    }

    public function join(string $expr): self
    {
        $this->join[] = $expr;
        return $this;
    }

    public function group(string $expr): self
    {
        $this->group[] = $expr;
        return $this;
    }

    public function order(string $expr): self
    {
        $this->order[] = $expr;
        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->lim = $limit;
        $this->off = $offset;
        return $this;
    }

    public function sql(): string
    {
        $sql = "SELECT " . implode(",", $this->cols) . " FROM `{$this->from}`";
        
        if (!empty($this->join)) {
            $sql .= " " . implode(" ", $this->join);
        }
        if (!empty($this->where)) {
            $sql .= " WHERE " . implode(" AND ", $this->where);
        }
        if (!empty($this->group)) {
            $sql .= " GROUP BY " . implode(",", $this->group);
        }
        if (!empty($this->order)) {
            $sql .= " ORDER BY " . implode(",", $this->order);
        }
        if ($this->lim > 0) {
            $sql .= " LIMIT " . intval($this->lim);
        }
        if ($this->off > 0) {
            $sql .= " OFFSET " . intval($this->off);
        }
        
        return $sql;
    }

    public function __toString(): string
    {
        return $this->sql();
    }

    // Terminales
    public function fetch(string|bool $as = false): rows
    {
        if (!$as) {
            if (class_exists($this->from)) {
                $as = $this->from;
            }
        }
        return db::fetch($this, $as);
    }

    public function first(string|bool $as = false): ?ar
    {
        return $this->limit(1)->fetch($as)->first();
    }

    public function count(): int
    {
        $clone = clone $this;
        $clone->cols = ["COUNT(1) as c"];
        
        // Limpiamos órdenes o límites que puedan romper un COUNT simple
        $clone->order = []; 
        $clone->lim = 0;
        $clone->off = 0;
        
        $row = $clone->first(false);
        return $row?->c ?? 0;
    }

    public function del(): int
    {
        $sql = "DELETE FROM `{$this->from}`";
        if (!empty($this->where)) {
            $sql .= " WHERE " . implode(" AND ", $this->where);
        }
        return db::exec($sql, $this->bind);
    }
}

class rows implements Iterator, Countable, ArrayAccess
{
    private mysqli_result|null $res;
    private string|null $as;
    private ?sel $sel;
    private int $n = 0;
    private int $pos = 0;
    
    private ?array $all_rows = null;
    private array $strong_cache = [];
    private array $weak_cache = [];
    
    private const MAX_STRONG_CACHE = 100;

    public function __construct(mysqli_result|bool $result, string|bool $as = ar::class, ?sel $sel = null)
    {
        // Limpiamos los "bool|..." convirtiendo los false en null para evitar comprobaciones raras después
        $this->res = $result instanceof mysqli_result ? $result : null;
        $this->as = is_string($as) ? $as : null;
        $this->sel = $sel;
        $this->n = $this->res ? mysqli_num_rows($this->res) : 0;
    }

    public function __destruct()
    {
        $this->free_resources();
    }

    public function free_resources(): void
    {
        if ($this->res) {
            mysqli_free_result($this->res);
            $this->res = null;
        }
        $this->strong_cache = [];
        $this->weak_cache = [];
    }

    private function load_all(): void
    {
        if ($this->all_rows !== null) return;
        if (!$this->res) {
            $this->all_rows = [];
            return;
        }

        $this->all_rows = [];
        mysqli_data_seek($this->res, 0);
        $class = $this->as ?: ar::class;
        
        while ($data = mysqli_fetch_assoc($this->res)) {
            $this->all_rows[] = new $class($data, true);
        }
        
        $this->free_resources(); // Limpiamos red/RAM al instante
    }

    private function fetch_at(int $offset): mixed 
    {
        if ($this->all_rows !== null) return $this->all_rows[$offset] ?? null;
        if (!$this->res) return null;

        // 1. Caché débil
        if (isset($this->weak_cache[$offset]) && ($row = $this->weak_cache[$offset]->get())) {
            return $row;
        }

        // 2. Fetch real
        if (!mysqli_data_seek($this->res, $offset)) return null;
        $data = mysqli_fetch_assoc($this->res);
        if (!$data) return null;

        $class = $this->as ?: ar::class;
        $row = new $class($data, true);

        // 3. Escribir cachés
        $this->weak_cache[$offset] = \WeakReference::create($row);
        $this->strong_cache[$offset] = $row;

        if (count($this->strong_cache) > self::MAX_STRONG_CACHE) {
            array_shift($this->strong_cache); 
        }

        return $row;
    }

    // --- Terminales ---

    public function first(): mixed
    {
        return $this->n === 0 ? null : $this->fetch_at(0);
    }

    public function all(): array
    {
        $this->load_all();
        return $this->all_rows;
    }

    public function arr(): array
    {
        $this->load_all();
        return array_map(fn($r) => method_exists($r, 'arr') ? $r->arr() : (array)$r, $this->all_rows);
    }

    public function col(string $key): array
    {
        if ($this->all_rows !== null) {
            return array_unique(array_filter(array_column($this->all_rows, $key)));
        }

        $values = [];
        foreach ($this as $row) {
            $values[] = $row->$key ?? null; // Null safe
        }
        return array_unique(array_filter($values));
    }

    public function ids(): array
    {
        if (!$this->as) throw new Exception("rows: ids() requires a class");
        // DRY: ¡Eliminado el bucle redundante! `col()` ya hace exactamente esto.
        return $this->col($this->as::PK);
    }

    public function map(string $key = null): array
    {
        $this->load_all();
        $key ??= $this->as ? $this->as::PK : null; // Sintaxis moderna de coalescencia
        
        if ($key === null) throw new Exception("rows: map() requires a key");
        
        return array_column($this->all_rows, null, $key);
    }

    public function maplist(string $key): array
    {
        $this->load_all();
        $result = [];
        foreach ($this->all_rows as $row) {
            $result[$row->$key][] = method_exists($row, 'arr') ? $row->arr() : (array)$row;
        }
        return $result;
    }

    // --- Relaciones ---

    public function refs(string $cls, string $fk = null): rows
    {
        $fk ??= "fk_" . $cls::TBL;
        $ids = $this->col($fk);
        return empty($ids) ? new rows(false, $cls, null) : $cls::sel()->where("`$fk` IN (?)", $ids)->fetch();
    }

    public function rels(string $cls, string $fk = null): rows
    {
        if (!$this->as) throw new Exception("rows: rels() requires a class");
        $fk ??= "fk_" . $this->as::TBL;
        $ids = $this->ids();
        return empty($ids) ? new rows(false, $cls, null) : $cls::sel()->where("`$fk` IN (?)", $ids)->fetch();
    }

    // --- Iterator & Countable ---

    public function rewind(): void  { $this->pos = 0; }
    public function current(): mixed { return $this->fetch_at($this->pos); }
    public function key(): int      { return $this->pos; }
    public function next(): void    { $this->pos++; }
    public function count(): int    { return $this->n; }
    
    public function valid(): bool   
    { 
        return $this->pos < $this->n && $this->current() !== null; 
    }

    // --- Aporte: ArrayAccess ---
    // Permite hacer $rows[5] directamente para obtener la fila 6 sin usar bucles
    public function offsetExists(mixed $offset): bool { return is_int($offset) && $offset >= 0 && $offset < $this->n; }
    public function offsetGet(mixed $offset): mixed   { return $this->fetch_at($offset); }
    public function offsetSet(mixed $offset, mixed $value): void { throw new Exception("rows is read-only"); }
    public function offsetUnset(mixed $offset): void  { throw new Exception("rows is read-only"); }
}

/**
 * Active Record
 */
class ar implements ArrayAccess
{
    const TBL = '';
    const PK = 'id';

    protected array $_data = [];
    protected array $_dirty = [];
    protected bool $_new = true;

    public function __construct(array $data = [], bool $fromdb = false)
    {
        $this->_data = $data;
        $this->_new = !$fromdb;
    }

    // Magic
    public function __get(string $k): mixed
    {
        return $this->_dirty[$k] ?? $this->_data[$k] ?? null;
    }

    public function __set(string $k, mixed $v): void
    {
        $this->_dirty[$k] = $v;
    }

    public function __isset(string $k): bool
    {
        return isset($this->_dirty[$k]) || isset($this->_data[$k]);
    }

    // Getters
    public function id(): mixed
    {
        return $this->{static::PK};
    }

    public function arr(): array
    {
        return array_merge($this->_data, $this->_dirty);
    }

    public function dirty(): array
    {
        return $this->_dirty;
    }

    public function clean(): bool
    {
        return empty($this->_dirty);
    }

    // Query builders
    public static function sel(): sel
    {
        return new sel(static::class);
    }

    public static function where(string $expr, mixed ...$vals): sel
    {
        return static::sel()->where($expr, ...$vals);
    }

    public static function find(mixed $id)
    {
        return static::where("`" . static::PK . "` = ?", $id)->first();
    }

    public static function all(): rows
    {
        return static::sel()->fetch();
    }

    // Relaciones de instancia
    public function rels(string $cls, string $fk = null): sel
    {
        $fk = $fk ?: "fk_" . static::TBL;
        return $cls::sel()->where("`$fk` = ?", $this->id());
    }

    public function refs(string $cls, string $fk = null): ?static
    {
        $fk = $fk ?: "fk_" . $cls::TBL;
        $fk_val = $this->{$fk};
        return $fk_val ? $cls::find($fk_val) : null;
    }

    // Persistencia
    public function save(): self
    {
        if (empty($this->_dirty)) {
            return $this;
        }

        if ($this->_new) {
            $cols = array_keys($this->_dirty);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $cols_str = implode(',', array_map(fn($c) => "`$c`", $cols));
            
            $sql = "INSERT INTO `" . static::TBL . "` ($cols_str) VALUES ($placeholders)";
            db::query($sql, array_values($this->_dirty));
            
            if (isset($this->_dirty[static::PK])) {
                $this->_data[static::PK] = $this->_dirty[static::PK];
            } else {
                $this->_data[static::PK] = db::lastid();
            }
            
            $this->_new = false;
        } else {
            $sets = array_map(fn($c) => "`$c` = ?", array_keys($this->_dirty));
            $sql = "UPDATE `" . static::TBL . "` SET " . implode(',', $sets) . " WHERE `" . static::PK . "` = ?";
            
            $bind = array_values($this->_dirty);
            $bind[] = $this->id();
            
            db::query($sql, $bind);
        }

        $this->_data = array_merge($this->_data, $this->_dirty);
        $this->_dirty = [];

        return $this;
    }

    public function del(): bool
    {
        if ($this->_new) {
            return false;
        }

        $sql = "DELETE FROM `" . static::TBL . "` WHERE `" . static::PK . "` = ?";
        db::exec($sql, [$this->id()]);
        
        return true;
    }

    public function reload(): self
    {
        if ($this->_new) {
            throw new Exception("ar: cannot reload unsaved record");
        }

        $fresh = static::find($this->id());
        if (!$fresh) {
            throw new Exception("ar: record not found");
        }

        $this->_data = $fresh->_data;
        $this->_dirty = [];

        return $this;
    }

	#[\Override]
	public function offsetExists(mixed $offset): bool {
		return isset($this->_data[$offset]);
	}

	#[\Override]
	public function offsetGet(mixed $offset): mixed {
		return $this->_data[$offset];
	}

	#[\Override]
	public function offsetSet(mixed $offset, mixed $value): void {
		$this->_data[$offset] = $value;
	}

	#[\Override]
	public function offsetUnset(mixed $offset): void {
		unset( $this->_data[$offset] );
	}
}


class ar_buffer
{
    /** @var array<string, ar[]> */
    private array $pool = [];

    public function add(ar $record): void
    {
        if ($record->clean()) return;
        $cls = get_class($record);
        $hash = spl_object_hash($record); 
        $this->pool[$cls][$hash] = $record;
    }

    public function flush(): int
    {
        if (empty($this->pool)) return 0;

        $total_affected = 0;
        db::begin();
        try {
            foreach ($this->pool as $cls => $records) {
                $total_affected += $this->flush_class($cls, array_values($records));
            }
            db::commit();
        } catch (Exception $e) {
            db::rollback();
            throw clone $e;
        }

        $this->pool = [];
        return $total_affected;
    }

    private function flush_class(string $cls, array $records): int
    {
        $all_cols = [];
        foreach ($records as $r) {
            $keys = $r->is_new() ? array_keys($r->dirty()) : array_merge(array_keys($r->dirty()), [$cls::PK]);
            foreach ($keys as $k) $all_cols[$k] = true;
        }

        if (empty($all_cols)) return 0;
        
        $cols = array_keys($all_cols);
        $cols_str = implode(',', array_map(fn($c) => "`$c`", $cols));
        $row_placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        
        $values_sql = [];
        $binds = [];

        foreach ($records as $r) {
            $values_sql[] = $row_placeholders;
            foreach ($cols as $col) {
                $binds[] = $r->$col ?? null; 
            }
        }

        $update_parts = [];
        foreach ($cols as $col) {
            if ($col !== $cls::PK) {
                $update_parts[] = "`$col` = VALUES(`$col`)";
            }
        }

        $sql = "INSERT INTO `" . $cls::TBL . "` ($cols_str) VALUES " . implode(', ', $values_sql);
        if (!empty($update_parts)) {
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts);
        }

        $affected = db::exec($sql, $binds);

        foreach ($records as $r) {
            $r->mark_clean(); 
        }

        return $affected;
    }
}