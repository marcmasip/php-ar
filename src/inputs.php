<?php
/** php-ar · kit · inputs / output / val · php 8.3+ **/
namespace ar;

function err(string $code, mixed $info = null): never{
    throw new \InvalidArgumentException($info ? "$code: $info" : $code);
}

class inputs {
    static self $default;
    protected array $data = [];

    public function __construct(array $data = []) { $this->data = $data; }

    public function raw(): array { return $this->data; }

    public function any(string $k, bool $ex = false, mixed $def = null): mixed {
        if (!array_key_exists($k, $this->data)) {
            if ($ex) err('required', $k);
            return $def;
        }
        return $this->data[$k];
    }

    public function str(string $k, bool $ex = true, mixed $def = null): ?string {
        $v = $this->any($k, $ex, $def);
        return $v !== null ? (string)$v : null;
    }

    public function int(string $k, bool $ex = true, mixed $def = null): ?int {
        $v = $this->any($k, $ex, $def);
        return $v !== null ? (int)$v : null;
    }

    public function num(string $k, bool $ex = true, mixed $def = null): ?float {
        $v = $this->any($k, $ex, $def);
        return $v !== null ? (float)$v : null;
    }

    public function bool(string $k, bool $ex = false, mixed $def = null): ?bool {
        $v = $this->any($k, $ex, $def);
        return $v !== null ? filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)$v : null;
    }

    public function arr(string $k, bool $ex = true, mixed $def = null): ?array {
        $v = $this->any($k, $ex, $def);
        if ($v !== null && !is_array($v)) err('expected_array', $k);
        return $v;
    }

    /** Array of arrays → array of inputs instances */
    public function arrin(string $k, bool $ex = true, mixed $def = null): array {
        return array_map(
            fn($row) => is_array($row) ? new self($row) : err('expected_array_of_arrays', $k),
            $this->arr($k, $ex, $def) ?? []
        );
    }

    public function expr(string $k, string $pattern, bool $ex = true, mixed $def = null): ?string {
        $v = $this->any($k, $ex, $def);
        if ($v === null) return $def;
        $r = val::expr((string)$v, $pattern);
        if ($r === null && $ex) err('invalid_format', $k);
        return $r ?? $def;
    }

    public function email(string $k, bool $ex = true, mixed $def = null): ?string {
        $v = $this->str($k, $ex, $def);
        if ($v === null) return $def;
        $r = val::email($v);
        if ($r === null && $ex) err('invalid_email', $k);
        return $r ?? $def;
    }

    public function json(string $k, bool $ex = true, mixed $def = null): mixed
    {
        $v = $this->str($k, $ex, $def);
        return $v !== null ? val::json($v, $ex, $def) : $def;
    }

    /** Pluck a sub-key from a nested array input */
    public function sub(string $k, bool $ex = true): self
    {
        return new self($this->arr($k, $ex) ?? []);
    }
}

class web_inputs extends inputs
{
    public function __construct()
    {
        parent::__construct(array_merge($_GET, $_POST));
    }
    public static function with_body(): self
    {
        $obj = new self();
        $raw = file_get_contents('php://input');
        if ($raw && $decoded = json_decode($raw, true))
            $obj->data = array_merge($obj->data, $decoded);
        return $obj;
    }
}

class cli_inputs extends inputs
{
    public function __construct()
    {
        global $argv;
        $data = [];
        if (isset($argv) && count($argv) > 1)
            parse_str(implode('&', array_slice($argv, 1)), $data);
        parent::__construct($data);
    }
}

inputs::$default = new web_inputs();

