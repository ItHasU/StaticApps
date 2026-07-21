<?php

declare(strict_types=1);

const UPLOAD_TMP_BASE = '/data/.admin-state/upload-tmp';
const MAX_ZIP_ENTRIES = 500;
const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;
const MAX_COMPRESSION_RATIO = 100;

function max_upload_bytes(): int
{
    $mb = (int) (getenv('MAX_UPLOAD_SIZE_MB') ?: 50);
    return $mb * 1024 * 1024;
}

function process_upload_request(): void
{
    $identifier = client_identifier();
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $overwrite = !empty($_POST['overwrite']);
    $errors = [];

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Jeton de sécurité invalide ou expiré.';
    }

    if ($errors === [] && !rate_limit_allow('upload', $identifier, 10, 300)) {
        $errors[] = "Trop de tentatives d'upload. Réessayez dans quelques minutes.";
    }

    if ($errors === [] && !is_valid_app_slug($slug)) {
        $errors[] = 'Nom de dossier invalide (minuscules, chiffres et tirets uniquement, sans espace ni "..").';
    }

    $uploadError = $_FILES['app_zip']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($errors === [] && $uploadError !== UPLOAD_ERR_OK) {
        $errors[] = describe_upload_error($uploadError);
    }

    $targetDir = APPS_DIR . '/' . $slug;
    if ($errors === [] && is_dir($targetDir) && !$overwrite) {
        $errors[] = "Le dossier « {$slug} » existe déjà. Cochez « écraser » pour le remplacer.";
    }

    $extractedDir = null;
    if ($errors === []) {
        $tmpName = $_FILES['app_zip']['tmp_name'];
        $result = validate_and_extract_zip($tmpName);
        if ($result['error'] !== null) {
            $errors[] = $result['error'];
        } else {
            $extractedDir = $result['path'];
        }
    }

    if ($errors === [] && $extractedDir !== null) {
        if (is_dir($targetDir)) {
            remove_directory_recursive($targetDir);
        }
        if (!rename($extractedDir, $targetDir)) {
            $errors[] = "Échec du déplacement de l'application vers son emplacement final.";
        }
    }

    if ($errors !== [] && $extractedDir !== null && is_dir($extractedDir)) {
        remove_directory_recursive($extractedDir);
    }

    $size = $_FILES['app_zip']['size'] ?? 0;

    if ($errors === []) {
        regenerate_portal_menu();
        audit_log('upload_success', ['slug' => $slug, 'size' => $size]);
        redirect_to_dashboard('Application « ' . $slug . ' » publiée avec succès.');
        return;
    }

    audit_log('upload_failed', ['slug' => $slug, 'size' => $size, 'errors' => $errors]);
    redirect_to_dashboard(implode(' ', $errors), true);
}

function describe_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée.',
        UPLOAD_ERR_NO_FILE => 'Aucun fichier envoyé.',
        UPLOAD_ERR_PARTIAL => "L'envoi du fichier a été interrompu.",
        default => "Erreur lors de l'envoi du fichier.",
    };
}

/**
 * Valide en profondeur un zip reçu puis l'extrait dans un dossier temporaire
 * isolé. Retourne ['error' => string|null, 'path' => string|null].
 * Ne déplace jamais le contenu vers /data/apps directement.
 */
