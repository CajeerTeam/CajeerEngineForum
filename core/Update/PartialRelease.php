<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** Durable partial bytes are scoped to the freshly downloaded digest, signature and tag.
 * The caller owns the exclusive download lock; no partial is an installable artifact.
 */
final class PartialRelease
{
    /** Reclaim abandoned partials and temporary sidecars, never published archives or inbox stages.
     * Called only under the global download guard. */
    public static function cleanup(string $directory, int $maxAge = 604800): int
    {
        $removed = 0; $threshold = time() - $maxAge;
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || $entry->isLink() || !$entry->isDir() || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $entry->getFilename())) continue;
            foreach (new \DirectoryIterator($entry->getPathname()) as $file) {
                if ($file->isDot() || $file->isLink() || !$file->isFile() || $file->getMTime() >= $threshold) continue;
                if (!preg_match('/^\.(?:partial\.(?:zip(?:\.sig)?|json)|[a-f0-9]{24}\.(?:zip(?:\.sig)?|sha256|sig)|tmp-[a-f0-9]{24})$/D', $file->getFilename())) continue;
                OperationFiles::remove($file->getPathname());
                if (++$removed >= 100) return $removed;
            }
        }
        return $removed;
    }

    /** @return resource */
    public static function open(string $file, string $state, array $identity): mixed
    {
        if (is_link($file) || is_link($state)) throw new \RuntimeException('Unsafe partial release path');
        $valid = false;
        if (is_file($file) && is_file($state)) {
            try {
                $stat = lstat($file);
                $valid = OperationFiles::read($state) === $identity && $stat['nlink'] === 1
                    && ($stat['mode'] & 0077) === 0 && $stat['size'] <= $identity['size'];
            } catch (\Throwable) { /* discard invalid receipt */ }
        }
        if (!$valid) {
            OperationFiles::remove($file); OperationFiles::remove($state);
            OperationFiles::json($state, $identity);
        }
        $handle = fopen($file, $valid ? 'r+b' : 'x+b');
        if (!is_resource($handle)) throw new \RuntimeException('Cannot open partial release');
        try {
            if (!chmod($file, 0600) || fseek($handle, 0, SEEK_END) !== 0) throw new \RuntimeException('Cannot protect partial release');
            return $handle;
        } catch (\Throwable $e) { fclose($handle); throw $e; }
    }
}