function in_any(string $k, bool $ex = false, mixed $def = null): mixed   { return inputs::$default->any($k, $ex, $def); }
function in_str(string $k, bool $ex = true,  mixed $def = null): ?string { return inputs::$default->str($k, $ex, $def); }
function in_int(string $k, bool $ex = true,  mixed $def = null): ?int    { return inputs::$default->int($k, $ex, $def); }
function in_num(string $k, bool $ex = true,  mixed $def = null): ?float  { return inputs::$default->num($k, $ex, $def); }
function in_bool(string $k, bool $ex = false,mixed $def = null): ?bool   { return inputs::$default->bool($k, $ex, $def); }
function in_arr(string $k, bool $ex = true,  mixed $def = null): ?array  { return inputs::$default->arr($k, $ex, $def); }
function in_json(string $k, bool $ex = true,  mixed $def = null): mixed  { return inputs::$default->json($k, $ex, $def); }
function in_email(string $k, bool $ex = true, mixed $def = null): ?string{ return inputs::$default->email($k, $ex, $def); }
function in_expr(string $k, string $p, bool $ex = true, mixed $def = null): ?string { return inputs::$default->expr($k, $p, $ex, $def); }

class output{
    /** Recursively resolve values: sel/rows/ar → plain arrays */
    static function resolve(mixed $v): mixed {
        return match(true) {
            $v instanceof sel  => self::resolve($v->fetch()),
            $v instanceof rows => $v->arr(),
            $v instanceof ar   => $v->arr(),
            is_array($v)       => array_map(self::resolve(...), $v),
            default            => $v,
        };
    }

    static function json(mixed $data, int $flags = JSON_PRETTY_PRINT): never {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(self::resolve($data), $flags);
        exit;
    }

    static function ok(mixed $data = null): never {
        self::json(['ok' => true, 'data' => $data]);
    }

    static function fail(string $code, mixed $info = null, int $status = 400): never {
        http_response_code($status);
        self::json(['ok' => false, 'error' => $code, 'info' => $info]);
    }

    static function redir(string $dst): never {
        header("Location: $dst");
        exit;
    }
}

class val
{
    static function expr(string $v, string $pattern): ?string {
        return preg_match($pattern, $v) ? $v : null;
    }

    static function email(string $v): ?string {
        return filter_var($v, FILTER_VALIDATE_EMAIL) ?: null;
    }

    static function url(string $v): ?string {
        return filter_var($v, FILTER_VALIDATE_URL) ?: null;
    }

    static function ip(string $v): ?string {
        return filter_var($v, FILTER_VALIDATE_IP) ?: null;
    }

    static function range(int|float $v, int|float $min = null, int|float $max = null): int|float|null {
        return ($min === null || $v >= $min) && ($max === null || $v <= $max) ? $v : null;
    }

    static function in(mixed $v, array $allow): mixed {
        return in_array($v, $allow, strict: true) ? $v : null;
    }

    static function key(array $o, string $k, bool $ex = true, mixed $def = null): mixed {
        if (!array_key_exists($k, $o)) {
            if ($ex) err('key_not_found', $k);
            return $def;
        }
        return $o[$k];
    }

    static function json(string $s, bool $ex = true, mixed $def = null): mixed {
        $r = json_decode($s, true);
        if ($r === null && $ex) err('invalid_json', $s);
        return $r ?? $def;
    }

    /** Dot-path access: val::path($arr, 'user.address.city') */
    static function path(array $o, string $path, bool $ex = true, mixed $def = null): mixed {
        $cur = $o;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                if ($ex) err('path_not_found', $path);
                return $def;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }

    static function filepath(string $path): string  {
        $abs = str_starts_with($path, '/');
        $parts = array_filter(
            array_map('trim', explode('/', $path)),
            fn($p) => $p !== '' && !str_starts_with($p, '.')
        );
        return ($abs ? '/' : '') . implode('/', $parts);
    }

    static function tel(string $v, string $def_prefix = '34'): array  {
        $n = preg_replace('/\D/', '', $v);
        return match(true) {
            strlen($n) === 9           => [$def_prefix, $n],
            strlen($n) > 9            => [substr($n, 0, strlen($n) - 9), substr($n, -9)],
            default                   => [null, null],
        };
    }
}