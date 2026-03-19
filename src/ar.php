<?php
/** php-ar by marc at azestudio.net & co. **/
class db
{
    private static ?mysqli $conn = null;

    public static function init(string $host, string $user, string $pass, string $name): void
    {
        if (self::$conn) return;
        
        self::$conn = mysqli_connect($host, $user, $pass, $name);
        if (!self::$conn) {
            throw new Exception("db: connection failed");
        }
        mysqli_set_charset(self::$conn, 'utf8mb4');
    }

    public static function conn(): mysqli
    {
        if (!self::$conn) {
            throw new Exception("db: not initialized");
        }
        return self::$conn;
    }

    public static function safe(string $str): string
    {
        return mysqli_real_escape_string(self::conn(), $str);
    }

    public static function query(string $sql, array $bind = []): mysqli_result|bool
    {
        $stmt = mysqli_prepare(self::conn(), $sql);
        if (!$stmt) {
            throw new Exception("db: prepare failed - " . mysqli_error(self::conn()) . " | SQL: $sql");
        }

        if (!empty($bind)) {
            $types = str_repeat('s', count($bind));
            mysqli_stmt_bind_param($stmt, $types, ...$bind);
        }

        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("db: execute failed - " . mysqli_stmt_error($stmt) . " | SQL: $sql");
        }

        return mysqli_stmt_get_result($stmt) ?: true;
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

    public static function fetch(sel|string $q, string|bool $as = false): rows
    {
        $sql = $q instanceof sel ? $q->sql() : $q;
        $bind = $q instanceof sel ? $q->bind : [];
      
        $result = self::query($sql, $bind);
        return new rows($result, $as, $q instanceof sel ? $q : null);
    }
}

class sel {
    private string $from;
    private array $cols = ["*"];
    private array $where = [];
    private array $join = [];
    private array $group = [];
    private array $order = [];
    private int $lim = 0;
    private int $off = 0;
    
    public array $bind = [];

    public function __construct(string $class){
        $this->from = $class::TBL;
    }

    public function select(string ...$cols): self{
        $this->cols = $cols;
        return $this;
    }

    public function where(string $expr, mixed ...$vals): self
    {
       
        if (isset($vals[0]) && $vals[0] instanceof ar) {
            $obj = $vals[0];
            $fk = "fk_" . $obj::TBL;
            $this->where[] = "`$fk` = ?";
            $this->bind[] = $obj->id();
            return $this;
        }


        if (empty($vals)) {
            $this->where[] = $expr;
            return $this;
        }

        $parts = explode('?', $expr);
        $final_expr = '';
        $final_bind = [];

        foreach ($vals as $i => $val) {
            $final_expr .= $parts[$i];

            if ($val instanceof sel) {
                $final_expr .= '(' . $val->sql() . ')';
                array_push($final_bind, ...$val->bind);
            } else if (is_array($val)) {
               
                $final_expr .= implode(',', array_fill(0, count($val), '?'));
                array_push($final_bind, ...$val);
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

class rows implements Iterator, Countable
{
    private mysqli_result|bool $res;
    private string|bool $as = ar::class;
    private ?sel $sel;
    private int $n;
    private int $pos = 0;
    private ?array $rows = null;

    public function __construct(mysqli_result|bool $result, string|bool $as, ?sel $sel)
    {
        $this->res = $result;
        $this->as = $as;
        $this->sel = $sel;
        $this->n = $result instanceof mysqli_result ? mysqli_num_rows($result) : 0;
    }

    private function load(): void
    {
        if ($this->rows !== null) return;
        if (!($this->res instanceof mysqli_result)) {
            $this->rows = [];
            return;
        }

        $this->rows = [];
        while ($data = mysqli_fetch_assoc($this->res)) {
            if ($this->as) {
                $this->rows[] = new $this->as($data, true);
            } else {
                $this->rows[] = new ar($data, true);
            }
        }
        mysqli_free_result($this->res);
    }

    // Terminales
    public function first(): mixed
    {
        $this->load();
        return $this->rows[0] ?? null;
    }

    public function all(): array
    {
        $this->load();
        return $this->rows;
    }

    public function arr(): array
    {
        $this->load();
        return array_map(fn($r) => is_object($r) && method_exists($r, 'arr') ? $r->arr() : (array)$r, $this->rows);
    }

    public function col(string $key): array
    {
        $this->load();
        return array_unique(array_filter(array_column($this->rows, $key)));
    }

    public function ids(): array
    {
        if (!$this->as) {
            throw new Exception("rows: ids() requires a class");
        }
        return $this->col($this->as::PK);
    }

    public function map(string $key = null): array
    {
        $this->load();
        if ($key === null && $this->as) {
            $key = $this->as::PK;
        }
        if ($key === null) {
            throw new Exception("rows: map() requires a key");
        }
        return array_column($this->rows, null, $key);
    }

    public function maplist(string $key): array
    {
        $this->load();
        $result = [];
        foreach ($this->rows as $row) {
            $result[$row->$key][] = $row;
        }
        return $result;
    }

    // Relaciones
    public function refs(string $cls, string $fk = null): rows
    {
        $fk = $fk ?: "fk_" . $cls::TBL;
        $ids = $this->col($fk);
        
        if (empty($ids)) {
            return new rows(false, $cls, null);
        }
        
        return $cls::sel()->where("`".$cls::PK."` IN (?)", $ids)->fetch();
    }

    public function rels(string $cls, string $fk = null): rows
    {
        if (!$this->as) {
            throw new Exception("rows: rels() requires a class");
        }
        
        $fk = $fk ?: "fk_" . $this->as::TBL;
        $ids = $this->ids();
        
        if (empty($ids)) {
            return new rows(false, $cls, null);
        }
        
        return $cls::sel()->where("`$fk` IN (?)", $ids)->fetch();
    }

    // Iterator
    public function rewind(): void
    {
        $this->load();
        $this->pos = 0;
    }

    public function current(): mixed
    {
        $this->load();
        return $this->rows[$this->pos] ?? null;
    }

    public function key(): int
    {
        return $this->pos;
    }

    public function next(): void
    {
        ++$this->pos;
    }

    public function valid(): bool
    {
        $this->load();
        return isset($this->rows[$this->pos]);
    }

    public function count(): int
    {
        return $this->n;
    }
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
