<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Skills;

use Closure;

class SkillTargetBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $schema = [];

    public function field(string $name, mixed $default = null): self
    {
        $name = trim($name);
        if ($name !== '') {
            $this->schema[$name] = $default;
        }

        return $this;
    }

    public function addField(string $name, mixed $default = null): self
    {
        return $this->field($name, $default);
    }

    public function id(string $name): self
    {
        return $this->field($name);
    }

    public function text(string $name, ?string $default = null): self
    {
        return $this->field($name, $default);
    }

    public function addText(string $name, ?string $default = null): self
    {
        return $this->text($name, $default);
    }

    public function email(string $name, ?string $default = null): self
    {
        return $this->field($name, $default);
    }

    public function number(string $name, int|float|null $default = null): self
    {
        return $this->field($name, $default);
    }

    public function money(string $name, int|float|null $default = null): self
    {
        return $this->field($name, $default);
    }

    public function date(string $name, ?string $default = null): self
    {
        return $this->field($name, $default);
    }

    public function addDate(string $name, ?string $default = null): self
    {
        return $this->date($name, $default);
    }

    /**
     * @param array<int, string> $options
     */
    public function select(string $name, array $options = [], mixed $default = null): self
    {
        return $this->field($name, $default);
    }

    /**
     * @param array<int, string> $options
     */
    public function addSelect(string $name, array $options = [], mixed $default = null): self
    {
        return $this->select($name, $options, $default);
    }

    public function boolean(string $name, bool $default = false): self
    {
        return $this->field($name, $default);
    }

    public function list(string $name, Closure $items): self
    {
        $name = trim($name);
        if ($name === '') {
            return $this;
        }

        $builder = new self();
        $items($builder);
        $this->schema[$name] = [$builder->toArray()];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->schema;
    }
}
