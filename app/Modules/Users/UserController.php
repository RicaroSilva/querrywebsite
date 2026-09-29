<?php
declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Basic user administration (admins only). */
final class UserController
{
    public function list(Request $request): never
    {
        Response::json(['ok' => true, 'users' => (new UserRepository())->all()]);
    }

    public function store(Request $request): never
    {
        $d = Validator::validate($request->all(), [
            'name'     => 'required|string|max:120',
            'email'    => 'required|email|max:190',
            'password' => 'required|string|min:10|max:200',
            'role'     => 'required|in:' . implode(',', array_keys(UserRepository::ROLES)),
        ]);
        $users = new UserRepository();
        if ($users->findByEmail($d['email'])) {
            throw new HttpException(422, 'Já existe um utilizador com esse email.', ['email' => 'Já existe']);
        }
        $id = $users->create($d);
        Audit::log('user.create', 'user', $id, ['email' => $d['email'], 'role' => $d['role']]);
        Response::json(['ok' => true, 'id' => $id], 201);
    }

    public function update(Request $request): never
    {
        $id = (int) $request->param('id');
        $users = new UserRepository();
        $user = $users->find($id) ?? throw new HttpException(404);
        $d = Validator::validate($request->all(), [
            'role'      => 'nullable|in:' . implode(',', array_keys(UserRepository::ROLES)),
            'is_active' => 'nullable|bool',
            'password'  => 'nullable|string|min:10|max:200',
        ]);
        if ($id === Auth::id() && (($d['role'] && $d['role'] !== 'admin') || ($request->input('is_active') !== null && !$d['is_active']))) {
            throw new HttpException(422, 'Não pode retirar o seu próprio acesso de administrador.');
        }
        $data = [];
        if ($d['role']) {
            $data['role'] = $d['role'];
        }
        if ($request->input('is_active') !== null) {
            $data['is_active'] = $d['is_active'] ? 1 : 0;
        }
        if ($data) {
            $users->update($id, $data);
        }
        if ($d['password']) {
            $users->setPassword($id, $d['password']);
        }
        Audit::log('user.update', 'user', $id, ['fields' => array_merge(array_keys($data), $d['password'] ? ['password'] : [])]);
        Response::json(['ok' => true]);
    }
}