function validate_and_extract_zip(string $uploadedPath): array
{
    $fail = static fn (string $message): array => ['error' => $message, 'path' => null];

    if (!is_file($uploadedPath) || filesize($uploadedPath) === 0) {
        return $fail('Fichier reçu vide ou illisible.');
    }

    if (filesize($uploadedPath) > max_upload_bytes()) {
        return $fail('Le fichier dépasse la taille maximale autorisée.');
    }

    $handle = fopen($uploadedPath, 'rb');
    $magic = $handle !== false ? fread($handle, 4) : '';
    if ($handle !== false) {
        fclose($handle);
    }
    if ($magic !== "PK\x03\x04") {
        return $fail("Le fichier n'est pas une archive zip valide (signature incorrecte).");
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo !== false ? finfo_file($finfo, $uploadedPath) : false;
    if ($finfo !== false) {
        finfo_close($finfo);
    }
    $allowedMimeTypes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
    if ($mime === false || !in_array($mime, $allowedMimeTypes, true)) {
        return $fail("Le fichier n'est pas reconnu comme une archive zip (type MIME : {$mime}).");
    }

    $zip = new ZipArchive();
    if ($zip->open($uploadedPath) !== true) {
        return $fail("Impossible d'ouvrir l'archive zip.");
    }

    $entryCount = $zip->numFiles;
    if ($entryCount === 0) {
        $zip->close();
        return $fail('Archive zip vide.');
    }
    if ($entryCount > MAX_ZIP_ENTRIES) {
        $zip->close();
        return $fail("L'archive contient trop de fichiers (maximum " . MAX_ZIP_ENTRIES . ').');
    }

    $totalUncompressed = 0;
    $totalCompressed = 0;

    for ($i = 0; $i < $entryCount; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            $zip->close();
            return $fail("Entrée d'archive illisible.");
        }

        if (!entry_path_is_safe($stat['name'])) {
            $zip->close();
            return $fail("Archive rejetée : chemin d'entrée invalide (« {$stat['name']} »).");
        }

        if (entry_is_symlink($zip, $i)) {
            $zip->close();
            return $fail('Archive rejetée : lien symbolique interdit.');
        }

        $totalUncompressed += $stat['size'];
        $totalCompressed += $stat['comp_size'];

        if ($totalUncompressed > MAX_UNCOMPRESSED_BYTES) {
            $zip->close();
            return $fail('Archive rejetée : taille décompressée excessive.');
        }
    }

    if ($totalCompressed > 0 && ($totalUncompressed / $totalCompressed) > MAX_COMPRESSION_RATIO) {
        $zip->close();
        return $fail('Archive rejetée : taux de compression anormal (zip bomb suspecté).');
    }

    ensure_security_state_dir();
    if (!is_dir(UPLOAD_TMP_BASE)) {
        mkdir(UPLOAD_TMP_BASE, 0700, true);
    }
    $extractDir = UPLOAD_TMP_BASE . '/' . bin2hex(random_bytes(16));
    mkdir($extractDir, 0700, true);

    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        remove_directory_recursive($extractDir);
        return $fail("Échec de l'extraction de l'archive.");
    }
    $zip->close();

    flatten_single_root_directory($extractDir);
    strip_execute_permissions($extractDir);

    if (!is_file($extractDir . '/index.html')) {
        remove_directory_recursive($extractDir);
        return $fail("L'archive ne contient pas de fichier index.html à sa racine.");
    }

    return ['error' => null, 'path' => $extractDir];
}

/**
 * Rejette tout chemin d'entrée susceptible de sortir du dossier cible :
 * chemin absolu, segment ".." (zip slip), octet nul.
 */
function entry_path_is_safe(string $entryName): bool
{
    if ($entryName === '' || str_contains($entryName, "\0")) {
        return false;
    }

    $normalized = str_replace('\\', '/', $entryName);

    if (str_starts_with($normalized, '/') || preg_match('#^[A-Za-z]:#', $normalized)) {
        return false;
    }

    foreach (explode('/', $normalized) as $segment) {
        if ($segment === '..') {
            return false;
        }
    }

    return true;
}

function entry_is_symlink(ZipArchive $zip, int $index): bool
{
    $attributes = $zip->getExternalAttributesIndex($index, $opsys, $attr);
    if (!$attributes || $opsys !== ZipArchive::OPSYS_UNIX) {
        return false;
    }

    $unixMode = ($attr >> 16) & 0xFFFF;
    return ($unixMode & 0xF000) === 0xA000;
}

/**
 * Si l'archive contient un unique dossier racine (motif courant des exports
 * zip), remonte son contenu d'un niveau pour retrouver index.html à la racine.
 */
function flatten_single_root_directory(string $dir): void
{
    $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    if (count($entries) !== 1) {
        return;
    }

    $inner = $dir . '/' . $entries[0];
    if (!is_dir($inner)) {
        return;
    }

    foreach (array_diff(scandir($inner) ?: [], ['.', '..']) as $item) {
        rename($inner . '/' . $item, $dir . '/' . $item);
    }
    rmdir($inner);
}

/**
 * Les apps sont strictement statiques : aucun fichier n'a besoin d'être
 * exécutable une fois extrait.
 */
function strip_execute_permissions(string $dir): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        chmod((string) $item, $item->isDir() ? 0755 : 0644);
    }
}

