<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Exceptions\ApiError;
use App\Mail\UpgradeReceiptRequestedMail;
use App\Models\Plan;
use App\Models\PromoCodeUse;
use App\Models\UpgradeRequest;
use App\Models\UpgradeRequestProof;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\AuditLog;
use App\Services\Screens\ScreenStore;
use App\Services\Screens\UnreadablePicture;
use App\Services\Storage\RecordingFiles;
use GdImage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Payment receipts (since v1.7, docs/API.md "Payment receipts"): the screenshots and PDFs a customer sends with an
 * upgrade request, the bank's transaction ID, "paid", cancel, and staff asking for a new receipt.
 *
 * The type is read from the bytes, never the name. Pictures are decoded and written again as WebP (turned upright
 * from their EXIF orientation, at most 4096 px on the long side), which leaves every piece of metadata behind, with a
 * 480 px thumbnail. A PDF with a password or active content (scripts, launch actions, embedded files, rich media,
 * forms) is refused. Files are encrypted with the recordings key under `payment-proofs/<year>/<month>/`, the
 * receipt's id bound into each (the thumbnail's with "/thumb"), and erased 90 days after the request is decided.
 *
 * Every change runs in a transaction holding the request's row, so two calls at once can neither pass a limit nor
 * act on a request that was decided meanwhile (`409 request_decided`).
 */
