<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class AdminRssFeedController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public function combined(Request $request): Response
    {
        if (! $this->isRssEnabled()) {
            return response()->json(['message' => __('rss_feed_disabled')], 403);
        }

        $limit = $this->resolveLimit($request);

        $reservationItems = Reservation::query()
            ->orderByDesc('date_added')
            ->limit($limit)
            ->get(['id', 'display_name', 'date_added', 'updated_at'])
            ->map(function (Reservation $reservation): array {
                return [
                    'type' => 'reservation',
                    'id' => $reservation->id,
                    'title' => sprintf('Reservation: %s', $reservation->display_name),
                    'description' => sprintf('Reservation for %s.', $reservation->display_name),
                    'created_at' => $reservation->date_added ?? $reservation->created_at,
                    'updated_at' => $reservation->updated_at,
                ];
            });

        $waitlistItems = WaitlistEntry::query()
            ->orderByDesc('date_added')
            ->limit($limit)
            ->get(['id', 'display_name', 'status', 'date_added', 'updated_at'])
            ->map(function (WaitlistEntry $entry): array {
                return [
                    'type' => 'waitlist',
                    'id' => $entry->id,
                    'title' => sprintf('Waitlist: %s', $entry->display_name),
                    'description' => sprintf('Waitlist entry for %s (status: %s).', $entry->display_name, $entry->status),
                    'created_at' => $entry->date_added ?? $entry->created_at,
                    'updated_at' => $entry->updated_at,
                ];
            });

        $items = $reservationItems
            ->concat($waitlistItems)
            ->sortByDesc(fn (array $item) => $item['created_at'])
            ->take($limit)
            ->values();

        return $this->rssResponse(
            title: 'Reservations and Waitlist Feed',
            description: 'Latest reservation and waitlist changes.',
            items: $items,
            selfUrl: route('admin.rss.combined', ['limit' => $limit], false)
        );
    }

    public function guestCombined(Request $request): Response
    {
        if (! $this->isRssEnabled()) {
            return response()->json(['message' => __('rss_feed_disabled')], 403);
        }

        $scopeToken = $this->resolveScopeToken($request);
        $limit = $this->resolveLimit($request);

        $reservationItems = Reservation::query()
            ->when($scopeToken !== null, fn ($query) => $query->where('site_token', $scopeToken))
            ->orderByDesc('date_added')
            ->limit($limit)
            ->get(['id', 'display_name', 'date_added', 'updated_at'])
            ->map(function (Reservation $reservation): array {
                return [
                    'type' => 'reservation',
                    'id' => $reservation->id,
                    'title' => sprintf('Reservation: %s', $reservation->display_name),
                    'description' => sprintf('Reservation for %s.', $reservation->display_name),
                    'created_at' => $reservation->date_added ?? $reservation->created_at,
                    'updated_at' => $reservation->updated_at,
                ];
            });

        $waitlistItems = WaitlistEntry::query()
            ->when($scopeToken !== null, fn ($query) => $query->where('site_token', $scopeToken))
            ->orderByDesc('date_added')
            ->limit($limit)
            ->get(['id', 'display_name', 'status', 'date_added', 'updated_at'])
            ->map(function (WaitlistEntry $entry): array {
                return [
                    'type' => 'waitlist',
                    'id' => $entry->id,
                    'title' => sprintf('Waitlist: %s', $entry->display_name),
                    'description' => sprintf('Waitlist entry for %s (status: %s).', $entry->display_name, $entry->status),
                    'created_at' => $entry->date_added ?? $entry->created_at,
                    'updated_at' => $entry->updated_at,
                ];
            });

        $items = $reservationItems
            ->concat($waitlistItems)
            ->sortByDesc(fn (array $item) => $item['created_at'])
            ->take($limit)
            ->values();

        return $this->rssResponse(
            title: 'Reservations and Waitlist Feed',
            description: 'Latest reservation and waitlist changes.',
            items: $items,
            selfUrl: route('guest.rss.combined', ['limit' => $limit], false)
        );
    }

    public function reservations(Request $request): Response
    {
        if (! $this->isRssEnabled()) {
            return response()->json(['message' => __('rss_feed_disabled')], 403);
        }

        $limit = $this->resolveLimit($request);

        $items = Reservation::query()
            ->orderByDesc('date_added')
            ->limit($limit)
            ->get(['id', 'display_name', 'date_added', 'updated_at'])
            ->map(function (Reservation $reservation): array {
                return [
                    'type' => 'reservation',
                    'id' => $reservation->id,
                    'title' => sprintf('Reservation: %s', $reservation->display_name),
                    'description' => sprintf('Reservation for %s.', $reservation->display_name),
                    'created_at' => $reservation->date_added ?? $reservation->created_at,
                    'updated_at' => $reservation->updated_at,
                ];
            });

        return $this->rssResponse(
            title: 'Reservations Feed',
            description: 'Latest reservation entries.',
            items: $items,
            selfUrl: route('admin.rss.reservations', ['limit' => $limit], false)
        );
    }

    public function guestReservations(Request $request): Response
    {
        if (! $this->isRssEnabled()) {
            return response()->json(['message' => __('rss_feed_disabled')], 403);
        }

        $scopeToken = $this->resolveScopeToken($request);
        $limit = $this->resolveLimit($request);

        $items = Reservation::query()
            ->when($scopeToken !== null, fn ($query) => $query->where('site_token', $scopeToken))
            ->orderByDesc('date_added')
            ->limit($limit)
            ->get(['id', 'display_name', 'date_added', 'updated_at'])
            ->map(function (Reservation $reservation): array {
                return [
                    'type' => 'reservation',
                    'id' => $reservation->id,
                    'title' => sprintf('Reservation: %s', $reservation->display_name),
                    'description' => sprintf('Reservation for %s.', $reservation->display_name),
                    'created_at' => $reservation->date_added ?? $reservation->created_at,
                    'updated_at' => $reservation->updated_at,
                ];
            });

        return $this->rssResponse(
            title: 'Reservations Feed',
            description: 'Latest reservation entries.',
            items: $items,
            selfUrl: route('guest.rss.reservations', ['limit' => $limit], false)
        );
    }

    public function waitlist(Request $request): Response
    {
        if (! $this->isRssEnabled()) {
            return response()->json(['message' => __('rss_feed_disabled')], 403);
        }

        $limit = $this->resolveLimit($request);

        $items = WaitlistEntry::query()
            ->orderByDesc('date_added')
            ->limit($limit)
            ->get(['id', 'display_name', 'status', 'date_added', 'updated_at'])
            ->map(function (WaitlistEntry $entry): array {
                return [
                    'type' => 'waitlist',
                    'id' => $entry->id,
                    'title' => sprintf('Waitlist: %s', $entry->display_name),
                    'description' => sprintf('Waitlist entry for %s (status: %s).', $entry->display_name, $entry->status),
                    'created_at' => $entry->date_added ?? $entry->created_at,
                    'updated_at' => $entry->updated_at,
                ];
            });

        return $this->rssResponse(
            title: 'Waitlist Feed',
            description: 'Latest waitlist entries.',
            items: $items,
            selfUrl: route('admin.rss.waitlist', ['limit' => $limit], false)
        );
    }

    public function guestWaitlist(Request $request): Response
    {
        if (! $this->isRssEnabled()) {
            return response()->json(['message' => __('rss_feed_disabled')], 403);
        }

        $scopeToken = $this->resolveScopeToken($request);
        $limit = $this->resolveLimit($request);

        $items = WaitlistEntry::query()
            ->when($scopeToken !== null, fn ($query) => $query->where('site_token', $scopeToken))
            ->orderByDesc('date_added')
            ->limit($limit)
            ->get(['id', 'display_name', 'status', 'date_added', 'updated_at'])
            ->map(function (WaitlistEntry $entry): array {
                return [
                    'type' => 'waitlist',
                    'id' => $entry->id,
                    'title' => sprintf('Waitlist: %s', $entry->display_name),
                    'description' => sprintf('Waitlist entry for %s (status: %s).', $entry->display_name, $entry->status),
                    'created_at' => $entry->date_added ?? $entry->created_at,
                    'updated_at' => $entry->updated_at,
                ];
            });

        return $this->rssResponse(
            title: 'Waitlist Feed',
            description: 'Latest waitlist entries.',
            items: $items,
            selfUrl: route('guest.rss.waitlist', ['limit' => $limit], false)
        );
    }

    private function isRssEnabled(): bool
    {
        return (int) ($this->settings->get('rss_feed_enabled', 1) ?? 1) === 1;
    }

    private function resolveScopeToken(Request $request): ?string
    {
        $token = Arr::first([
            $request->header('X-Site-Token'),
            $request->query('t'),
            $request->header('X-Api-Key'),
            $request->query('api_key'),
            $request->cookie('api_session'),
        ], fn ($value) => is_string($value) && $value !== '');

        return is_string($token) ? $token : null;
    }

    private function resolveLimit(Request $request): int
    {
        $limit = (int) $request->query('limit', 50);

        if ($limit < 1) {
            return 1;
        }

        return min($limit, 200);
    }

    /**
     * @param Collection<int, array<string, mixed>> $items
     */
    private function rssResponse(string $title, string $description, Collection $items, string $selfUrl): Response
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rss = $dom->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $channel = $dom->createElement('channel');

        $channel->appendChild($dom->createElement('title', $title));
        $channel->appendChild($dom->createElement('description', $description));
        $channel->appendChild($dom->createElement('link', url('/')));
        $channel->appendChild($dom->createElement('language', config('app.locale', 'en')));
        $channel->appendChild($dom->createElement('generator', 'EasyReservation RSS'));
        $channel->appendChild($dom->createElement('lastBuildDate', now()->toRssString()));
        $channel->appendChild($dom->createElement('atom:link'));

        $lastAtomLink = $channel->lastChild;
        if ($lastAtomLink instanceof \DOMElement) {
            $lastAtomLink->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
            $lastAtomLink->setAttribute('href', url($selfUrl));
            $lastAtomLink->setAttribute('rel', 'self');
            $lastAtomLink->setAttribute('type', 'application/rss+xml');
        }

        foreach ($items as $item) {
            $created = $item['created_at'] ?? null;
            $updated = $item['updated_at'] ?? null;

            $itemNode = $dom->createElement('item');
            $itemNode->appendChild($dom->createElement('title', (string) $item['title']));
            $itemNode->appendChild($dom->createElement('description', (string) $item['description']));
            $itemNode->appendChild($dom->createElement('guid', sprintf('%s-%s-%s', $item['type'], $item['id'], optional($updated)->timestamp ?? '0')));
            $itemNode->appendChild($dom->createElement('pubDate', optional($created)->toRssString() ?? now()->toRssString()));
            $channel->appendChild($itemNode);
        }

        $rss->appendChild($channel);
        $dom->appendChild($rss);

        return response($dom->saveXML() ?: '', 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
        ]);
    }
}
