<?php

namespace App\Http\Controllers\Pages;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use App\Notifications\WelcomeEmail;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Hash;

class ManagementUserController extends Controller
{
    protected $drive;

    public function __construct(GoogleDriveService $drive)
    {
        $this->drive = $drive;
    }

    public function profileImage($fileId)
    {
        return $this->drive->getImageStream($fileId);
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $role = Role::orderBy('name')->get();
        $users = User::with(['roles', 'profile'])->withTrashed()->get();
        return view('users.index', compact('users', 'role'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $rules = [
            'name' => 'required|string',
            'username' => 'required|string|regex:/^[a-z0-9_-]+$/|unique:users,username',
            'email' => 'required|email|unique:users,email',
            // 'password' => 'required_without:generate_password|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id', // ← Sekarang sesuai form!
            'is_active' => 'sometimes|boolean',
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'birth_date' => 'nullable|date',
            'job_title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:' . \App\Support\BatasUnggah::kb(2048),
        ];

        if(!$request->has('generate_password')) {
            $rules['password'] = 'required|min:8|confirmed';
        } else {
            $rules['generated_password'] = 'required|string|min:8';
        }

        $validate = $request->validate($rules);

        // Tentukan password
        $password = $request->has('generate_password')
        ? $request->generated_password
        : $request->password;

        // Ambil data checkbox
        $notifyUser = $request->has('notify_user');
        $forcePasswordChange = $request->has('force_password_change');

        // Ambil ID admin yang sedang login
        $createdBy = auth()->id() ?? 1; // fallback ke 1 kalau tidak ada auth

        // Buat user
        $user = User::create([
            'name' => $validate['name'],
            'username' => $validate['username'],
            'email' => $validate['email'],
            'password' => Hash::make($password),
            'is_active' => $request->has('is_active') ? $validate['is_active'] : true,
            'force_password_change' => $forcePasswordChange,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);

        //asssign user to role
        $user->assignRole(Role::findById($request->role_id));

        $profileData = [];

        // === UPLOAD FOTO KE GOOGLE DRIVE ===
        if ($request->hasFile('profile_picture')) {
            $file = $request->file('profile_picture');
            $userId = $user->id;
            $folderPath = "Application/profile/{$userId}";
            $folderId = $this->drive->getOrCreateFolderByPath($folderPath);

            if (!$folderId) {
                return back()->with('error', 'Gagal buat folder Google Drive.');
            }

            $uploadResult = $this->drive->uploadFile($file, $folderId, true);

            if (!$uploadResult || !isset($uploadResult['id'])) {
                Log::error('Upload gagal', [$uploadResult]);
                return back()->with('error', 'Gagal upload foto. Coba lagi.');
            }

            $profileData['profile_picture_id'] = $uploadResult['id'];
            $profileData['profile_picture_url'] = $uploadResult['url'];

            Log::info('Foto profil berhasil diupload', [
                'user_id' => $userId,
                'file_id' => $uploadResult['id'],
                'url' => $uploadResult['url']
            ]);
        }

        // Simpan profil
        $user->profile()->create([
            'user_id' => $user->id,
            'phone_number' => $validate['phone_number'] ?? null,
            'address' => $validate['address'] ?? null,
            'birth_date' => $validate['birth_date'] ?? null,
            'job_title' => $validate['job_title'] ?? null,
            'department' => $validate['department'] ?? null,
            'profile_picture_id' => $profileData['profile_picture_id'] ?? null,
            'profile_picture_url' => $profileData['profile_picture_url'] ?? null,
        ]);

        // Kirim email notifikasi
        if ($notifyUser) {
            try {
                $user->notify(new WelcomeEmail($user, $password));
            } catch (\Exception $e) {
                Log::error('Gagal kirim email ke ' . $user->email . ': ' . $e->getMessage());
            }
        }

        return redirect()->route('user.management')->with('success', 'User berhasil dibuat!');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        //
        $updatedBy = auth()->user()->id;
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'role_id' => 'required|exists:roles,id',
            'password' => 'nullable|min:8|confirmed',
            'is_active' => 'sometimes|in:1,0', // Hanya terima 1 atau 0
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'birth_date' => 'nullable|date',
            'job_title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:' . \App\Support\BatasUnggah::kb(2048),

        ]);

        // Ambil nilai is_active: kalau checkbox dicentang → 1, kalau tidak → 0
        $data = [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'is_active' => $request->has('is_active') ? 1 : 0, // <--- INI PENTING!
            'force_password_change' => $user->force_password_change,
            'updated_by' => $updatedBy,
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        // syncRoles, BUKAN assignRole: assignRole hanya menambah, sehingga
        // menurunkan superadmin jadi marketing menyisakan kedua role — dan
        // superadmin lolos duluan lewat Gate::before. Role harus diganti.
        $user->syncRoles([Role::findById($request->role_id)]);

        $user->update($data);

        return redirect()
            ->route('user.management')
            ->with('success', 'User berhasil diupdate!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        //
        try {
            // Cek kalau user lagi login sendiri → jangan boleh hapus diri sendiri!
            if (auth()->id() == $user->id) {
                return back()->with('error', 'Kamu tidak bisa menghapus akun sendiri!');
            }

            $user->delete(); // Soft delete!

            return back()->with('success', 'User berhasil dihapus!');
        } catch (\Exception $e) {
            Log::error('Gagal hapus user: ' . $e->getMessage());
            return back()->with('error', 'Gagal menghapus user. Coba lagi.');
        }
    }

    public function restore(User $user)
    {
        // Pastikan user memang di-soft-delete
        if (!$user->trashed()) {
            return back()->with('error', 'User ini tidak dalam status terhapus!');
        }

        try {
            $user->restore(); // Kembalikan!

            return back()->with('success', 'User berhasil dikembalikan!');
        } catch (\Exception $e) {
            Log::error('Gagal restore user: ' . $e->getMessage());
            return back()->with('error', 'Gagal mengembalikan user!');
        }
    }

    public function forceDelete(User $user)
    {

        // Hapus struk dari Google Drive (opsional)
        if ($user->profile_picture_id) {
            $this->drive->deleteFile($user->profile_picture_id);
        }

        $user->forceDelete();

        return redirect()
            ->route('user.management')
            ->with('success', "User {$user->name} dihapus PERMANEN!");
    }
}
