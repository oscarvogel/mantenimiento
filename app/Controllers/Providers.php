<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class Providers extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        $db = db_connect();
        $query = $db->table('proveedores')->where('empresa_id', $actor->companyId());
        $search = trim((string) $this->request->getGet('q'));
        if ($search !== '') $query->groupStart()->like('razon_social', $search)->orLike('cuit', $search)->orLike('especialidad', $search)->groupEnd();
        if ((string) $this->request->getGet('estado') === 'inactivo') $query->where('activo', 0);
        else $query->where('activo', 1);
        $providers = $query->orderBy('razon_social')->get()->getResultArray();
        $all = $db->table('proveedores')->where('empresa_id', $actor->companyId())->get()->getResultArray();

        return $this->renderApp($actor, 'providers', 'providers-index', 'Proveedores y talleres', [
            'providers' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'name' => (string) $row['razon_social'], 'taxId' => $row['cuit'] ?: null,
                'workshop' => (bool) $row['es_taller'], 'supplier' => (bool) $row['es_proveedor'], 'email' => $row['email'] ?: null,
                'phone' => $row['telefono'] ?: null, 'address' => $row['direccion'] ?: null, 'specialty' => $row['especialidad'] ?: null,
                'active' => (bool) $row['activo'], 'notes' => $row['observaciones'] ?: null,
            ], $providers),
            'metrics' => ['total' => count($all), 'active' => count(array_filter($all, static fn (array $row): bool => (int) $row['activo'] === 1)), 'inactive' => count(array_filter($all, static fn (array $row): bool => (int) $row['activo'] === 0))],
            'filters' => ['q' => $search, 'status' => (string) $this->request->getGet('estado')],
            'permissions' => ['edit' => $actor->hasPermission('proveedores.editar')],
            'actions' => ['index' => base_url('mantenimiento/proveedores'), 'create' => base_url('mantenimiento/proveedores')],
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    public function create(): RedirectResponse { return $this->persist(null); }
    public function update(int $providerId): RedirectResponse { return $this->persist($providerId); }

    public function status(int $providerId): RedirectResponse
    {
        try {
            $actor = $this->actor();
            $this->assertEdit($actor);
            $db = db_connect();
            $row = $db->table('proveedores')->where('id', $providerId)->where('empresa_id', $actor->companyId())->get()->getRowArray();
            if ($row === null) throw new DomainException('El proveedor no pertenece a la empresa activa.');
            $db->table('proveedores')->where('id', $providerId)->where('empresa_id', $actor->companyId())->update(['activo' => (int) $row['activo'] === 1 ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')]);
            return redirect()->to('/mantenimiento/proveedores')->with('success', 'Estado del proveedor actualizado.');
        } catch (Throwable $exception) { return $this->failure($exception); }
    }

    private function persist(?int $providerId): RedirectResponse
    {
        try {
            $actor = $this->actor(); $this->assertEdit($actor); $db = db_connect();
            $name = trim((string) $this->request->getPost('razon_social'));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 255) throw new DomainException('Indicá una razón social válida.');
            $email = trim((string) $this->request->getPost('email'));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new DomainException('El email del proveedor no es válido.');
            $data = [
                'razon_social' => $name, 'cuit' => $this->nullable($this->request->getPost('cuit')), 'es_taller' => $this->checked('es_taller'),
                'es_proveedor' => $this->checked('es_proveedor'), 'email' => $this->nullable($email), 'telefono' => $this->nullable($this->request->getPost('telefono')),
                'direccion' => $this->nullable($this->request->getPost('direccion')), 'especialidad' => $this->nullable($this->request->getPost('especialidad')),
                'observaciones' => $this->nullable($this->request->getPost('observaciones')), 'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($providerId !== null) {
                $existing = $db->table('proveedores')->where('id', $providerId)->where('empresa_id', $actor->companyId())->get()->getRowArray();
                if ($existing === null) throw new DomainException('El proveedor no pertenece a la empresa activa.');
                foreach (['cuit' => 'cuit', 'email' => 'email', 'telefono' => 'telefono', 'direccion' => 'direccion', 'especialidad' => 'especialidad', 'observaciones' => 'observaciones'] as $input => $column) {
                    if ($this->request->getPost($input) === null) $data[$column] = $existing[$column];
                }
            }
            if ($providerId === null) { $data['empresa_id'] = $actor->companyId(); $data['activo'] = 1; $data['created_at'] = date('Y-m-d H:i:s'); $db->table('proveedores')->insert($data); }
            else { $this->assertProvider($db, $actor, $providerId); $db->table('proveedores')->where('id', $providerId)->where('empresa_id', $actor->companyId())->update($data); }
            return redirect()->to('/mantenimiento/proveedores')->with('success', $providerId === null ? 'Proveedor creado.' : 'Proveedor actualizado.');
        } catch (Throwable $exception) { return $this->failure($exception); }
    }

    private function actor(): ActorContext { $actor = (new SessionActorContext())->current(); if ($actor === null || $actor->companyId() === null) throw new DomainException('No existe un contexto autenticado válido.'); return $actor; }
    private function assertEdit(ActorContext $actor): void { if (! $actor->hasPermission('proveedores.editar')) throw new DomainException('No tenés permiso para editar proveedores.'); }
    private function assertProvider(object $db, ActorContext $actor, int $id): void { if ($db->table('proveedores')->where('id', $id)->where('empresa_id', $actor->companyId())->countAllResults() !== 1) throw new DomainException('El proveedor no pertenece a la empresa activa.'); }
    private function checked(string $name): int { return $this->request->getPost($name) ? 1 : 0; }
    private function nullable(mixed $value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function failure(Throwable $exception): RedirectResponse { if (! $exception instanceof DomainException) log_message('error', 'Falló el catálogo de proveedores: {message}', ['message' => $exception->getMessage()]); return redirect()->to('/mantenimiento/proveedores')->withInput()->with('error', $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo actualizar el proveedor.'); }
}
