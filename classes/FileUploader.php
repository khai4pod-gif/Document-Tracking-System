<?php
/**
 * classes/FileUploader.php
 * Strict, reusable file-upload handler.
 * Validates real MIME type (via fileinfo, not the client-supplied one),
 * enforces size limits, and writes files under randomized names to
 * prevent path traversal / overwrite / execution attacks.
 */

declare(strict_types=1);

class FileUploader
{
    private string $targetDir;
    private array $allowedMimes;
    private int $maxBytes;

    public function __construct(string $targetDir, array $allowedMimes = null, int $maxBytes = null)
    {
        $this->targetDir    = rtrim($targetDir, '/') . '/';
        $this->allowedMimes = $allowedMimes ?? ALLOWED_UPLOAD_MIMES;
        $this->maxBytes     = $maxBytes ?? MAX_UPLOAD_BYTES;

        if (!is_dir($this->targetDir)) {
            mkdir($this->targetDir, 0755, true);
        }
    }

    /**
     * @param array $file A single entry from $_FILES
     * @return array{original_name:string,stored_name:string,file_path:string,mime_type:string,file_size:int}
     * @throws RuntimeException on any validation failure
     */
    public function upload(array $file): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException('Invalid file upload parameters.');
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new RuntimeException('No file was uploaded.');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new RuntimeException('The file exceeds the maximum allowed size.');
            default:
                throw new RuntimeException('File upload failed. Please try again.');
        }

        if ($file['size'] > $this->maxBytes) {
            $maxMb = round($this->maxBytes / 1024 / 1024, 1);
            throw new RuntimeException("File is too large. Maximum allowed size is {$maxMb} MB.");
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Possible file upload attack detected.');
        }

        // Detect the REAL mime type by inspecting file contents — never trust $_FILES['type'].
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!$realMime || !isset($this->allowedMimes[$realMime])) {
            throw new RuntimeException('Invalid file type. Only PDF and Word documents (.pdf, .doc, .docx) are allowed.');
        }

        $extension  = $this->allowedMimes[$realMime];
        $originalName = basename($file['name']);
        $storedName   = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination  = $this->targetDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Failed to save the uploaded file on the server.');
        }

        // Harden the file itself against execution.
        chmod($destination, 0644);

        return [
            'original_name' => $originalName,
            'stored_name'   => $storedName,
            'file_path'     => $destination,
            'mime_type'     => $realMime,
            'file_size'     => (int)$file['size'],
        ];
    }

    public function delete(string $storedPath): bool
    {
        if (is_file($storedPath)) {
            return unlink($storedPath);
        }
        return false;
    }
}