final class PaymentProofs
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Files sent at a time (not yet locked by "paid"). */
    public const MAX_UNSENT = 3;

    /** Files on one request in all, sent or not. */
    public const MAX_TOTAL = 10;

    public const STORED_MAX_SIDE = 4096;

    public const RETENTION_DAYS = 90;

    public const ACCEPTS = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /** Names in a PDF that run something or carry another file: refused as `pdf_active`. */
    private const ACTIVE_PDF = '#/(?:JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA)(?![A-Za-z0-9])#';

    /** The most an object stream of a PDF may inflate to while it is checked. */
    private const MAX_INFLATED = 32 * 1024 * 1024;

    public function __construct(private readonly AuditLog $audit, private readonly RecordingFiles $files) {}

    /**
     * Keeps the file at [$path] (a local temporary file the caller deletes) as a receipt of [$request], sent by
     * [$user] as [$originalName]. The same file again on the same request is the receipt already kept
     * (`wasRecentlyCreated` false). `413 too_large`, `422 validation_failed` with `reason` (`file_type`, `pdf_password`,
     * `pdf_active`, `unreadable`), `409 too_many_proofs` with `max`, `409 request_decided`.
     */
    public function add(UpgradeRequest $request, User $user, string $path, string $originalName): UpgradeRequestProof
    {
        if ($request->user_id !== $user->id) {
            throw new ApiError(404, 'not_found', 'Upgrade request not found.');
        }
        $current = UpgradeRequest::query()->find($request->id);
        self::assertOpen($current ?? $request);
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw self::refused('unreadable', 'The file could not be read. Add it again.');
        }
        if ($size > self::MAX_BYTES) {
            throw new ApiError(413, 'too_large', 'A receipt is at most 5 MB.');
        }
        $sha = (string) hash_file('sha256', $path);
        $twin = $this->twin($request, $sha);
        if ($twin instanceof UpgradeRequestProof) {
            return $twin;
        }
        // Cheaper than decoding a picture only to find there is no room for it.
        $this->assertRoom($request);

        $kind = self::sniff($path);
        $id = strtolower((string) Str::ulid()); // as the model's own ids are
        $folder = 'payment-proofs/'.now()->format('Y/m');
        $stored = RecordingFiles::tempPath('proof');
        $thumb = RecordingFiles::tempPath('proof-thumb');
        $written = [];
        try {
            if ($kind === UpgradeRequestProof::PDF) {
                self::assertSafePdf((string) file_get_contents($path));
                [$width, $height] = [null, null];
                if (! copy($path, $stored)) {
                    throw self::refused('unreadable', 'The file could not be read. Add it again.');
                }
            } else {
                [$width, $height] = self::reencode($path, $stored);
                try {
                    ScreenStore::writeThumbnail($stored, $thumb);
                } catch (UnreadablePicture) {
                    throw self::refused('unreadable', 'The picture could not be read. Add a screenshot or the PDF from your bank app.');
                }
            }
            $bytes = (int) filesize($stored);
            $mainPath = $folder.'/'.$id.'.elr';
            $written[] = $mainPath;
            $this->files->encryptInto($stored, $mainPath, $id);
            $thumbPath = null;
            if ($kind === UpgradeRequestProof::IMAGE) {
                $thumbPath = $folder.'/'.$id.'-thumb.elr';
                $written[] = $thumbPath;
                $this->files->encryptInto($thumb, $thumbPath, ScreenStore::thumbKey($id));
            }

            $proof = DB::transaction(function () use ($request, $user, $sha, $id, $kind, $originalName, $bytes, $width, $height, $mainPath, $thumbPath): UpgradeRequestProof {
                self::assertOpen($this->lock($request));
                $twin = $this->twin($request, $sha);
                if ($twin instanceof UpgradeRequestProof) {
                    return $twin;
                }
                $this->assertRoom($request);
                $proof = new UpgradeRequestProof([
                    'upgrade_request_id' => $request->id,
                    'user_id' => $user->id,
                    'kind' => $kind,
                    'mime' => $kind === UpgradeRequestProof::PDF ? 'application/pdf' : 'image/webp',
                    'name' => self::cleanName($originalName, $kind),
                    'bytes' => $bytes,
                    'width' => $width,
                    'height' => $height,
                    'sha256' => $sha,
                    'path' => $mainPath,
                    'thumb_path' => $thumbPath,
                ]);
                $proof->id = $id;
                $proof->save();

                return $proof;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same file arrived twice at the same moment: the other call kept it.
            $this->files->erase($written);

            return $this->twin($request, $sha) ?? throw $e;
        } catch (Throwable $e) {
            $this->files->erase($written);
            throw $e;
        } finally {
            @unlink($stored);
            @unlink($thumb);
        }
        if (! $proof->wasRecentlyCreated) {
            $this->files->erase($written);

            return $proof;
        }
        $this->audit->record('payment_proof_added', $user->id, $request, "Receipt added to {$request->reference_code}");

        return $proof;
    }

    /** Removes a receipt not sent yet, with its files. `409 proof_locked` once sent, `409 request_decided`. */
    public function remove(UpgradeRequestProof $proof): void
    {
        $paths = DB::transaction(function () use ($proof): array {
            $request = $this->lock($proof->request);
            self::assertOpen($request);
            $current = UpgradeRequestProof::query()->lockForUpdate()->find($proof->id);
            if (! $current instanceof UpgradeRequestProof) {
                throw new ApiError(404, 'proof_not_found', 'This receipt is not on the request any more.');
            }
            if ($current->isLocked()) {
                throw new ApiError(409, 'proof_locked', 'This receipt was already sent, so it stays with the request.');
            }
            $current->delete();

            return [$current->path, $current->thumb_path];
        });
        $this->files->erase($paths);
    }

    /**
     * The customer says they paid: the files sent so far are locked, the transaction ID and payer name are kept, and
     * the request is in review. Needs an unsent file or a [$reference] (`422 proof_required`). Staff are told only when
     * the request goes into review now, not when more is sent while it already is.
     */
    public function markPaid(UpgradeRequest $request, ?string $reference, ?string $payerName): UpgradeRequest
    {
        $reference = self::normalizeReference($reference);
        $payerName = filled($payerName) ? mb_substr(trim((string) $payerName), 0, 120) : null;
        [$request, $before] = DB::transaction(function () use ($request, $reference, $payerName): array {
            $current = $this->lock($request);
            self::assertOpen($current);
            $unsent = UpgradeRequestProof::query()->where('upgrade_request_id', $current->id)->whereNull('locked_at')->whereNull('erased_at');
            if (! (clone $unsent)->exists() && $reference === null) {
                throw new ApiError(422, 'proof_required', 'Add a receipt or the transaction ID first.');
            }
            $before = $current->stage();
            $now = now();
            $unsent->update(['locked_at' => $now]);
            $current->forceFill([
                'paid_at' => $now,
                'payment_reference' => $reference ?? $current->payment_reference,
                'payer_name' => $payerName ?? $current->payer_name,
            ])->save();

            return [$current, $before];
        });
        $plan = self::planName($request->plan);
        $this->audit->record('upgrade_paid', $request->user_id, $request, "Receipt sent for {$plan} ({$request->reference_code})");
        if ($before !== UpgradeRequest::STAGE_IN_REVIEW) {
            $user = $request->user;
            $amount = $request->amount_due_pkr !== null ? Plan::rupees($request->amount_due_pkr) : (string) $request->amount_due;
            $what = $request->payment_reference !== null ? ' Transaction ID '.$request->payment_reference.'.' : '';
            app(UpgradeRequests::class)->tellStaff($before === UpgradeRequest::STAGE_NEEDS_RECEIPT ? "New receipt for {$plan}" : "Receipt for {$plan}",
                "{$user->name} ({$user->email}) · {$amount} · {$request->reference_code}.{$what}");
        }

        return $request;
    }

    /** The customer cancels a request still open: it is declined with "Cancelled by you" and a promo use comes back. */
    public function cancel(UpgradeRequest $request, User $user): UpgradeRequest
    {
        if ($request->user_id !== $user->id) {
            throw new ApiError(404, 'not_found', 'Upgrade request not found.');
        }
        $request = DB::transaction(function () use ($request): UpgradeRequest {
            $current = $this->lock($request);
            self::assertOpen($current);
            $now = now();
            $current->forceFill(['status' => UpgradeRequest::DECLINED, 'cancelled_at' => $now, 'decided_at' => $now, 'decided_by' => null, 'reply' => 'Cancelled by you'])->save();
            // Nothing was bought with the code: the person may use it again.
            PromoCodeUse::query()->where('upgrade_request_id', $current->id)->delete();

            return $current;
        });
        $this->audit->record('upgrade_cancelled', $user->id, $request, 'Upgrade request '.$request->reference_code.' cancelled');

        return $request;
    }

    /**
     * Staff ask for another receipt with [$note] (the request stays open, stage `needs_receipt`); the customer gets an
     * email and an activity item. `409 request_decided`.
     */
    public function requestNewReceipt(UpgradeRequest $request, User $staff, string $note): UpgradeRequest
    {
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') {
            throw new ApiError(422, 'validation_failed', 'Say what is needed.', ['errors' => ['note' => ['Say what is needed.']]]);
        }
        $request = DB::transaction(function () use ($request, $note): UpgradeRequest {
            $current = $this->lock($request);
            self::assertOpen($current);
            $current->forceFill(['proof_requested_at' => now(), 'proof_request_note' => $note])->save();

            return $current;
        });
        $plan = self::planName($request->plan);
        $user = $request->user;
        $this->audit->record('upgrade_receipt_requested', $staff->id, $request, "New receipt asked for {$request->reference_code} of ".$user->email);
        Mail::to($user->email)->queue(new UpgradeReceiptRequestedMail($plan, (string) $request->reference_code, $note, UpgradeRequests::accountUrl()));
        app(ActivityFeed::class)->post($user, 'upgrade_receipt_requested', "We need another receipt for {$plan}", $note, null, 'heroicon-o-document-magnifying-glass');

        return $request;
    }

    /**
     * The decrypted receipt ([$variant] `full`, or `thumb` for a picture). `404 proof_not_found` for a PDF's
     * thumbnail, another variant, or files already erased.
     *
     * @return array{bytes: string, mime: string, filename: string, inline: bool}
     */
    public function read(UpgradeRequestProof $proof, string $variant): array
    {
        $thumb = $variant === 'thumb';
        $missing = new ApiError(404, 'proof_not_found', 'This receipt is not here any more.');
        if (! in_array($variant, ['thumb', 'full'], true) || ($thumb && $proof->isPdf()) || $proof->erased_at !== null) {
            throw $missing;
        }
        $path = $thumb ? $proof->thumb_path : $proof->path;
        if ($path === null || ! $this->files->disk()->exists($path)) {
            throw $missing;
        }
        $plain = $this->files->decryptToMemory($path, $thumb ? ScreenStore::thumbKey($proof->id) : $proof->id);
        $bytes = '';
        while (! $plain->eof()) {
            $bytes .= $plain->fread(1 << 20);
        }
        $reference = (string) (UpgradeRequest::query()->whereKey($proof->upgrade_request_id)->value('reference_code') ?? 'EL-RECEIPT');

        return [
            'bytes' => $bytes,
            'mime' => $thumb ? 'image/webp' : $proof->mime,
            'filename' => $reference.'-receipt'.($thumb ? '-thumb' : '').($proof->isPdf() ? '.pdf' : '.webp'),
            'inline' => ! $proof->isPdf(),
        ];
    }

    /** Erases the files of receipts on requests decided or cancelled 90 days ago; the rows stay, with `erased_at`. */
    public function prune(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $erased = 0;
        UpgradeRequestProof::query()->whereNull('erased_at')
            ->whereHas('request', fn ($q) => $q->where(fn ($decided) => $decided
                ->where(fn ($q) => $q->whereNotNull('cancelled_at')->where('cancelled_at', '<=', $cutoff))
                ->orWhere(fn ($q) => $q->where('status', '!=', UpgradeRequest::PENDING)->whereNotNull('decided_at')->where('decided_at', '<=', $cutoff))))
            ->lazyById(200)
            ->each(function (UpgradeRequestProof $proof) use (&$erased): void {
                $this->files->erase([$proof->path, $proof->thumb_path]);
                $proof->forceFill(['erased_at' => now()])->save();
                $erased++;
            });

        return $erased;
    }

    /**
     * Erases the receipt files of every request of [$userId] or in [$workspaceId] before their rows go with the
     * account or workspace (the foreign keys would remove the rows and leave the encrypted files on the disk).
     */
    public function eraseFilesOf(?int $userId = null, ?int $workspaceId = null): void
    {
        if ($userId === null && $workspaceId === null) {
            return;
        }
        UpgradeRequestProof::query()->whereNull('erased_at')
            ->where(fn ($q) => $q->when($userId !== null, fn ($q) => $q->orWhere('user_id', $userId))
                ->when($workspaceId !== null, fn ($q) => $q->orWhereIn('upgrade_request_id', UpgradeRequest::query()->select('id')->where('workspace_id', $workspaceId))))
            ->lazyById(200)
            ->each(function (UpgradeRequestProof $proof): void {
                $this->files->erase([$proof->path, $proof->thumb_path]);
                $proof->forceFill(['erased_at' => now()])->save();
            });
    }

    /** The bank's transaction ID as kept: upper case, no spaces, at most 64 characters; null when empty. */
    private static function normalizeReference(?string $reference): ?string
    {
        $reference = strtoupper((string) preg_replace('/\s+/u', '', (string) $reference));

        return $reference !== '' ? mb_substr($reference, 0, 64) : null;
    }

    /** `image` or `pdf` from the file's first bytes (and, for a PDF, its end); `422 file_type` for anything else. */
    private static function sniff(string $path): string
    {
        $handle = @fopen($path, 'rb');
        $head = $handle !== false ? (string) fread($handle, 16) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        if (str_starts_with($head, '%PDF-')) {
            $size = (int) filesize($path);
            $tail = (string) file_get_contents($path, false, null, max(0, $size - 2048));
            if (! str_contains($tail, '%%EOF')) {
                throw self::refused('unreadable', 'This PDF is incomplete. Download it again from your bank app.');
            }

            return UpgradeRequestProof::PDF;
        }
        $image = str_starts_with($head, "\x89PNG\r\n\x1a\n") || str_starts_with($head, "\xFF\xD8\xFF")
            || (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP');
        if (! $image) {
            throw self::refused('file_type', 'Add a JPEG, PNG or WebP screenshot, or a PDF.');
        }

        return UpgradeRequestProof::IMAGE;
    }

    /** `422 pdf_password` for an encrypted PDF, `422 pdf_active` for one with scripts, actions or files inside. */
    private static function assertSafePdf(string $raw): void
    {
        // Dictionaries are in the file's text, or (since PDF 1.5) inside compressed object streams: both are read.
        // Other streams (pages, pictures, fonts) hold no dictionaries, and are skipped so their bytes are not misread.
        $outside = '';
        $inside = [];
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length && ($start = strpos($raw, 'stream', $offset)) !== false) {
            if ($start >= 3 && substr($raw, $start - 3, 3) === 'end') {
                $outside .= substr($raw, $offset, $start + 6 - $offset);
                $offset = $start + 6;

                continue;
            }
            $before = substr($raw, $offset, $start - $offset);
            $outside .= $before;
            $dataStart = $start + 6;
            if (substr($raw, $dataStart, 2) === "\r\n") {
                $dataStart += 2;
            } elseif (in_array(substr($raw, $dataStart, 1), ["\n", "\r"], true)) {
                $dataStart++;
            }
            $end = strpos($raw, 'endstream', $dataStart);
            $end = $end === false ? $length : $end;
            $objectAt = strrpos($before, 'obj');
            $dictionary = self::decodeNames($objectAt === false ? $before : substr($before, $objectAt));
            if (preg_match('#/Type\s*/ObjStm#', $dictionary) === 1) {
                $data = substr($raw, $dataStart, $end - $dataStart);
                $inflated = match (true) {
                    ! str_contains($dictionary, '/Filter') => $data,
                    preg_match('#/Filter\s*\[?\s*/FlateDecode\s*\]?\s*(?:/|>>)#', $dictionary) === 1 => @zlib_decode($data, self::MAX_INFLATED) ?: @zlib_decode(rtrim($data, "\r\n"), self::MAX_INFLATED),
                    default => false,
                };
                if (! is_string($inflated)) {
                    throw self::refused('unreadable', 'This PDF could not be read. Add a screenshot of the receipt instead.');
                }
                $inside[] = $inflated;
            }
            $offset = $end + 9;
        }
        $outside .= substr($raw, min($offset, $length));
        $text = self::decodeNames($outside."\n".implode("\n", array_map(self::decodeNames(...), $inside)));
        if (preg_match('#/Encrypt(?![A-Za-z0-9])#', $text) === 1) {
            throw self::refused('pdf_password', 'This PDF has a password. Add one without a password, or a screenshot.');
        }
        if (preg_match(self::ACTIVE_PDF, $text) === 1) {
            throw self::refused('pdf_active', 'This PDF has scripts or other files inside. Add a screenshot of the receipt instead.');
        }
    }

    /** PDF names may write characters as #xx ("/J#61vaScript"): read them as the characters they are. */
    private static function decodeNames(string $text): string
    {
        return (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $m): string => chr((int) hexdec($m[1])), $text);
    }

    /**
     * Decodes the picture and writes it again as WebP at [$target]: upright, at most STORED_MAX_SIDE on the long side.
     * Nothing of the file but its pixels survives (no EXIF, location, comments or colour profile).
     *
     * @return array{0: int, 1: int} the width and height kept
     */
    private static function reencode(string $path, string $target): array
    {
        $picture = ScreenStore::sniff($path);
        if ($picture === null) {
            throw self::refused('unreadable', 'The picture could not be read. Add a screenshot or the PDF from your bank app.');
        }
        if ($picture['width'] * $picture['height'] > ScreenStore::MAX_PIXELS || max($picture['width'], $picture['height']) > ScreenStore::MAX_SIDE) {
            throw self::refused('unreadable', 'The picture is too large to read. Add a smaller screenshot.');
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (! $image instanceof GdImage) {
            throw self::refused('unreadable', 'The picture could not be read. Add a screenshot or the PDF from your bank app.');
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        $image = self::upright($image, $picture['mime'] === 'image/jpeg' ? self::jpegOrientation($path) : 1);
        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1.0, self::STORED_MAX_SIDE / max($width, $height));
        if ($scale < 1.0) {
            $w = max(1, (int) round($width * $scale));
            $h = max(1, (int) round($height * $scale));
            $scaled = imagecreatetruecolor($w, $h);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagecopyresampled($scaled, $image, 0, 0, 0, 0, $w, $h, $width, $height);
            $image = $scaled;
            [$width, $height] = [$w, $h];
        }
        imagesavealpha($image, true);
        if (! imagewebp($image, $target, 90)) {
            throw self::refused('unreadable', 'The picture could not be read. Add a screenshot or the PDF from your bank app.');
        }

        return [$width, $height];
    }

    /** Turns the picture as its EXIF orientation (1–8) says. */
    private static function upright(GdImage $image, int $orientation): GdImage
    {
        $rotate = fn (GdImage $i, int $degrees): GdImage => imagerotate($i, $degrees, 0) ?: $i;
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = $rotate($image, 180);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = $rotate($image, -90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $image = $rotate($image, -90);
                break;
            case 7:
                $image = $rotate($image, 90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = $rotate($image, 90);
                break;
        }

        return $image;
    }

    /** The orientation tag (0x0112) of a JPEG's EXIF block, read without the exif extension; 1 when there is none. */
    private static function jpegOrientation(string $path): int
    {
        $data = (string) file_get_contents($path, false, null, 0, 256 * 1024);
        $offset = 2;
        $length = strlen($data);
        while ($offset + 4 <= $length && $data[$offset] === "\xFF") {
            $marker = ord($data[$offset + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break; // the picture data starts: no more metadata
            }
            $size = (int) unpack('n', substr($data, $offset + 2, 2))[1];
            if ($marker === 0xE1 && substr($data, $offset + 4, 6) === "Exif\0\0") {
                $tiff = substr($data, $offset + 10, $size - 8);
                $little = substr($tiff, 0, 2) === 'II';
                $short = fn (int $at): int => strlen($tiff) >= $at + 2 ? (int) unpack($little ? 'v' : 'n', substr($tiff, $at, 2))[1] : 0;
                $long = fn (int $at): int => strlen($tiff) >= $at + 4 ? (int) unpack($little ? 'V' : 'N', substr($tiff, $at, 4))[1] : 0;
                $ifd = $long(4);
                $entries = $short($ifd);
                for ($i = 0; $i < min($entries, 256); $i++) {
                    $entry = $ifd + 2 + $i * 12;
                    if ($short($entry) === 0x0112) {
                        $value = $short($entry + 8);

                        return $value >= 1 && $value <= 8 ? $value : 1;
                    }
                }

                return 1;
            }
            $offset += 2 + $size;
        }

        return 1;
    }

    /** The name shown to staff and the customer: the file's own, without folders or control characters. */
    private static function cleanName(string $name, string $kind): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))));
        if ($name === '') {
            $name = $kind === UpgradeRequestProof::PDF ? 'receipt.pdf' : 'receipt';
        }

        return mb_substr($name, 0, 255);
    }

    private static function refused(string $reason, string $message): ApiError
    {
        return new ApiError(422, 'validation_failed', $message, ['errors' => ['file' => [$message]], 'reason' => $reason]);
    }

    /** `409 request_decided` once the request is approved, declined or cancelled. */
    private static function assertOpen(UpgradeRequest $request): void
    {
        if ($request->isDecided()) {
            throw new ApiError(409, 'request_decided', match ($request->stage()) {
                UpgradeRequest::STAGE_APPROVED => 'This request was approved already.',
                UpgradeRequest::STAGE_CANCELLED => 'This request was cancelled.',
                default => 'This request was declined already.',
            });
        }
    }

    private function lock(UpgradeRequest $request): UpgradeRequest
    {
        return UpgradeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    private function twin(UpgradeRequest $request, string $sha): ?UpgradeRequestProof
    {
        $proof = UpgradeRequestProof::query()->where('upgrade_request_id', $request->id)->where('sha256', $sha)->first();

        return $proof instanceof UpgradeRequestProof ? $proof : null;
    }

    private function assertRoom(UpgradeRequest $request): void
    {
        $all = UpgradeRequestProof::query()->where('upgrade_request_id', $request->id);
        if ((clone $all)->count() >= self::MAX_TOTAL) {
            throw new ApiError(409, 'too_many_proofs', 'This request has '.self::MAX_TOTAL.' receipts already.', ['max' => self::MAX_TOTAL]);
        }
        if ((clone $all)->whereNull('locked_at')->whereNull('erased_at')->count() >= self::MAX_UNSENT) {
            throw new ApiError(409, 'too_many_proofs', 'Send these '.self::MAX_UNSENT.' receipts first, or remove one.', ['max' => self::MAX_UNSENT]);
        }
    }

    private static function planName(string $key): string
    {
        return (string) (Plan::query()->where('key', $key)->value('name') ?? $key);
    }
}
