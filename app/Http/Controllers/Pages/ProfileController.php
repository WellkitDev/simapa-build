<?php

namespace App\Http\Controllers\Pages;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    protected $drive;

    public function __construct(GoogleDriveService $drive)
    {
        $this->drive = $drive;
    }

    /**
     * Proksi foto profil dari Google Drive.
     *
     * ID WAJIB benar-benar terdaftar sebagai foto profil. Tanpa pemeriksaan ini
     * endpoint-nya meneruskan ID apa pun ke Drive — dan Drive yang sama memuat
     * naskah, bukti bayar, LOA, serta lampiran laporan harian, sehingga setiap
     * pemegang sesi bisa menarik berkas perusahaan mana pun yang ID-nya bocor.
     */
    public function profileImage($fileId)
    {
        abort_unless(
            UserProfile::where('profile_picture_id', $fileId)->exists(),
            404
        );

        return $this->drive->getImageStream($fileId);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        return view('profile.index');
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
    public function update(Request $request)
    {
        //
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username,' . Auth::id(),
            'email' => 'required|email|max:255|unique:users,email,' . Auth::id(),
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'birth_date' => 'nullable|date',
            'job_name' => 'nullable|string|max:255',
            'job_description' => 'nullable|string|max:255',
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:' . \App\Support\BatasUnggah::kb(2048),
        ]);

        $user = User::findOrFail(Auth::id());

        // Data profil tambahan
        $profileData = $request->only([
            'phone_number', 'address', 'birth_date', 'job_name', 'job_description'
        ]);

        // === HAPUS FOTO LAMA (JIKA ADA) ===
        if ($user->profile?->profile_picture_id) {
            $fileId = $user->profile->profile_picture_id; // DEKLARASI VARIABEL $fileId

            // Validasi: pastikan hanya ID, bukan URL
            if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $fileId)) {
                $deleted = $this->drive->deleteFile($fileId);
                if (!$deleted) {
                    Log::warning("Gagal hapus file lama (ID: {$fileId}) – mungkin sudah dihapus manual.");
                }
            } else {
                Log::warning("File ID tidak valid di database: {$fileId}");
            }
        }

        if ($user->profile?->profile_picture_id) {
            $fileId = $user->profile->profile_picture_id;
            if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $fileId)) {
                $this->drive->deleteFile($fileId);
            }
        }

        // === UPLOAD FOTO BARU ===
        if ($request->hasFile('profile_picture')) {
            $file = $request->file('profile_picture');
            $userId = Auth::id();

            $folderPath = "Application/profile/{$userId}";
            $folderId = $this->drive->getOrCreateFolderByPath($folderPath);

            if (!$folderId) {
                return back()->with('error', 'Gagal buat folder Google Drive.');
            }

            $uploadResult = $this->drive->uploadFile($file, $folderId, true);

            // CEK: Apakah upload sukses?
            if (!$uploadResult || !isset($uploadResult['id'])) {
                Log::error('Upload gagal: uploadResult kosong atau tidak ada ID', [$uploadResult]);
                return back()->with('error', 'Gagal upload foto. Coba lagi.');
            }

            // PASTIKAN ID & URL MASUK KE $profileData
            $profileData['profile_picture_id'] = $uploadResult['id'];
            $profileData['profile_picture_url'] = $uploadResult['url'];

            // LOG BUAT DEBUG
            Log::info('Foto profil berhasil diupload', [
                'user_id' => $userId,
                'file_id' => $uploadResult['id'],
                'url' => $uploadResult['url']
            ]);
        }

        // === UPDATE USER ===
        $user->update([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'updated_by' => Auth::id(),
        ]);

        // === UPDATE PROFIL: PASTIKAN $profileData MASUK SEMUA ===
        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            array_merge($profileData)
        );

        return back()->with('success', 'Profile berhasil diperbarui!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
