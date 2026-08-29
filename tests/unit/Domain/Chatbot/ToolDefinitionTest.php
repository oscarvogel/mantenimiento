<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Chatbot;

use App\Domain\Chatbot\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class ToolDefinitionTest extends TestCase
{
    public function testReadToolCreation(): void
    {
        $tool = ToolDefinition::read(
            name: 'buscar_equipo',
            description: 'Busca un equipo por código',
            parameters: ['query' => ['type' => 'string', 'description' => 'Código del equipo']],
            permission: 'equipos.ver',
        );

        $this->assertSame('buscar_equipo', $tool->name);
        $this->assertSame('Busca un equipo por código', $tool->description);
        $this->assertFalse($tool->isWrite);
        $this->assertFalse($tool->confirmationRequired);
        $this->assertSame('equipos.ver', $tool->permission);
    }

    public function testWriteToolRequiresConfirmationByDefault(): void
    {
        $tool = ToolDefinition::write(
            name: 'registrar_lectura',
            description: 'Registra una lectura',
            parameters: ['equipmentId' => ['type' => 'integer']],
            permission: 'lecturas.cargar',
        );

        $this->assertTrue($tool->isWrite);
        $this->assertTrue($tool->confirmationRequired);
    }

    public function testToolToFunctionCallingFormat(): void
    {
        $tool = ToolDefinition::read(
            name: 'buscar_equipo',
            description: 'Busca un equipo',
            parameters: ['query' => ['type' => 'string']],
            permission: 'equipos.ver',
        );

        $format = $tool->toFunctionCallingFormat();

        $this->assertSame('function', $format['type']);
        $this->assertSame('buscar_equipo', $format['function']['name']);
        $this->assertSame('Busca un equipo', $format['function']['description']);
        $this->assertSame(['type' => 'string'], $format['function']['parameters']['properties']['query']);
    }
}
