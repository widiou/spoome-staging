<?php

namespace Spoome\Core;

/**
 * Validatore server-side minimale (la validazione client è solo UX — qui è la difesa vera).
 * Regole: required, email, min:N, max:N, same:field, in:a,b,c, confirmed, regex:/.../
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];
    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules es. ['email' => 'required|email', 'password' => 'required|min:10']
     */
    public static function make(array $data, array $rules): self
    {
        $v = new self($data);
        foreach ($rules as $field => $ruleset) {
            $v->applyRules($field, explode('|', $ruleset));
        }
        return $v;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors ? reset($this->errors) : null;
    }

    private function applyRules(string $field, array $rules): void
    {
        $value = $this->data[$field] ?? null;
        $present = $value !== null && $value !== '';

        foreach ($rules as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

            // Salta le regole non-required se il campo è vuoto e non obbligatorio.
            if (!$present && $name !== 'required') {
                continue;
            }

            $ok = match ($name) {
                'required' => $present,
                'email'    => (bool) filter_var($value, FILTER_VALIDATE_EMAIL),
                'min'      => mb_strlen((string) $value) >= (int) $param,
                'max'      => mb_strlen((string) $value) <= (int) $param,
                'same'     => ($value === ($this->data[$param] ?? null)),
                'confirmed'=> ($value === ($this->data[$field . '_confirmation'] ?? null)),
                'in'       => in_array((string) $value, explode(',', (string) $param), true),
                'regex'    => (bool) preg_match($param, (string) $value),
                default    => true,
            };

            if (!$ok && !isset($this->errors[$field])) {
                $this->errors[$field] = $this->message($field, $name, $param);
            }
        }
    }

    private function message(string $field, string $rule, ?string $param): string
    {
        $key = match ($rule) {
            'required'          => 'validation.required',
            'email'             => 'validation.email',
            'min'               => 'validation.min',
            'max'               => 'validation.max',
            'same', 'confirmed' => 'validation.same',
            'in'                => 'validation.in',
            'regex'             => 'validation.regex',
            default             => 'validation.generic',
        };
        return I18n::t($key, ['field' => $field, 'n' => $param ?? '']);
    }
}
