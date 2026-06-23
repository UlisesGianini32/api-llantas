<?php

namespace App\Services;

use InvalidArgumentException;

class FormulaEngine
{
    public function evaluate(string $formula, array $vars): float
    {
        $formula = trim($formula);
        if ($formula === '') {
            throw new InvalidArgumentException("La fórmula está vacía.");
        }

        // permitir solo chars seguros
        if (!preg_match('/^[0-9a-zA-Z_\s\+\-\*\/\(\)\.]+$/', $formula)) {
            throw new InvalidArgumentException("La fórmula contiene caracteres no permitidos.");
        }

        // tokens de variables
        preg_match_all('/\b[a-zA-Z_][a-zA-Z0-9_]*\b/', $formula, $m);
        $tokens = array_unique($m[0] ?? []);

        foreach ($tokens as $t) {
            if (is_numeric($t)) continue;
            if (!array_key_exists($t, $vars)) {
                throw new InvalidArgumentException("Variable no permitida: {$t}");
            }
            $formula = preg_replace('/\b' . preg_quote($t, '/') . '\b/', (string)((float)$vars[$t]), $formula);
        }

        // ya debe ser solo matemático
        if (!preg_match('/^[0-9\.\s\+\-\*\/\(\)]+$/', $formula)) {
            throw new InvalidArgumentException("Fórmula inválida.");
        }

        // Evaluación controlada (sin eval): usamos una evaluación sencilla vía Expression (RPN)
        return $this->evalMath($formula);
    }

    private function evalMath(string $expr): float
    {
        $expr = preg_replace('/\s+/', '', $expr);

        $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
        $output = [];
        $ops = [];

        $i = 0;
        while ($i < strlen($expr)) {
            $ch = $expr[$i];

            if (ctype_digit($ch) || $ch === '.') {
                $num = '';
                while ($i < strlen($expr) && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i];
                    $i++;
                }
                $output[] = (float)$num;
                continue;
            }

            if ($ch === '(') { $ops[] = $ch; $i++; continue; }

            if ($ch === ')') {
                while (!empty($ops) && end($ops) !== '(') $output[] = array_pop($ops);
                if (empty($ops)) throw new InvalidArgumentException("Paréntesis desbalanceados.");
                array_pop($ops);
                $i++;
                continue;
            }

            if (isset($precedence[$ch])) {
                $prev = $expr[$i - 1] ?? null;
                if ($ch === '-' && ($i === 0 || $prev === '(' || isset($precedence[$prev]))) {
                    $output[] = 0.0;
                }

                while (!empty($ops)) {
                    $top = end($ops);
                    if ($top === '(') break;
                    if ($precedence[$top] >= $precedence[$ch]) $output[] = array_pop($ops);
                    else break;
                }
                $ops[] = $ch;
                $i++;
                continue;
            }

            throw new InvalidArgumentException("Símbolo inválido: {$ch}");
        }

        while (!empty($ops)) {
            $op = array_pop($ops);
            if ($op === '(') throw new InvalidArgumentException("Paréntesis desbalanceados.");
            $output[] = $op;
        }

        $stack = [];
        foreach ($output as $t) {
            if (is_float($t) || is_int($t)) { $stack[] = (float)$t; continue; }

            $b = array_pop($stack);
            $a = array_pop($stack);
            if ($a === null || $b === null) throw new InvalidArgumentException("Expresión inválida.");

            switch ($t) {
                case '+': $stack[] = $a + $b; break;
                case '-': $stack[] = $a - $b; break;
                case '*': $stack[] = $a * $b; break;
                case '/':
                    if (abs($b) < 1e-12) throw new InvalidArgumentException("División entre 0.");
                    $stack[] = $a / $b;
                    break;
            }
        }

        if (count($stack) !== 1) throw new InvalidArgumentException("Expresión inválida.");
        return (float)$stack[0];
    }
}
