<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

final class ToolDefinition
{
    private function __construct(
        public readonly string $name,
        public readonly string $description,
        /** @var array<string, mixed> */
        public readonly array $parameters,
        public readonly string $permission,
        public readonly bool $isWrite,
        public readonly bool $confirmationRequired,
        public readonly string $handlerClass,
    ) {}

    public static function read(
        string $name,
        string $description,
        array $parameters,
        string $permission,
        string $handlerClass = '',
    ): self {
        return new self(
            name: $name,
            description: $description,
            parameters: $parameters,
            permission: $permission,
            isWrite: false,
            confirmationRequired: false,
            handlerClass: $handlerClass,
        );
    }

    public static function write(
        string $name,
        string $description,
        array $parameters,
        string $permission,
        bool $confirmationRequired = true,
        string $handlerClass = '',
    ): self {
        return new self(
            name: $name,
            description: $description,
            parameters: $parameters,
            permission: $permission,
            isWrite: true,
            confirmationRequired: $confirmationRequired,
            handlerClass: $handlerClass,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toFunctionCallingFormat(): array
    {
        $properties = [];
        foreach ($this->parameters as $key => $def) {
            $property = [
                'type' => $def['type'] ?? 'string',
            ];
            if (isset($def['description']) && $def['description'] !== '') {
                $property['description'] = $def['description'];
            }
            $properties[$key] = $property;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => array_keys($this->parameters),
                ],
            ],
        ];
    }
}
