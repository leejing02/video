<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:60'],
            'username' => ['required', 'string', 'max:40', 'unique:users,username'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'username' => $data['username'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => User::ROLE_USER,
        ]);

        // 自动加入全局聊天室
        if ($global = \App\Models\ChatRoom::globalRoom()) {
            $user->chatRooms()->syncWithoutDetaching([
                $global->id => ['joined_at' => now(), 'role' => 'member'],
            ]);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['账号或密码错误'],
            ]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['账号已停用'],
            ]);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->loadCount(['videos', 'comments']));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:60'],
            'bio'    => ['sometimes', 'nullable', 'string', 'max:500'],
            'avatar' => ['sometimes', 'nullable', 'string'],
            'phone'  => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $request->user()->update($data);
        return response()->json($request->user()->fresh());
    }
}
