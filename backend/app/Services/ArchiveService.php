<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\ArchiveReservation;
use App\Models\ArchiveWaitlistEntry;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArchiveService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Create a new archive with current data.
     *
     * @param string $name Unique name for the archive
     * @param string|null $description Optional description
     * @param bool $storeEmails Whether to store email addresses (default from settings)
     */
    public function createArchive(string $name, ?string $description = null, ?bool $storeEmails = null): Archive
    {
        // Validate archive name is unique
        $existing = Archive::where('name', $name)->first();
        if ($existing) {
            throw new \RuntimeException("Archive with name '{$name}' already exists.");
        }

        return Archive::create([
            'name' => $name,
            'description' => $description,
            'store_emails' => $storeEmails ?? (int)($this->settings->get('archive_store_emails_by_default', 1) ?? 1) === 1,
        ]);
    }

    /**
     * Archive all current reservations and waitlist entries to an archive.
     *
     * @param Archive $archive The archive to store data in
     * @param bool|null $storeEmails Override the archive's default email storage setting
     */
    public function archiveData(Archive $archive, ?bool $storeEmails = null): void
    {
        DB::transaction(function () use ($archive, $storeEmails) {
            // Archive reservations
            $reservations = Reservation::query()->get();
            foreach ($reservations as $reservation) {
                $this->archiveReservation($archive, $reservation, $storeEmails);
            }

            // Archive waitlist entries
            $waitlistEntries = WaitlistEntry::query()->get();
            foreach ($waitlistEntries as $entry) {
                $this->archiveWaitlistEntry($archive, $entry, $storeEmails);
            }
        });
    }

    /**
     * Archive a single reservation.
     */
    private function archiveReservation(Archive $archive, Reservation $reservation, ?bool $storeEmails = null): void
    {
        $emailEncrypted = $reservation->email_encrypted;
        $emailValue = $reservation->email;

        // If store_emails is false, clear the email but keep it encrypted if it was
        if (($storeEmails ?? $archive->store_emails) === false) {
            $emailValue = '';
            $emailEncrypted = false;
        }

        ArchiveReservation::create([
            'archive_id' => $archive->id,
            'original_reservation_id' => $reservation->id,
            'display_name' => $reservation->display_name,
            'email' => $emailValue,
            'payload' => $reservation->payload,
            'date_added' => $reservation->date_added,
            'site_token' => $reservation->site_token,
            'email_encrypted' => $emailEncrypted,
        ]);
    }

    /**
     * Archive a single waitlist entry.
     */
    private function archiveWaitlistEntry(Archive $archive, WaitlistEntry $entry, ?bool $storeEmails = null): void
    {
        $emailEncrypted = $entry->email_encrypted;
        $emailValue = $entry->email;

        // If store_emails is false, clear the email but keep it encrypted if it was
        if (($storeEmails ?? $archive->store_emails) === false) {
            $emailValue = '';
            $emailEncrypted = false;
        }

        ArchiveWaitlistEntry::create([
            'archive_id' => $archive->id,
            'original_waitlist_entry_id' => $entry->id,
            'display_name' => $entry->display_name,
            'email' => $emailValue,
            'payload' => $entry->payload,
            'status' => $entry->status,
            'reservation_id' => $entry->reservation_id,
            'promoted_at' => $entry->promoted_at,
            'date_added' => $entry->date_added,
            'site_token' => $entry->site_token,
            'email_encrypted' => $emailEncrypted,
        ]);
    }

    /**
     * Get archived reservations with optional filtering.
     *
     * @param Archive $archive The archive to get reservations from
     * @param array $filters Optional filters (e.g., ['name' => 'search term'])
     */
    public function getReservations(Archive $archive, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = ArchiveReservation::where('archive_id', $archive->id);

        if (isset($filters['name']) && $filters['name'] !== '') {
            $query->where('display_name', 'LIKE', '%' . $filters['name'] . '%');
        }

        return $query->orderByDesc('date_added')->get();
    }

    /**
     * Get archived waitlist entries with optional filtering.
     *
     * @param Archive $archive The archive to get waitlist entries from
     * @param array $filters Optional filters (e.g., ['name' => 'search term', 'status' => 'pending'])
     */
    public function getWaitlistEntries(Archive $archive, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = ArchiveWaitlistEntry::where('archive_id', $archive->id);

        if (isset($filters['name']) && $filters['name'] !== '') {
            $query->where('display_name', 'LIKE', '%' . $filters['name'] . '%');
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('date_added')->get();
    }

    /**
     * Restore a single reservation from archive.
     *
     * @param ArchiveReservation $reservation The archived reservation to restore
     * @return Reservation The newly created active reservation
     */
    public function restoreReservation(ArchiveReservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation) {
            $newReservation = Reservation::create([
                'display_name' => $reservation->display_name,
                'email' => $reservation->email,
                'payload' => $reservation->payload,
                'date_added' => now(),
                'site_token' => $reservation->site_token,
                'email_encrypted' => $reservation->email_encrypted,
            ]);

            // Delete the archived reservation after successful restore
            $reservation->delete();

            return $newReservation;
        });
    }

    /**
     * Restore multiple reservations from archive.
     *
     * @param \Illuminate\Database\Eloquent\Collection $reservations Collection of archived reservations
     */
    public function restoreReservations(\Illuminate\Database\Eloquent\Collection $reservations): void
    {
        DB::transaction(function () use ($reservations) {
            foreach ($reservations as $reservation) {
                $this->restoreReservation($reservation);
            }
        });
    }

    /**
     * Restore a single waitlist entry from archive.
     *
     * @param ArchiveWaitlistEntry $entry The archived waitlist entry to restore
     * @return WaitlistEntry The newly created active waitlist entry
     */
    public function restoreWaitlistEntry(ArchiveWaitlistEntry $entry): WaitlistEntry
    {
        return DB::transaction(function () use ($entry) {
            $newEntry = WaitlistEntry::create([
                'display_name' => $entry->display_name,
                'email' => $entry->email,
                'payload' => $entry->payload,
                'status' => $entry->status,
                'reservation_id' => $entry->reservation_id,
                'promoted_at' => $entry->promoted_at,
                'date_added' => now(),
                'site_token' => $entry->site_token,
                'email_encrypted' => $entry->email_encrypted,
            ]);

            // Delete the archived entry after successful restore
            $entry->delete();

            return $newEntry;
        });
    }

    /**
     * Restore multiple waitlist entries from archive.
     *
     * @param \Illuminate\Database\Eloquent\Collection $entries Collection of archived waitlist entries
     */
    public function restoreWaitlistEntries(\Illuminate\Database\Eloquent\Collection $entries): void
    {
        DB::transaction(function () use ($entries) {
            foreach ($entries as $entry) {
                $this->restoreWaitlistEntry($entry);
            }
        });
    }

    /**
     * Delete an archive and all its data.
     *
     * @param Archive $archive The archive to delete
     */
    public function deleteArchive(Archive $archive): void
    {
        DB::transaction(function () use ($archive) {
            // Delete all archived reservations
            $archive->reservations()->delete();

            // Delete all archived waitlist entries
            $archive->waitlistEntries()->delete();

            // Delete the archive itself
            $archive->delete();
        });
    }
}
