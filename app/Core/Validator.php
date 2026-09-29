<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Small validator. Rules: required, string, int, bool, email, max:N, min:N, in:a,b,c, nullable.
 * Throws HttpException(422) with per-field errors.
 */
final class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        $clean = [];
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $ruleList = explode('|', $ruleString);
            $nullable = in_array('nullable', $ruleList, true);
            $empty = $value === null || $value === '' || $value === [];

            if ($empty) {
                if (in_array('required', $ruleList, true)) {
                    $errors[$field] = 'Campo obrigatório.';
                } elseif (in_array('bool', $ruleList, true)) {
                    $clean[$field] = false;
                } else {
                    $clean[$field] = $nullable ? null : ($value ?? null);
                }
                continue;
            }

            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = match ($name) {
                    'string' => is_scalar($value) ? null : 'Texto inválido.',
                    'int'    => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : 'Número inteiro inválido.',
                    'email'  => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Email inválido.',
                    'max'    => mb_strlen((string) $value) <= (int) $arg ? null : "Máximo de $arg caracteres.",
                    'min'    => mb_strlen((string) $value) >= (int) $arg ? null : "Mínimo de $arg caracteres.",
                    'in'     => in_array((string) $value, explode(',', (string) $arg), true) ? null : 'Valor não permitido.',
                    'array'  => is_array($value) ? null : 'Lista inválida.',
                    default  => null,
                };
                if ($error) {
                    $errors[$field] = $error;
                    break;
                }
            }
            if (in_array('int', $ruleList, true)) {
                $value = (int) $value;
            } elseif (in_array('bool', $ruleList, true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
            $clean[$field] = $value;
        }
        if ($errors) {
            throw new HttpException(422, 'Verifique os campos assinalados.', $errors);
        }
        return $clean;
    }
}
