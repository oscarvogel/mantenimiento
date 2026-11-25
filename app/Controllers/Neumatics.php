<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\MountNeumaticCommand;
use App\Application\Neumaticos\NeumaticRegistryFilters;
use App\Application\Neumaticos\RegisterNeumaticCommand;
use App\Application\Neumaticos\UpdateNeumaticCommand;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DomainException;
use Throwable;

/**
 * Padrón y ficha de neumáticos.
 *
 * Traduce la request a los casos de uso del contexto; las reglas, el scope y
 * los permisos viven en ellos.
 */
final class Neumatics extends BaseController
{
    private const BASE = 'neumaticos';

    public function index(): string|ResponseInterface|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $filters = NeumaticRegistryFilters::fromQuery((array) $this->request->getGet());
            $payload = service('neumaticRegistry')->execute($actor, $filters);
            foreach ($payload['items'] as &$item) {
                $item['url'] = base_url(self::BASE . '/' . (int) $item['id']);
            }
            unset($item);
            $payload['urls'] = [
                'index' => base_url(self::BASE),
                'create' => base_url(self::BASE),
                'masters' => base_url(self::BASE . '/maestros'),
            ];
            // Si el alta falló, el formulario vuelve con lo que el operador había cargado.
            $payload['oldInput'] = [];
            foreach (['sucursal_id', 'numero', 'marca_id', 'modelo_id', 'tipo_cubierta_id', 'medida', 'anio_fabricacion', 'estado_inicial', 'fecha_alta'] as $field) {
                $value = old($field);
                if (is_string($value) && $value !== '') {
                    $payload['oldInput'][$field] = $value;
                }
            }

