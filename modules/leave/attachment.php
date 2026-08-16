<?php
/**
 * Supporting document download.
 *
 * The only route to an attachment. Files used to be linked directly out of
 * uploads/attachments/, which meant the web server handed a medical certificate
 * to anybody who could guess its name. Now the file is looked up by application,
 * the viewer is checked against AttachmentStore::viewableBy(), and the bytes are
 * streamed by PHP - the directory itself is closed to the web (see the .htaccess
 * beside the files).
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/AttachmentStore.php';
check_auth();

$applicationId = (int)($_GET['app'] ?? 0);
$viewerId      = (int)$_SESSION['user_id'];
$viewerRole    = $_SESSION['user_role'] ?? ROLE_EMPLOYEE;

/**
 * Refuse without saying which of "no such application", "no attachment" or
 * "not yours" applies, so the route cannot be used to probe for who has filed
 * a medical certificate.
 */
function attachment_denied(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Document not available.";
    exit;
}

if ($applicationId <= 0) {
    attachment_denied();
}

$db          = getDBConnection();
$application = AttachmentStore::loadApplication($db, $applicationId);

if ($application === null || empty($application['attachment_path'])) {
    attachment_denied();
}
if (!AttachmentStore::viewableBy($application, $viewerId, $viewerRole)) {
    attachment_denied();
}

$path = AttachmentStore::resolve($application['attachment_path']);
if ($path === null) {
    attachment_denied();
}

$extension = AttachmentStore::extensionOf($path);
// Named after the application rather than the stored token, so a file saved to
// a desktop is still identifiable, and sanitised because it goes in a header.
$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '', $application['application_no'] . '-attachment.' . $extension);

header('Content-Type: ' . AttachmentStore::contentTypeFor($extension));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
// A sick note has no business in a shared cache.
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

readfile($path);
