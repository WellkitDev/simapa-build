<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Log;
use Google\Service\Drive\Permission;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\DriveFile;

class GoogleDriveService
{
    private ?GoogleClient $client = null;
    private ?GoogleDrive $service = null;

    /**
     * Constructor SENGAJA kosong. Service ini disuntik ke constructor enam controller,
     * dan Laravel membangun dependensi controller pada setiap request — jadi kerja
     * apa pun di sini dibayar oleh halaman yang tak pernah menyentuh berkas.
     * Otentikasi terjadi saat client()/service() pertama kali dipanggil.
     */
    public function __construct()
    {
    }

    private function client(): GoogleClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $client = new GoogleClient();
        $client->setClientId(config('filesystems.disks.google.clientId'));
        $client->setClientSecret(config('filesystems.disks.google.clientSecret'));
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force'); // penting untuk refresh token

        $refreshToken = config('filesystems.disks.google.refreshToken');
        if (! $refreshToken) {
            throw new \Exception('Refresh token is missing in config.');
        }

        $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($token['error'])) {
            throw new \Exception('Failed to fetch access token: ' . ($token['error_description'] ?? $token['error']));
        }

        $client->setAccessToken($token);

        return $this->client = $client;
    }

    private function service(): GoogleDrive
    {
        return $this->service ??= new GoogleDrive($this->client());
    }

    /*
    * create a new class instance
    */
    public function getAccessToken()
    {
        $this->client()->getAccessToken();

        if($this->client()->isAccessTokenExpired())
        {
           $token = $this->client()->fetchAccessTokenWithRefreshToken(config('filesystems.disks.google.refreshToken'));
        }

        return $token['access_token'];
    }

    /**
     * Panggilan termurah yang membuktikan kredensial Drive masih sah.
     *
     * Seluruh unggahan berkas bergantung pada satu refresh token. Kalau token itu
     * dicabut atau kedaluwarsa, tak ada yang meledak — unggahan hanya diam-diam
     * berhenti bekerja, dan sebabnya cuma muncul di laravel.log. Perintah terjadwal
     * memanggil method ini supaya kematian token ketahuan sebelum ada yang mengeluh.
     *
     * Sengaja MELEMPAR, tidak mengembalikan false: sebab kegagalan adalah bagian
     * paling berguna dari kabarnya, dan uploadFile() sudah menunjukkan betapa
     * mudahnya sebab hilang kalau exception ditelan.
     */
    public function cekKesehatan(): bool
    {
        $this->service()->files->listFiles(['pageSize' => 1, 'fields' => 'files(id)']);

        return true;
    }

    /*
    * create a new class instance
    */
    public function makeFileToPublic($driveFileId)
    {
        //
        $permission = new Permission([
            'type' => 'anyone',
            'role' => 'reader',
            'allowFileDiscovery' => false, // Ini kunci: bikin link "anyone with link" tanpa search discovery
        ]);

        // Set permission dengan option tambahan
        $this->service()->permissions->create($driveFileId, $permission, [
            'fields' => 'id',
            'sendNotificationEmail' => false, // Nggak kirim email notif
            'useDomainAdminAccess' => false,
            'supportsAllDrives' => true,
        ]);
    }

    /**
     * Upload file ke Google Drive
     *
     * @param \Illuminate\Http\UploadedFile|string $file
     * @param string|null $folderId
     * @param bool $makePublic
     * @return array|null
     */
    public function uploadFile($file, ?string $folderId = null, bool $makePublic = true): ?array
    {
        try {
            $fileMetadata = new DriveFile([
                'name' => is_string($file) ? basename($file) : $file->getClientOriginalName(),
            ]);

            // Jika ada folderId
            if ($folderId) {
                $fileMetadata->setParents([$folderId]);
            } else {
                $configFolder = config('filesystems.disks.google.folderId');
                if ($configFolder) {
                    $fileMetadata->setParents([$configFolder]);
                }
            }

            $content = is_string($file)
                ? file_get_contents($file)
                : file_get_contents($file->getRealPath());

            $createdFile = $this->service()->files->create($fileMetadata, [
                'data' => $content,
                'mimeType' => is_string($file) ? mime_content_type($file) : $file->getMimeType(),
                'uploadType' => 'multipart',
                'fields' => 'id, name, webViewLink, webContentLink',
            ]);

            $fileId = $createdFile->id;

            if ($makePublic) {
                $this->makeFileToPublic($fileId);
            }

            $publicUrl = $this->getPublicUrl($fileId);

            return [
                'id' => $fileId,
                'name' => $createdFile->name,
                'url' => $publicUrl,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to upload file to Google Drive: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Ambil ID file dari nama/path file
     */
    public function getFileIdFromPath(string $path): ?string
    {
        try {
            $filename = basename($path);
            $folderId = config('filesystems.disks.google.folderId', null);

            $query = "name='{$filename}' and trashed=false";
            if ($folderId) {
                $query .= " and '{$folderId}' in parents";
            }

            $files = $this->service()->files->listFiles([
                'q' => $query,
                'fields' => 'files(id, name, webViewLink)',
            ]);

            $fileList = $files->getFiles();

            if (empty($fileList)) {
                Log::warning("No file found for path: {$path}");
                return null;
            }

            $fileId = $fileList[0]->getId();
            Log::info("File ID found: {$fileId} for {$filename}");

            return $fileId;
        } catch (\Exception $e) {
            Log::error("Failed to get file ID for path {$path}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Buat URL publik Google Drive
     */
    public function getPublicUrl(?string $fileId): ?string
    {
        if (!$fileId) {
            return null;
        }

        return "https://drive.google.com/file/d/{$fileId}/view?usp=sharing";
    }

    /**
     * Ambil gambar dari Google Drive + compress otomatis
     */
    public function getImageStream(string $fileId)
    {
        try {
            // 1. Refresh token otomatis (karena service sudah punya client)
            if ($this->client()->isAccessTokenExpired()) {
                $newToken = $this->client()->fetchAccessTokenWithRefreshToken(
                    config('filesystems.disks.google.refreshToken')
                );
                $this->client()->setAccessToken($newToken);
            }

            // 2. Ambil file sebagai stream
            $response = $this->service()->files->get($fileId, [
                'alt' => 'media',
                'supportsAllDrives' => true,
            ]);

            // 3. Ambil metadata
            $file = $this->service()->files->get($fileId, [
                'fields' => 'mimeType, name',
                'supportsAllDrives' => true,
            ]);

            // 4. Return response stream
            return response($response->getBody(), 200)
                ->header('Content-Type', $file->mimeType)
                ->header('Cache-Control', 'public, max-age=31536000')
                ->header('Content-Disposition', 'inline; filename="' . $file->name . '"');

        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() === 404) {
                Log::warning("File tidak ditemukan di Google Drive: {$fileId}");
            } else {
                Log::error('Google API Error saat ambil gambar: ' . $e->getMessage(), [
                    'file_id' => $fileId,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Gagal ambil gambar dari Google Drive: ' . $e->getMessage(), [
                'file_id' => $fileId,
                'trace' => $e->getTraceAsString()
            ]);
        }

        // Fallback: gambar default
        $default = public_path('images/default-avatar.png');
        if (file_exists($default)) {
            return response()->file($default);
        }

        abort(404, 'Gambar tidak ditemukan');
    }

    /**
     * Hapus file di Google Drive
     */
    public function deleteFile(string $fileId): bool
    {
        // Validasi: pastikan hanya ID, bukan URL
        if (str_contains($fileId, 'http') || str_contains($fileId, '/')) {
            Log::warning("Invalid file ID for delete: {$fileId}");
            return false;
        }

        try {
            $this->service()->files->delete($fileId);
            Log::info("File deleted from Google Drive: {$fileId}");
            return true;
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() == 404) {
                Log::info("File already deleted or not found: {$fileId}");
                return true; // Anggap sukses kalau sudah nggak ada
            }
            Log::error("Failed to delete file from Google Drive: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            Log::error("Unexpected error deleting file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Dapatkan atau buat folder berdasarkan path
     * Contoh: Application/profile/123
     */
    public function getOrCreateFolderByPath(string $path): ?string
    {
        $parts = array_filter(explode('/', $path));
        $parentId = null; // Mulai dari root

        foreach ($parts as $folderName) {
            $folderId = $this->findFolderByName($folderName, $parentId);

            if (!$folderId) {
                $folderId = $this->createFolder($folderName, $parentId);
            }

            $parentId = $folderId;
        }

        return $parentId;
    }

    /**
     * Cari folder berdasarkan nama dan parent
     */
    private function findFolderByName(string $name, ?string $parentId = null): ?string
    {
        $query = "mimeType='application/vnd.google-apps.folder' and trashed=false and name='{$name}'";
        if ($parentId) {
            $query .= " and '{$parentId}' in parents";
        } else {
            $query .= " and 'root' in parents";
        }

        try {
            $results = $this->service()->files->listFiles([
                'q' => $query,
                'fields' => 'files(id)',
                'spaces' => 'drive',
            ]);

            $files = $results->getFiles();
            return !empty($files) ? $files[0]->getId() : null;
        } catch (\Exception $e) {
            Log::warning("Folder search failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Buat folder baru
     */
    private function createFolder(string $name, ?string $parentId = null): string
    {
        $fileMetadata = new DriveFile([
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);

        if ($parentId) {
            $fileMetadata->setParents([$parentId]);
        }

        $folder = $this->service()->files->create($fileMetadata, [
            'fields' => 'id'
        ]);

        Log::info("Folder created: {$name} (ID: {$folder->id})");
        return $folder->id;
    }
}
