<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController
{
    public const ROLES = ['admin' => '管理者', 'editor' => '編集者', 'viewer' => '閲覧者'];

    public function index()
    {
        return view('admin.users', ['users' => User::orderBy('id')->paginate(30), 'roles' => self::ROLES]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150', 'email' => 'required|email|max:254|unique:users,email',
            'password' => 'required|string|min:12|max:72', 'role' => ['required', Rule::in(array_keys(self::ROLES))],
        ]);
        User::create($data);

        return redirect()->route('admin.users')->with('notice', '利用者を追加しました。');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(self::ROLES))]]);
        DB::transaction(function () use ($user, $data) {
            $admins = User::where('role', 'admin')->orderBy('id')->lockForUpdate()->pluck('id');
            $target = User::lockForUpdate()->findOrFail($user->id);
            if ($target->role === 'admin' && $data['role'] !== 'admin' && $admins->count() === 1) {
                throw ValidationException::withMessages(['role' => '管理者を少なくとも1人残してください。']);
            }
            if ($target->role !== $data['role']) {
                $target->forceFill(['role' => $data['role'], 'role_version' => $target->role_version + 1])->save();
            }
        });

        return redirect()->route('admin.forms')->with('notice', '利用者の権限を変更しました。');
    }
}
