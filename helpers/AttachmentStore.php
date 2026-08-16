<?php
require_once __DIR__ . '/../config/constants.php';

/**
 * Storage and access rules for supporting documents.
 *
 * These files are medical certificates. They were previously written under
 * uploads/attachments/ with a name built from the applicant's user id and the
 * upload time, and linked to directly - so the web server served them to anyone
 * who asked, signed in or not, and the name was guessable from a user id and a
 * timestamp. A sick note is the most sensitive thing this system holds, so both
 * halves of that are fixed here: names are random, and nothing is served without
 * passing viewableBy().
 *
 * The rules are static and take plain arrays so they can be tested without a
 * database or an HTTP request behind them.
 */
class AttachmentStore {
    /** Largest document accepted, in bytes. A scan of a sick note is well under this. */
    const MAX_BYTES = 5242880; // 5 MB

    /** Accepted extensions, mapped to what we send back on download. */
    const ALLOWED_TYPES = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    /**
     * The extension of an uploaded file, lowercased and without the dot.
     */
    public static function extensionOf(string $filename): string {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Whether an extension is one we accept.
     */
    public static function isAllowedExtension(string $extension): bool {
        return isset(self::ALLOWED_TYPES[strtolower($extension)]);
    }

    /**
     * What to serve a stored file as. Unknown extensions download as opaque
     * bytes rather than being guessed at and rendered.
     */
    public static function contentTypeFor(string $extension): string {
        return self::ALLOWED_TYPES[strtolower($extension)] ?? 'application/octet-stream';
    }

    /**
     * Why an upload cannot be accepted. An empty list means it can.
     *
     * PHP reports its own failures through $file['error']; a request that hit
     * post_max_size arrives with an empty $_FILES entry, which the caller sees
     * as "no file" rather than as a silent success.
     *
     * @param array $file one entry from $_FILES
     * @return string[] human-readable failures
     */
    public static function rejectionReasons(array $file): array {
        $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
            return ['The attached document is too large. The limit is ' . self::maxSizeLabel() . '.'];
        }
        if ($errorCode === UPLOAD_ERR_PARTIAL) {
            return ['The document only uploaded part-way. Please try again.'];
        }
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return ['No document was attached.'];
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            return ['The document could not be uploaded. Please try again.'];
        }

        $reasons = [];
        $extension = self::extensionOf($file['name'] ?? '');
        if (!self::isAllowedExtension($extension)) {
            $reasons[] = 'Only PDF, JPG and PNG documents are accepted.';
        }
        if ((int)($file['size'] ?? 0) > self::MAX_BYTES) {
            $reasons[] = 'The attached document is too large. The limit is ' . self::maxSizeLabel() . '.';
        }
        if ((int)($file['size'] ?? 0) <= 0) {
            $reasons[] = 'The attached document is empty.';
        }

        return $reasons;
    }

    /**
     * The size limit as it should be written on screen.
     */
    public static function maxSizeLabel(): string {
        return (int)(self::MAX_BYTES / 1048576) . ' MB';
    }

    /**
     * The name a document is stored under.
     *
     * Random rather than derived from the applicant: the old med_<id>_<time>
     * scheme let anybody who knew a user id walk the upload directory by
     * guessing timestamps. Nothing about the person survives in the name.
     */
    public static function storedName(string $extension, ?string $token = null): string {
        $token = $token ?? bin2hex(random_bytes(16));
        return 'att_' . $token . '.' . strtolower($extension);
    }

    /**
     * The relative path recorded against an application.
     */
    public static function relativePath(string $storedName): string {
        return 'uploads/attachments/' . $storedName;
    }

    /**
     * Resolve a stored path to a real file inside the upload directory, or null.
     *
     * Only the file name is honoured, and the result must still sit under the
     * upload directory once symlinks are resolved, so a path smuggled into the
     * database cannot be used to read /etc/passwd through the download route.
     */
    public static function resolve(?string $storedPath, ?string $uploadDir = null): ?string {
        if ($storedPath === null || $storedPath === '') {
            return null;
        }

        $base = realpath($uploadDir ?? UPLOAD_DIR);
        if ($base === false) {
            return null;
        }

        $candidate = realpath(rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($storedPath));
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }

        // Belt and braces: the resolved file must still be under the base.
        $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strncmp($candidate, $prefix, strlen($prefix)) === 0 ? $candidate : null;
    }

    /**
     * Who may open the document attached to an application.
     *
     * The applicant always may. HR, executives and administrators may, because
     * they sit in the approval chain or are accountable for it. A line manager
     * may only for their own people - being a manager somewhere else in the
     * organisation is not a reason to read a colleague's sick note. Everybody
     * else may not, including colleagues in the same department who can see the
     * absence on the calendar but have no business with the certificate behind it.
     *
     * @param array $application user_id, plus the applicant's manager_id and
     *                           their department's line_manager_id
     */
    public static function viewableBy(array $application, int $viewerId, string $viewerRole): bool {
        if ((int)($application['user_id'] ?? 0) === $viewerId) {
            return true;
        }

        if (in_array($viewerRole, [ROLE_HR, ROLE_EXECUTIVE, ROLE_ADMIN], true)) {
            return true;
        }

        if ($viewerRole === ROLE_MANAGER) {
            $isDirectManager = (int)($application['manager_id'] ?? 0) === $viewerId;
            $isDepartmentHead = (int)($application['line_manager_id'] ?? 0) === $viewerId;
            return $isDirectManager || $isDepartmentHead;
        }

        return false;
    }

    /**
     * Move an accepted upload into place and return the path to record.
     *
     * Returns null when the move fails, which the caller must treat as a failed
     * submission: a mandatory document that silently vanished is worse than a
     * rejected application, because the request then sits in a queue looking
     * complete.
     */
    public static function store(array $file, ?string $uploadDir = null): ?string {
        $directory = $uploadDir ?? UPLOAD_DIR;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return null;
        }

        $storedName = self::storedName(self::extensionOf($file['name'] ?? ''));
        $target = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return null;
        }
        // Readable by the web server only; these are medical documents sitting
        // on a shared host.
        @chmod($target, 0640);

        return self::relativePath($storedName);
    }

    /**
     * An application with the two fields viewableBy() needs to judge a manager.
     */
    public static function loadApplication(PDO $db, int $applicationId): ?array {
        $stmt = $db->prepare("
            SELECT a.id, a.user_id, a.application_no, a.attachment_path,
                   u.manager_id, d.line_manager_id
            FROM leave_applications a
            JOIN users u ON u.id = a.user_id
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE a.id = :id
        ");
        $stmt->execute(['id' => $applicationId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
