<?php

declare(strict_types=1);

namespace CW\Files;

/**
 * Where the bytes of stored files live (I23): a write-once, content-addressed blob store keyed by sha256.
 *
 * Deliberately NO delete, rename, overwrite or shortening of a retention: a document's evidence (a supplier invoice, a
 * duty-stamp photo) must outlive every mistake and every compromised login of the application for at least 7 years.
 * Staging uses LocalFileStorage (a non-web directory whose shard directories are append-only and whose files a root
 * sweep makes immutable, deploy/staging/install_file_store.sh and seal_file_store.sh); on staging that is
 * DETECT-only for a file's content until the sweep has run (I36). Before live an S3 (London) or B2 bucket in Object
 * Lock COMPLIANCE mode replaces it behind this same interface, and that backend enforces the retention itself. That
 * backend must (I36): put with a conditional write (`If-None-Match: *`), so a second put of a key never adds a version;
 * read the key's OLDEST version (or refuse a key with more than one) and treat a delete marker as missing; run under a
 * role without s3:DeleteObject / DeleteObjectVersion / PutObjectRetention-with-bypass; and set the object's retention
 * at put and extend it in extendRetention() (COMPLIANCE allows extending, never shortening).
 */
interface FileStorage
{
    /**
     * Stores the file at $path under $key (its sha256, lower-case hex). Returns false when the key is already stored
     * with exactly this content (nothing written). FileStoreException: sha_mismatch (the content is not $key),
     * collision (the key is stored with other content), io.
     */
    public function put(string $key, string $path, \DateTimeImmutable $retainUntil): bool;

    /** @return resource a read stream of the stored content (FileStoreException missing) */
    public function open(string $key);

    public function exists(string $key): bool;

    /**
     * Keeps the stored content of $key at least until $until (a later attachment to another document, I36). Never
     * shortens a retention. FileStoreException missing when the key is not stored.
     */
    public function extendRetention(string $key, \DateTimeImmutable $until): void;

    /** @return iterable<string> every stored key (bin/verify_files.php counts the ones no row names) */
    public function keys(): iterable;

    /** The backend's name, stored on each row (stored_file.backend): local | s3_object_lock. */
    public function name(): string;
}
