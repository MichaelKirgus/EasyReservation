<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Archive;
use App\Models\ArchiveReservation;
use App\Models\ArchiveWaitlistEntry;
use App\Services\ArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArchiveController extends Controller
{
    public function __construct(
        private readonly ArchiveService $archiveService,
    ) {
    }

    /**
     * Get all archives (filtered by role permissions).
     */
    public function index(): JsonResponse
    {
        $archives = Archive::query()
            ->orderByDesc('created_at')
            ->get();

        return response()->json($archives);
    }

    /**
     * Create a new archive.
     *
     * @throws \RuntimeException If archive name already exists
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:archives,name'],
            'description' => ['nullable', 'string'],
            'store_emails' => ['nullable', 'boolean'],
        ]);

        try {
            $archive = $this->archiveService->createArchive(
                name: $data['name'],
                description: $data['description'] ?? null,
                storeEmails: $data['store_emails'] ?? null,
            );

            return response()->json($archive, 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Delete an archive (admin/superadmin only).
     */
    public function destroy(Archive $archive): JsonResponse
    {
        try {
            $this->archiveService->deleteArchive($archive);

            return response()->json(['message' => 'Archive deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to delete archive', ['archive_id' => $archive->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to delete archive'], 500);
        }
    }

    /**
     * Get archived reservations for an archive.
     */
    public function getReservations(Request $request, Archive $archive): JsonResponse
    {
        $nameFilter = $request->query('name', '');

        $reservations = $this->archiveService->getReservations($archive, ['name' => $nameFilter]);

        return response()->json($reservations);
    }

    /**
     * Get archived waitlist entries for an archive.
     */
    public function getWaitlistEntries(Request $request, Archive $archive): JsonResponse
    {
        $nameFilter = $request->query('name', '');
        $statusFilter = $request->query('status', '');

        $entries = $this->archiveService->getWaitlistEntries($archive, [
            'name' => $nameFilter,
            'status' => $statusFilter,
        ]);

        return response()->json($entries);
    }

    /**
     * Restore a reservation from archive.
     */
    public function restoreReservation(Archive $archive, ArchiveReservation $reservation): JsonResponse
    {
        // Verify the reservation belongs to this archive
        if ($reservation->archive_id !== $archive->id) {
            return response()->json(['message' => 'Reservation does not belong to this archive'], 404);
        }

        try {
            $newReservation = $this->archiveService->restoreReservation($reservation);

            return response()->json([
                'message' => 'Reservation restored successfully',
                'reservation' => $newReservation,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to restore reservation', [
                'archive_id' => $archive->id,
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to restore reservation'], 500);
        }
    }

    /**
     * Restore multiple reservations from archive.
     */
    public function restoreReservations(Request $request, Archive $archive): JsonResponse
    {
        $data = $request->validate([
            'reservation_ids' => ['required', 'array'],
            'reservation_ids.*' => ['integer', 'exists:archive_reservations,id'],
        ]);

        // Verify all reservations belong to this archive
        $reservations = ArchiveReservation::where('archive_id', $archive->id)
            ->whereIn('id', $data['reservation_ids'])
            ->get();

        if ($reservations->count() !== count($data['reservation_ids'])) {
            return response()->json(['message' => 'Some reservations do not belong to this archive'], 404);
        }

        try {
            $this->archiveService->restoreReservations($reservations);

            return response()->json([
                'message' => 'Reservations restored successfully',
                'count' => count($data['reservation_ids']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to restore reservations', [
                'archive_id' => $archive->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to restore reservations'], 500);
        }
    }

    /**
     * Restore a waitlist entry from archive.
     */
    public function restoreWaitlistEntry(Archive $archive, ArchiveWaitlistEntry $entry): JsonResponse
    {
        // Verify the entry belongs to this archive
        if ($entry->archive_id !== $archive->id) {
            return response()->json(['message' => 'Entry does not belong to this archive'], 404);
        }

        try {
            $newEntry = $this->archiveService->restoreWaitlistEntry($entry);

            return response()->json([
                'message' => 'Waitlist entry restored successfully',
                'entry' => $newEntry,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to restore waitlist entry', [
                'archive_id' => $archive->id,
                'entry_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to restore waitlist entry'], 500);
        }
    }

    /**
     * Restore multiple waitlist entries from archive.
     */
    public function restoreWaitlistEntries(Request $request, Archive $archive): JsonResponse
    {
        $data = $request->validate([
            'entry_ids' => ['required', 'array'],
            'entry_ids.*' => ['integer', 'exists:archive_waitlist_entries,id'],
        ]);

        // Verify all entries belong to this archive
        $entries = ArchiveWaitlistEntry::where('archive_id', $archive->id)
            ->whereIn('id', $data['entry_ids'])
            ->get();

        if ($entries->count() !== count($data['entry_ids'])) {
            return response()->json(['message' => 'Some entries do not belong to this archive'], 404);
        }

        try {
            $this->archiveService->restoreWaitlistEntries($entries);

            return response()->json([
                'message' => 'Waitlist entries restored successfully',
                'count' => count($data['entry_ids']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to restore waitlist entries', [
                'archive_id' => $archive->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to restore waitlist entries'], 500);
        }
    }

    /**
     * Download archive as CSV.
     */
    public function downloadCsv(Archive $archive, string $type = 'reservations'): StreamedResponse
    {
        if ($type === 'waitlist') {
            $entries = ArchiveWaitlistEntry::where('archive_id', $archive->id)
                ->orderBy('date_added')
                ->get(['display_name', 'email', 'payload', 'status', 'date_added']);

            $callback = function () use ($entries) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['display_name', 'email', 'payload', 'status', 'date_added'], ';');
                foreach ($entries as $entry) {
                    fputcsv($handle, [
                        $entry->display_name,
                        $entry->email,
                        is_array($entry->payload) ? json_encode($entry->payload) : $entry->payload,
                        $entry->status,
                        $entry->date_added,
                    ], ';');
                }
                fclose($handle);
            };

            return response()->streamDownload($callback, 'archive_' . $archive->name . '_waitlist.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        // Default to reservations
        $reservations = ArchiveReservation::where('archive_id', $archive->id)
            ->orderBy('date_added')
            ->get(['display_name', 'email', 'payload', 'date_added']);

        $callback = function () use ($reservations) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['display_name', 'email', 'payload', 'date_added'], ';');
            foreach ($reservations as $reservation) {
                fputcsv($handle, [
                    $reservation->display_name,
                    $reservation->email,
                    is_array($reservation->payload) ? json_encode($reservation->payload) : $reservation->payload,
                    $reservation->date_added,
                ], ';');
            }
            fclose($handle);
        };

        return response()->streamDownload($callback, 'archive_' . $archive->name . '_reservations.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Archive current data to an archive.
     */
    public function archiveData(Archive $archive): JsonResponse
    {
        try {
            $this->archiveService->archiveData($archive);
            
            return response()->json(['message' => 'Archive populated successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to archive data', ['archive_id' => $archive->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to archive data'], 500);
        }
    }
}
