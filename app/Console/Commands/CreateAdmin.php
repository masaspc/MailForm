<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'mailform:admin {email} {--name=管理者}';
    protected $description = '管理者アカウントを作成（パスワードは非表示で入力）';

    public function handle(): int
    {
        $email = trim($this->argument('email'));
        $name = trim($this->option('name'));
        $rules = ['email' => 'required|email|max:255|unique:users,email', 'name' => 'required|string|max:255'];
        if (Validator::make(compact('email', 'name'), $rules)->fails()) {
            $this->error('名前と未登録のメールアドレスを指定してください。');
            return self::FAILURE;
        }
        $password = $this->secret('パスワード（12文字以上）');
        if (!is_string($password) || mb_strlen($password) < 12 || $password !== $this->secret('パスワードを再入力')) {
            $this->error('パスワードの長さ、または確認入力を確認してください。');
            return self::FAILURE;
        }
        User::create(compact('email', 'name', 'password') + ['role' => 'admin']);
        $this->info('管理者を登録しました。');
        return self::SUCCESS;
    }
}