            return $this->renderApp($actor, 'tire-registry', 'tire-registry', 'Padrón de neumáticos', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, base_url('inicio'));
        }
    }

    public function show(int $id): string|ResponseInterface|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $payload = service('neumaticDetail')->execute($actor, $id);
            $payload['urls'] = [
                'index' => base_url(self::BASE),
                'update' => base_url(self::BASE . '/' . $id),
                'status' => base_url(self::BASE . '/' . $id . '/estado'),
                'mount' => base_url(self::BASE . '/' . $id . '/colocar'),
                'remove' => base_url(self::BASE . '/' . $id . '/extraer'),
                'deposit' => base_url(self::BASE . '/deposito'),
            ];
            $payload['oldInput'] = [];
            foreach (['equipo_id', 'eje', 'lado', 'lugar', 'boton', 'km', 'fecha'] as $field) {
                $value = old($field);
                if (is_string($value) && $value !== '') {
                    $payload['oldInput'][$field] = $value;
                }
            }

            return $this->renderApp($actor, 'tire-detail', 'tire-detail', 'Neumático ' . $payload['neumatic']['number'], $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, base_url(self::BASE));
        }
    }

    public function store(): RedirectResponse
    {
        try {
            $command = new RegisterNeumaticCommand(
                $this->requiredInt('sucursal_id', 'Elegí la sucursal del neumático.'),
                trim((string) $this->request->getPost('numero')),
                $this->optionalInt('marca_id'),
                $this->optionalInt('modelo_id'),
                $this->optionalInt('tipo_cubierta_id'),
                trim((string) $this->request->getPost('medida')),
                $this->optionalInt('anio_fabricacion'),
                trim((string) ($this->request->getPost('estado_inicial') ?? 'NUEVA')),
                $this->optionalDate('fecha_alta'),
            );
            $id = service('registerNeumatic')->execute($this->actor(), $command);

            return redirect()->to(base_url(self::BASE . '/' . $id))->with('success', 'El neumático quedó dado de alta.');
        } catch (Throwable $exception) {
            return $this->failure($exception, base_url(self::BASE) . '#alta');
        }
    }

    public function update(int $id): RedirectResponse
    {
        try {
            service('updateNeumatic')->execute($this->actor(), new UpdateNeumaticCommand(
                $id,
                $this->optionalInt('marca_id'),
                $this->optionalInt('modelo_id'),
                $this->optionalInt('tipo_cubierta_id'),
                trim((string) $this->request->getPost('medida')),
                $this->optionalInt('anio_fabricacion'),
            ));

            return redirect()->to(base_url(self::BASE . '/' . $id))->with('success', 'Los datos del neumático quedaron guardados.');
        } catch (Throwable $exception) {
            return $this->failure($exception, base_url(self::BASE . '/' . $id));
        }
    }

    public function status(int $id): RedirectResponse
    {
        try {
            service('changeNeumaticStatus')->execute(
                $this->actor(),
                $id,
                trim((string) $this->request->getPost('estado')),
                trim((string) $this->request->getPost('motivo')),
                $this->optionalDateTime('fecha'),
            );

            return redirect()->to(base_url(self::BASE . '/' . $id))->with('success', 'El estado del neumático quedó actualizado.');
        } catch (Throwable $exception) {
            return $this->failure($exception, base_url(self::BASE . '/' . $id));
        }
    }

    public function mount(int $id): RedirectResponse
    {
        $back = base_url(self::BASE . '/' . $id);
        try {
            $result = service('mountNeumatic')->execute($this->actor(), new MountNeumaticCommand(
                $id,
                $this->requiredInt('equipo_id', 'Elegí el equipo donde se coloca el neumático.'),
                $this->requiredInt('eje', 'Elegí el eje.'),
                trim((string) $this->request->getPost('lado')),
                trim((string) $this->request->getPost('lugar')),
                $this->requiredInt('km', 'Indicá el kilometraje del equipo al colocar el neumático.'),
                trim((string) $this->request->getPost('boton')) ?: null,
                $this->optionalDateTime('fecha'),
            ));

            $message = sprintf('Quedó colocado en %s, %s.', $result['equipment'], $result['position']);
            if ($result['movedToBranch']) {
                $message .= ' Pasó a la sucursal del equipo.';
            }
            if ($result['depositExits'] > 0) {
                $message .= ' Se registró su salida del depósito.';
            }

            return redirect()->to($back)->with('success', $message);
        } catch (Throwable $exception) {
            return $this->failure($exception, $back . '#colocar');
        }
    }

    public function remove(int $id): RedirectResponse
    {
        $back = base_url(self::BASE . '/' . $id);
        try {
            $result = service('removeNeumatic')->execute(
                $this->actor(),
                $id,
                $this->requiredInt('km', 'Indicá el kilometraje del equipo al extraer el neumático.'),
                $this->optionalDateTime('fecha'),
            );

            return redirect()->to($back)->with('success', $result['reliable']
                ? sprintf('El neumático quedó extraído. Recorrió %s en esa colocación.', $result['label'])
                : 'El neumático quedó extraído. El km de extracción es menor al de colocación: el rendimiento queda sin datos suficientes.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $back . '#extraer');
        }
    }

    private function requiredInt(string $field, string $message): int
    {
        return $this->optionalInt($field) ?? throw new DomainException($message);
    }

    private function optionalInt(string $field): ?int
    {
        $value = trim((string) $this->request->getPost($field));
        if ($value === '') {
            return null;
        }
        if (! ctype_digit($value)) {
            throw new DomainException('Revisá los datos: hay un valor que no es un número válido.');
        }

        return (int) $value;
    }

    private function optionalDate(string $field): ?DateTimeImmutable
    {
        $value = trim((string) $this->request->getPost($field));
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException('La fecha no es válida.');
        }

        return $date;
    }

    private function optionalDateTime(string $field): ?DateTimeImmutable
    {
        $value = trim((string) $this->request->getPost($field));
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value) {
            throw new DomainException('La fecha y hora no son válidas.');
        }

        return $date;
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || $actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('El padrón de neumáticos requiere una cuenta de empresa.');
        }

        return $actor;
    }

    private function failure(Throwable $exception, string $back): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló una operación del padrón de neumáticos: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to($back)->withInput()->with('error', $exception instanceof DomainException
            ? $exception->getMessage()
            : 'No se pudo completar la operación sobre el neumático.');
    }
}
