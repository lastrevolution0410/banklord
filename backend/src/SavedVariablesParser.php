<?php
declare(strict_types=1);
namespace Banklord;

/**
 * Data-only reader for the narrow SavedVariables Lua table-literal grammar.
 * NEVER execute Lua or use eval. No identifiers/calls/expressions are accepted
 * as values; a single BanklordDB assignment is expected.
 */
final class SavedVariablesParser
{
    private int $i = 0;
    private int $length;
    private int $nodes = 0;

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
        if ($this->length > 4_194_304) throw new \InvalidArgumentException('SV_FILE_TOO_LARGE');
        if (str_starts_with($source, "\xEF\xBB\xBF")) $this->i = 3;
    }

    public function parse(): array
    {
        $this->whitespace();
        if ($this->identifier() !== 'BanklordDB') $this->fail('SV_VARIABLE_INVALID');
        $this->expect('=');
        $value = $this->value(0);
        $this->whitespace();
        if ($this->peek() === ';') { ++$this->i; $this->whitespace(); }
        if ($this->i !== $this->length || !is_array($value)) $this->fail('SV_TRAILING_DATA');
        return $value;
    }

    private function fail(string $reason): never
    {
        throw new \InvalidArgumentException($reason . ' at byte ' . $this->i);
    }

    private function peek(): string { return $this->source[$this->i] ?? ''; }

    private function whitespace(): void
    {
        while ($this->i < $this->length) {
            $c = $this->peek();
            if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t") { ++$this->i; continue; }
            if ($c === '-' && substr($this->source, $this->i, 2) === '--') {
                $next = strpos($this->source, "\n", $this->i + 2);
                $this->i = $next === false ? $this->length : $next + 1;
                continue;
            }
            break;
        }
    }

    private function expect(string $char): void
    {
        $this->whitespace();
        if ($this->peek() !== $char) $this->fail('SV_SYNTAX_EXPECTED_' . $char);
        ++$this->i;
    }

    private function identifier(): string
    {
        $this->whitespace();
        if (!preg_match('/\G[A-Za-z_][A-Za-z_0-9]*/A', $this->source, $m, 0, $this->i)) {
            $this->fail('SV_IDENTIFIER_REQUIRED');
        }
        $this->i += strlen($m[0]);
        return $m[0];
    }

    private function quoted(): string
    {
        $quote = $this->peek();
        if ($quote !== '"' && $quote !== "'") $this->fail('SV_STRING_REQUIRED');
        ++$this->i;
        $out = '';
        while ($this->i < $this->length) {
            $c = $this->source[$this->i++];
            if ($c === $quote) return $out;
            if ($c === "\n" || $c === "\r") $this->fail('SV_UNESCAPED_NEWLINE');
            if ($c !== '\\') { $out .= $c; continue; }
            if ($this->i >= $this->length) $this->fail('SV_INVALID_ESCAPE');
            $esc = $this->source[$this->i++];
            $map = ['a'=>"\x07", 'b'=>"\x08", 'f'=>"\x0C", 'n'=>"\n", 'r'=>"\r", 't'=>"\t", 'v'=>"\x0B", '\\'=>'\\', '"'=>'"', "'"=>"'"];
            if (array_key_exists($esc, $map)) { $out .= $map[$esc]; continue; }
            if ($esc >= '0' && $esc <= '9') {
                $digits = $esc;
                for ($j = 0; $j < 2 && $this->i < $this->length; $j++) {
                    $next = $this->source[$this->i];
                    if ($next < '0' || $next > '9') break;
                    $digits .= $next; ++$this->i;
                }
                if ((int)$digits > 255) $this->fail('SV_BYTE_ESCAPE_INVALID');
                $out .= chr((int)$digits); continue;
            }
            if ($esc === 'z') { $this->whitespace(); continue; }
            $this->fail('SV_INVALID_ESCAPE');
        }
        $this->fail('SV_UNTERMINATED_STRING');
    }

    private function number(): int|float
    {
        if (!preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/A', $this->source, $m, 0, $this->i)) {
            $this->fail('SV_NUMBER_INVALID');
        }
        $this->i += strlen($m[0]);
        if (strpbrk($m[0], '.eE') !== false) {
            $value = (float)$m[0];
            if (!is_finite($value)) $this->fail('SV_NUMBER_OVERFLOW');
            return $value;
        }
        $value = filter_var($m[0], FILTER_VALIDATE_INT);
        if ($value === false) $this->fail('SV_NUMBER_OVERFLOW');
        return $value;
    }

    private function value(int $depth): mixed
    {
        if (++$this->nodes > 50_000 || $depth > 40) $this->fail('SV_COMPLEXITY_LIMIT');
        $this->whitespace();
        $c = $this->peek();
        if ($c === '{') return $this->table($depth + 1);
        if ($c === '"' || $c === "'") return $this->quoted();
        if ($c === '-' || ($c >= '0' && $c <= '9')) return $this->number();
        $word = $this->identifier();
        return match ($word) {
            'true' => true, 'false' => false, 'nil' => null,
            default => throw new \InvalidArgumentException('SV_UNSUPPORTED_VALUE_' . $word),
        };
    }

    private function table(int $depth): array
    {
        $this->expect('{');
        $result = [];
        $nextSequential = 1;
        while (true) {
            $this->whitespace();
            if ($this->peek() === '}') { ++$this->i; return $result; }
            if ($this->i >= $this->length) $this->fail('SV_TABLE_UNCLOSED');
            $key = null;
            if ($this->peek() === '[') {
                ++$this->i;
                $key = $this->value($depth);
                if (!is_string($key) && !is_int($key)) $this->fail('SV_TABLE_KEY_INVALID');
                $this->expect(']'); $this->expect('=');
            } else {
                $before = $this->i;
                if (preg_match('/\G[A-Za-z_][A-Za-z_0-9]*/A', $this->source, $m, 0, $this->i)) {
                    $this->i += strlen($m[0]); $this->whitespace();
                    if ($this->peek() === '=') { ++$this->i; $key = $m[0]; }
                    else $this->i = $before;
                }
            }
            if ($key === null) { while (array_key_exists($nextSequential, $result)) ++$nextSequential; $key = $nextSequential++; }
            if (array_key_exists($key, $result)) $this->fail('SV_DUPLICATE_KEY');
            $result[$key] = $this->value($depth);
            $this->whitespace();
            $sep = $this->peek();
            if ($sep === ',' || $sep === ';') { ++$this->i; continue; }
            if ($sep === '}') continue;
            $this->fail('SV_MISSING_SEPARATOR');
        }
    }
}
